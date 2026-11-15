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
 * Counter continuity between two observations of one value (REQ-STATE-004,
 * docs/research/counter-reset.md).
 *
 * A counter's interval is a reset when the value went down, the counter's
 * start_time changed (where the node prints one), the value came from a
 * different node than before (a new mount starts new counters; failover rule
 * 5), or the previous value was unknown, so no rate can be trusted across it.
 * A reset hidden by a later, larger value with no start_time is not
 * detectable; that limit is documented in the research.
 *
 * A counter Lustre left out of its stats section (Continuity::Absent) has not
 * counted since its start time, so its first printed value continues from
 * zero when the start time is printed, unchanged, and the node is the same;
 * without a start time to compare there is no way to rule out a clear, and
 * the interval is a reset (ADR 0013).
 */
final class ResetDetector {
	public static function continuity(?Observation $previous, ?float $value, ?string $start, int $sourceNodeId, ValueKind $kind): Continuity {
		if ($kind === ValueKind::Gauge || $previous === null || $value === null) {
			return Continuity::Continuous;
		}

		if ($previous->continuity === Continuity::Absent) {
			return $start !== null && $start === $previous->startMarker && $previous->sourceNodeId === $sourceNodeId
				? Continuity::Continuous
				: Continuity::Reset;
		}

		return match (true) {
			$previous->value === null                                                     => Continuity::Reset,
			$value < $previous->value                                                     => Continuity::Reset,
			$previous->sourceNodeId !== $sourceNodeId                                     => Continuity::Reset,
			$start !== null && $previous->startMarker !== null && $start !== $previous->startMarker => Continuity::Reset,
			default                                                                       => Continuity::Continuous,
		};
	}
}
