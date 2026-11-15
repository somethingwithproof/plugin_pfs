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
 * Script server data query for the OSTs and MDTs of a parallel filesystem
 * (resource/script_server/pfs_osts.xml and pfs_mdts.xml). Cacti runs it as a
 * PHP CLI process for the index and query steps and through the script
 * server for each polled value. It answers from the plugin cache only and
 * never contacts a node (REQ-DQ-001).
 *
 * This file must parse on PHP 8.0: the script server includes it on
 * whatever PHP the site runs, and pfs_boot() refuses before any plugin class
 * loads, answering U.
 */

if (!isset($called_by_script_server)) {
	include_once(__DIR__ . '/../../../include/cli_check.php');

	$pfs_args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_values(array_filter(array_slice($_SERVER['argv'], 1), 'is_string')) : array();

	print ss_pfs_targets(...$pfs_args);
}

/**
 * @param int|string $host_id the Cacti device of the filesystem
 * @param string     $type    ost or mdt, fixed by the query XML
 * @param string     $cmd     index, num_indexes, query, or get
 * @param string     $arg1    the field for query and get
 * @param string     $arg2    the index (target name) for get
 */
function ss_pfs_targets($host_id = 0, $type = '', $cmd = 'index', $arg1 = '', $arg2 = ''): string {
	static $query = null;

	include_once(dirname(__DIR__) . '/setup.php');

	if (!pfs_boot()) {
		return $cmd === 'get' ? 'U' : '';
	}

	if ($query === null) {
		$query = pfs_target_query();
	}

	return $query->answer((int) $host_id, (string) $type, (string) $cmd, (string) $arg1, (string) $arg2);
}
