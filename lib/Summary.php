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
 * One filesystem's summary as the reader last computed it: the values the
 * "Parallel Filesystem Summary" data source serves, the member set behind
 * each aggregate counter, the read time each aggregate counter was summed
 * at, and the time of the oldest observation that contributed. A null value
 * is unknown.
 */
final readonly class Summary {
	/** Gauges: levels, percentages, and counts. */
	public const GAUGES = [
		'fill_max_pct', 'fill_mean_pct', 'fill_min_pct', 'fill_spread_pct', 'inode_max_pct',
		'kbytes_total', 'kbytes_avail',
		'targets_total', 'targets_missing', 'targets_unhealthy',
		'ost_contributors', 'mdt_contributors',
	];

	/** Aggregate cumulative counters, stored as DERIVE. */
	public const COUNTERS = ['read_bytes', 'write_bytes', 'md_ops'];

	/**
	 * Per-second rates of the aggregate counters, computed by the reader and
	 * stored as GAUGE, so Thold can threshold a rate without seeing a counter
	 * (ADR 0012). Each is the counter's name plus RATE_SUFFIX.
	 */
	public const RATES = ['read_bytes_rate', 'write_bytes_rate', 'md_ops_rate'];

	/** Rate suffix constant. */
	public const RATE_SUFFIX = '_rate';

	/** Computed when the data source is polled, from observedAt. */
	public const AGE = 'age';

	/**
	 * @param array<string, ?float> $values    metric => value, for every name in GAUGES, COUNTERS, and RATES
	 * @param array<string, string> $members   counter => hash of the targets (and operations) summed
	 * @param array<string, int>    $counterAt counter => the read time every summed value shares, 0 when the sum is unknown or its values were read at different times
	 */
	public function __construct(
		public array $values,
		public array $members,
		public int $observedAt,
		public int $computedAt,
		public array $counterAt = [],
	) {
	}

	/**
	 * @return list<string> every value name the data source returns, in order
	 */
	public static function names(): array {
		return array_merge(self::GAUGES, self::COUNTERS, self::RATES, [self::AGE]);
	}

	/**
	 * Value.
	 *
	 * @param string $metric
	 * @return ?float
	 */
	public function value(string $metric): ?float {
		return $this->values[$metric] ?? null;
	}

	/** The counter a rate is computed from, or null when $metric is not a rate. */
	public static function counterOf(string $metric): ?string {
		return in_array($metric, self::RATES, true) ? substr($metric, 0, -strlen(self::RATE_SUFFIX)) : null;
	}
}
