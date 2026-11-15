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
 * Counter continuity of one cached value (REQ-STATE-004). Reset means the
 * interval that ends at this observation contains a reset, so its rate is
 * unknown. Absent means the node printed the counter's stats section and the
 * counter's line was not in it, which Lustre does for a counter that has not
 * counted since its start time; the value itself stays unknown (ADR 0013).
 */
enum Continuity: string {
	case Continuous = 'continuous';
	case Reset      = 'reset';
	case Absent     = 'absent';
}
