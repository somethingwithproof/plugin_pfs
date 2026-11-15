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

use Somethingwithproof\Pfs\AdminState;
use Somethingwithproof\Pfs\BackendRegistry;
use Somethingwithproof\Pfs\Filesystem;
use Somethingwithproof\Pfs\FilesystemInput;
use Somethingwithproof\Pfs\NetworkPolicy;
use Somethingwithproof\Pfs\NodeInput;
use Somethingwithproof\Pfs\NodeRole;
use Somethingwithproof\Pfs\Resolver;

final class PfsInputResolver implements Resolver {
	public function resolve(string $host): array {
		return match ($host) {
			'oss01.example' => ['10.20.0.11'],
			'split.example' => ['10.20.0.12', '203.0.113.5'],
			default         => filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [],
		};
	}
}

function pfs_node_input(array $override = []): array {
	return $override + ['filesystem_id' => '1', 'name' => 'oss01', 'role' => 'oss', 'poller_id' => '1', 'endpoint' => 'oss01.example', 'port' => '22', 'ssh_user' => 'pfsread', 'enabled' => 'on'];
}

function pfs_parse_node(array $input): NodeInput {
	return NodeInput::parse($input, 0, [1 => new Filesystem(1, 'scratch', 'lustre', 1, 300, AdminState::Enabled)], [1 => 'Main'], new PfsInputResolver(), new NetworkPolicy([1 => ['10.20.0.0/16']]));
}

it('accepts a valid filesystem', function () {
	$parsed = FilesystemInput::parse(['name' => 'scratch', 'backend' => 'lustre', 'poller_id' => '1', 'expected_interval' => '300', 'enabled' => 'on'], 0, BackendRegistry::production(), [1 => 'Main']);

	expect($parsed->errors)->toBe([])
		->and($parsed->filesystem?->name)->toBe('scratch')
		->and($parsed->filesystem?->enabled())->toBeTrue();
});

it('rejects invalid filesystem fields', function (array $input, string $field) {
	$parsed = FilesystemInput::parse($input + ['name' => 'scratch', 'backend' => 'lustre', 'poller_id' => '1', 'expected_interval' => '300'], 0, BackendRegistry::production(), [1 => 'Main']);

	expect($parsed->filesystem)->toBeNull()
		->and($parsed->errors)->toHaveKey($field);
})->with([
	[['name' => 'too-long-name'], 'name'],
	[['name' => '<script>'], 'name'],
	[['backend' => 'gpfs'], 'backend'],
	[['poller_id' => '9'], 'poller_id'],
	[['expected_interval' => '10'], 'expected_interval'],
	[['expected_interval' => '1e3'], 'expected_interval'],
]);

it('accepts a node whose endpoint resolves inside the poller networks', function () {
	$parsed = pfs_parse_node(pfs_node_input());

	expect($parsed->errors)->toBe([])
		->and($parsed->node?->role)->toBe(NodeRole::Oss)
		->and($parsed->node?->endpoint)->toBe('oss01.example');
});

it('rejects endpoints that are not plain names or addresses, or that resolve outside the allowed networks', function (string $endpoint) {
	expect(pfs_parse_node(pfs_node_input(['endpoint' => $endpoint]))->errors)->toHaveKey('endpoint');
})->with([
	'option'       => ['-oProxyCommand=sh'],
	'user'         => ['root@oss01.example'],
	'port'         => ['oss01.example:22'],
	'space'        => ['oss01 example'],
	'unresolved'   => ['nowhere.example'],
	'outside'      => ['203.0.113.5'],
	'partly out'   => ['split.example'],
	'empty'        => [''],
]);

it('rejects invalid node fields', function (array $override, string $field) {
	expect(pfs_parse_node(pfs_node_input($override))->errors)->toHaveKey($field);
})->with([
	[['name' => '-x'], 'name'],
	[['role' => 'admin'], 'role'],
	[['port' => '0'], 'port'],
	[['port' => '70000'], 'port'],
	[['ssh_user' => 'root;id'], 'ssh_user'],
	[['ssh_user' => '-oFoo'], 'ssh_user'],
	[['filesystem_id' => '2'], 'filesystem_id'],
	[['poller_id' => '2'], 'poller_id'],
]);
