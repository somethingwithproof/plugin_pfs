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

pfs_require_post('pfs_health.php', array('reinstall_templates'));

$pfs_clock     = new \Somethingwithproof\Pfs\SystemClock();
$pfs_templates = pfs_template_installer();

if (get_nfilter_request_var('action') === 'reinstall_templates') {
	$pfs_ok = $pfs_templates->install();
	raise_message('pfs_templates', $pfs_ok ? __('The templates and data query files were installed.', 'pfs') : __('The templates could not be installed: %s', $pfs_templates->failure(), 'pfs'), $pfs_ok ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);
	header('Location: pfs_health.php');
	exit;
}

$pfs_access      = new \Somethingwithproof\Pfs\DeviceAccess(function ($host_id) {
	return is_device_allowed($host_id);
});
$pfs_objects     = new \Somethingwithproof\Pfs\CactiObjectRepository(false);
$pfs_filesystems = array();
$pfs_devices     = array();

foreach ($pfs_access->filesystems((new \Somethingwithproof\Pfs\FilesystemRepository(false, $pfs_clock))->all()) as $pfs_fs) {
	$pfs_filesystems[$pfs_fs->id] = $pfs_fs;
	$pfs_devices[$pfs_fs->id]     = $pfs_fs->hostId > 0 ? $pfs_objects->device($pfs_fs->hostId) : null;
}

$pfs_page  = new \Somethingwithproof\Pfs\HealthPage(
	new \Somethingwithproof\Pfs\FilesystemRepository(false, $pfs_clock),
	new \Somethingwithproof\Pfs\NodeRepository(false, $pfs_clock),
	new \Somethingwithproof\Pfs\NodeHealthRepository(false),
	new \Somethingwithproof\Pfs\ReaderRepository(false, $pfs_clock),
	\Somethingwithproof\Pfs\HealthWarnings::production($pfs_clock),
	$pfs_clock,
	$pfs_access
);

top_header();
$pfs_page->render(pfs_settings(), \Somethingwithproof\Pfs\HealthWarnings::production($pfs_clock)->objects($pfs_templates->failure(), $pfs_templates->present(), $pfs_templates->queryFileProblems(), $pfs_filesystems, $pfs_devices));
bottom_footer();
