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

// The version INFO declares, which Cacti records as the installed version.
define('PFS_VERSION', (string) (parse_ini_file(dirname(__DIR__, 2) . '/INFO', true)['info']['version'] ?? ''));

const PFS_TABLES = [
	'plugin_pfs_created_devices',
	'plugin_pfs_filesystem',
	'plugin_pfs_network',
	'plugin_pfs_node',
	'plugin_pfs_node_health',
	'plugin_pfs_observation',
	'plugin_pfs_reader',
	'plugin_pfs_summary',
	'plugin_pfs_target',
];

const PFS_HOOKS = ['api_device_new', 'auth_alternate_realms', 'config_arrays', 'config_settings', 'device_remove', 'draw_navigation_text', 'graph_buttons', 'poller_bottom'];

const PFS_T = 1800000000;

beforeEach(function () {
	lifecycle('reset');
});

afterAll(function () {
	lifecycle('reset');
});

it('installs fresh with tracked tables, hooks, and three realms, leaving the plugin disabled', function () {
	$state = lifecycle('install');

	expect($state['plugin']['status'])->toEqual('4')
		->and($state['plugin']['version'])->toEqual(PFS_VERSION)
		->and($state['schema'])->toEqual('3')
		->and(array_keys($state['tables']))->toBe(PFS_TABLES)
		->and($state['tracked'])->toBe(PFS_TABLES)
		->and(array_keys($state['hooks']))->toBe(PFS_HOOKS)
		->and($state['realms'])->toEqual([
			'pfs.php' => 'Parallel Filesystem View',
			'pfs_admin.php,pfs_health.php' => 'Parallel Filesystem Admin',
			'pfs_operator.php' => 'Parallel Filesystem Operator',
		])
		->and($state['admin'])->toBe(2);
});

it('enables and disables through core, keeping data on disable', function () {
	lifecycle('install');
	lifecycle('seed');

	$enabled = lifecycle('enable');
	expect($enabled['plugin']['status'])->toEqual('1')
		->and(array_values(array_unique(array_map('strval', $enabled['hooks']))))->toBe(['1']);

	$disabled = lifecycle('disable');
	expect($disabled['plugin']['status'])->toEqual('4')
		->and($disabled['tables']['plugin_pfs_filesystem'])->toBe(1);
});

it('upgrades from an older recorded version and is idempotent on repeated runs', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('set_version', '0.0');

	$first = lifecycle('upgrade');
	expect($first['result'])->toBeTrue()
		->and($first['plugin']['version'])->toEqual(PFS_VERSION);

	foreach ([lifecycle('upgrade'), lifecycle('upgrade')] as $run) {
		expect($run['result'])->toBeTrue()
			->and($run['tables'])->toBe($first['tables'])
			->and($run['hooks'])->toBe($first['hooks'])
			->and($run['realms'])->toBe($first['realms'])
			->and($run['tracked'])->toBe(PFS_TABLES);
	}

	expect($first['tables']['plugin_pfs_filesystem'])->toBe(1);
});

it('re-registers hooks and realms that went missing', function () {
	lifecycle('install');

	expect(lifecycle('drop_registrations')['hooks'])->toBe([]);

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['hooks']))->toBe(PFS_HOOKS)
		->and($state['realms'])->toHaveCount(3);
});

it('finishes an upgrade that was interrupted after creating only some tables', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('drop_table', 'plugin_pfs_observation');
	lifecycle('drop_table', 'plugin_pfs_reader');
	lifecycle('set_schema', '0');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['tables']))->toBe(PFS_TABLES)
		->and($state['tables']['plugin_pfs_filesystem'])->toBe(1)
		->and($state['schema'])->toEqual('3')
		->and($state['plugin']['version'])->toEqual(PFS_VERSION);
});

it('upgrades a milestone 2 schema, keeping its filesystems and adding the device column and summary table', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('to_schema_1');

	$before = lifecycle('state');
	expect($before['schema'])->toEqual('1')
		->and($before['tables'])->not->toHaveKey('plugin_pfs_summary')
		->and($before['host_column'])->toBeFalse();

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and($state['schema'])->toEqual('3')
		->and($state['host_column'])->toBeTrue()
		->and(array_keys($state['tables']))->toBe(PFS_TABLES)
		->and($state['tables']['plugin_pfs_filesystem'])->toBe(1);
});

