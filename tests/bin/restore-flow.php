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
 * Backup and restore of a filesystem device (issue #54, REQ-DR-001). Driven
 * by tests/bin/restore-replay.sh:
 *
 *   setup     a filesystem with its device and a per-target graph, read
 *   (poll, then dump the database and copy the RRD directory)
 *   snapshot  record the device's objects and RRD files in a state file
 *   (change things after the backup, then restore the dump and the copy)
 *   verify    the restored objects match the snapshot, the reader resumes,
 *   (poll)
 *   resumed   the poll wrote into the restored RRD files, nothing duplicated
 *
 * State that must survive the restore is kept in the file named by
 * PFS_RESTORE_STATE, not in the database. Must parse on PHP 8.0.
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
require_once($config['base_path'] . '/plugins/pfs/tests/bin/lustre-fixture.php');

use Somethingwithproof\Pfs\AccessMethod;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\FixtureNodeSource;
use Somethingwithproof\Pfs\NodeSources;
use Somethingwithproof\Pfs\Settings;
use Somethingwithproof\Pfs\TemplateInstaller;

$stateFile = (string) getenv('PFS_RESTORE_STATE');

if (getenv('PFS_TEST_DATABASE') !== 'disposable' || $stateFile === '' || !isset($argv[1]) || !in_array($argv[1], array('setup', 'snapshot', 'drift', 'verify', 'resumed'), true)) {
	fwrite(STDERR, "Set PFS_TEST_DATABASE=disposable and PFS_RESTORE_STATE, and pass setup, snapshot, drift, verify, or resumed\n");
	exit(2);
}

if (!pfs_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

$failures = 0;

function restore_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function restore_state(array $add = array()) {
	global $stateFile;

	$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : array();
	$state = array_merge(is_array($state) ? $state : array(), $add);

	if ($add !== array()) {
		file_put_contents($stateFile, json_encode($state));
	}

	return $state;
}

function restore_read($fsName, $write) {
	$state   = restore_state();
	$source  = new FixtureNodeSource(array());
	$sources = new NodeSources();
	$source->answer((int) $state['oss'], pfs_fixture_oss($fsName, array(0 => array('used' => 40, 'read' => $write, 'write' => $write), 1 => array('used' => 60, 'read' => $write, 'write' => $write))));
	$source->answer((int) $state['mds'], pfs_fixture_mds($fsName, array(0 => array('open' => 100, 'close' => 90))));
	$sources->register(AccessMethod::Ssh, $source);

	db_execute('UPDATE plugin_pfs_node_health SET last_attempt = last_attempt - 3600');

	return pfs_reader_with(1, $sources, new FixedClock(time()))->run(1, 240, 4, Settings::DEFAULT_TIMEOUT);
}

/** What a backup must bring back for the device: objects by id and the RRD files. */
function restore_inventory($hostId) {
	$ds = db_fetch_assoc_prepared('SELECT id, data_template_id, snmp_index FROM data_local WHERE host_id = ? ORDER BY id', array($hostId));

	return array(
		'filesystem'  => db_fetch_assoc("SELECT id, name, host_id FROM plugin_pfs_filesystem ORDER BY id"),
		'targets'     => array_column(db_fetch_assoc('SELECT name FROM plugin_pfs_target ORDER BY name'), 'name'),
		'data_local'  => $ds,
		'graph_local' => array_column(db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE host_id = ? ORDER BY id', array($hostId)), 'id'),
		'poller_item' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM poller_item WHERE host_id = ?', array($hostId)),
		'rrd_files'   => array_map(function ($row) { return basename(get_data_source_path((int) $row['id'], true)); }, $ds),
	);
}

$step = $argv[1];

switch ($step) {
	case 'setup':
		$repo = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());

		foreach ($repo->all() as $old) {
			$repo->delete($old->id);
		}

		db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('keep', 'lustre', 1, 300, 'enabled')");
		$fsId = (int) db_fetch_insert_id();
		$ids  = array();

		foreach (array('oss' => 'oss', 'mds' => 'mds') as $name => $role) {
			db_execute_prepared("INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state) VALUES (?, ?, ?, 1, '10.20.0.4', 22, 'pfsread', 'ssh', 'enabled')", array($fsId, 'keep-' . $name, $role));
			$ids[$name] = (int) db_fetch_insert_id();
		}

		restore_state(array('oss' => $ids['oss'], 'mds' => $ids['mds'], 'fs' => $fsId));
		restore_read('keep', 1000000);
		restore_read('keep', 2000000);

		$hostId  = pfs_provisioner()->create($repo->find($fsId));
		$queryId = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query WHERE hash = ?', array(TemplateInstaller::hash('data_query', 'Parallel Filesystem OSTs')));
		$gt      = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE hash = ?', array(TemplateInstaller::hash('graph_template', 'Parallel Filesystem OST Throughput')));
		$sqg     = (int) db_fetch_cell_prepared('SELECT id FROM snmp_query_graph WHERE snmp_query_id = ? AND graph_template_id = ?', array($queryId, $gt));
		$empty   = array();
		$graph   = create_complete_graph_from_template($gt, $hostId, array('snmp_query_id' => $queryId, 'snmp_index_on' => 'pfsName', 'snmp_query_graph_id' => $sqg, 'snmp_index' => 'keep-OST0000'), $empty);
		push_out_host($hostId, 0);

		restore_state(array('host' => $hostId, 'ost_ds' => (int) array_values($graph['local_data_id'])[0]));
		restore_expect($hostId > 0 && isset($graph['local_graph_id']), 'a filesystem device with a per-target graph exists before the backup');
		restore_read('keep', 3000000);

		break;
	case 'snapshot':
		$state = restore_state();
		$inv   = restore_inventory((int) $state['host']);
		$last  = (string) shell_exec('rrdtool lastupdate ' . escapeshellarg(get_data_source_path((int) $state['ost_ds'], true)) . ' 2>&1');
		restore_expect(strpos($last, 'read_bytes') !== false, 'the poll before the backup wrote the per-target RRD file');
		restore_state(array('inventory' => $inv));

		break;
	case 'drift':
		// Changes made after the backup, which the restore must undo.
		$state = restore_state();
		db_execute_prepared('DELETE FROM plugin_pfs_target WHERE filesystem_id = ?', array((int) $state['fs']));
		api_device_remove((int) $state['host']);
		restore_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', array((int) $state['host'])) === 0, 'after the backup the device and the inventory were removed');

		break;
	case 'verify':
		$state = restore_state();
		$inv   = restore_inventory((int) $state['host']);
		restore_expect($inv === $state['inventory'], 'the restore brought back the filesystem, inventory, device objects, and RRD file names exactly: ' . json_encode(array_diff_key($inv, array('data_local' => 1))));

		foreach ($inv['data_local'] as $row) {
			if (!is_file(get_data_source_path((int) $row['id'], true))) {
				restore_expect(false, 'restored RRD file for data source ' . $row['id'] . ' exists');
			}
		}

		$schema = new \Somethingwithproof\Pfs\Schema(false);
		restore_expect($schema->isCurrent(), 'the restored plugin schema is current, so the reader runs');

		$run = restore_read('keep', 4000000);
		restore_expect($run->nodesRead === 2, 'the reader resumed on the restored registry');
		restore_state(array('restored_at' => time()));

		break;
	case 'resumed':
		$state = restore_state();
		$inv   = restore_inventory((int) $state['host']);
		$dups  = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM (SELECT COUNT(*) AS n FROM data_local WHERE host_id = ? AND snmp_query_id > 0 GROUP BY data_template_id, snmp_query_id, snmp_index HAVING n > 1) d', array((int) $state['host']));
		restore_expect($inv === $state['inventory'] && $dups === 0, 'the poll after the restore added no data source, graph, or poller item: ' . json_encode(array('duplicates' => $dups, 'same' => $inv === $state['inventory'])));

		$out  = (string) shell_exec('rrdtool lastupdate ' . escapeshellarg(get_data_source_path((int) $state['ost_ds'], true)) . ' 2>&1');
		$rows = preg_split('/\R/', trim($out));
		restore_expect(count($rows) >= 3 && (int) $rows[2] >= (int) $state['restored_at'], 'the poll after the restore wrote into the restored RRD file: ' . (isset($rows[2]) ? $rows[2] : $out));

		$repo = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());
		$repo->delete((int) $state['fs']);
		@unlink($stateFile);

		break;
}

exit($failures > 0 ? 1 : 0);
