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
 * What one node said about one target in one read.
 *
 * $values holds every value the backend declares for the target's type; a
 * value the node did not print, or printed in a form the parser rejects, is
 * null (unknown), never zero (REQ-BACKEND-003). $absent names the counters
 * whose stats section the node printed without that counter's line: Lustre
 * omits a counter that has not counted since its start time, so the summary
 * may count it as zero for a healthy target (ADR 0013). The value itself
 * stays null.
 */
final readonly class TargetReport {
	/**
	 * Initialize a parsed target report.
	 *
	 * @param TargetId              $id                      parsed target identifier
	 * @param array<string, ?float> $values                  value name => value
	 * @param ?string               $startMarker             counter stats start marker
	 * @param Condition             $condition               target operational condition
	 * @param ?string               $recovery                target recovery status string
	 * @param list<string>          $absent                  counter value names absent from a printed stats section
	 * @param ?float                $recoveryDurationSeconds observed target recovery duration in seconds
	 * @param ?int                  $evictedClients          observed evicted client count for this target
	 */
	public function __construct(
		public TargetId $id,
		public array $values,
		public ?string $startMarker,
		public Condition $condition,
		public ?string $recovery,
		public array $absent = [],
		public ?float $recoveryDurationSeconds = null,
		public ?int $evictedClients = null,
	) {
	}
}
