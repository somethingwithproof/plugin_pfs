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
 * End-to-end device flow on a disposable Cacti with the plugin installed and
 * enabled. Two phases around a real poller run:
 *
 *   PFS_TEST_DATABASE=disposable php plugins/pfs/tests/bin/device-flow.php setup
 *   php poller.php --force
 *   PFS_TEST_DATABASE=disposable php plugins/pfs/tests/bin/device-flow.php check
 *
 * setup: a second template install changes no row; the reader reads the
 * fixture node output twice (FixtureNodeSource, no SSH); the plugin creates
 * the filesystem's device, data queries, and overview graphs, and one
 * per-target graph is created as an operator would; another filesystem is
 * mapped onto an existing device.
 *
 * check: the poller wrote the reader's values into the RRDs; a counter reset
 * and an absent counter line are served as U, as are hostile indexes and
 * fields; deleting a device unmaps only its filesystem.
 *
 * Must parse on PHP 8.0. State between the phases is kept in Cacti's
 * settings table under pfs_e2e_*.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/api_data_source.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/lib/rrd.php');
require_once($config['base_path'] . '/plugins/pfs/setup.php');
require_once($config['base_path'] . '/plugins/pfs/tests/bin/reader-idle.php');

// Load the script server functions without running their CLI branch.
$called_by_script_server = true;
require_once($config['base_path'] . '/plugins/pfs/scripts/ss_pfs_targets.php');
require_once($config['base_path'] . '/plugins/pfs/scripts/ss_pfs_summary.php');

use Somethingwithproof\Pfs\AccessMethod;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\FixtureNodeSource;
use Somethingwithproof\Pfs\NodeSources;
use Somethingwithproof\Pfs\TemplateInstaller;

if (getenv('PFS_TEST_DATABASE') !== 'disposable' || !isset($argv[1]) || !in_array($argv[1], array('setup', 'check', 'later'), true)) {
	fwrite(STDERR, "Set PFS_TEST_DATABASE=disposable and pass setup, check, or later\n");
	exit(2);
}

if (!pfs_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

$failures = 0;
$fixtures = $config['base_path'] . '/plugins/pfs/tests/fixtures/lustre/';

function flow_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

/**
 * The fixture with every stats counter advanced by $factor times its value,
 * the same start time, so the reader sees continuous counters.
 */
function flow_advance($output, $factor) {
	return preg_replace_callback('/^(\w+)(\s+)(\d+) samples \[(\w+)\] (\d+) (\d+) (\d+)/m', function ($m) use ($factor) {
		$samples = (int) round($m[3] * $factor);
		$sum     = (int) round($m[7] * $factor);

		return $m[1] . $m[2] . $samples . ' samples [' . $m[4] . '] ' . $m[5] . ' ' . $m[6] . ' ' . $sum;
	}, $output);
}

function flow_read(FixedClock $clock, array $answers) {
	$source  = new FixtureNodeSource(array());
	$sources = new NodeSources();

	foreach ($answers as $node => $text) {
		$source->answer($node, $text);
	}

	$sources->register(AccessMethod::Ssh, $source);

	return pfs_reader_with(1, $sources, $clock)->run(1, 240, 4);
}

function flow_counts() {
	$out = array();

	foreach (array('host_template', 'data_template', 'data_template_rrd', 'graph_templates', 'graph_templates_item', 'graph_template_input', 'snmp_query', 'snmp_query_graph', 'snmp_query_graph_rrd', 'data_input', 'data_input_fields', 'cdef', 'graph_templates_gprint') as $table) {
		$out[$table] = (int) db_fetch_cell("SELECT COUNT(*) FROM `$table`");
	}

	return $out;
}

function flow_state($name, $value = null) {
	if ($value !== null) {
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array('pfs_e2e_' . $name, (string) $value));
	}

	return (string) db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', array('pfs_e2e_' . $name));
}

function flow_rrd($localDataId) {
	$path = get_data_source_path($localDataId, true);
	$out  = shell_exec('rrdtool lastupdate ' . escapeshellarg($path) . ' 2>&1');
	$out  = is_string($out) ? $out : '';
	$rows = preg_split('/\R/', trim($out));

	if (count($rows) < 3) {
		return array('path' => $path, 'error' => $out);
	}

	$names  = preg_split('/\s+/', trim($rows[0]));
	$values = preg_split('/\s+/', trim(substr($rows[2], strpos($rows[2], ':') + 1)));

	return array('path' => $path, 'at' => (int) $rows[2], 'values' => array_combine($names, $values));
}

