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
 * The per-poller reader lease and run record. A lease is taken only when no
 * unexpired one is held by another run, in one statement, so two readers on
 * one poller cannot both write (REQ-READ-001). register_process_start()
 * already keeps a second reader process from starting; the lease also
 * fences a run that outlived its registration.
 */
final class ReaderRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public function acquire(int $pollerId, string $holder, int $seconds): bool {
		$now = $this->clock->now();

		db_execute_prepared('INSERT IGNORE INTO plugin_pfs_reader (poller_id, holder, expires_at) VALUES (?, ?, 0)', [$pollerId, ''], true, $this->connection);
		db_execute_prepared('UPDATE plugin_pfs_reader SET holder = ?, expires_at = ?, started_at = ? WHERE poller_id = ? AND (holder = ? OR expires_at < ?)', [$holder, $now + $seconds, $now, $pollerId, $holder, $now], true, $this->connection);

		return $this->holds($pollerId, $holder);
	}

	public function holds(int $pollerId, string $holder): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_pfs_reader WHERE poller_id = ? AND holder = ? AND expires_at >= ?', [$pollerId, $holder, $this->clock->now()], '', true, $this->connection) === 1;
	}

	public function release(int $pollerId, string $holder): void {
		db_execute_prepared('UPDATE plugin_pfs_reader SET expires_at = 0 WHERE poller_id = ? AND holder = ?', [$pollerId, $holder], true, $this->connection);
	}

	/**
	 * Record the run's counts while the lease is held. lastCompleted 0 keeps
	 * the previous completed time.
	 */
	public function finish(ReaderRun $run, string $holder): void {
		db_execute_prepared('UPDATE plugin_pfs_reader SET finished_at = ?, last_completed = IF(? > 0, ?, last_completed), nodes_total = ?, nodes_read = ?, nodes_failed = ?, nodes_skipped = ?, stopped_early = ?
			WHERE poller_id = ? AND holder = ? AND expires_at >= ?', [
			$run->finishedAt, $run->lastCompleted, $run->lastCompleted, $run->nodesTotal, $run->nodesRead, $run->nodesFailed, $run->nodesSkipped, $run->stoppedEarly ? 1 : 0,
			$run->pollerId, $holder, $this->clock->now(),
		], true, $this->connection);
	}

	/**
	 * @return array<int, ReaderRun> poller id => last run
	 */
	public function all(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_pfs_reader ORDER BY poller_id', [], true, $this->connection)) as $row) {
			$run = ReaderRun::fromRow($row);

			if ($run !== null) {
				$out[$run->pollerId] = $run;
			}
		}

		return $out;
	}
}
