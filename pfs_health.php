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

/*
 * Parallel Filesystem Health (Parallel Filesystem Admin realm,
 * REQ-HEALTH-004). Parses on PHP 8.0; pfs_page_start() refuses an
 * unsupported runtime before any plugin class loads. Reads recorded results
 * only and never contacts a node.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/pfs/setup.php');

if (!pfs_page_start()) {
	exit;
}

$pfs_clock = new \Somethingwithproof\Pfs\SystemClock();
$pfs_page  = new \Somethingwithproof\Pfs\HealthPage(
	new \Somethingwithproof\Pfs\FilesystemRepository(false, $pfs_clock),
	new \Somethingwithproof\Pfs\NodeRepository(false, $pfs_clock),
	new \Somethingwithproof\Pfs\NodeHealthRepository(false),
	new \Somethingwithproof\Pfs\ReaderRepository(false, $pfs_clock),
	\Somethingwithproof\Pfs\HealthWarnings::production($pfs_clock),
	$pfs_clock
);

top_header();
$pfs_page->render(pfs_settings());
bottom_footer();
