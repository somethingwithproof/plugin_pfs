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

use Somethingwithproof\Pfs\BackendRegistry;
use Somethingwithproof\Pfs\CascProvider;
use Somethingwithproof\Pfs\EcosystemIntegration;
use Somethingwithproof\Pfs\FilesystemRepository;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\NetworkRepository;
use Somethingwithproof\Pfs\NodeHealthRepository;
use Somethingwithproof\Pfs\NodeRepository;
use Somethingwithproof\Pfs\ObservationRepository;
use Somethingwithproof\Pfs\ReaderRepository;
use Somethingwithproof\Pfs\ReplicationRepository;
use Somethingwithproof\Pfs\Schema;
use Somethingwithproof\Pfs\SettingsRepository;
use Somethingwithproof\Pfs\TargetRepository;

function pfs_test_casc(FixedClock $clock, ?\Closure $inMaint = null): CascProvider {
	return new CascProvider(
		new SettingsRepository(false),
		new FilesystemRepository(false, $clock),
		new NodeRepository(false, $clock),
		new NetworkRepository(false, $clock),
		new TargetRepository(false),
		new ObservationRepository(false),
		new NodeHealthRepository(false),
		new ReaderRepository(false, $clock),
		BackendRegistry::production(),
		$clock,
		$inMaint,
	);
}

function pfs_test_ecosystem(FixedClock $clock, \Closure $hasView, \Closure $deviceAllowed, ?\Closure $inMaint = null): EcosystemIntegration {
	return new EcosystemIntegration(
		new Schema(false),
		new SettingsRepository(false),
		new FilesystemRepository(false, $clock),
		new NodeRepository(false, $clock),
		new NetworkRepository(false, $clock),
		new TargetRepository(false),
		new ObservationRepository(false),
		new NodeHealthRepository(false),
		new ReaderRepository(false, $clock),
		BackendRegistry::production(),
		$clock,
		$hasView,
		$deviceAllowed,
		$inMaint,
	);
}

it('scopes ReplicationRepository queries to a single remote poller', function () {
	$repo = new ReplicationRepository(false);

	expect($repo->tablesForPoller(0))->toBe([]);

	$GLOBALS['__pfs_db_rows'] = [
		'FROM plugin_pfs_filesystem' => [['id' => 2, 'name' => 'gpfs01', 'poller_id' => 3]],
		'FROM plugin_pfs_node'       => [['id' => 9, 'filesystem_id' => 2, 'poller_id' => 3]],
		'FROM plugin_pfs_network'    => [['id' => 4, 'poller_id' => 3, 'cidr' => '10.30.0.0/24']],
	];

	try {
		$tables = $repo->tablesForPoller(3);

		expect(array_keys($tables))->toBe(['plugin_pfs_filesystem', 'plugin_pfs_node', 'plugin_pfs_network'])
			->and($tables['plugin_pfs_filesystem'][0]['name'])->toBe('gpfs01')
			->and($tables['plugin_pfs_network'][0]['cidr'])->toBe('10.30.0.0/24');
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
	}
});

