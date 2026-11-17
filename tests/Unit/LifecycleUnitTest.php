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

use Somethingwithproof\Pfs\CreatedDeviceRepository;
use Somethingwithproof\Pfs\Pages;
use Somethingwithproof\Pfs\Schema;
use Somethingwithproof\Pfs\Settings;

it('applies documented defaults to settings that were never saved', function () {
	$settings = Settings::fromRow([]);

	expect($settings->sshBinary)->toBe(Settings::DEFAULT_SSH)
		->and($settings->sshKeyFile)->toBe(Settings::DEFAULT_KEY_FILE)
		->and($settings->readerBudget)->toBe(Settings::DEFAULT_BUDGET)
		->and($settings->maxResponseBytes())->toBe(Settings::DEFAULT_MAX_KIB * 1024)
		->and($settings->keepTablesOnUninstall)->toBeTrue()
		->and($settings->deleteDevicesOnUninstall)->toBeFalse();
});

it('deletes the plugin\'s devices on uninstall only after the box was checked and saved', function () {
	expect(Settings::fromRow(['pfs_uninstall_delete_devices' => ''])->deleteDevicesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['pfs_uninstall_delete_devices' => 'on'])->deleteDevicesOnUninstall)->toBeTrue()
		->and(Settings::form()['pfs_uninstall_delete_devices']['default'])->toBe('');
});

it('records the devices the plugin created and forgets deleted ones, one row each', function () {
	$GLOBALS['__pfs_db_exec'] = [];
	$record = new CreatedDeviceRepository(false);

	expect($record->add(7, 100))->toBeTrue()
		->and($record->add(0, 100))->toBeFalse()
		->and($record->forget([7, 0, 7, 99]))->toBeTrue()
		->and($record->forget([]))->toBeTrue();

	expect($GLOBALS['__pfs_db_exec'])->toBe([
		['sql' => 'INSERT IGNORE INTO plugin_pfs_created_devices (host_id, created_at) VALUES (?, ?)', 'params' => [7, 100]],
		['sql' => 'DELETE FROM plugin_pfs_created_devices WHERE host_id IN (?, ?)', 'params' => [7, 99]],
	]);

	$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_created_devices' => [['host_id' => 3], ['host_id' => '7'], ['host_id' => 0]]];
	expect($record->ids())->toBe([3, 7]);
	$GLOBALS['__pfs_db_rows'] = [];
	$GLOBALS['__pfs_db_exec'] = [];
});

it('lists mapped Cacti devices for uninstall checks', function () {
	$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_filesystem' => [['host_id' => 7], ['host_id' => '7'], ['host_id' => 0]]];

	try {
		$repository = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());
		expect($repository->mappedHostIds())->toBe([7]);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
	}
});

it('keeps mappings intact when an unmap query or write fails', function () {
	$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_filesystem' => [['COUNT(*)' => 1]]];
	$repository              = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());

	try {
		$GLOBALS['__pfs_db_rows']['FROM plugin_pfs_filesystem'] = false;
		expect(fn () => $repository->unmapHosts([7]))->toThrow(RuntimeException::class);

		$GLOBALS['__pfs_db_rows']['FROM plugin_pfs_filesystem'] = [['COUNT(*)' => 1]];
		$GLOBALS['__pfs_db_fail'] = ['UPDATE plugin_pfs_filesystem SET host_id = 0'];
		expect(fn () => $repository->unmapHosts([7]))->toThrow(RuntimeException::class);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
		$GLOBALS['__pfs_db_fail'] = [];
	}
});

it('drops tables on uninstall only after the box was cleared and saved', function () {
	expect(Settings::fromRow(['pfs_uninstall_keep_tables' => ''])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['pfs_uninstall_keep_tables' => null])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['pfs_uninstall_keep_tables' => 'on'])->keepTablesOnUninstall)->toBeTrue();
});

it('keeps device ownership records when a database read fails or legacy JSON is damaged', function () {
	$record = new CreatedDeviceRepository(false);
	$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_created_devices' => false, 'FROM settings' => false];
	$GLOBALS['__pfs_db_exec'] = [];

	try {
		expect($record->hasRecords())->toBeTrue()
			->and($record->importLegacy(100))->toBeFalse();
		$GLOBALS['__pfs_db_rows'] = ['FROM settings' => [['value' => '[123,']]];
		expect($record->importLegacy(100))->toBeFalse()
			->and($GLOBALS['__pfs_db_exec'])->toBe([]);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
		$GLOBALS['__pfs_db_exec'] = [];
	}
});

it('accepts an empty legacy device setting during uninstall', function () {
	$GLOBALS['__pfs_db_rows'] = ['FROM settings' => [['value' => '']]];
	try {
		expect((new CreatedDeviceRepository(false))->importLegacy(100))->toBeTrue();
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
		$GLOBALS['__pfs_db_exec'] = [];
	}
});

