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
 * Drive the plugin lifecycle through Cacti's own plugin API in a fresh PHP
 * process, the way plugins.php and cli/plugin_manage.php do, and print the
 * resulting state as JSON. Used by tests/Integration. Disposable databases
 * only.
 *
 *   php plugins/pfs/tests/bin/lifecycle.php <action> [argument]
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/plugins.php');
require_once($config['base_path'] . '/lib/poller.php');

if (getenv('PFS_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set PFS_TEST_DATABASE=disposable\n");
	exit(2);
}

$action   = isset($argv[1]) ? $argv[1] : 'state';
$argument = isset($argv[2]) ? $argv[2] : '';
$result   = null;

ob_start();

switch ($action) {
	case 'reset':
		api_plugin_uninstall('pfs');

		// Devices the device actions below made, with their graphs.
		$hosts = array_map('intval', array_column(db_fetch_assoc("SELECT id FROM host WHERE hostname LIKE '%.pfs.invalid' OR hostname = 'lifecycle.example.invalid'"), 'id'));

		if ($hosts !== array()) {
			include_once($config['base_path'] . '/lib/api_device.php');
			include_once($config['base_path'] . '/lib/api_data_source.php');
			include_once($config['base_path'] . '/lib/api_graph.php');
			api_device_remove_multi($hosts, 2);
		}

		foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_pfs\\_%'") as $row) {
			db_execute('DROP TABLE `' . $row['t'] . '`');
		}

		db_execute("DELETE FROM settings WHERE name LIKE 'pfs\\_%'");
		db_execute("DELETE FROM plugin_db_changes WHERE plugin = 'pfs'");

		break;
	case 'install':
		api_plugin_install('pfs');

		break;
	case 'enable':
		api_plugin_enable('pfs');

		break;
	case 'disable':
		api_plugin_disable('pfs');

		break;
	case 'uninstall':
		api_plugin_uninstall('pfs');

		break;
	case 'upgrade':
		include_once($config['base_path'] . '/plugins/pfs/setup.php');
		$result = plugin_pfs_upgrade();

		break;
	case 'migrate_through':
		// Stop after the given migration, as an upgrade killed part way would.
		include_once($config['base_path'] . '/plugins/pfs/setup.php');
		pfs_boot();
		$result = (new \Somethingwithproof\Pfs\Schema(false))->migrate((int) $argument);

		break;
	case 'set_version':
		db_execute_prepared("UPDATE plugin_config SET version = ? WHERE directory = 'pfs'", array($argument));

		break;
	case 'set_schema':
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('pfs_schema_version', ?)", array($argument));

		break;
	case 'set_setting':
		list($name, $value) = explode('=', $argument, 2);
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $value));

		break;
	case 'drop_registrations':
		// What a failed hook registration on an earlier request leaves.
		db_execute("DELETE FROM plugin_hooks WHERE name = 'pfs'");
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'pfs'");

		break;
	case 'drop_table':
		db_execute('DROP TABLE IF EXISTS `' . preg_replace('/[^a-z_]/', '', $argument) . '`');

		break;
	case 'to_schema_1':
		// What a milestone 2 install left: no device column, no summary.
		db_execute('ALTER TABLE plugin_pfs_filesystem DROP INDEX host_id, DROP COLUMN host_id');
		db_execute('DROP TABLE IF EXISTS plugin_pfs_summary');
		db_execute("DELETE FROM plugin_db_changes WHERE plugin = 'pfs' AND `table` = 'plugin_pfs_summary'");
		db_execute("UPDATE settings SET value = '1' WHERE name = 'pfs_schema_version'");

		break;
	case 'seed':
		// A filesystem row, so table drops and keeps are visible.
		db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('seed', 'lustre', 1, 300, 'enabled')");

		break;
	case 'reader_flow':
		include($config['base_path'] . '/plugins/pfs/tests/bin/reader-flow.php');

		break;
	case 'devices':
		// One filesystem with a device the plugin creates, one mapped onto a
		// device that already existed (REQ-LIFE-005).
		include_once($config['base_path'] . '/plugins/pfs/setup.php');
		include_once($config['base_path'] . '/lib/api_device.php');
		pfs_boot();

		$repo = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());
		db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('made', 'lustre', 1, 300, 'enabled')");
		$made    = (int) db_fetch_insert_id();
		$created = pfs_provisioner()->create($repo->find($made));

		$existing = (int) api_device_save(0, 0, 'lifecycle existing', 'lifecycle.example.invalid', '', 0, '', '', 161, 500, '', 0, 1, 23, 400, 1, '', '', '', '', '', '', 10, 1, 1, 0);
		db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('mapped', 'lustre', 1, 300, 'enabled')");
		$mapped = (int) db_fetch_insert_id();
		pfs_provisioner()->map($repo->find($mapped), $existing);

		$result = array('created' => $created, 'existing' => $existing);

		break;
	case 'uninstall_unbooted':
		// What core does on uninstall when pfs_boot() refuses: setup.php is
		// loaded, the PHP 8.0 fallback runs, then core drops whatever is
		// still tracked.
		include_once($config['base_path'] . '/plugins/pfs/setup.php');
		include_once($config['base_path'] . '/plugins/pfs/include/unbooted-uninstall.php');
		pfs_uninstall_unbooted();
		api_plugin_db_changes_remove('pfs');
		api_plugin_remove_hooks('pfs');
		api_plugin_remove_realms('pfs');
		db_execute("DELETE FROM plugin_config WHERE directory = 'pfs'");

		break;
	case 'repair_as_viewer':
		// A viewer opens a plugin page after the realm rows went missing.
		db_execute("DELETE FROM user_auth WHERE username = 'pfsviewer'");
		db_execute("INSERT INTO user_auth (username, password, realm, full_name, enabled) VALUES ('pfsviewer', '', 0, 'viewer', 'on')");
		$viewer = (int) db_fetch_insert_id();
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'pfs'");
		db_execute("DELETE FROM plugin_hooks WHERE name = 'pfs'");
		$_SESSION['sess_user_id'] = $viewer;
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '0')");
		include_once($config['base_path'] . '/plugins/pfs/setup.php');
		plugin_pfs_upgrade();
		$result = array('viewer_realms' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = ?', array($viewer)));
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '1')");

		break;
	case 'reader_second':
	case 'reader_victim':
	case 'reader_kill':
		include($config['base_path'] . '/plugins/pfs/tests/bin/reader-restart.php');

		break;
	case 'state':
		break;
	default:
		fwrite(STDERR, "Unknown action $action\n");
		exit(2);
}

