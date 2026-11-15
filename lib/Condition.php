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
 * Target condition from flags the node reports (REQ-STATE-003). With no flag
 * available the condition is Unknown, never InService.
 */
enum Condition: string {
	case InService  = 'in_service';
	case Degraded   = 'degraded';
	case ReadOnly   = 'read_only';
	case NoCreate   = 'no_create';
	case OutOfSpace = 'out_of_space';
	case Inactive   = 'inactive';
	case Unknown    = 'unknown';

	public function healthy(): bool {
		return $this === self::InService;
	}
}
