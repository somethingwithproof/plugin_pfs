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
 * Target churn on a filesystem device across polls (issues #43, #46, #47):
 * an OST disappears, a new one appears, the old one returns, and Cacti
 * reindexes the device after each change. Run between real poller runs:
 *
 *   PFS_TEST_DATABASE=disposable php plugins/pfs/tests/bin/target-flow.php setup
 *   php poller.php --force
 *   ... remove, poll, add, poll, return, poll, check
 *
 * Checks that no data source or poller item is duplicated, that a missing
 * OST keeps its data source and serves U, that a release which does not
 * print some metadata operations still has a filesystem metadata rate, and
 * that a missing OST exports from its RRD as a gap, not as zero.
 *
 * Must parse on PHP 8.0. State between phases is kept in Cacti's settings
 * table under pfs_churn_*.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/lib/rrd.php');
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

if (getenv('PFS_TEST_DATABASE') !== 'disposable' || !isset($argv[1]) || !in_array($argv[1], array('setup', 'remove', 'add', 'return', 'check'), true)) {
	fwrite(STDERR, "Set PFS_TEST_DATABASE=disposable and pass setup, remove, add, return, or check\n");
	exit(2);
}

if (!pfs_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

$failures = 0;
$mds215   = str_replace('scratch-MDT', 'churn-MDT', (string) file_get_contents($config['base_path'] . '/plugins/pfs/tests/fixtures/lustre/mds-2.15.txt'));

function churn_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function churn_state($name, $value = null) {
	if ($value !== null) {
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array('pfs_churn_' . $name, (string) $value));
	}

	return (string) db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', array('pfs_churn_' . $name));
}

/** Read the OSTs given (index => used percent) and the 2.15 MDS answer now. */
function churn_read(array $used, $at) {
	global $mds215;

	$osts = array();

	foreach ($used as $index => $pct) {
		$osts[$index] = array('used' => $pct, 'read' => 1000000 * ($at % 100000), 'write' => 2000000 * ($at % 100000));
	}

	$source  = new FixtureNodeSource(array());
	$sources = new NodeSources();
	$source->answer((int) churn_state('oss'), pfs_fixture_oss('churn', $osts));
	$source->answer((int) churn_state('mds'), $mds215);
	$sources->register(AccessMethod::Ssh, $source);

	db_execute('UPDATE plugin_pfs_node_health SET last_attempt = last_attempt - 3600');

	return pfs_reader_with(1, $sources, new FixedClock($at))->run(1, 240, 4);
}

/** Rerun both data queries on the device, as a reindex does. */
function churn_reindex($hostId) {
	foreach (db_fetch_assoc_prepared('SELECT snmp_query_id FROM host_snmp_query WHERE host_id = ?', array($hostId)) as $row) {
		run_data_query($hostId, (int) $row['snmp_query_id']);
	}
}

function churn_indexes($hostId) {
	return array_column(db_fetch_assoc_prepared('SELECT DISTINCT snmp_index FROM host_snmp_cache WHERE host_id = ? ORDER BY snmp_index', array($hostId)), 'snmp_index');
}

/**
 * Data query data sources per (template, query, index) and poller items per
 * (data source, field); each should be one. The overview graphs' summary
 * data sources share a template and have no index, so they are left out.
 */
function churn_duplicates($hostId) {
	$ds = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM (SELECT data_template_id, snmp_index, COUNT(*) AS n FROM data_local WHERE host_id = ? AND snmp_query_id > 0 GROUP BY data_template_id, snmp_query_id, snmp_index HAVING n > 1) d', array($hostId));
	$pi = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM (SELECT local_data_id, rrd_name, COUNT(*) AS n FROM poller_item WHERE host_id = ? GROUP BY local_data_id, rrd_name, rrd_path HAVING n > 1) p', array($hostId));

	return array('data_sources' => $ds, 'poller_items' => $pi);
}

function churn_graph($hostId, $index) {
	$queryId = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query WHERE hash = ?', array(TemplateInstaller::hash('data_query', 'Parallel Filesystem OSTs')));
	$gt      = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE hash = ?', array(TemplateInstaller::hash('graph_template', 'Parallel Filesystem OST Capacity')));
	$sqg     = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query_graph WHERE snmp_query_id = ? AND graph_template_id = ?', array($queryId, $gt));
	$empty   = array();
	$graph   = create_complete_graph_from_template($gt, $hostId, array('snmp_query_id' => $queryId, 'snmp_index_on' => 'pfsName', 'snmp_query_graph_id' => $sqg, 'snmp_index' => $index), $empty);

	return isset($graph['local_data_id']) ? (int) array_values($graph['local_data_id'])[0] : 0;
}

function churn_counts($hostId) {
	return array(
		'data_local'  => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM data_local WHERE host_id = ?', array($hostId)),
		'graph_local' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ?', array($hostId)),
		'poller_item' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM poller_item WHERE host_id = ?', array($hostId)),
	);
}

$step   = $argv[1];
$hostId = (int) churn_state('host');
$now    = time();

if ($step === 'setup') {
	db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('churn', 'lustre', 1, 300, 'enabled')");
	$fsId = (int) db_fetch_insert_id();

	foreach (array('oss' => 'oss', 'mds' => 'mds') as $name => $role) {
		db_execute_prepared("INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state) VALUES (?, ?, ?, 1, '10.20.0.3', 22, 'pfsread', 'ssh', 'enabled')", array($fsId, 'churn-' . $name, $role));
		churn_state($name, (int) db_fetch_insert_id());
	}

	churn_read(array(0 => 40, 1 => 50), $now - 300);
	churn_read(array(0 => 40, 1 => 50), $now);

	$repo   = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());
	$hostId = pfs_provisioner()->create($repo->find($fsId));
	churn_state('host', $hostId);
	churn_state('fs', $fsId);

	churn_state('ds0', churn_graph($hostId, 'churn-OST0000'));
	churn_state('ds1', churn_graph($hostId, 'churn-OST0001'));
	push_out_host($hostId, 0);

	churn_expect($hostId > 0 && (int) churn_state('ds1') > 0 && churn_indexes($hostId) === array('churn-MDT0000', 'churn-OST0000', 'churn-OST0001'), 'the churn device indexes two OSTs and one MDT, with a capacity graph per OST');

	$summary = ss_pfs_summary($hostId);
	churn_expect(preg_match('/(^| )md_ops:[0-9]/', $summary) === 1, 'a 2.15 MDT that does not print the newer operations still has a metadata rate: ' . $summary);
	churn_state('counts', json_encode(churn_counts($hostId)));
} elseif ($step === 'remove') {
	churn_read(array(0 => 40), $now);
	churn_reindex($hostId);

	churn_expect(churn_indexes($hostId) === array('churn-MDT0000', 'churn-OST0000', 'churn-OST0001'), 'a missing OST keeps its index after a reindex: ' . implode(', ', churn_indexes($hostId)));
	churn_expect(json_encode(churn_counts($hostId)) === churn_state('counts'), 'the reindex added or dropped no data source, graph, or poller item: ' . json_encode(churn_counts($hostId)));
	churn_expect(ss_pfs_targets($hostId, 'ost', 'get', 'kbytes_total', 'churn-OST0001') === 'U', 'the missing OST serves U');
	churn_state('missing_from', $now);
} elseif ($step === 'add') {
	churn_read(array(0 => 40, 2 => 30), $now);
	churn_reindex($hostId);

	churn_expect(churn_indexes($hostId) === array('churn-MDT0000', 'churn-OST0000', 'churn-OST0001', 'churn-OST0002'), 'a new OST is indexed beside the missing one: ' . implode(', ', churn_indexes($hostId)));
	churn_state('ds2', churn_graph($hostId, 'churn-OST0002'));
	push_out_host($hostId, 0);
	churn_expect(churn_duplicates($hostId) === array('data_sources' => 0, 'poller_items' => 0), 'adding a graph for the new OST duplicated nothing: ' . json_encode(churn_duplicates($hostId)));
	churn_state('counts', json_encode(churn_counts($hostId)));
} elseif ($step === 'return') {
	churn_read(array(0 => 40, 1 => 55, 2 => 30), $now);
	churn_reindex($hostId);
	push_out_host($hostId, 0);

	$ds1 = (int) db_fetch_cell_prepared("SELECT id FROM data_local WHERE host_id = ? AND snmp_index = 'churn-OST0001' ORDER BY id LIMIT 1", array($hostId));
	churn_expect($ds1 === (int) churn_state('ds1') && json_encode(churn_counts($hostId)) === churn_state('counts') && churn_duplicates($hostId) === array('data_sources' => 0, 'poller_items' => 0), 'the returning OST resumes on its original data source with nothing duplicated: ' . json_encode(array(churn_counts($hostId), churn_duplicates($hostId))));
	churn_expect(ss_pfs_targets($hostId, 'ost', 'get', 'kbytes_total', 'churn-OST0001') === '1000000000', 'the returning OST serves values again');
} else {
	$path = get_data_source_path((int) churn_state('ds1'), true);
	$last = (string) shell_exec('rrdtool lastupdate ' . escapeshellarg($path) . ' 2>&1');
	churn_expect(strpos($last, 'kbytes_total') !== false && preg_match('/:\s.*1000000000/', $last) === 1, 'the poller kept writing the returning OST into its original RRD file');

	// The poller writes at wall-clock time, seconds apart, so a gap cannot
	// span an RRD step in this run. Replay the values the plugin served for
	// this OST at 300 second steps into a copy of the RRD file Cacti
	// created: present, missing (the U the script served), present.
	$copy = sys_get_temp_dir() . '/pfs-churn-gap-' . getmypid() . '.rrd';
	$base = (int) (floor($now / 300) * 300) + 3000;
	$info = (string) shell_exec('rrdtool info ' . escapeshellarg($path));
	preg_match_all('/^ds\[([a-z_0-9]+)\]\.type/m', $info, $m);
	$names = $m[1];
	$ok    = copy($path, $copy);
	$plan  = array('1000000000', '1000000000', 'U', 'U', 'U', '1000000000', '1000000000');

	foreach ($plan as $i => $value) {
		$values = array();

		foreach ($names as $name) {
			$values[] = $name === 'kbytes_total' ? $value : 'U';
		}

		shell_exec('rrdtool update ' . escapeshellarg($copy) . ' --template ' . escapeshellarg(implode(':', $names)) . ' ' . escapeshellarg(($base + 300 * ($i + 1)) . ':' . implode(':', $values)) . ' 2>&1');
	}

	$xport = (string) shell_exec('rrdtool xport --start ' . ($base + 300) . ' --end ' . ($base + 2100) . ' --step 300 ' . escapeshellarg('DEF:t=' . $copy . ':kbytes_total:AVERAGE') . ' XPORT:t 2>&1');
	@unlink($copy);

	// rrdtool 1.8 leaves the <t> element out of each row unless asked, so
	// rows are taken in order from the start time.
	preg_match_all('#<row>(?:<t>\d+</t>)?<v>([^<]+)</v></row>#', $xport, $rows, PREG_SET_ORDER);
	$series = array();

	foreach ($rows as $i => $row) {
		$series[$base + 300 * ($i + 1)] = is_numeric($row[1]) && strtolower($row[1]) !== 'nan' ? (float) $row[1] : 'gap';
	}

	print 'EVIDENCE xport ' . ($series === array() ? substr($xport, 0, 400) : json_encode($series)) . PHP_EOL;
	$gaps  = count(array_filter($series, function ($v) { return $v === 'gap'; }));
	$zeros = count(array_filter($series, function ($v) { return $v === 0.0; }));
	churn_expect($ok && $gaps >= 3 && $zeros === 0 && in_array(1000000000.0, $series, true), "a missing OST exports from its RRD as a gap, not zero ($gaps gap steps, $zeros zero steps)");

	$repo = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());
	$repo->delete((int) churn_state('fs'));
	db_execute("DELETE FROM settings WHERE name LIKE 'pfs\\_churn\\_%'");
}

exit($failures > 0 ? 1 : 0);
