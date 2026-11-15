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
 * Scale harness (issue #52): one filesystem with N OSTs, 16 per OSS as the
 * plan assumes, and one MDT, read from generated fixtures. Driven by
 * tests/bin/scale-replay.sh:
 *
 *   php plugins/pfs/tests/bin/scale-flow.php build <N>
 *   (time one forced Cacti poller run)
 *   php plugins/pfs/tests/bin/scale-flow.php after <N>
 *
 * build prints the reader's time and memory, the data query reindex time,
 * the script server functions' time per call, and the time to create one
 * per-target graph for every OST. after prints the Cacti row and RRD file
 * counts. Results are for the machine the run is on; nothing here is a
 * capacity claim. Must parse on PHP 8.0.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/plugins/pfs/setup.php');
require_once($config['base_path'] . '/plugins/pfs/tests/bin/lustre-fixture.php');

$called_by_script_server = true;
require_once($config['base_path'] . '/plugins/pfs/scripts/ss_pfs_targets.php');
require_once($config['base_path'] . '/plugins/pfs/scripts/ss_pfs_summary.php');

use Somethingwithproof\Pfs\AccessMethod;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\FixtureNodeSource;
use Somethingwithproof\Pfs\NodeSources;
use Somethingwithproof\Pfs\TemplateInstaller;

$step = isset($argv[1]) ? $argv[1] : '';
$n    = isset($argv[2]) ? (int) $argv[2] : 0;

if (getenv('PFS_TEST_DATABASE') !== 'disposable' || !in_array($step, array('build', 'after'), true) || $n < 16 || $n > 20000) {
	fwrite(STDERR, "Set PFS_TEST_DATABASE=disposable and pass build or after, and a target count of 16 to 20000\n");
	exit(2);
}

if (!pfs_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

// A Lustre filesystem name is at most eight characters.
$name = 'sc' . $n;

function scale_metric($label, $value) {
	print "METRIC $label $value" . PHP_EOL;
}

/** Average milliseconds per call of $fn over $calls calls. */
function scale_time($calls, $fn) {
	$start = hrtime(true);

	for ($i = 0; $i < $calls; $i++) {
		$fn($i);
	}

	return round((hrtime(true) - $start) / 1e6 / $calls, 3);
}

function scale_read($name, array $nodes, $mds, $n, $at) {
	$source  = new FixtureNodeSource(array());
	$sources = new NodeSources();

	foreach ($nodes as $i => $nodeId) {
		$osts = array();

		for ($t = $i * 16; $t < min($n, ($i + 1) * 16); $t++) {
			$osts[$t] = array('used' => 30 + ($t % 40), 'read' => 1000000 * ($at % 100000), 'write' => 1000000 * ($at % 100000));
		}

		$source->answer($nodeId, pfs_fixture_oss($name, $osts));
	}

	$source->answer($mds, pfs_fixture_mds($name, array(0 => array('open' => 100, 'close' => 90))));
	$sources->register(AccessMethod::Ssh, $source);
	db_execute('UPDATE plugin_pfs_node_health SET last_attempt = last_attempt - 3600');

	$start = hrtime(true);
	$run   = pfs_reader_with(1, $sources, new FixedClock($at))->run(1, 600, 8);

	return array($run, round((hrtime(true) - $start) / 1e9, 2));
}

$fs = null;

foreach ((new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock()))->all() as $f) {
	$fs = $f->name === $name ? $f : $fs;
}

if ($step === 'build') {
	db_execute_prepared("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES (?, 'lustre', 1, 300, 'enabled')", array($name));
	$fsId  = (int) db_fetch_insert_id();
	$nodes = array();

	for ($i = 0; $i < (int) ceil($n / 16); $i++) {
		db_execute_prepared("INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state) VALUES (?, ?, 'oss', 1, '10.20.0.9', 22, 'pfsread', 'ssh', 'enabled')", array($fsId, sprintf('%s-oss%03d', $name, $i)));
		$nodes[$i] = (int) db_fetch_insert_id();
	}

	db_execute_prepared("INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state) VALUES (?, ?, 'mds', 1, '10.20.0.9', 22, 'pfsread', 'ssh', 'enabled')", array($fsId, $name . '-mds'));
	$mds = (int) db_fetch_insert_id();

	scale_metric("$n nodes", count($nodes) + 1);
	$now = time();
	[$run, $first] = scale_read($name, $nodes, $mds, $n, $now - 300);
	$peakFirst     = memory_get_peak_usage(true);
	[$run, $second] = scale_read($name, $nodes, $mds, $n, $now);
	scale_metric("$n reader_first_run_s", $first);
	scale_metric("$n reader_run_s", $second);
	scale_metric("$n reader_nodes_read", $run->nodesRead);
	scale_metric("$n reader_peak_memory_mib", round(max($peakFirst, memory_get_peak_usage(true)) / 1048576, 1));

	$fs    = (new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock()))->find($fsId);
	$start = hrtime(true);
	$host  = pfs_provisioner()->create($fs);
	scale_metric("$n device_create_and_index_s", round((hrtime(true) - $start) / 1e9, 2));
	$indexes = (int) db_fetch_cell_prepared('SELECT COUNT(DISTINCT snmp_index) FROM host_snmp_cache WHERE host_id = ?', array($host));
	scale_metric("$n indexes", $indexes);

	if ($run->nodesRead !== count($nodes) + 1 || $indexes !== $n + 1) {
		fwrite(STDERR, "FAIL expected $n OSTs and one MDT indexed from " . (count($nodes) + 1) . " nodes\n");
		exit(1);
	}

	// The script server keeps one process, so the functions are timed the
	// same way: repeated calls in one process. The first call of each
	// fills the query's 30 second cache.
	$t = pfs_target_query();
	scale_metric("$n targets_index_first_ms", scale_time(1, function () use ($t, $host) { $t->answer($host, 'ost', 'index'); }));
	scale_metric("$n targets_get_ms_per_call", scale_time(2000, function ($i) use ($t, $host, $name, $n) { $t->answer($host, 'ost', 'get', 'kbytes_total', sprintf('%s-OST%04x', $name, $i % $n)); }));
	scale_metric("$n targets_query_ms", scale_time(5, function () use ($t, $host) { $t->answer($host, 'ost', 'query', 'kbytes_total'); }));
	scale_metric("$n summary_ms_per_call", scale_time(200, function () use ($host) { ss_pfs_summary($host); }));
	scale_metric("$n summary_fresh_process_ms", scale_time(1, function () use ($host) { pfs_summary_script()->answer($host); }));

	$queryId = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query WHERE hash = ?', array(TemplateInstaller::hash('data_query', 'Parallel Filesystem OSTs')));
	$gt      = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE hash = ?', array(TemplateInstaller::hash('graph_template', 'Parallel Filesystem OST Capacity')));
	$sqg     = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query_graph WHERE snmp_query_id = ? AND graph_template_id = ?', array($queryId, $gt));
	$start   = hrtime(true);

	for ($i = 0; $i < $n; $i++) {
		$empty = array();
		create_complete_graph_from_template($gt, $host, array('snmp_query_id' => $queryId, 'snmp_index_on' => 'pfsName', 'snmp_query_graph_id' => $sqg, 'snmp_index' => sprintf('%s-OST%04x', $name, $i)), $empty);
	}

	push_out_host($host, 0);
	scale_metric("$n capacity_graphs_create_s", round((hrtime(true) - $start) / 1e9, 1));
} else {
	$host = $fs === null ? 0 : $fs->hostId;

	foreach (array('data_local' => 'SELECT COUNT(*) FROM data_local WHERE host_id = ?', 'data_template_rrd' => 'SELECT COUNT(*) FROM data_template_rrd r INNER JOIN data_local d ON d.id = r.local_data_id WHERE d.host_id = ?', 'graph_local' => 'SELECT COUNT(*) FROM graph_local WHERE host_id = ?', 'poller_item' => 'SELECT COUNT(*) FROM poller_item WHERE host_id = ?', 'host_snmp_cache' => 'SELECT COUNT(*) FROM host_snmp_cache WHERE host_id = ?') as $table => $sql) {
		scale_metric("$n rows_$table", (int) db_fetch_cell_prepared($sql, array($host)));
	}

	$files = 0;

	foreach (db_fetch_assoc_prepared('SELECT id FROM data_local WHERE host_id = ?', array($host)) as $row) {
		$files += is_file(get_data_source_path((int) $row['id'], true)) ? 1 : 0;
	}

	scale_metric("$n rrd_files", $files);
	scale_metric("$n plugin_observation_rows", (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_pfs_observation o INNER JOIN plugin_pfs_target t ON t.id = o.target_id WHERE t.filesystem_id = ?', array($fs === null ? 0 : $fs->id)));
	print 'NOTE the per-target capacity graph is one of three OST graphs; an operator who graphs all three multiplies the data source rows and RRD files by three' . PHP_EOL;
}

exit(0);