it('bounds numeric settings', function (string $name, mixed $stored, string $property, int $expected) {
	expect(Settings::fromRow([$name => $stored])->{$property})->toBe($expected);
})->with([
	['pfs_reader_budget', '120', 'readerBudget', 120],
	['pfs_reader_budget', '9', 'readerBudget', Settings::DEFAULT_BUDGET],
	['pfs_reader_budget', 'abc', 'readerBudget', Settings::DEFAULT_BUDGET],
	['pfs_reader_budget', '99999', 'readerBudget', 3600],
	['pfs_concurrency', '0', 'concurrency', Settings::DEFAULT_CONCURRENCY],
	['pfs_concurrency', '500', 'concurrency', 64],
	['pfs_read_timeout', '1', 'readTimeout', Settings::DEFAULT_TIMEOUT],
	['pfs_max_response', '10', 'maxResponseKib', Settings::DEFAULT_MAX_KIB],
]);

it('accepts only plain absolute paths for the SSH files', function (string $stored, string $expected) {
	expect(Settings::fromRow(['pfs_ssh_key_file' => $stored])->sshKeyFile)->toBe($expected);
})->with([
	['/etc/cacti/pfs/key', '/etc/cacti/pfs/key'],
	['relative/key', Settings::DEFAULT_KEY_FILE],
	['/etc/../root/.ssh/id_rsa', Settings::DEFAULT_KEY_FILE],
	['/etc/cacti/pfs/..', Settings::DEFAULT_KEY_FILE],
	['/tmp/key -oProxyCommand=x', Settings::DEFAULT_KEY_FILE],
	["/tmp/key\nx", Settings::DEFAULT_KEY_FILE],
	['', Settings::DEFAULT_KEY_FILE],
]);

it('gives every setting in the form a default that fromRow agrees with', function () {
	$form = Settings::form();
	$row  = [];

	foreach (Settings::NAMES as $name) {
		expect($form)->toHaveKey($name);
		$row[$name] = $form[$name]['default'];
	}

	expect(Settings::fromRow($row))->toEqual(Settings::fromRow([]));
});

it('puts every page in exactly one realm, with health in the admin realm only', function () {
	$files = Pages::files();

	expect($files)->toBe(array_values(array_unique($files)))
		->and(Pages::ADMIN)->toContain('pfs_health.php')
		->and(Pages::VIEW)->not->toContain('pfs_health.php');
});

it('lists every page script the plugin ships in a realm', function () {
	$scripts = array_map('basename', glob(dirname(__DIR__, 2) . '/pfs*.php') ?: []);

	expect($scripts)->not->toBeEmpty()
		->and($scripts)->toEqualCanonicalizing(Pages::files());
});

it('gives every menu page a breadcrumb under the console', function () {
	$nav = Pages::navigation();

	foreach (array_keys(Pages::menu()) as $file) {
		expect($nav[$file . ':']['mapping'])->toBe('index.php:')
			->and($nav[$file . ':']['url'])->toBe($file);
	}
});

it('names every hook function that setup.php defines', function () {
	foreach (Schema::HOOKS as $function) {
		expect(function_exists($function))->toBeTrue($function);
	}
});

it('defines plugin tables with the plugin prefix, a primary key, and no secret columns', function () {
	foreach (Schema::tables() as $table => $definition) {
		expect($table)->toStartWith('plugin_pfs_')
			->and($definition)->toHaveKey('primary')
			->and($definition['type'])->toBe('InnoDB');

		foreach ($definition['columns'] as $column) {
			expect($column['name'])->not->toMatch('/token|password|secret|private_key|key_file/');
		}
	}
});

it('keeps observation names within the 19 characters of an RRD data source', function () {
	$metric = array_values(array_filter(Schema::tables()['plugin_pfs_observation']['columns'], fn (array $c): bool => $c['name'] === 'metric'));

	expect($metric[0]['type'])->toBe('varchar(19)');
});

it('forgets unbooted ownership only after a device is confirmed absent', function () {
	require_once dirname(__DIR__, 2) . '/include/unbooted-uninstall.php';
	try {
		$GLOBALS['__pfs_db_rows'] = ['FROM host' => false];
		expect(pfs_unbooted_removed_devices([7]))->toBe([]);
		$GLOBALS['__pfs_db_rows'] = ['FROM host' => [['id' => 7]]];
		expect(pfs_unbooted_removed_devices([7]))->toBe([]);
		$GLOBALS['__pfs_db_rows'] = ['FROM host' => []];
		expect(pfs_unbooted_removed_devices([7]))->toBe([7]);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
	}
});

it('stops unbooted uninstall when ownership metadata or legacy data cannot be read', function () {
	require_once dirname(__DIR__, 2) . '/include/unbooted-uninstall.php';
	try {
		$GLOBALS['__pfs_db_rows'] = ['information_schema.TABLES' => false];
		expect(fn () => pfs_unbooted_created_devices())->toThrow(RuntimeException::class);
		$GLOBALS['__pfs_db_rows'] = ['FROM settings' => [['value' => '[123,']]];
		expect(fn () => pfs_unbooted_created_devices())->toThrow(RuntimeException::class);
		$GLOBALS['__pfs_db_rows'] = ['FROM settings' => false];
		expect(fn () => pfs_unbooted_created_devices())->toThrow(RuntimeException::class);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
	}
});

