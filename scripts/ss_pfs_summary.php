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
 * Script server input for the "Parallel Filesystem Summary" data source:
 * every summary value of one filesystem as name:value pairs in one call
 * (ADR 0011). It answers from the summary the reader stored and never
 * contacts a node.
 *
 * This file must parse on PHP 8.0: the script server includes it on
 * whatever PHP the site runs, and pfs_boot() refuses before any plugin class
 * loads, answering U.
 */

if (!isset($called_by_script_server)) {
	include_once(__DIR__ . '/../../../include/cli_check.php');

	$pfs_args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_values(array_filter(array_slice($_SERVER['argv'], 1), 'is_string')) : array();

	print ss_pfs_summary(...$pfs_args);
}

/**
 * @param int|string $host_id the Cacti device of the filesystem
 */
function ss_pfs_summary($host_id = 0): string {
	static $script = null;

	include_once(dirname(__DIR__) . '/setup.php');

	if (!pfs_boot()) {
		return 'U';
	}

	if ($script === null) {
		$script = pfs_summary_script();
	}

	return $script->answer((int) $host_id);
}