it('does not advance past a migration that did not run', function () {
	lifecycle('install');
	lifecycle('set_schema', '0');

	$stopped = lifecycle('migrate_through', '0');
	expect($stopped['result'])->toBeTrue()
		->and($stopped['schema'])->toEqual('0');

	expect(lifecycle('upgrade')['schema'])->toEqual('3');
});

it('refuses a schema newer than the code and leaves the recorded version alone', function () {
	lifecycle('install');
	lifecycle('set_schema', '99');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeFalse()
		->and($state['plugin']['version'])->toEqual('0.0')
		->and($state['schema'])->toEqual('99');
});

it('keeps tables on uninstall by default, and drops them only when the box was cleared', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall');
	expect(array_keys($kept['tables']))->toBe(PFS_TABLES)
		->and($kept['tables']['plugin_pfs_filesystem'])->toBe(1)
		->and($kept['tracked'])->toBe([])
		->and($kept['hooks'])->toBe([])
		->and($kept['realms'])->toBe([]);

	$reinstalled = lifecycle('install');
	expect($reinstalled['plugin']['status'])->toEqual('4')
		->and($reinstalled['tables']['plugin_pfs_filesystem'])->toBe(1)
		->and($reinstalled['tracked'])->toBe(PFS_TABLES);

	lifecycle('set_setting', 'pfs_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall');
	expect($dropped['plugin'])->toBeEmpty()
		->and($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('honours keep tables on uninstall when the runtime is refused', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall_unbooted');
	expect($kept['tables']['plugin_pfs_filesystem'])->toBe(1)
		->and($kept['tracked'])->toBe([]);

	lifecycle('reset');
	lifecycle('install');
	lifecycle('set_setting', 'pfs_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall_unbooted');
	expect($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('keeps every device on uninstall unless asked to delete the ones it created', function () {
	lifecycle('install');

	$made    = lifecycle('devices');
	$created = (string) $made['result']['created'];
	$mapped  = (string) $made['result']['existing'];

	expect((int) $created)->toBeGreaterThan(0)
		->and($made['created_devices'])->toBe('[' . $created . ']')
		->and($made['devices'][$created]['graph_local'])->toBe(5)
		->and($made['devices'][$mapped]['graph_local'])->toBe(5);

	$kept = lifecycle('uninstall');

	expect($kept['devices'])->toBe($made['devices'])
		->and($kept['created_devices'])->toBe('[' . $created . ']')
		->and($kept['filesystem_hosts'])->toBe(['made' => (int) $created, 'mapped' => (int) $mapped]);
});

it('deletes only the devices it created, with their graphs and data sources, when the box was checked', function () {
	lifecycle('install');

	$made    = lifecycle('devices');
	$created = (string) $made['result']['created'];
	$mapped  = (string) $made['result']['existing'];

	lifecycle('set_setting', 'pfs_uninstall_delete_devices=on');

	$after = lifecycle('uninstall');

	expect(array_keys($after['devices']))->toBe([(int) $mapped])
		->and($after['devices'][$mapped])->toBe($made['devices'][$mapped])
		->and($after['created_devices'])->toBe('[]')
		->and($after['filesystem_hosts'])->toBe(['made' => 0, 'mapped' => (int) $mapped])
		->and($after['tables']['plugin_pfs_filesystem'])->toBe(2);
});

it('deletes only the devices it created on uninstall when the runtime is refused', function () {
	lifecycle('install');

	$made   = lifecycle('devices');
	$mapped = (string) $made['result']['existing'];

	lifecycle('set_setting', 'pfs_uninstall_delete_devices=on');

	$after = lifecycle('uninstall_unbooted');

	expect(array_keys($after['devices']))->toBe([(int) $mapped])
		->and($after['created_devices'])->toBe('[]')
		->and($after['filesystem_hosts'])->toBe(['made' => 0, 'mapped' => (int) $mapped]);
});

it('keeps the record of devices it created through an uninstall that drops the other tables', function () {
	lifecycle('install');

	$made    = lifecycle('devices');
	$created = (string) $made['result']['created'];

	lifecycle('set_setting', 'pfs_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall');

	// The devices stay in Cacti, so the record of which one the plugin made
	// stays too, for a later uninstall asked to delete it.
	expect(array_keys($dropped['tables']))->toBe(['plugin_pfs_created_devices'])
		->and($dropped['created_devices'])->toBe('[' . $created . ']')
		->and($dropped['devices'])->toBe($made['devices']);

	$reinstalled = lifecycle('install');
	expect($reinstalled['created_devices'])->toBe('[' . $created . ']')
		->and($reinstalled['tracked'])->toBe(PFS_TABLES);
});

it('moves a milestone 4 record of created devices from its setting into the table', function () {
	lifecycle('install');
	lifecycle('to_schema_2', '[5, 1234567, "x", -1]');

	$before = lifecycle('state');
	expect($before['tables'])->not->toHaveKey('plugin_pfs_created_devices')
		->and($before['legacy_created_devices'])->toBe('[5, 1234567, "x", -1]');

	$state = lifecycle('upgrade');
	expect($state['schema'])->toEqual('3')
		->and($state['created_devices'])->toBe('[5,1234567]')
		->and($state['legacy_created_devices'])->toBe('')
		->and($state['tracked'])->toBe(PFS_TABLES);
});

it('grants realms on install but never on a repair a viewer triggers', function () {
	expect(lifecycle('install')['admin'])->toBe(2);

	$repair = lifecycle('repair_as_viewer');
	expect($repair['realms'])->toHaveCount(3)
		->and($repair['result'])->toBe(['viewer_realms' => 0]);
});

it('reads only due, enabled nodes of its own poller and keeps unknown apart from zero', function () {
	lifecycle('install');

	$r = lifecycle('reader_flow')['result'];

	expect($r['run1'])->toMatchArray(['total' => 3, 'read' => 2, 'failed' => 1, 'skipped' => 0, 'stopped' => false])
		->and($r['run1_reads'])->toBe(['oss01', 'oss02', 'mds01'])
		->and(array_keys($r['targets1']))->toBe(['scratch-MDT0000', 'scratch-OST0000', 'scratch-OST0001'])
		->and($r['targets1']['scratch-OST0000'])->toBe(['presence' => 'present', 'condition' => 'in_service', 'source' => 'oss01', 'last_seen' => PFS_T, 'review' => 'proposed'])
		->and($r['targets1']['scratch-OST0001']['condition'])->toBe('degraded')
		->and($r['targets1']['scratch-MDT0000']['condition'])->toBe('unknown')
		->and($r['values1'])->toBe(43)
		->and($r['unknown1']['value'])->toBeNull()
		->and($r['zero1']['value'])->toEqual(0)
		->and($r['zero1']['value'])->not->toBeNull()
		->and($r['bytes1'])->toEqual(['value' => 40960.0, 'observed_at' => PFS_T, 'continuity' => 'continuous', 'epoch' => 0])
		->and($r['health_oss02'])->toMatchArray(['outcome' => 'failed', 'error' => 'timeout', 'last_success' => '0'])
		->and($r['zeros_from_failure'])->toBe(0);
});

it('is not due again inside the interval', function () {
	lifecycle('install');

	expect(lifecycle('reader_flow')['result']['run2'])->toMatchArray(['total' => 0, 'reads' => []]);
});

it('follows failover to the freshest successful reader as a counter reset, and keeps a failed node\'s values', function () {
	lifecycle('install');

	$r = lifecycle('reader_flow')['result'];

	expect($r['run3'])->toMatchArray(['total' => 3, 'read' => 2, 'failed' => 1])
		->and($r['targets3']['scratch-OST0001']['source'])->toBe('oss02')
		->and($r['targets3']['scratch-OST0001']['presence'])->toBe('present')
		->and($r['bytes3'])->toEqual(['value' => 40960.0, 'observed_at' => PFS_T + 300, 'continuity' => 'reset', 'epoch' => 1])
		->and($r['targets3']['scratch-OST0000'])->toMatchArray(['presence' => 'present', 'source' => 'oss01', 'last_seen' => PFS_T])
		->and($r['ost0_3']['observed_at'])->toBe(PFS_T);
});

it('uses neither value when two nodes report one target, and marks it missing once all answer without it', function () {
	lifecycle('install');

	$r = lifecycle('reader_flow')['result'];

	expect($r['targets4']['scratch-OST0001'])->toMatchArray(['presence' => 'conflict', 'condition' => 'unknown'])
		->and($r['free4'])->toMatchArray(['value' => null, 'observed_at' => PFS_T + 600])
		->and($r['targets5']['scratch-OST0001'])->toMatchArray(['presence' => 'missing', 'last_seen' => PFS_T + 300])
		->and($r['free5'])->toBe($r['free4'])
		->and($r['targets5']['scratch-OST0000']['last_seen'])->toBe(PFS_T + 900);
});

it('reads nothing while another run holds the lease or when the budget is spent', function () {
	lifecycle('install');

	$r = lifecycle('reader_flow')['result'];

	expect($r['run6'])->toMatchArray(['total' => 0, 'reads' => []])
		->and($r['health6'])->toBeTrue()
		->and($r['run7'])->toMatchArray(['total' => 3, 'read' => 0, 'skipped' => 3, 'stopped' => true, 'reads' => []])
		->and($r['health_skip7'])->toMatchArray(['outcome' => 'skipped', 'last_attempt' => (string) (PFS_T + 900)]);
});

it('records an answer without the read script framing as a failure and keeps the old values', function () {
	lifecycle('install');

	$r = lifecycle('reader_flow')['result'];

	expect($r['run8'])->toMatchArray(['total' => 3, 'read' => 1, 'failed' => 2])
		->and($r['health8'])->toMatchArray(['outcome' => 'failed', 'error' => 'unframed'])
		->and($r['health8_oss02'])->toMatchArray(['outcome' => 'failed', 'error' => 'host_key'])
		->and($r['ost0_8']['observed_at'])->toBe(PFS_T + 900)
		->and($r['reader_rows'])->toEqual([['poller_id' => '1', 'last_completed' => (string) (PFS_T + 1500), 'nodes_total' => '3', 'nodes_read' => '1', 'nodes_failed' => '2', 'nodes_skipped' => '0', 'stopped_early' => '0']]);
});

it('exits at once when a reader already runs on the poller', function () {
	lifecycle('install');
	lifecycle('enable');

	$r = lifecycle('reader_second')['result'];

	expect($r['rc'])->toBe(0)
		->and($r['seconds'])->toBeLessThan(5.0)
		->and($r['leases'])->toBe(0)
		->and($r['rc_when_free'])->toBe(0)
		->and($r['leases_when_free'])->toBe(1);
});

it('leaves no partial result when a run is killed mid-read, and the next run continues after the lease expires', function () {
	lifecycle('install');

	$r = lifecycle('reader_kill')['result'];

	expect($r['victim_rc'])->not->toBe(0)
		->and($r['after_kill']['lease']['holder'])->toBe('victim')
		->and($r['after_kill'])->toMatchArray(['health' => 0, 'targets' => 0, 'observations' => 0])
		->and($r['while_leased'])->toBe(['total' => 0, 'read' => 0, 'skipped' => 0])
		->and($r['reads_leased'])->toBe([])
		->and($r['after_expiry'])->toBe(['total' => 1, 'read' => 1, 'skipped' => 0])
		->and($r['final'])->toMatchArray(['health' => 1, 'targets' => 2, 'observations' => 18]);
});

it('preserves old grants and resolves the three roles through Cacti including groups', function () {
	lifecycle('install');
	lifecycle('enable');
	$roles = lifecycle('operator_roles')['result'];
	expect($roles['ids_preserved'])->toBeTrue()
		->and($roles['viewer_grants'])->toBe([$roles['expected_view']])
		->and($roles['hook_enabled'])->toBe(1)
		->and($roles['viewer'])->toBe([true, false, false])
		->and($roles['operator'])->toBe([true, true, false])
		->and($roles['admin'])->toBe([true, true, true])
		->and($roles['revoked'])->toBe([false, false, false])
		->and($roles['group_operator'])->toBe([true, true, false])
		->and($roles['disabled_group'])->toBe([false, false, false])
		->and($roles['first_request']['viewer'])->toBe([[true, true, true], [true, false, true], [false, false, true]])
		->and($roles['first_request']['operator'])->toBe([[true, true, true], [true, true, true], [false, false, true]])
		->and($roles['first_request']['admin'])->toBe([[true, true, true], [true, true, true], [false, true, true]])
		->and($roles['first_request']['revoked'])->toBe([false, false, false])
		->and($roles['disabled_user'])->toBe([false, false, false]);
});
