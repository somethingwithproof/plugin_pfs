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
 * One reader run on one poller (REQ-READ-001 to REQ-READ-006).
 *
 * It takes the poller's lease, reads each due node of its own poller once
 * (least recently attempted first, at most the configured number at a time,
 * starting no read after the time budget), parses each answer with the
 * filesystem's backend, reconciles the inventory per filesystem, and writes
 * each filesystem's results in one transaction that commits only while the
 * lease is still held. A node that failed keeps its previous observations
 * and their times; a run killed part way leaves every filesystem either as
 * the last run wrote it or as this run wrote it.
 *
 * The reader only reads: node sources carry one fixed read, and nothing here
 * can send a node anything else.
 */
final class Reader {
	/** Due slack constant. */
	public const DUE_SLACK = 30;

	/**
	 * Initialize Reader.
	 *
	 * @param FilesystemRepository $filesystems
	 * @param NodeRepository $nodes
	 * @param TargetRepository $targets
	 * @param ObservationRepository $observations
	 * @param NodeHealthRepository $health
	 * @param ReaderRepository $runs
	 * @param CacheRepository $cache
	 * @param SummaryRepository $summaries
	 * @param BackendRegistry $backends
	 * @param NodeSources $sources
	 * @param Clock $clock
	 * @param string $holder
	 */
	public function __construct(
		private readonly FilesystemRepository $filesystems,
		private readonly NodeRepository $nodes,
		private readonly TargetRepository $targets,
		private readonly ObservationRepository $observations,
		private readonly NodeHealthRepository $health,
		private readonly ReaderRepository $runs,
		private readonly CacheRepository $cache,
		private readonly SummaryRepository $summaries,
		private readonly BackendRegistry $backends,
		private readonly NodeSources $sources,
		private readonly Clock $clock,
		private readonly string $holder,
	) {
	}

	/**
	 * Seconds after a node's last attempt when it is due again: the
	 * filesystem's interval less a slack of a tenth of it, at most DUE_SLACK,
	 * because each run starts a little after its poller cycle.
	 */
	public static function dueAfter(int $interval): int {
		return $interval - min(self::DUE_SLACK, intdiv($interval, 10));
	}

	/**
	 * The budget only stops new reads from starting; a read started just
	 * before it may run for the whole read timeout. The lease covers that,
	 * plus time to write, so a slow last read does not cost the run its
	 * results.
	 */
	public function run(int $pollerId, int $budgetSeconds, int $concurrency, int $readTimeout, ?ShardAssigner $shard = null): ReaderRun {
		$started = $this->clock->now();

		if (!$this->runs->acquire($pollerId, $this->holder, $budgetSeconds + $readTimeout + 60)) {
			Log::info(sprintf('Reader on poller %d skipped: another run holds the lease', $pollerId));

			return new ReaderRun($pollerId);
		}

		try {
			return $this->runLeased($pollerId, $started, $budgetSeconds, $concurrency, $shard);
		} finally {
			$this->runs->release($pollerId, $this->holder);
		}
	}

	/**
	 * Run leased.
	 *
	 * @param int $pollerId
	 * @param int $started
	 * @param int $budgetSeconds
	 * @param int $concurrency
	 * @param ?ShardAssigner $shard
	 * @return ReaderRun
	 */
	private function runLeased(int $pollerId, int $started, int $budgetSeconds, int $concurrency, ?ShardAssigner $shard = null): ReaderRun {
		$deadline = microtime(true) + $budgetSeconds;
		$health   = $this->health->all();
		$fsById   = [];
		$due      = [];

		foreach ($this->filesystems->all() as $filesystem) {
			if ($filesystem->enabled() && $filesystem->pollerId === $pollerId && $this->backends->has($filesystem->backend)) {
				$fsById[$filesystem->id] = $filesystem;
			}
		}

		foreach ($this->nodes->forPoller($pollerId) as $node) {
			$filesystem = $fsById[$node->filesystemId] ?? null;

			if ($filesystem === null || !$node->enabled()) {
				continue;
			}

			if ($shard !== null && !$shard->ownsNode($node)) {
				continue;
			}

			$last = $health[$node->id]->lastAttempt ?? 0;

			if ($last > 0 && $started - $last < self::dueAfter($filesystem->interval)) {
				continue;
			}

			$due[] = $node;
		}

		usort($due, static function (Node $a, Node $b) use ($health): int {
			$aLast = $health[$a->id]->lastAttempt ?? 0;
			$bLast = $health[$b->id]->lastAttempt ?? 0;

			return $aLast !== $bLast ? ($aLast <=> $bLast) : ($a->id <=> $b->id);
		});

		$reads = $this->readNodes($due, $concurrency, $deadline);
		$now   = $this->clock->now();

		$totals = ['read' => 0, 'failed' => 0, 'skipped' => 0];
		$byFs   = [];

		foreach ($due as $node) {
			$byFs[$node->filesystemId][] = $node;
		}

		foreach ($byFs as $fsId => $fsNodes) {
			$this->applyFilesystem($pollerId, $fsById[$fsId], $fsNodes, $reads, $health, $now, $totals);
		}

		$stopped = $totals['skipped'] > 0;
		$run     = new ReaderRun($pollerId, $started, $this->clock->now(), $stopped ? 0 : $started, count($due), $totals['read'], $totals['failed'], $totals['skipped'], $stopped);

		$this->runs->finish($run, $this->holder);

		if ($stopped) {
			Log::warning(sprintf('Reader budget of %d seconds spent on poller %d; %d nodes are read next run', $budgetSeconds, $pollerId, $totals['skipped']));
		}

		return $run;
	}