it('round-trips CasC YAML and XML exports deterministically and rejects XXE', function () {
	$clock = new FixedClock(1800000000);
	$GLOBALS['__pfs_db_rows'] = [
		'FROM settings'              => [['name' => 'pfs_concurrency', 'value' => '12']],
		'FROM plugin_pfs_filesystem' => [
			['id' => 2, 'name' => 'scratch', 'backend' => 'lustre', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 10],
			['id' => 1, 'name' => 'beegfs1', 'backend' => 'beegfs', 'poller_id' => 2, 'expected_interval' => 120, 'admin_state' => 'disabled', 'host_id' => 0],
		],
		'FROM plugin_pfs_network' => [
			['id' => 2, 'poller_id' => 2, 'cidr' => '10.20.0.0/24'],
			['id' => 1, 'poller_id' => 1, 'cidr' => '10.10.0.0/24'],
		],
	];

	try {
		$casc = pfs_test_casc($clock);
		$snap = $casc->snapshot();

		expect($snap['settings']['pfs_concurrency'])->toBe(12)
			->and($snap['settings'])->not->toHaveKey('pfs_ssh_key_file')
			->and($snap['filesystems'][0]['backend'])->toBe('beegfs')
			->and($snap['filesystems'][1]['backend'])->toBe('lustre');

		$yaml = $casc->export('yaml');
		$xml  = $casc->export('xml');

		expect(CascProvider::parse($yaml, 'yaml'))->toBe($snap)
			->and(CascProvider::parse($xml, 'xml'))->toBe($snap);

		$xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><casc plugin="pfs" schema_version="1"/>';
		expect(fn () => CascProvider::parse($xxe, 'xml'))->toThrow(InvalidArgumentException::class);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
	}
});

