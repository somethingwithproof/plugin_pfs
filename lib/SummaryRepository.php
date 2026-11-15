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
 * Reads the summary the reader stored per filesystem. The reader writes it
 * through CacheRepository, in the same transaction as the observations it
 * was computed from. A rate row's observed_at holds the read time its
 * counter was summed at (Summary::$counterAt); every other row's holds the
 * summary's oldest observation.
 */
final class SummaryRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	public function find(int $filesystemId): ?Summary {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT metric, value, members, observed_at, computed_at FROM plugin_pfs_summary WHERE filesystem_id = ?', [$filesystemId], true, $this->connection));

		if ($rows === []) {
			return null;
		}

		$known    = array_merge(Summary::GAUGES, Summary::COUNTERS, Summary::RATES);
		$values   = array_fill_keys($known, null);
		$members  = [];
		$at       = [];
		$observed = 0;
		$computed = 0;

		foreach ($rows as $row) {
			$metric = Row::string($row, 'metric');

			if (!in_array($metric, $known, true)) {
				continue;
			}

			$values[$metric] = Row::float($row, 'value');
			$computed        = Row::int($row, 'computed_at', 0, 0);
			$counter         = Summary::counterOf($metric);

			if ($counter !== null) {
				$at[$counter] = Row::int($row, 'observed_at', 0, 0);

				continue;
			}

			$observed = Row::int($row, 'observed_at', 0, 0);

			$hash = Row::string($row, 'members');

			if (in_array($metric, Summary::COUNTERS, true)) {
				$members[$metric] = preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? $hash : '';
			}
		}

		return new Summary($values, $members, $observed, $computed, $at);
	}
}
