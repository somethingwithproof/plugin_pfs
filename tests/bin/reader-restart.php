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
 * Reader restart scenarios, included by tests/bin/lifecycle.php:
 *
 *   reader_kill    a run killed while reading leaves no partial result, and
 *                  the next run continues once the lease expires
 *   reader_victim  the run that is killed (started by reader_kill)
 *   reader_second  a second reader start on the same poller exits at once
 *
 * Sets $result. Must parse on PHP 8.0.
 */

use Somethingwithproof\Pfs\AccessMethod;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\FixtureNodeSource;
use Somethingwithproof\Pfs\NodeSource;
use Somethingwithproof\Pfs\NodeSources;

include_once($config['base_path'] . '/plugins/pfs/setup.php');
pfs_boot();

const RESTART_TIME   = 1800000000;
const RESTART_BUDGET = 240;

/**
 * Dies outright while reading, as on an OOM kill or a reboot:
 * no finally block runs, so the lease is never released.
 */
final class RestartKillingSource implements NodeSource {
	public function readAll(array $nodes, int $concurrency, float $deadline): array {
		function_exists('posix_kill') ? posix_kill(getmypid(), 9) : exec('kill -9 ' . getmypid());
		sleep(5);

		return array();
	}
}

function restart_reader(FixedClock $clock, NodeSource $source, $holder) {
	$sources = new NodeSources();
	$sources->register(AccessMethod::Ssh, $source);

	$run = pfs_reader_with(1, $sources, $clock, $holder)->run(1, RESTART_BUDGET, 4);

	return array('total' => $run->nodesTotal, 'read' => $run->nodesRead, 'skipped' => $run->nodesSkipped);
}

function restart_state() {
	return array(
		'lease'        => db_fetch_row('SELECT holder, expires_at FROM plugin_pfs_reader WHERE poller_id = 1'),
		'health'       => (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_pfs_node_health'),
		'targets'      => (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_pfs_target'),
		'observations' => (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_pfs_observation'),
	);
}

function restart_seed() {
	db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('scratch', 'lustre', 1, 300, 'enabled')");
	$fs = (int) db_fetch_insert_id();
	db_execute_prepared("INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state) VALUES (?, 'oss01', 'oss', 1, '10.20.0.1', 22, 'pfsread', 'ssh', 'enabled')", array($fs));

	return (int) db_fetch_insert_id();
}

switch ($action) {
	case 'reader_second':
		// A live process holds the reader slot for poller 1. Cacti 1.2.x
		// treats a slot as held only when the holder runs the same script,
		// so the stand-in names poller_pfs.php on its command line.
		$script  = $config['base_path'] . '/plugins/pfs/poller_pfs.php';
		$holder  = proc_open(array(PHP_BINARY, '-r', 'sleep(60);', $script), array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
		$status  = proc_get_status($holder);
		register_process('pfs', 'reader', 1, $status['pid'], 300);
		$started = microtime(true);
		exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg($script) . ' --poller=1 --force 2>&1', $lines, $rc);
		proc_terminate($holder, 9);
		proc_close($holder);
		unregister_process('pfs', 'reader', 1);
		$result = array('rc' => $rc, 'seconds' => round(microtime(true) - $started, 1), 'output' => implode("\n", $lines), 'leases' => (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_pfs_reader'));

		// Control: with the slot free, the same start does run and take the lease.
		exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg($script) . ' --poller=1 --force 2>&1', $lines, $rc);
		$result['rc_when_free']     = $rc;
		$result['leases_when_free'] = (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_pfs_reader');

		break;
	case 'reader_victim':
		restart_reader(new FixedClock(RESTART_TIME), new RestartKillingSource(), 'victim');

		break;
	case 'reader_kill':
		$node = restart_seed();

		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/lifecycle.php') . ' reader_victim 2>&1', $lines, $rc);
		$result = array('victim_rc' => $rc, 'after_kill' => restart_state());

		$source = new FixtureNodeSource(array($node => (string) file_get_contents(dirname(__DIR__) . '/fixtures/lustre/oss-2.15.txt')));

		// Inside the dead run's lease, the next run reads nothing.
		$clock = new FixedClock(RESTART_TIME + 60);
		$result['while_leased'] = restart_reader($clock, $source, 'next');
		$result['reads_leased'] = $source->reads;

		// After it expires, the next run takes over.
		$clock->advance(RESTART_BUDGET + 120);
		$result['after_expiry'] = restart_reader($clock, $source, 'next');
		$result['final']        = restart_state();

		break;
}
