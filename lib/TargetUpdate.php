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
 * What one reader run decided about one target. $report is set only for a
 * present target; a conflict or missing target has none, and the cache
 * writer stores unknown values for a conflict and leaves a missing target's
 * values as they were.
 */
final readonly class TargetUpdate {
	/**
	 * Initialize TargetUpdate.
	 *
	 * @param TargetId $id
	 * @param ?Target $existing
	 * @param Presence $presence
	 * @param int $sourceNodeId
	 * @param ?TargetReport $report
	 */
	public function __construct(
		public TargetId $id,
		public ?Target $existing,
		public Presence $presence,
		public int $sourceNodeId,
		public ?TargetReport $report,
	) {
	}
}