	/**
	 * @param list<Node> $nodes
	 *
	 * @return array<int, NodeRead> node id => read; nodes left at the deadline are absent
	 */
	private function readNodes(array $nodes, int $concurrency, float $deadline): array {
		$byMethod = [];

		foreach ($nodes as $node) {
			$byMethod[$node->accessMethod->value][] = $node;
		}

		$reads = [];

		foreach ($byMethod as $method => $group) {
			$source = $this->sources->for(AccessMethod::from($method));

			if ($source === null) {
				foreach ($group as $node) {
					$reads[$node->id] = NodeRead::failed(ReadError::Config);
				}

				continue;
			}

			foreach ($source->readAll($group, $concurrency, $deadline) as $id => $read) {
				$reads[$id] = $read;
			}
		}

		return $reads;
	}

	/**
	 * @param list<Node>                $nodes  this filesystem's due nodes
	 * @param array<int, NodeRead>      $reads
	 * @param array<int, NodeHealth>    $health
	 * @param array{read: int, failed: int, skipped: int} $totals
	 */
	private function applyFilesystem(int $pollerId, Filesystem $filesystem, array $nodes, array $reads, array $health, int $now, array &$totals): void {
		$backend = $this->backends->get($filesystem->backend);
		$reports = [];
		$records = [];

		foreach ($nodes as $node) {
			$previous = $health[$node->id] ?? new NodeHealth($node->id);
			$read     = $reads[$node->id] ?? null;

			if ($read === null) {
				// Not reached before the budget ran out: keep its last attempt
				// time so it goes first next run.
				$totals['skipped']++;
				$records[] = new NodeHealth($node->id, $previous->lastAttempt, $previous->lastSuccess, $previous->duration, $previous->bytes, Collection::Skipped, $previous->error, $previous->version, $previous->unexpectedSections, $previous->rejectedNames);

				continue;
			}

			$report = $read->succeeded() ? $backend->parse($read->output) : null;
			$error  = $read->error ?? $report?->error();

			if ($error !== null || $report === null) {
				$totals['failed']++;
				$reports[$node->id] = null;
				$records[]          = new NodeHealth($node->id, $now, $previous->lastSuccess, $read->duration, $read->bytes, Collection::Failed, $error ?? ReadError::Unframed, $previous->version, 0, 0);

				Log::warning(sprintf('Node %d (%s) read failed: %s', $node->id, $node->name, ($error ?? ReadError::Unframed)->value));

				continue;
			}

			$totals['read']++;
			$reports[$node->id] = $report;
			$records[]          = new NodeHealth($node->id, $now, $now, $read->duration, $read->bytes, $report->partial ? Collection::Partial : Collection::Completed, null, $report->version, $report->unexpectedSections, $report->rejectedNames);
		}

		$existing = $this->targets->forFilesystem($filesystem->id);
		$plan     = InventoryReconciler::reconcile($filesystem, $reports, $existing);
		$previous = $this->observations->forFilesystem($filesystem->id);
		$specs    = [];

		foreach ($backend->values() as $spec) {
			$specs[$spec->targetType->value][$spec->name] = $spec;
		}

		$writes = [];

		foreach ($plan->updates as $update) {
			$writes[] = ['update' => $update, 'observations' => $this->observationsFor($update, $specs[$update->id->type->value] ?? [], $previous, $filesystem, $now)];
		}

		foreach ($records as $i => $record) {
			$foreign = $plan->foreign[$record->nodeId] ?? 0;

			if ($foreign > 0) {
				$records[$i] = new NodeHealth($record->nodeId, $record->lastAttempt, $record->lastSuccess, $record->duration, $record->bytes, $record->outcome, $record->error, $record->version, $record->unexpectedSections, $record->rejectedNames + $foreign);
			}
		}

		$rejected = array_sum(array_map(static fn (NodeHealth $h): int => $h->outcome === Collection::Skipped ? 0 : $h->rejectedNames, $records));

		if ($rejected > 0) {
			// Counted, never quoted: a rejected name is node output.
			Log::warning(sprintf('Filesystem %d: %d target names from node output were rejected', $filesystem->id, $rejected));
		}

		$summary = SummaryCalculator::calculate(self::summaryTargets($existing, $plan->updates, $previous, $writes), $this->summaries->find($filesystem->id), $filesystem->interval, $now);

		if (!$this->cache->apply($pollerId, $this->holder, $now, $filesystem, $writes, $records, $summary)) {
			Log::warning(sprintf('Filesystem %d: results discarded because the reader lease was lost', $filesystem->id));
		}
	}

