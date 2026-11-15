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
 * The reader's last run on one poller (REQ-HEALTH-002). lastCompleted is
 * the start of the last run that got through its node list; a run that
 * stopped at its budget or died leaves it unchanged.
 */
final readonly class ReaderRun {
	public function __construct(
		public int $pollerId,
		public int $startedAt = 0,
		public int $finishedAt = 0,
		public int $lastCompleted = 0,
		public int $nodesTotal = 0,
		public int $nodesRead = 0,
		public int $nodesFailed = 0,
		public int $nodesSkipped = 0,
		public bool $stoppedEarly = false,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$poller = Row::int($row, 'poller_id', 0, 1);

		if ($poller === 0) {
			return null;
		}

		return new self(
			pollerId: $poller,
			startedAt: Row::int($row, 'started_at', 0, 0),
			finishedAt: Row::int($row, 'finished_at', 0, 0),
			lastCompleted: Row::int($row, 'last_completed', 0, 0),
			nodesTotal: Row::int($row, 'nodes_total', 0, 0),
			nodesRead: Row::int($row, 'nodes_read', 0, 0),
			nodesFailed: Row::int($row, 'nodes_failed', 0, 0),
			nodesSkipped: Row::int($row, 'nodes_skipped', 0, 0),
			stoppedEarly: Row::bool($row, 'stopped_early'),
		);
	}
}