it('validates CasC inputs, computes deterministic plan hashes, and fails closed on wrong apply hash', function () {
	$clock = new FixedClock(1800000000);
	$GLOBALS['__pfs_db_rows'] = [
		'FROM settings'              => [],
		'FROM plugin_pfs_filesystem' => [],
		'FROM plugin_pfs_network'    => [],
	];
	$GLOBALS['__pfs_db_exec'] = [];

	try {
		$casc = pfs_test_casc($clock);

		$invalidErrors = $casc->validate([
			'plugin'         => 'other',
			'schema_version' => 99,
			'settings'       => ['pfs_ssh_key_file' => '/etc/secret', 'pfs_concurrency' => 999],
			'filesystems'    => [
				['name' => 'bad', 'backend' => 'nope', 'poller_id' => 0, 'expected_interval' => 5, 'admin_state' => 'bogus'],
				['name' => 'gpfs01', 'backend' => 'gpfs', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled'],
				['name' => 'gpfs01', 'backend' => 'gpfs', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled'],
			],
			'networks' => [
				['poller_id' => 0, 'cidr' => 'not-a-cidr'],
			],
		]);

		expect(count($invalidErrors))->toBeGreaterThanOrEqual(8);

		$desired = [
			'schema_version' => 1,
			'plugin'         => 'pfs',
			'settings'       => ['pfs_concurrency' => 16, 'pfs_uninstall_keep_tables' => false],
			'filesystems'    => [
				['name' => 'gpfs01', 'backend' => 'gpfs', 'poller_id' => 2, 'expected_interval' => 300, 'admin_state' => 'enabled'],
			],
			'networks' => [
				['poller_id' => 2, 'cidr' => '10.40.0.0/24'],
			],
		];

		$plan1 = $casc->plan($desired);
		$plan2 = $casc->plan($desired);

		expect($plan1['valid'])->toBeTrue()
			->and($plan1['plan_hash'])->toMatch('/^[0-9a-f]{64}$/')
			->and($plan1['plan_hash'])->toBe($plan2['plan_hash'])
			->and($plan1['changes']['settings'])->toHaveKey('pfs_concurrency')
			->and($plan1['changes']['filesystems'][0]['action'])->toBe('create')
			->and($plan1['changes']['networks'][0]['action'])->toBe('add');

		$rejected = $casc->apply($desired, str_repeat('0', 64));
		expect($rejected['applied'])->toBeFalse()
			->and($GLOBALS['__pfs_db_exec'])->toBe([]);

		$applied = $casc->apply($desired, $plan1['plan_hash']);
		expect($applied['applied'])->toBeTrue()
			->and($applied['settings_updated'])->toBe(2)
			->and($applied['networks_added'])->toBe(1);
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
		$GLOBALS['__pfs_db_exec'] = [];
	}
});

it('formats health in text and JSON while respecting Maint windows', function () {
	$clock = new FixedClock(1800000000);
	$GLOBALS['__pfs_db_rows'] = [
		'FROM plugin_pfs_filesystem' => [
			['id' => 1, 'name' => 'scratch', 'backend' => 'lustre', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 11],
		],
		'FROM plugin_pfs_node_health' => [
			['node_id' => 1, 'last_attempt' => 1799999900, 'last_success' => 0, 'duration' => 0.1, 'bytes' => 0, 'outcome' => 'failed', 'error' => 'timeout', 'version' => null, 'unexpected_sections' => 0, 'rejected_names' => 0],
		],
		'FROM plugin_pfs_node' => [
			['id' => 1, 'filesystem_id' => 1, 'name' => 'oss01', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.1.0.1', 'port' => 22, 'ssh_user' => 'pfsread', 'access_method' => 'ssh', 'admin_state' => 'enabled'],
		],
		'FROM plugin_pfs_target'      => [],
		'FROM plugin_pfs_observation' => [],
		'FROM plugin_pfs_reader'      => [],
	];

	try {
		$inMaint = pfs_test_casc($clock, static fn (int $hostId): bool => $hostId === 11);
		$noMaint = pfs_test_casc($clock, static fn (int $hostId): bool => false);

		expect($inMaint->health()['status'])->toBe('ok')
			->and($inMaint->health()['summary']['filesystems_in_maintenance'])->toBe(1)
			->and($noMaint->health()['status'])->toBe('degraded')
			->and($inMaint->formatHealth('text'))->toContain('maint=maint')
			->and(json_decode($inMaint->formatHealth('json'), true, 512, JSON_THROW_ON_ERROR)['plugin'])->toBe('pfs');
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
	}
});

it('registers read-only abilities, Doctor checks, CasC descriptor, and remote_agent actions', function () {
	$clock = new FixedClock(1800000000);
	$GLOBALS['__pfs_db_rows'] = [
		'FROM settings'              => [['value' => (string) Schema::VERSION, 'name' => 'pfs_schema_version']],
		'FROM plugin_pfs_filesystem' => [
			['id' => 1, 'name' => 'gpfs01', 'backend' => 'gpfs', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 100],
			['id' => 2, 'name' => 'secret', 'backend' => 'beegfs', 'poller_id' => 2, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 200],
		],
		'FROM plugin_pfs_node_health' => [],
		'FROM plugin_pfs_node'        => [],
		'FROM plugin_pfs_target'      => [
			['id' => 10, 'filesystem_id' => 1, 'name' => 'gpfs01-OST0000', 'type' => 'ost', 'target_index' => 0, 'review' => 'accepted', 'presence' => 'present', 'health' => 'in_service', 'stats_start' => null, 'node_id' => 1, 'first_seen' => 1799999000, 'last_seen' => 1799999990],
			['id' => 11, 'filesystem_id' => 1, 'name' => 'gpfs01-OST0001', 'type' => 'ost', 'target_index' => 1, 'review' => 'accepted', 'presence' => 'present', 'health' => 'in_service', 'stats_start' => null, 'node_id' => 1, 'first_seen' => 1799999000, 'last_seen' => 1799999990],
		],
		'FROM plugin_pfs_observation' => [
			['target_id' => 10, 'metric' => 'kbytes_total', 'value' => 1000.0, 'observed_at' => 1799999990, 'updated_at' => 1799999990, 'node_id' => 1, 'interval_sec' => 300, 'continuity' => 'continuous'],
			['target_id' => 10, 'metric' => 'kbytes_free', 'value' => 800.0, 'observed_at' => 1799999990, 'updated_at' => 1799999990, 'node_id' => 1, 'interval_sec' => 300, 'continuity' => 'continuous'],
			['target_id' => 10, 'metric' => 'kbytes_avail', 'value' => 800.0, 'observed_at' => 1799999990, 'updated_at' => 1799999990, 'node_id' => 1, 'interval_sec' => 300, 'continuity' => 'continuous'],
			['target_id' => 11, 'metric' => 'kbytes_total', 'value' => 1000.0, 'observed_at' => 1799999990, 'updated_at' => 1799999990, 'node_id' => 1, 'interval_sec' => 300, 'continuity' => 'continuous'],
			['target_id' => 11, 'metric' => 'kbytes_free', 'value' => 500.0, 'observed_at' => 1799999990, 'updated_at' => 1799999990, 'node_id' => 1, 'interval_sec' => 300, 'continuity' => 'continuous'],
			['target_id' => 11, 'metric' => 'kbytes_avail', 'value' => 500.0, 'observed_at' => 1799999990, 'updated_at' => 1799999990, 'node_id' => 1, 'interval_sec' => 300, 'continuity' => 'continuous'],
		],
		'FROM plugin_pfs_reader' => [
			['poller_id' => 1, 'holder' => null, 'lease_until' => 0, 'started_at' => 1799999900, 'finished_at' => 1799999950, 'last_completed' => 1799999950, 'nodes_total' => 1, 'nodes_read' => 1, 'nodes_failed' => 0, 'nodes_skipped' => 0],
		],
	];

	try {
		$eco = pfs_test_ecosystem(
			$clock,
			static fn (int $userId): bool => $userId === 7,
			static fn (int $userId, int $hostId): bool => $userId === 7 && $hostId === 100,
			static fn (int $hostId): bool => false,
		);

		$recorded  = [];
		$registrar = new class($recorded) {
			/** @param array<string, array<string, mixed>> $recorded */
			public function __construct(public array &$recorded) {
			}

			/** @param array<string, mixed> $definition */
			public function register(string $id, array $definition): void {
				$this->recorded[$id] = $definition;
			}
		};

		$eco->registerAbilities($registrar);

		expect(array_keys($recorded))->toBe(['pfs-list-filesystems', 'pfs-get-imbalance', 'pfs-get-health']);

		foreach ($recorded as $def) {
			expect($def['annotations']['readOnly'])->toBeTrue()
				->and($def['annotations']['destructive'])->toBeFalse()
				->and(($def['permission'])(7))->toBeTrue()
				->and(($def['permission'])(99))->toBeFalse();
		}

		$list = ($recorded['pfs-list-filesystems']['execute'])(7, new stdClass());
		expect(count($list['filesystems']))->toBe(1)
			->and($list['filesystems'][0]['name'])->toBe('gpfs01');

		$imb = ($recorded['pfs-get-imbalance']['execute'])(7, (object) ['filesystemId' => 1]);
		expect($imb['filesystems'][0]['fillMaxPct'])->toBe(50.0)
			->and($imb['filesystems'][0]['fillSpreadPct'])->toBe(30.0)
			->and($imb['filesystems'][0]['severity'])->toBe('critical');

		$health = ($recorded['pfs-get-health']['execute'])(7);
		expect($health['filesystemsTotal'])->toBe(1)
			->and($health['status'])->toBe('degraded');

		$checks = [];

		foreach ($eco->doctorChecks() as $check) {
			$checks[$check['id']] = ($check['run'])();
		}

		expect($checks['pfs_schema']['status'])->toBe('ok')
			->and($checks['pfs_reader_freshness']['status'])->toBe('warning') // poller 2 has no run
			->and($checks['pfs_capacity_imbalance']['status'])->toBe('critical');

		$desc = $eco->cascDescriptor();
		expect($desc['id'])->toBe('pfs')
			->and($desc['formats'])->toBe(['yaml', 'xml']);

		$inv = $eco->handleRemoteAgent('pfs_inventory', 2);
		expect($inv['filesystems'])->toHaveCount(1)
			->and($inv['filesystems'][0]['name'])->toBe('secret')
			->and($eco->handleRemoteAgent('pfs_health')['plugin'])->toBe('pfs')
			->and($eco->handleRemoteAgent('unknown_action'))->toBeNull();
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
	}
});