	/**
	 * The filesystem's targets as they stand once this run's writes are
	 * applied: stored targets overlaid with this run's decisions, and stored
	 * observations overlaid with this run's values.
	 *
	 * @param array<string, Target>                                               $existing
	 * @param iterable<TargetUpdate>                                              $updates
	 * @param array<int, array<string, Observation>>                              $previous
	 * @param list<array{update: TargetUpdate, observations: list<Observation>}> $writes
	 *
	 * @return list<SummaryTarget>
	 */
	private static function summaryTargets(array $existing, iterable $updates, array $previous, array $writes): array {
		$targets = [];

		foreach ($existing as $name => $target) {
			$targets[$name] = new SummaryTarget($target->targetId, $target->review, $target->presence, $target->condition, $previous[$target->id] ?? []);
		}

		foreach ($updates as $update) {
			$name      = $update->id->name();
			$before    = $targets[$name] ?? null;
			$condition = $update->report->condition ?? ($update->presence === Presence::Missing ? ($before->condition ?? Condition::Unknown) : Condition::Unknown);

			$targets[$name] = new SummaryTarget($update->id, $update->existing->review ?? Review::Proposed, $update->presence, $condition, $before->values ?? []);
		}

		foreach ($writes as $write) {
			$name = $write['update']->id->name();

			if (!isset($targets[$name])) {
				continue;
			}

			$values = $targets[$name]->values;

			foreach ($write['observations'] as $observation) {
				$values[$observation->metric] = $observation;
			}

			$targets[$name] = new SummaryTarget($targets[$name]->id, $targets[$name]->review, $targets[$name]->presence, $targets[$name]->condition, $values);
		}

		return array_values($targets);
	}

	/**
	 * Observations to store for one decision: a present target's values with
	 * their continuity, unknown values for a conflict, nothing for a missing
	 * target (its last values keep their old times).
	 *
	 * @param array<string, ValueSpec>                    $specs    value name => spec for the target's type
	 * @param array<int, array<string, Observation>>      $previous target id => value name => cached observation
	 *
	 * @return list<Observation> with target id 0 for a target not stored yet
	 */
	private function observationsFor(TargetUpdate $update, array $specs, array $previous, Filesystem $filesystem, int $now): array {
		if ($update->presence === Presence::Missing) {
			return [];
		}

		$targetId  = $update->existing->id ?? 0;
		$before    = $previous[$targetId] ?? [];
		$absentMap = ($update->report !== null && $update->report->absent !== [])
			? array_fill_keys($update->report->absent, true)
			: [];
		$out = [];

		foreach ($specs as $name => $spec) {
			$prior = $before[$name] ?? null;

			if ($update->report === null) {
				$out[] = new Observation($targetId, $name, null, $now, $now, 0, $filesystem->interval, Continuity::Reset, null, ($prior->epoch ?? 0) + 1);

				continue;
			}

			$value      = $update->report->values[$name] ?? null;
			$start      = $update->report->startMarker;
			$continuity = $value === null && $spec->kind === ValueKind::Counter && isset($absentMap[$name])
				? ResetDetector::absent($prior, $start, $update->sourceNodeId)
				: ResetDetector::continuity($prior, $value, $start, $update->sourceNodeId, $spec->kind);
			$epoch      = ($prior->epoch ?? 0) + ($continuity === Continuity::Reset ? 1 : 0);

			$out[] = new Observation($targetId, $name, $value, $now, $now, $update->sourceNodeId, $filesystem->interval, $continuity, $spec->kind === ValueKind::Counter ? $start : null, $epoch);
		}

		return $out;
	}
}
