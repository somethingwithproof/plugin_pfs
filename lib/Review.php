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
 * Administrator review of a target the reader found (REQ-INV-003). A new
 * target is Proposed; milestone 3 creates Cacti objects only for Accepted
 * ones.
 */
enum Review: string {
	/** Review proposed case. */
	case Proposed = 'proposed';
	/** Review accepted case. */
	case Accepted = 'accepted';
	/** Review ignored case. */
	case Ignored  = 'ignored';
}
