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
 * Outcome of the last read of one node.
 *
 * Completed: the fixed script's output arrived whole and parsed. Partial: it
 * arrived whole, but some sections failed, were truncated, or the script hit
 * its total limit, so some values are unknown. Failed: no usable output; no
 * value of that node was written. Skipped: the run's time budget ended before
 * the node was read.
 */
enum Collection: string {
	/** Collection never case. */
	case Never     = 'never';
	/** Collection completed case. */
	case Completed = 'completed';
	/** Collection partial case. */
	case Partial   = 'partial';
	/** Collection failed case. */
	case Failed    = 'failed';
	/** Collection skipped case. */
	case Skipped   = 'skipped';
}
