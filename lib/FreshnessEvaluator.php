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
 * Freshness from observation time against the expected interval
 * (REQ-STATE-001). Current within one and a half intervals, delayed up to
 * three, stale beyond. The time a page is rendered never counts: a page
 * reload shows the same age (REQ-STATE-006).
 *
 * Observation times come from the reader's clock. On a remote collector
 * that clock can run ahead of the server rendering the page, so a time up
 * to one interval in the future reads as current; further ahead it is
 * stale, never current.
 */
final class FreshnessEvaluator {
	public function __construct(private readonly Clock $clock) {
	}

	public function evaluate(int $observedAt, int $interval): Freshness {
		if ($observedAt <= 0) {
			return Freshness::Never;
		}

		$interval = max(1, $interval);
		$age      = $this->clock->now() - $observedAt;

		return match (true) {
			$age < -$interval                 => Freshness::Stale,
			$age * 2 <= $interval * 3         => Freshness::Current,
			$age <= $interval * 3             => Freshness::Delayed,
			default                           => Freshness::Stale,
		};
	}
}
