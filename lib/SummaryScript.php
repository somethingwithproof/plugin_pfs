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
 * Answers the "Parallel Filesystem Summary" script server input: every
 * summary value as name:value pairs in one call (ADR 0011), from the summary
 * the reader stored. Nothing here contacts a node.
 *
 * Gauges are served while the summary's oldest contributing observation is
 * current or delayed, counters and their rates only while it is current
 * (ADR 0012), and age whenever anything was ever observed. A rate is also
 * served only while the counter it comes from was read recently enough to
 * be current: that read can trail the capacity read the summary time is
 * taken from.
 */
final class SummaryScript {
	/**
	 * Initialize SummaryScript.
	 *
	 * @param FilesystemRepository $filesystems
	 * @param SummaryRepository $summaries
	 * @param Clock $clock
	 */
	public function __construct(
		private readonly FilesystemRepository $filesystems,
		private readonly SummaryRepository $summaries,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Answer.
	 *
	 * @param int $hostId
	 * @return string
	 */
	public function answer(int $hostId): string {
		$filesystem = $hostId > 0 ? $this->filesystems->findByHost($hostId) : null;
		$summary    = $filesystem === null ? null : $this->summaries->find($filesystem->id);

		return self::format($summary, $filesystem->interval ?? Filesystem::DEFAULT_INTERVAL, $this->clock);
	}

	/**
	 * Format.
	 *
	 * @param ?Summary $summary
	 * @param int $interval
	 * @param Clock $clock
	 * @return string
	 */
	public static function format(?Summary $summary, int $interval, Clock $clock): string {
		$freshness = $summary === null ? Freshness::Never : (new FreshnessEvaluator($clock))->evaluate($summary->observedAt, $interval);
		$pairs     = [];

		foreach (Summary::GAUGES as $name) {
			$served  = $freshness === Freshness::Current || $freshness === Freshness::Delayed;
			$pairs[] = $name . ':' . ($served ? Number::format($summary?->value($name)) : 'U');
		}

		foreach (Summary::COUNTERS as $name) {
			$pairs[] = $name . ':' . ($freshness === Freshness::Current ? Number::format($summary?->value($name)) : 'U');
		}

		foreach (Summary::COUNTERS as $counter) {
			$read    = (new FreshnessEvaluator($clock))->evaluate($summary?->counterAt[$counter] ?? 0, $interval);
			$served  = $freshness === Freshness::Current && $read === Freshness::Current;
			$pairs[] = $counter . Summary::RATE_SUFFIX . ':' . ($served ? Number::format($summary?->value($counter . Summary::RATE_SUFFIX)) : 'U');
		}

		$age     = $summary === null || $summary->observedAt <= 0 ? null : (float) max(0, $clock->now() - $summary->observedAt);
		$pairs[] = Summary::AGE . ':' . Number::format($age);

		return implode(' ', $pairs);
	}
}
