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
 * One cached value of one target (REQ-STATE-005): the raw value, when the
 * node was read, when the reader stored it, which node supplied it, the
 * interval it is expected at, and for counters the continuity and reset
 * epoch.
 *
 * A null value is unknown. published() is what milestone 3's data input will
 * serve: unknown for a value that is unknown or whose interval contains a
 * counter reset (REQ-STATE-004).
 */
final readonly class Observation {
	/**
	 * Initialize Observation.
	 *
	 * @param int $targetId
	 * @param string $metric
	 * @param ?float $value
	 * @param int $observedAt
	 * @param int $ingestedAt
	 * @param int $sourceNodeId
	 * @param int $expectedInterval
	 * @param Continuity $continuity
	 * @param ?string $startMarker
	 * @param int $epoch
	 */
	public function __construct(
		public int $targetId,
		public string $metric,
		public ?float $value,
		public int $observedAt,
		public int $ingestedAt,
		public int $sourceNodeId,
		public int $expectedInterval,
		public Continuity $continuity = Continuity::Continuous,
		public ?string $startMarker = null,
		public int $epoch = 0,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$target = Row::int($row, 'target_id', 0, 1);
		$metric = Row::string($row, 'metric');

		if ($target === 0 || preg_match(ValueSpec::NAME_PATTERN, $metric) !== 1) {
			return null;
		}

		$start = Row::string($row, 'start_marker');

		return new self(
			targetId: $target,
			metric: $metric,
			value: Row::float($row, 'value'),
			observedAt: Row::int($row, 'observed_at', 0, 0),
			ingestedAt: Row::int($row, 'ingested_at', 0, 0),
			sourceNodeId: Row::int($row, 'source_node_id', 0, 0),
			expectedInterval: Row::int($row, 'expected_interval', Filesystem::DEFAULT_INTERVAL, 1),
			continuity: Continuity::tryFrom(Row::string($row, 'continuity')) ?? Continuity::Reset,
			startMarker: $start === '' ? null : $start,
			epoch: Row::int($row, 'epoch', 0, 0),
		);
	}

	/**
	 * Published.
	 *
	 * @return ?float
	 */
	public function published(): ?float {
		return $this->continuity === Continuity::Reset ? null : $this->value;
	}
}
