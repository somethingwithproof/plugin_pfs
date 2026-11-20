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
 * Parallel Filesystems (Parallel Filesystem View realm). Parses on PHP 8.0;
 * pfs_page_start() refuses an unsupported runtime before any plugin class
 * loads. Reads the cache only. Every user, plugin administrators included,
 * sees only filesystems whose device Cacti lets them see, checked on this
 * request; the admin realm adds unmapped filesystems and node names.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/pfs/setup.php');

if (!pfs_page_start()) {
	exit;
}

$pfs_clock = new \Somethingwithproof\Pfs\SystemClock();
$pfs_page  = new \Somethingwithproof\Pfs\TargetsPage(
	new \Somethingwithproof\Pfs\FilesystemRepository(false, $pfs_clock),
	new \Somethingwithproof\Pfs\NodeRepository(false, $pfs_clock),
	new \Somethingwithproof\Pfs\TargetRepository(false),
	new \Somethingwithproof\Pfs\ObservationRepository(false),
	new \Somethingwithproof\Pfs\FreshnessEvaluator($pfs_clock),
	$pfs_clock,
	new \Somethingwithproof\Pfs\DeviceAccess(function ($host_id) {
		return is_device_allowed($host_id);
	}),
	api_plugin_user_realm_auth('pfs_admin.php')
);

top_header();
$pfs_page->render();
bottom_footer();
