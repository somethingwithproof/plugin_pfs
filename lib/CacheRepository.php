<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_pfs, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_pfs
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Pfs;

/**
 * Applies one reader run's results for one filesystem: targets, their
 * observations, the node health rows, and the summary computed from them,
 * in one transaction that commits
 * only while the run's lease is held (REQ-READ-004). A run that lost its
 * lease, or whose transaction InnoDB ended, writes nothing; every statement
 * is an upsert keyed on the target and value, so the next run simply writes
 * again.
 *
 * Missing targets keep their observations, so their age keeps growing from
 * the last real read. Values of a failed node are never touched, because
 * the plan holds no update for them.
 */
final class CacheRepository {
	private const KIB_METRICS = ['kbytes_total', 'kbytes_free', 'kbytes_avail'];

	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Upsert observations in chunked 500-row multi-row INSERT statements so
	 * large filesystems execute O(targets / 500) SQL round-trips.
	 *
	 * @param list<array{target_id: int, observation: Observation}> $entries
	 */
	public function upsertObservationsBatch(array $entries, int $batchSize = BatchUpserter::DEFAULT_BATCH_SIZE): int {
		if ($entries === []) {
			return 0;
		}

		$rows = [];

		foreach ($entries as $entry) {
			$o      = $entry['observation'];
			$valKib = in_array($o->metric, self::KIB_METRICS, true) ? FramedReportParser::kibToUint64($o->value) : null;
			$rows[] = [
				$entry['target_id'],
				$o->metric,
				$o->value,
				$valKib,
				$o->observedAt,
				$o->ingestedAt,
				$o->sourceNodeId,
				$o->expectedInterval,
				$o->continuity->value,
				$o->startMarker ?? '',
				$o->epoch,
			];
		}

		$batches = BatchUpserter::buildUpsertBatches(
			'plugin_pfs_observation',
			['target_id', 'metric', 'value', 'value_kib', 'observed_at', 'ingested_at', 'source_node_id', 'expected_interval', 'continuity', 'start_marker', 'epoch'],
			$rows,
			['value', 'value_kib', 'observed_at', 'ingested_at', 'source_node_id', 'expected_interval', 'continuity', 'start_marker', 'epoch'],
			$batchSize,
		);

		return BatchUpserter::execute(
			$batches,
			fn (string $sql, array $params): bool => (bool) db_execute_prepared($sql, $params, true, $this->connection),
		);
	}

	/**
	 * @param list<array{update: TargetUpdate, observations: list<Observation>}> $writes
	 * @param list<NodeHealth>                                                    $health
	 */
	public function apply(int $pollerId, string $holder, int $now, Filesystem $filesystem, array $writes, array $health, ?Summary $summary = null): bool {
		if (!db_begin_transaction($this->connection)) {
			return false;
		}

		$ok = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_pfs_reader WHERE poller_id = ? AND holder = ? AND expires_at >= ? FOR UPDATE', [$pollerId, $holder, $now], '', true, $this->connection) === 1;

		foreach ($writes as $write) {
			if (!$ok) {
				break;
			}

			$targetId = $this->target($filesystem, $write['update'], $now);
			$ok       = $targetId > 0 && $this->inTransaction();

			foreach ($write['observations'] as $observation) {
				if (!$ok) {
					break;
				}

				$ok = $this->observation($targetId, $observation) && $this->inTransaction();
			}
		}

		foreach ($health as $record) {
			if (!$ok) {
				break;
			}

			$ok = $this->health($record) && $this->inTransaction();
		}

		foreach ($summary === null ? [] : $summary->values as $metric => $value) {
			if (!$ok || $summary === null) {
				break;
			}

			$ok = $this->summary($filesystem->id, $metric, $value, $summary) && $this->inTransaction();
		}

		if ($ok && db_commit_transaction($this->connection)) {
			return true;
		}

		if ($this->inTransaction()) {
			db_rollback_transaction($this->connection);
		}

		return false;
	}