$output = ob_get_clean();

$tables = array();

foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_pfs\\_%' ORDER BY TABLE_NAME") as $row) {
	$tables[$row['t']] = (int) db_fetch_cell('SELECT COUNT(*) FROM `' . $row['t'] . '`');
}

print json_encode(array(
	'result'  => $result,
	'output'  => $output,
	'plugin'  => db_fetch_row("SELECT status, version FROM plugin_config WHERE directory = 'pfs'"),
	'schema'  => db_fetch_cell("SELECT value FROM settings WHERE name = 'pfs_schema_version'"),
	'hooks'   => array_column(db_fetch_assoc("SELECT hook, status FROM plugin_hooks WHERE name = 'pfs' ORDER BY hook"), 'status', 'hook'),
	'realms'  => array_column(db_fetch_assoc("SELECT file, display FROM plugin_realms WHERE plugin = 'pfs' ORDER BY file"), 'display', 'file'),
	'admin'   => (int) db_fetch_cell("SELECT COUNT(*) FROM user_auth_realm AS uar INNER JOIN plugin_realms AS pr ON pr.id + 100 = uar.realm_id WHERE pr.plugin = 'pfs' AND uar.user_id = 1"),
	'tracked' => array_column(db_fetch_assoc("SELECT `table` FROM plugin_db_changes WHERE plugin = 'pfs' AND method = 'create' ORDER BY `table`"), 'table'),
	'tables'  => $tables,
	'devices' => array_map(static function ($row) {
		return array('data_local' => (int) $row['ds'], 'graph_local' => (int) $row['graphs']);
	}, array_column(db_fetch_assoc("SELECT h.id, (SELECT COUNT(*) FROM data_local d WHERE d.host_id = h.id) AS ds, (SELECT COUNT(*) FROM graph_local g WHERE g.host_id = h.id) AS graphs FROM host h WHERE h.hostname LIKE '%.pfs.invalid' OR h.hostname = 'lifecycle.example.invalid'"), null, 'id')),
	'created_devices' => (string) db_fetch_cell("SELECT value FROM settings WHERE name = 'pfs_created_devices'"),
	'filesystem_hosts' => db_fetch_cell("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_pfs_filesystem' AND COLUMN_NAME = 'host_id'") ? array_map('intval', array_column(db_fetch_assoc('SELECT name, host_id FROM plugin_pfs_filesystem ORDER BY name'), 'host_id', 'name')) : array(),
	'host_column' => (int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_pfs_filesystem' AND COLUMN_NAME = 'host_id'") === 1,
), JSON_PRETTY_PRINT) . PHP_EOL;
