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
 * The parallel filesystem reader for one poller, started by the
 * poller_bottom hook:
 *
 *   php plugins/pfs/poller_pfs.php [--poller=N] [--force] [--debug]
 *
 * One instance per poller, through Cacti's process registry (REQ-READ-001).
 * This file must parse on PHP 8.0 and refuses to run below 8.2.
 */

chdir(__DIR__ . '/../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/plugins/pfs/setup.php');

ini_set('max_execution_time', '0');

$pfs_poller = (int) $config['poller_id'];
$pfs_force  = false;

foreach (array_slice($_SERVER['argv'], 1) as $pfs_arg) {
	if (strpos($pfs_arg, '--poller=') === 0) {
		$pfs_poller = (int) substr($pfs_arg, 9);
	} elseif ($pfs_arg === '--force') {
		$pfs_force = true;
	} elseif ($pfs_arg === '--debug') {
		$debug = true;
	} else {
		fwrite(STDERR, 'ERROR: Unknown argument' . PHP_EOL . 'usage: poller_pfs.php [--poller=N] [--force] [--debug]' . PHP_EOL);
		exit(1);
	}
}

if (pfs_refuse_unsupported()) {
	exit(1);
}

pfs_boot();

if (!$pfs_force && !api_plugin_is_enabled('pfs')) {
	exit(0);
}

if ($pfs_poller < 1) {
	exit(0);
}

if (!(new \Somethingwithproof\Pfs\Schema(false))->isCurrent()) {
	cacti_log('ERROR: The parallel filesystem reader did not run because the plugin schema is not current; open the Plugins page to finish the upgrade', false, 'PFS');
	exit(1);
}

$pfs_settings = pfs_settings();

if (!register_process_start('pfs', 'reader', $pfs_poller, $pfs_settings->readerBudget + $pfs_settings->readTimeout + 120)) {
	cacti_log('NOTE: A parallel filesystem reader is already running on poller ' . $pfs_poller, false, 'PFS', POLLER_VERBOSITY_MEDIUM);
	exit(0);
}

try {
	$pfs_run = pfs_reader($pfs_poller, $pfs_settings)->run($pfs_poller, $pfs_settings->readerBudget, $pfs_settings->concurrency, $pfs_settings->readTimeout);
} finally {
	unregister_process('pfs', 'reader', $pfs_poller);
}

cacti_log(sprintf('STATS: Poller:%d Nodes:%d Read:%d Failed:%d NotReached:%d', $pfs_poller, $pfs_run->nodesTotal, $pfs_run->nodesRead, $pfs_run->nodesFailed, $pfs_run->nodesSkipped), false, 'PFS', POLLER_VERBOSITY_MEDIUM);

exit(0);