$installer = pfs_template_installer();
$oss       = (string) file_get_contents($fixtures . 'oss-2.15.txt');
$mds       = (string) file_get_contents($fixtures . 'mds-2.17.txt');

if ($argv[1] === 'setup') {
	flow_expect($installer->isCurrent() && $installer->failure() === '', 'the plugin install imported the templates and the query files');

	$before = flow_counts();
	flow_expect($installer->install() && flow_counts() === $before, 'a second template install changes no Cacti row');

	// Earlier scripts leave filesystems behind; this flow starts clean.
	$repo = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());

	foreach ($repo->all() as $old) {
		$repo->delete($old->id);
	}

	db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('scratch', 'lustre', 1, 300, 'enabled')");
	$fsId = (int) db_fetch_insert_id();
	$ids  = array();

	foreach (array('oss01' => 'oss', 'mds01' => 'mds') as $name => $role) {
		db_execute_prepared("INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state) VALUES (?, ?, ?, 1, '10.20.0.1', 22, 'pfsread', 'ssh', 'enabled')", array($fsId, $name, $role));
		$ids[$name] = (int) db_fetch_insert_id();
	}

	// Two reads one interval apart, ending now, so the poller that follows
	// finds current values and continuous counters.
	$now   = time();
	$clock = new FixedClock($now - 300);
	flow_read($clock, array($ids['oss01'] => $oss, $ids['mds01'] => $mds));
	$clock->advance(300);
	$run = flow_read($clock, array($ids['oss01'] => flow_advance($oss, 2), $ids['mds01'] => flow_advance($mds, 2)));
	flow_expect($run->nodesRead === 2, 'the reader read both fixture nodes twice');

	$summary = (new \Somethingwithproof\Pfs\SummaryRepository(false))->find($fsId);
	flow_expect($summary !== null && $summary->value('targets_total') !== null, 'the reader stored a summary with the cache');

	$fs          = $repo->find($fsId);
	$provisioner = pfs_provisioner();
	flow_expect($provisioner->refusal() === null && count($provisioner->plan($fs)) > 0, 'the provisioner is ready and lists what it will add');

	$hostId = $provisioner->create($fs);
	$host   = db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', array($hostId));
	$ht     = (int) db_fetch_cell_prepared('SELECT id FROM host_template WHERE hash = ?', array(TemplateInstaller::hash('host_template', TemplateInstaller::DEVICE_TEMPLATE)));

	flow_expect($hostId > 0 && is_array($host) && (int) $host['host_template_id'] === $ht && (int) $host['availability_method'] === 0 && (int) $host['poller_id'] === 1 && $host['hostname'] === 'scratch.lustre.pfs.invalid', 'the device was created from the Parallel Filesystem template, with no availability check');
	flow_expect($repo->find($fsId)->hostId === $hostId, 'the filesystem is mapped to its device');

	$queries = db_fetch_assoc_prepared('SELECT snmp_query_id FROM host_snmp_query WHERE host_id = ?', array($hostId));
	$indexes = db_fetch_assoc_prepared('SELECT DISTINCT snmp_index FROM host_snmp_cache WHERE host_id = ? ORDER BY snmp_index', array($hostId));
	flow_expect(count($queries) === 2, 'both data queries are attached');
	flow_expect(array_column($indexes, 'snmp_index') === array('scratch-MDT0000', 'scratch-OST0000', 'scratch-OST0001'), 'the data queries index every target: ' . implode(', ', array_column($indexes, 'snmp_index')));

	$overview = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ? AND snmp_query_id = 0', array($hostId));
	$summaryDs = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM data_local WHERE host_id = ?', array($hostId));
	flow_expect($overview === 5 && $summaryDs === 5, "five overview graphs, each with its own summary data source ($overview graphs, $summaryDs data sources)");

	// A per-target graph as the New Graphs page creates it.
	$queryId = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query WHERE hash = ?', array(TemplateInstaller::hash('data_query', 'Parallel Filesystem OSTs')));
	$gt      = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE hash = ?', array(TemplateInstaller::hash('graph_template', 'Parallel Filesystem OST Throughput')));
	$sqg     = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query_graph WHERE snmp_query_id = ? AND graph_template_id = ?', array($queryId, $gt));
	$empty   = array();
	$graph   = create_complete_graph_from_template($gt, $hostId, array('snmp_query_id' => $queryId, 'snmp_index_on' => 'pfsName', 'snmp_query_graph_id' => $sqg, 'snmp_index' => 'scratch-OST0000'), $empty);

	$gtCap = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE hash = ?', array(TemplateInstaller::hash('graph_template', 'Parallel Filesystem OST Capacity')));
	$sqgCap = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query_graph WHERE snmp_query_id = ? AND graph_template_id = ?', array($queryId, $gtCap));
	$graph2 = create_complete_graph_from_template($gtCap, $hostId, array('snmp_query_id' => $queryId, 'snmp_index_on' => 'pfsName', 'snmp_query_graph_id' => $sqgCap, 'snmp_index' => 'scratch-OST0000'), $empty);

	$ostDs  = isset($graph['local_data_id']) ? (int) array_values($graph['local_data_id'])[0] : 0;
	$capDs  = isset($graph2['local_data_id']) ? (int) array_values($graph2['local_data_id'])[0] : 0;
	flow_expect($ostDs > 0 && $capDs > 0 && $capDs !== $ostDs, 'each per-target graph has its own data source');
	push_out_host($hostId, 0);

	// A second filesystem mapped onto a device that already exists.
	$other = (int) api_device_save(0, 0, 'existing device', 'existing.example.invalid', '', 0, '', '', 161, 500, '', 0, 1, 23, 400, 1, '', '', '', '', '', '', 10, 1, 1, 0);
	db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('work', 'lustre', 1, 300, 'enabled')");
	$workId = (int) db_fetch_insert_id();
	$mapped = $provisioner->map($repo->find($workId), $other);
	$otherState = array('mapped' => $mapped, 'host' => $repo->find($workId)->hostId, 'other' => (int) $other, 'queries' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_snmp_query WHERE host_id = ?', array($other)), 'template' => (int) db_fetch_cell_prepared('SELECT host_template_id FROM host WHERE id = ?', array($other)));
	flow_expect($otherState === array('mapped' => true, 'host' => $other, 'other' => (int) $other, 'queries' => 2, 'template' => 0), 'mapping an existing device adds the data queries and keeps its template: ' . json_encode($otherState));
	flow_expect(!$provisioner->map($repo->find($workId), $hostId), 'a device mapped to one filesystem cannot be mapped to another');

	$answer = ss_pfs_summary($hostId);
	flow_expect(strpos($answer, 'targets_total:3 ') !== false && strpos($answer, 'read_bytes:U') === false, 'the summary input answers current values: ' . $answer);

	flow_state('fs', $fsId);
	flow_state('host', $hostId);
	flow_state('other', $other);
	flow_state('ost_ds', $ostDs);
	flow_state('cap_ds', $capDs);
	flow_state('oss', $ids['oss01']);
	flow_state('mds', $ids['mds01']);
	flow_state('graph', $graph['local_graph_id']);
} elseif ($argv[1] === 'check') {
	$fsId   = (int) flow_state('fs');
	$hostId = (int) flow_state('host');
	$ostDs  = (int) flow_state('ost_ds');
	$sumDs  = (int) db_fetch_cell_prepared('SELECT dl.id FROM data_local dl INNER JOIN data_template dt ON dt.id = dl.data_template_id WHERE dl.host_id = ? AND dt.hash = ? ORDER BY dl.id LIMIT 1', array($hostId, TemplateInstaller::hash('data_template', TemplateInstaller::SUMMARY)));

	$ost = flow_rrd($ostDs);
	$cap = flow_rrd((int) flow_state('cap_ds'));
	$sum = flow_rrd($sumDs);

	// Lustre counts bytes in the stats sum column; the second read doubled it.
	flow_expect(isset($ost['values'], $cap['values']) && $ost['values']['read_bytes'] === '1468006400' && $cap['values']['kbytes_total'] === '1000000000', 'the poller wrote the OST values into their RRDs: ' . json_encode(array($ost, $cap)));
	flow_expect(isset($sum['values']) && $sum['values']['targets_total'] === '3' && $sum['values']['fill_max_pct'] !== 'U', 'the poller wrote the summary into its RRD: ' . json_encode($sum));

	$items = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM poller_item WHERE local_data_id = ?', array($ostDs));
	flow_expect(isset($ost['values']) && $items === count($ost['values']), "every value the throughput graph draws is polled ($items poller items)");

	$info = shell_exec('rrdtool info ' . escapeshellarg($ost['path'])) . shell_exec('rrdtool info ' . escapeshellarg($cap['path']));
	flow_expect(strpos($info, 'ds[read_bytes].type = "DERIVE"') !== false && strpos($info, 'ds[read_bytes].min = 0.0000000000e+00') !== false && strpos($info, 'ds[kbytes_free].type = "GAUGE"') !== false, 'counters are DERIVE with minimum 0 and capacity is GAUGE');

	flow_expect(ss_pfs_targets($hostId, 'ost', 'get', 'write_ops', 'scratch-OST0001') === 'U', 'a counter line the node never printed is served as U');
	flow_expect(ss_pfs_targets($hostId, 'ost', 'get', 'read_bytes', '../../etc/passwd') === 'U' && ss_pfs_targets($hostId, 'ost', 'get', 'password', 'scratch-OST0000') === 'U' && ss_pfs_targets($hostId, 'mdt', 'get', 'read_bytes', 'scratch-OST0000') === 'U', 'hostile indexes and fields are served as U');
	flow_expect(ss_pfs_targets($hostId + 999, 'ost', 'index') === '', 'a device without a filesystem lists no targets');

	// The poll before this phase started a reader in the background. While
	// it holds the lease the reset read below would be skipped, so wait,
	// bounded by the longest a run can hold the lease.
	$bound = pfs_settings()->readerBudget + 60;
	flow_expect(pfs_wait_until(function () {
		return pfs_reader_idle(1);
	}, $bound), "the background reader ended within $bound s before the reset read");

	// The counters go backwards: a reset. The reader marks it and the
	// script serves U for the counters until the next continuous read.
	$clock = new FixedClock(time() + 300);
	flow_read($clock, array((int) flow_state('oss') => flow_advance($oss, 0.5), (int) flow_state('mds') => flow_advance($mds, 0.5)));
	// A new answerer: the script server's keeps its cache for 30 seconds.
	$fresh = pfs_target_query();
	flow_expect($fresh->answer($hostId, 'ost', 'get', 'read_bytes', 'scratch-OST0000') === 'U' && $fresh->answer($hostId, 'ost', 'get', 'kbytes_total', 'scratch-OST0000') === '1000000000', 'after a counter reset the counter is U and the gauge is served');
	flow_expect(preg_match('/(^| )read_bytes:U( |$)/', ss_pfs_summary($hostId)) === 1, 'after a reset the summary counter is U: ' . ss_pfs_summary($hostId));

	// A graph added later for the same OST: the next poll must extend the
	// existing RRD file with the values it draws.
	$queryId = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query WHERE hash = ?', array(TemplateInstaller::hash('data_query', 'Parallel Filesystem OSTs')));
	$gt      = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE hash = ?', array(TemplateInstaller::hash('graph_template', 'Parallel Filesystem OST Inodes')));
	$sqg     = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query_graph WHERE snmp_query_id = ? AND graph_template_id = ?', array($queryId, $gt));
	$empty   = array();
	$graph   = create_complete_graph_from_template($gt, $hostId, array('snmp_query_id' => $queryId, 'snmp_index_on' => 'pfsName', 'snmp_query_graph_id' => $sqg, 'snmp_index' => 'scratch-OST0000'), $empty);
	$inodeDs = isset($graph['local_data_id']) ? (int) array_values($graph['local_data_id'])[0] : 0;
	flow_expect($inodeDs > 0 && $inodeDs !== $ostDs, 'a graph added later gets a data source of its own');
	flow_state('inode_ds', $inodeDs);
	push_out_host($hostId, 0);
} else {
	$fsId   = (int) flow_state('fs');
	$hostId = (int) flow_state('host');
	$inodes = flow_rrd((int) flow_state('inode_ds'));
	flow_expect(isset($inodes['values']['files_total']) && $inodes['values']['files_total'] === '65536000', 'the next poll filled the later graph\'s RRD: ' . json_encode($inodes));

	api_device_remove((int) flow_state('other'));
	$repo = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());
	$work = null;

	foreach ($repo->all() as $f) {
		$work = $f->name === 'work' ? $f : $work;
	}

	flow_expect($work !== null && $work->hostId === 0 && $repo->find($fsId)->hostId === $hostId, 'deleting a device unmaps only its own filesystem');

	$repo->delete($fsId);
	flow_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', array($hostId)) === 1 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ?', array($hostId)) === 8, 'deleting a filesystem keeps its device and graphs');

	db_execute("DELETE FROM settings WHERE name LIKE 'pfs\\_e2e\\_%'");
}

exit($failures > 0 ? 1 : 0);
