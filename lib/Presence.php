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
 * Whether the nodes still report a target (REQ-INV-002, REQ-INV-004).
 *
 * Missing: a node that reported it was read successfully and no longer does,
 * and no other node does. Conflict: two nodes reported it in the same run,
 * so neither observation is used. A missing target keeps its row and history.
 */
enum Presence: string {
	/** Presence present case. */
	case Present  = 'present';
	/** Presence missing case. */
	case Missing  = 'missing';
	/** Presence conflict case. */
	case Conflict = 'conflict';
}