	/**
	 * Upsert the target and return its id. A new target is proposed for
	 * review; review is never changed here.
	 */
	private function target(Filesystem $filesystem, TargetUpdate $update, int $now): int {
		$present  = $update->presence === Presence::Present;
		$report   = $update->report;
		$lastSeen = $present ? $now : ($update->existing->lastSeen ?? 0);

		$ok = db_execute_prepared('INSERT INTO plugin_pfs_target (filesystem_id, type, target_index, name, review, presence, condition_state, recovery, source_node_id, first_seen, last_seen)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), presence = VALUES(presence), condition_state = VALUES(condition_state), recovery = VALUES(recovery),
				source_node_id = IF(VALUES(source_node_id) > 0, VALUES(source_node_id), source_node_id), last_seen = GREATEST(last_seen, VALUES(last_seen))', [
			$filesystem->id, $update->id->type->value, $update->id->index, $update->id->name(), Review::Proposed->value, $update->presence->value,
			($report->condition ?? ($update->presence === Presence::Missing ? ($update->existing->condition ?? Condition::Unknown) : Condition::Unknown))->value,
			$report->recovery ?? '', $present ? $update->sourceNodeId : 0, $present ? $now : 0, $lastSeen,
		], true, $this->connection);

		return $ok ? (int) db_fetch_insert_id($this->connection) : 0;
	}

	private function observation(int $targetId, Observation $o): bool {
		$valKib = in_array($o->metric, self::KIB_METRICS, true) ? FramedReportParser::kibToUint64($o->value) : null;

		return db_execute_prepared('INSERT INTO plugin_pfs_observation (target_id, metric, value, value_kib, observed_at, ingested_at, source_node_id, expected_interval, continuity, start_marker, epoch)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE value = VALUES(value), value_kib = VALUES(value_kib), observed_at = VALUES(observed_at), ingested_at = VALUES(ingested_at), source_node_id = VALUES(source_node_id),
				expected_interval = VALUES(expected_interval), continuity = VALUES(continuity), start_marker = VALUES(start_marker), epoch = VALUES(epoch)', [
			$targetId, $o->metric, $o->value, $valKib, $o->observedAt, $o->ingestedAt, $o->sourceNodeId, $o->expectedInterval, $o->continuity->value, $o->startMarker ?? '', $o->epoch,
		], true, $this->connection);
	}

	private function summary(int $filesystemId, string $metric, ?float $value, Summary $summary): bool {
		$counter = Summary::counterOf($metric);
		$valKib  = in_array($metric, self::KIB_METRICS, true) ? FramedReportParser::kibToUint64($value) : null;

		// A rate row keeps its counter's read time, which the next run's rate
		// is measured from (SummaryRepository).
		$observedAt = $counter === null ? $summary->observedAt : ($summary->counterAt[$counter] ?? 0);

		return db_execute_prepared('INSERT INTO plugin_pfs_summary (filesystem_id, metric, value, value_kib, members, observed_at, computed_at)
			VALUES (?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE value = VALUES(value), value_kib = VALUES(value_kib), members = VALUES(members), observed_at = VALUES(observed_at), computed_at = VALUES(computed_at)', [
			$filesystemId, $metric, $value, $valKib, $summary->members[$metric] ?? '', $observedAt, $summary->computedAt,
		], true, $this->connection);
	}

	private function health(NodeHealth $h): bool {
		return db_execute_prepared('INSERT INTO plugin_pfs_node_health (node_id, last_attempt, last_success, duration, bytes, outcome, error, version, unexpected_sections, rejected_names)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE last_attempt = VALUES(last_attempt), last_success = VALUES(last_success), duration = VALUES(duration), bytes = VALUES(bytes), outcome = VALUES(outcome),
				error = VALUES(error), version = VALUES(version), unexpected_sections = VALUES(unexpected_sections), rejected_names = VALUES(rejected_names)', [
			$h->nodeId, $h->lastAttempt, $h->lastSuccess, $h->duration, $h->bytes, $h->outcome->value, $h->error->value ?? '', $h->version ?? '', $h->unexpectedSections, $h->rejectedNames,
		], true, $this->connection);
	}

	/**
	 * InnoDB ends a transaction on a deadlock, and Cacti then retries the
	 * failed statement outside it, so the transaction is checked after every
	 * statement and the run's writes for this filesystem are abandoned when
	 * it has ended.
	 */
	private function inTransaction(): bool {
		return (int) db_fetch_cell_prepared('SELECT @@in_transaction', [], '', true, $this->connection) === 1;
	}
}