it('stops uninstall when core would still drop the ownership table', function () {
	$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_created_devices' => [[1]]];
	$GLOBALS['__pfs_db_fail'] = ['DELETE FROM plugin_db_changes'];
	try {
		expect(fn () => (new Schema(false))->keepCreatedDevices())->toThrow(RuntimeException::class);
		expect(fn () => (new Schema(false))->untrackTables())->toThrow(RuntimeException::class);
		$GLOBALS['__pfs_db_rows']['information_schema.TABLES'] = [[1]];
		$GLOBALS['__pfs_db_rows']['FROM settings'] = [['value' => '']];
		expect(fn () => pfs_uninstall_unbooted())->toThrow(RuntimeException::class);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
		$GLOBALS['__pfs_db_fail'] = [];
	}
});

it('cleans mappings for remote hosts whose deletion is queued by Cacti', function () {
	$GLOBALS['__pfs_db_rows'] = ['FROM host' => [['id' => 7, 'deleted' => 'on']]];
	try {
		expect((new \Somethingwithproof\Pfs\CactiObjectRepository(false))->deviceRemoved(7))->toBeTrue()
			->and(pfs_unbooted_removed_devices([7]))->toBe([7]);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
	}
});

it('does not treat a failed Cacti host lookup as a confirmed deletion', function () {
	$GLOBALS['__pfs_db_rows'] = ['FROM host' => false];

	try {
		expect(fn () => (new \Somethingwithproof\Pfs\CactiObjectRepository(false))->deviceRemoved(7))->toThrow(RuntimeException::class);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
	}
});

it('clears mappings for remote hosts soft-deleted while the plugin was disabled', function () {
	$GLOBALS['__pfs_db_rows'] = [
		'information_schema.COLUMNS'       => [['has_column' => 1]],
		'FROM plugin_pfs_filesystem'        => [['host_id' => 7]],
		'FROM host'                         => [['id' => 7, 'deleted' => 'on']],
		'information_schema.TABLES'         => [],
		'FROM settings'                     => [],
	];
	$GLOBALS['__pfs_db_exec'] = [];

	try {
		pfs_uninstall_unbooted();

		expect($GLOBALS['__pfs_db_exec'])->toContain([
			'sql'    => 'UPDATE plugin_pfs_filesystem SET host_id = 0 WHERE host_id IN (?)',
			'params' => [7],
		]);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
		$GLOBALS['__pfs_db_exec'] = [];
	}
});

it('stops unbooted uninstall when a filesystem mapping cannot be read', function () {
	$GLOBALS['__pfs_db_rows'] = [
		'information_schema.COLUMNS' => [['has_column' => 1]],
		'FROM plugin_pfs_filesystem' => false,
	];
	$GLOBALS['__pfs_db_exec'] = [];

	try {
		expect(fn () => pfs_uninstall_unbooted())->toThrow(RuntimeException::class);
		expect($GLOBALS['__pfs_db_exec'])->toBe([]);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
		$GLOBALS['__pfs_db_exec'] = [];
	}
});

it('keeps ownership records when clearing a removed-device mapping fails', function () {
	$GLOBALS['__pfs_db_rows'] = [
		'information_schema.COLUMNS'       => [['has_column' => 1]],
		'FROM plugin_pfs_filesystem'        => [['host_id' => 7]],
		'FROM host'                         => [['id' => 7, 'deleted' => 'on']],
		'information_schema.TABLES'         => [],
		'FROM settings'                     => [],
	];
	$GLOBALS['__pfs_db_exec'] = [];
	$GLOBALS['__pfs_db_fail'] = ['UPDATE plugin_pfs_filesystem SET host_id = 0'];

	try {
		expect(fn () => pfs_uninstall_unbooted())->toThrow(RuntimeException::class);
		expect(array_column($GLOBALS['__pfs_db_exec'], 'sql'))->not->toContain('DELETE FROM plugin_pfs_created_devices WHERE host_id NOT IN (SELECT id FROM host)');
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
		$GLOBALS['__pfs_db_exec'] = [];
		$GLOBALS['__pfs_db_fail'] = [];
	}
});

it('waits for a reader process that has not registered in Cacti yet', function () {
	require_once dirname(__DIR__) . '/bin/reader-idle.php';
	$GLOBALS['__pfs_db_rows'] = [];
	$process = proc_open([PHP_BINARY, '-r', 'usleep(5000000);', 'poller_pfs.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
	expect(is_resource($process))->toBeTrue();
	try {
		expect(pfs_reader_idle())->toBeFalse();
	} finally {
		proc_terminate($process);
		fclose($pipes[0]);
		proc_close($process);
	}
});
