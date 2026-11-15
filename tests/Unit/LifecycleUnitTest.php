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

use Somethingwithproof\Pfs\Pages;
use Somethingwithproof\Pfs\Schema;
use Somethingwithproof\Pfs\Settings;

it('applies documented defaults to settings that were never saved', function () {
	$settings = Settings::fromRow([]);

	expect($settings->sshBinary)->toBe(Settings::DEFAULT_SSH)
		->and($settings->sshKeyFile)->toBe(Settings::DEFAULT_KEY_FILE)
		->and($settings->readerBudget)->toBe(Settings::DEFAULT_BUDGET)
		->and($settings->maxResponseBytes())->toBe(Settings::DEFAULT_MAX_KIB * 1024)
		->and($settings->keepTablesOnUninstall)->toBeTrue();
});

it('drops tables on uninstall only after the box was cleared and saved', function () {
	expect(Settings::fromRow(['pfs_uninstall_keep_tables' => ''])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['pfs_uninstall_keep_tables' => null])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['pfs_uninstall_keep_tables' => 'on'])->keepTablesOnUninstall)->toBeTrue();
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
