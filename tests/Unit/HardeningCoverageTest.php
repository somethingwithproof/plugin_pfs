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

use Somethingwithproof\Pfs\AccessMethod;
use Somethingwithproof\Pfs\AdminPage;
use Somethingwithproof\Pfs\AdminState;
use Somethingwithproof\Pfs\BackendRegistry;
use Somethingwithproof\Pfs\CacheRepository;
use Somethingwithproof\Pfs\CactiObjectRepository;
use Somethingwithproof\Pfs\Collection;
use Somethingwithproof\Pfs\Condition;
use Somethingwithproof\Pfs\Continuity;
use Somethingwithproof\Pfs\CreatedDeviceRepository;
use Somethingwithproof\Pfs\DeviceAccess;
use Somethingwithproof\Pfs\DeviceProvisioner;
use Somethingwithproof\Pfs\Filesystem;
use Somethingwithproof\Pfs\FilesystemRepository;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\FixtureNodeSource;
use Somethingwithproof\Pfs\Format;
use Somethingwithproof\Pfs\GraphLinks;
use Somethingwithproof\Pfs\NetworkRepository;
use Somethingwithproof\Pfs\Node;
use Somethingwithproof\Pfs\NodeHealth;
use Somethingwithproof\Pfs\NodeHealthRepository;
use Somethingwithproof\Pfs\NodeRead;
use Somethingwithproof\Pfs\NodeRepository;
use Somethingwithproof\Pfs\NodeRole;
use Somethingwithproof\Pfs\NodeSources;
use Somethingwithproof\Pfs\Observation;
use Somethingwithproof\Pfs\ObservationRepository;
use Somethingwithproof\Pfs\PollerRepository;
use Somethingwithproof\Pfs\Presence;
use Somethingwithproof\Pfs\ReadError;
use Somethingwithproof\Pfs\Reader;
use Somethingwithproof\Pfs\ReaderRepository;
use Somethingwithproof\Pfs\ReaderRun;
use Somethingwithproof\Pfs\Review;
use Somethingwithproof\Pfs\Schema;
use Somethingwithproof\Pfs\SettingsRepository;
use Somethingwithproof\Pfs\ShardAssigner;
use Somethingwithproof\Pfs\Summary;
use Somethingwithproof\Pfs\SummaryRepository;
use Somethingwithproof\Pfs\SystemClock;
use Somethingwithproof\Pfs\SystemResolver;
use Somethingwithproof\Pfs\Target;
use Somethingwithproof\Pfs\TargetId;
use Somethingwithproof\Pfs\TargetQuery;
use Somethingwithproof\Pfs\TargetReport;
use Somethingwithproof\Pfs\TargetRepository;
use Somethingwithproof\Pfs\TargetType;
use Somethingwithproof\Pfs\TargetUpdate;
use Somethingwithproof\Pfs\TemplateInstaller;
use Somethingwithproof\Pfs\UnknownBackend;

function pfs_reset_unit_globals(): void {
	$GLOBALS['__pfs_db_rows'] = [];
	$GLOBALS['__pfs_db_exec'] = [];
	$GLOBALS['__pfs_db_fail'] = [];
	$GLOBALS['__pfs_in_tx']   = [];
	$GLOBALS['__pfs_options'] = [];
	$GLOBALS['__pfs_log']     = [];
}

it('formats ages, byte sizes, and escaped HTML table rows', function () {
	$now = 1800000000;

	expect(Format::age(0, $now))->toBe('Never')
		->and(Format::age($now - 45, $now))->toBe('45 seconds ago')
		->and(Format::age($now - 600, $now))->toBe('10 minutes ago')
		->and(Format::age($now - 10800, $now))->toBe('3 hours ago')
		->and(Format::age($now - 259200, $now))->toBe('3 days ago')
		->and(Format::bytes(512))->toBe('512 B')
		->and(Format::bytes(2048))->toBe('2.0 KiB')
		->and(Format::bytes(3 * 1048576))->toBe('3.0 MiB')
		->and(Format::header(['Name', '<script>']))->toBe('<tr class="tableHeader"><th>Name</th><th>&lt;script&gt;</th></tr>')
		->and(Format::row(['a&b', '"c"']))->toBe('<tr class="tableRow"><td>a&amp;b</td><td>&quot;c&quot;</td></tr>');
});

it('builds graph links and resolves filesystem by graph id', function () {
	$clock = new FixedClock(1800000000);
	$fs    = new Filesystem(7, 'scratch', 'lustre', 1, 300, AdminState::Enabled, 42);
	$html  = GraphLinks::html('/cacti/', $fs);

	expect($html)->toContain('/cacti/plugins/pfs/pfs.php#pfs_graphs_7')
		->and($html)->toContain('Parallel Filesystem view of scratch');

	try {
		$GLOBALS['__pfs_db_rows'] = [
			'FROM graph_local'           => [['host_id' => 42]],
			'FROM plugin_pfs_filesystem' => [['id' => 7, 'name' => 'scratch', 'backend' => 'lustre', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 42]],
		];

		$links = new GraphLinks(new FilesystemRepository(false, $clock), new CactiObjectRepository(false));
		expect($links->filesystemFor(0))->toBeNull()
			->and($links->filesystemFor(100)?->name)->toBe('scratch');
	} finally {
		pfs_reset_unit_globals();
	}
});

it('reads poller names with fallback and Cacti object lookups', function () {
	try {
		$pollers = new PollerRepository(false);
		expect($pollers->names())->toBe([1 => 'Main Poller']);

		$GLOBALS['__pfs_db_rows'] = [
			'FROM poller'                => [['id' => 1, 'name' => 'Main'], ['id' => 2, 'name' => 'Remote HPC']],
			'FROM `host_template`'       => [['id' => 11]],
			'FROM `graph_templates`'     => [['id' => 22]],
			'FROM `snmp_query`'          => [['id' => 33]],
			'FROM data_source_profiles'  => [['id' => 4]],
			'FROM host WHERE id'         => [['id' => 50, 'description' => 'Lustre scratch', 'poller_id' => 2, 'disabled' => '']],
			'FROM host WHERE deleted'    => [['id' => 50, 'description' => 'Lustre scratch'], ['id' => 51, 'description' => 'Other']],
			'FROM graph_local WHERE host_id = ? AND' => [['COUNT(*)' => 2]],
			'FROM host_snmp_query'       => [['COUNT(*)' => 1]],
			'SELECT id FROM graph_local' => [['id' => 101], ['id' => 102]],
		];

		$objects = new CactiObjectRepository(false);
		expect($pollers->names())->toBe([1 => 'Main', 2 => 'Remote HPC'])
			->and($objects->idByHash('invalid_table', str_repeat('a', 32)))->toBe(0)
			->and($objects->idByHash('host_template', 'not-a-hash'))->toBe(0)
			->and($objects->deviceTemplateId())->toBe(11)
			->and($objects->graphTemplateId('Parallel Filesystem Fill'))->toBe(22)
			->and($objects->queryId('Parallel Filesystem OSTs'))->toBe(33)
			->and($objects->defaultProfileId())->toBe(4)
			->and($objects->device(0))->toBeNull()
			->and($objects->device(50))->toBe(['id' => 50, 'description' => 'Lustre scratch', 'poller_id' => 2, 'disabled' => false])
			->and($objects->mappableDevices([50]))->toBe([51 => 'Other'])
			->and($objects->graphCount(50, 22))->toBe(2)
			->and($objects->hasQuery(50, 33))->toBeTrue()
			->and($objects->graphsOf(50))->toBe([101, 102]);
	} finally {
		pfs_reset_unit_globals();
	}
});

it('exercises repository CRUD, strict error handling, and summary loading', function () {
	$clock = new FixedClock(1800000000);

	try {
		$fsRepo = new FilesystemRepository(false, $clock);
		$fsRow  = ['id' => 3, 'name' => 'scratch', 'backend' => 'lustre', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 10];
		$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_filesystem' => [$fsRow]];

		expect($fsRepo->find(3)?->name)->toBe('scratch')
			->and($fsRepo->findByHost(0))->toBeNull()
			->and($fsRepo->findByHost(10)?->id)->toBe(3)
			->and($fsRepo->nameTaken('lustre', 'scratch', 0))->toBeTrue()
			->and($fsRepo->mapHost(3, 0))->toBeFalse()
			->and($fsRepo->mapHost(3, 10))->toBeTrue()
			->and($fsRepo->save(new Filesystem(0, 'newfs', 'gpfs', 1, 300, AdminState::Enabled)))->toBe(1)
			->and($fsRepo->save(new Filesystem(3, 'scratch', 'lustre', 1, 300, AdminState::Enabled)))->toBe(3)
			->and($fsRepo->unmapHosts([]))->toBe(0)
			->and($fsRepo->unmapHosts([10]))->toBe(3)
			->and($fsRepo->delete(3))->toBeTrue();

		// Strict read failure throws on FilesystemRepository, NodeRepository, and NodeHealthRepository.
		$GLOBALS['__pfs_db_rows'] = [
			'FROM plugin_pfs_filesystem'  => false,
			'FROM plugin_pfs_node_health' => false,
			'FROM plugin_pfs_node'        => false,
		];

		expect(fn () => $fsRepo->all(true))->toThrow(RuntimeException::class)
			->and(fn () => $fsRepo->mappedHostIds())->toThrow(RuntimeException::class)
			->and(fn () => (new NodeRepository(false, $clock))->all(true))->toThrow(RuntimeException::class)
			->and(fn () => (new NodeHealthRepository(false))->all(true))->toThrow(RuntimeException::class);

		// NodeRepository CRUD and malformed row skipping.
		$nodeRow = ['id' => 5, 'filesystem_id' => 3, 'name' => 'oss01', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.1.0.1', 'port' => 22, 'ssh_user' => 'pfsread', 'access_method' => 'ssh', 'admin_state' => 'enabled'];
		$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_node' => [$nodeRow, ['id' => 6, 'name' => '']]];
		$nodeRepo = new NodeRepository(false, $clock);

		expect($nodeRepo->all())->toHaveCount(1)
			->and($nodeRepo->forPoller(1))->toHaveCount(1)
			->and($nodeRepo->find(5)?->name)->toBe('oss01')
			->and($nodeRepo->nameTaken('oss01', 0))->toBeTrue()
			->and($nodeRepo->save(new Node(0, 3, 'oss02', NodeRole::Oss, 1, '10.1.0.2', 22, 'pfsread', AccessMethod::Ssh, AdminState::Enabled)))->toBe(1)
			->and($nodeRepo->save(new Node(5, 3, 'oss01', NodeRole::Oss, 1, '10.1.0.1', 22, 'pfsread', AccessMethod::Ssh, AdminState::Enabled)))->toBe(5)
			->and($nodeRepo->delete(5))->toBeTrue();

		// TargetRepository keyset pagination, all, find, and review.
		$targetRow = ['id' => 10, 'filesystem_id' => 3, 'type' => 'ost', 'target_index' => 0, 'name' => 'scratch-OST0000', 'review' => 'proposed', 'presence' => 'present', 'condition_state' => 'in_service', 'recovery' => '', 'source_node_id' => 5, 'first_seen' => 1799999000, 'last_seen' => 1800000000];
		$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_target' => [$targetRow]];
		$targetRepo = new TargetRepository(false);

		expect($targetRepo->forFilesystem(3))->toHaveKey('scratch-OST0000')
			->and($targetRepo->forFilesystemPage(3, 0, 50))->toHaveCount(1)
			->and($targetRepo->all())->toHaveCount(1)
			->and($targetRepo->find(10)?->name())->toBe('scratch-OST0000')
			->and($targetRepo->review(10, Review::Accepted))->toBeTrue();

		// NetworkRepository add/delete/policy and SettingsRepository load/save.
		$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_network' => [['id' => 1, 'poller_id' => 1, 'cidr' => '10.0.0.0/8']]];
		$netRepo = new NetworkRepository(false, $clock);
		expect($netRepo->policy()->allows(1, '10.1.2.3'))->toBeTrue()
			->and($netRepo->add(1, 'not-a-cidr'))->toBeFalse()
			->and($netRepo->add(1, '10.20.0.0/16'))->toBeTrue()
			->and($netRepo->delete(1))->toBeTrue();

		$settingsRepo = new SettingsRepository(false);
		expect($settingsRepo->save('pfs_concurrency', '16'))->toBeTrue()
			->and($settingsRepo->save('not_a_pfs_setting', '1'))->toBeFalse();

		// SummaryRepository loading counters, rates, gauges, and ignoring unknown metrics.
		$hash = str_repeat('a', 40);
		$GLOBALS['__pfs_db_rows'] = [
			'FROM plugin_pfs_summary' => [
				['metric' => 'kbytes_total', 'value' => '1000', 'members' => '', 'observed_at' => 1799999950, 'computed_at' => 1799999960],
				['metric' => 'read_bytes', 'value' => '5000', 'members' => $hash, 'observed_at' => 1799999950, 'computed_at' => 1799999960],
				['metric' => 'read_bytes_rate', 'value' => '100', 'members' => '', 'observed_at' => 1799999940, 'computed_at' => 1799999960],
				['metric' => 'unknown_metric', 'value' => '99', 'members' => '', 'observed_at' => 1799999950, 'computed_at' => 1799999960],
			],
		];
		$summaryRepo = new SummaryRepository(false);
		$summary     = $summaryRepo->find(3);
		expect($summary)->not->toBeNull()
			->and($summary?->value('kbytes_total'))->toBe(1000.0)
			->and($summary?->members['read_bytes'])->toBe($hash)
			->and($summary?->counterAt['read_bytes'])->toBe(1799999940);

		$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_summary' => []];
		expect($summaryRepo->find(3))->toBeNull();
	} finally {
		pfs_reset_unit_globals();
	}
});

it('answers script server data queries for index, num_indexes, query, get, freshness, and caching', function () {
	$clock = new FixedClock(1800000000);
	$query = new TargetQuery(
		new FilesystemRepository(false, $clock),
		new TargetRepository(false),
		new ObservationRepository(false),
		BackendRegistry::production(),
		$clock,
	);

	try {
		expect($query->answer(0, 'ost', 'get', 'kbytes_total', 'scratch-OST0000'))->toBe('U')
			->and($query->answer(10, 'badtype', 'index'))->toBe('')
			->and($query->answer(99, 'ost', 'num_indexes'))->toBe('0')
			->and($query->answer(99, 'ost', 'get', 'kbytes_total', 'scratch-OST0000'))->toBe('U')
			->and($query->answer(99, 'ost', 'index'))->toBe('');

		$GLOBALS['__pfs_db_rows'] = [
			'FROM plugin_pfs_filesystem' => [['id' => 1, 'name' => 'scratch', 'backend' => 'lustre', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 10]],
			'FROM plugin_pfs_target'     => [
				['id' => 100, 'filesystem_id' => 1, 'type' => 'ost', 'target_index' => 0, 'name' => 'scratch-OST0000', 'review' => 'accepted', 'presence' => 'present', 'condition_state' => 'in_service', 'recovery' => '', 'source_node_id' => 1, 'first_seen' => 1799999000, 'last_seen' => 1799999990],
				['id' => 101, 'filesystem_id' => 1, 'type' => 'ost', 'target_index' => 1, 'name' => 'scratch-OST0001', 'review' => 'accepted', 'presence' => 'missing', 'condition_state' => 'unknown', 'recovery' => '', 'source_node_id' => 1, 'first_seen' => 1799999000, 'last_seen' => 1799999000],
				['id' => 102, 'filesystem_id' => 1, 'type' => 'ost', 'target_index' => 2, 'name' => 'scratch-OST0002', 'review' => 'ignored', 'presence' => 'present', 'condition_state' => 'in_service', 'recovery' => '', 'source_node_id' => 1, 'first_seen' => 1799999000, 'last_seen' => 1799999990],
			],
			'FROM plugin_pfs_observation' => [
				['target_id' => 100, 'metric' => 'kbytes_total', 'value' => 2048.0, 'observed_at' => 1799999950, 'ingested_at' => 1799999950, 'source_node_id' => 1, 'expected_interval' => 300, 'continuity' => 'continuous', 'start_marker' => '', 'epoch' => 0],
				['target_id' => 100, 'metric' => 'read_bytes', 'value' => 4096.0, 'observed_at' => 1799999950, 'ingested_at' => 1799999950, 'source_node_id' => 1, 'expected_interval' => 300, 'continuity' => 'continuous', 'start_marker' => '1', 'epoch' => 0],
			],
		];

		expect($query->answer(10, 'ost', 'index'))->toBe("scratch-OST0000\nscratch-OST0001\n")
			->and($query->answer(10, 'ost', 'num_indexes'))->toBe('2')
			->and($query->answer(10, 'ost', 'query', 'name'))->toBe("scratch-OST0000!scratch-OST0000\nscratch-OST0001!scratch-OST0001\n")
			->and($query->answer(10, 'ost', 'query', 'number'))->toBe("scratch-OST0000!0\nscratch-OST0001!1\n")
			->and($query->answer(10, 'ost', 'query', 'kbytes_total'))->toBe("scratch-OST0000!2048\nscratch-OST0001!U\n")
			->and($query->answer(10, 'ost', 'query', 'nonexistent_metric'))->toBe('')
			->and($query->answer(10, 'ost', 'get', 'kbytes_total', 'scratch-OST0000'))->toBe('2048')
			->and($query->answer(10, 'ost', 'get', 'read_bytes', 'scratch-OST0000'))->toBe('4096')
			->and($query->answer(10, 'ost', 'get', 'kbytes_total', 'scratch-OST0001'))->toBe('U')
			->and($query->answer(10, 'ost', 'get', 'kbytes_total', 'bad-index'))->toBe('U')
			->and($query->answer(10, 'ost', 'get', 'kbytes_total', 'scratch-OST0099'))->toBe('U')
			->and($query->answer(10, 'ost', 'unknown_cmd'))->toBe('');

		// Advance clock into Delayed window: gauge still served, counter returns U.
		$clock->advance(500);
		expect($query->answer(10, 'ost', 'get', 'kbytes_total', 'scratch-OST0000'))->toBe('2048')
			->and($query->answer(10, 'ost', 'get', 'read_bytes', 'scratch-OST0000'))->toBe('U');
	} finally {
		pfs_reset_unit_globals();
	}
});

it('runs Reader end-to-end with fixtures, shards, failures, missing sources, and lease loss', function () {
	$clock   = new FixedClock(1800000000);
	$lustre  = (string) file_get_contents(dirname(__DIR__) . '/fixtures/lustre/oss-2.15.txt');
	$fixture = new FixtureNodeSource([
		1 => $lustre,
		2 => NodeRead::failed(ReadError::Timeout, 0, 30.0),
	]);
	$fixture->answer(3, '#unframed garbage');

	$sources = new NodeSources();
	$sources->register(AccessMethod::Ssh, $fixture);

	$reader = new Reader(
		new FilesystemRepository(false, $clock),
		new NodeRepository(false, $clock),
		new TargetRepository(false),
		new ObservationRepository(false),
		new NodeHealthRepository(false),
		new ReaderRepository(false, $clock),
		new CacheRepository(false),
		new SummaryRepository(false),
		BackendRegistry::production(),
		$sources,
		$clock,
		'holder-1',
	);

	try {
		// When lease cannot be acquired, reader returns an empty run immediately.
		$GLOBALS['__pfs_db_rows'] = ['FROM plugin_pfs_reader' => [['COUNT(*)' => 0]]];
		$noLease = $reader->run(1, 240, 4, 30);
		expect($noLease->nodesTotal)->toBe(0);

		// Full leased run covering success, timeout failure, unframed failure, and shard filtering.
		$GLOBALS['__pfs_db_rows'] = [
			'FROM plugin_pfs_reader'     => [['COUNT(*)' => 1]],
			'FROM plugin_pfs_filesystem' => [
				['id' => 1, 'name' => 'scratch', 'backend' => 'lustre', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 10],
			],
			'FROM plugin_pfs_node_health' => [],
			'FROM plugin_pfs_node'        => [
				['id' => 1, 'filesystem_id' => 1, 'name' => 'oss01', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.1.0.1', 'port' => 22, 'ssh_user' => 'pfsread', 'access_method' => 'ssh', 'admin_state' => 'enabled'],
				['id' => 2, 'filesystem_id' => 1, 'name' => 'oss02', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.1.0.2', 'port' => 22, 'ssh_user' => 'pfsread', 'access_method' => 'ssh', 'admin_state' => 'enabled'],
				['id' => 3, 'filesystem_id' => 1, 'name' => 'oss03', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.1.0.3', 'port' => 22, 'ssh_user' => 'pfsread', 'access_method' => 'ssh', 'admin_state' => 'enabled'],
				['id' => 4, 'filesystem_id' => 1, 'name' => 'oss04', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.1.0.4', 'port' => 22, 'ssh_user' => 'pfsread', 'access_method' => 'ssh', 'admin_state' => 'disabled'],
			],
			'FROM plugin_pfs_target'      => [],
			'FROM plugin_pfs_observation' => [],
			'FROM plugin_pfs_summary'     => [],
		];

		$run = $reader->run(1, 240, 4, 30);
		expect($run->nodesTotal)->toBe(3)
			->and($run->nodesRead)->toBe(1)
			->and($run->nodesFailed)->toBe(2)
			->and($run->nodesSkipped)->toBe(0)
			->and($run->stoppedEarly)->toBeFalse();

		// Budget-exhausted deadline skips nodes cleanly.
		$skippedRun = $reader->run(1, -10, 4, 30, ShardAssigner::parse('1/1'));
		expect($skippedRun->nodesSkipped)->toBe(3)
			->and($skippedRun->stoppedEarly)->toBeTrue();

		// Missing NodeSource records Config error for nodes.
		$emptyReader = new Reader(
			new FilesystemRepository(false, $clock),
			new NodeRepository(false, $clock),
			new TargetRepository(false),
			new ObservationRepository(false),
			new NodeHealthRepository(false),
			new ReaderRepository(false, $clock),
			new CacheRepository(false),
			new SummaryRepository(false),
			BackendRegistry::production(),
			new NodeSources(),
			$clock,
			'holder-2',
		);
		$cfgRun = $emptyReader->run(1, 240, 4, 30);
		expect($cfgRun->nodesFailed)->toBe(3);

		// CacheRepository transaction abort when InnoDB drops transaction.
		$cache = new CacheRepository(false);
		expect($cache->upsertObservationsBatch([]))->toBe(0);

		$GLOBALS['__pfs_in_tx'] = [0, 1];
		$fs = new Filesystem(1, 'scratch', 'lustre', 1, 300, AdminState::Enabled, 10);
		$up = new TargetUpdate(TargetId::parse('scratch-OST0000'), null, Presence::Present, 1, new TargetReport(TargetId::parse('scratch-OST0000'), ['kbytes_total' => 1024.0], null, Condition::InService, ''));
		$ob = new Observation(0, 'kbytes_total', 1024.0, 1800000000, 1800000000, 1, 300, Continuity::Continuous);
		$ok = $cache->apply(1, 'holder-1', 1800000000, $fs, [['update' => $up, 'observations' => [$ob]]], [new NodeHealth(1, 1800000000, 1800000000, 0.1, 100, Collection::Completed)], null);
		expect($ok)->toBeFalse();
	} finally {
		pfs_reset_unit_globals();
	}
});

it('plans device provisioning and renders AdminPage views and JSON export', function () {
	$clock = new FixedClock(1800000000);
	$fs    = new Filesystem(1, 'scratch', 'lustre', 1, 300, AdminState::Enabled, 0);
	$node  = new Node(1, 1, 'oss01', NodeRole::Oss, 1, '10.1.0.1', 22, 'pfsread', AccessMethod::Ssh, AdminState::Enabled);

	try {
		$GLOBALS['__pfs_db_rows'] = [
			'FROM `host_template`'       => [['id' => 0]],
			'FROM plugin_pfs_filesystem' => [
				['id' => 1, 'name' => 'scratch', 'backend' => 'lustre', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 0],
			],
			'FROM plugin_pfs_node' => [
				['id' => 1, 'filesystem_id' => 1, 'name' => 'oss01', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.1.0.1', 'port' => 22, 'ssh_user' => 'pfsread', 'access_method' => 'ssh', 'admin_state' => 'enabled'],
			],
			'FROM plugin_pfs_network' => [
				['id' => 1, 'poller_id' => 1, 'cidr' => '10.1.0.0/24'],
			],
			'FROM plugin_pfs_target' => [
				['id' => 10, 'filesystem_id' => 1, 'type' => 'ost', 'target_index' => 0, 'name' => 'scratch-OST0000', 'review' => 'proposed', 'presence' => 'present', 'condition_state' => 'in_service', 'recovery' => '', 'source_node_id' => 1, 'first_seen' => 1799999000, 'last_seen' => 1800000000],
			],
			'FROM poller' => [['id' => 1, 'name' => 'Main Poller']],
		];

		$provisioner = new DeviceProvisioner(
			new FilesystemRepository(false, $clock),
			new TargetRepository(false),
			new CactiObjectRepository(false),
			BackendRegistry::production(),
			'/nonexistent/cacti',
			new CreatedDeviceRepository(false),
			$clock,
		);

		expect($provisioner->refusal())->not->toBeNull()
			->and($provisioner->create($fs))->toBe(0)
			->and($provisioner->map($fs, 999))->toBeFalse()
			->and($provisioner->description($fs))->toBe('Lustre scratch')
			->and($provisioner->hostname($fs))->toBe('scratch.lustre.pfs.invalid')
			->and($provisioner->plan($fs, 0))->not->toBeEmpty();

		$admin = new AdminPage(
			new FilesystemRepository(false, $clock),
			new NodeRepository(false, $clock),
			new NetworkRepository(false, $clock),
			new TargetRepository(false),
			BackendRegistry::production(),
			new PollerRepository(false),
			$provisioner,
			new CactiObjectRepository(false),
			new DeviceAccess(static fn (int $hostId): bool => true),
		);

		ob_start();
		$admin->renderList();
		$admin->renderFilesystemEdit($fs, [], ['name' => 'Bad name']);
		$admin->renderNodeEdit($node, [], ['endpoint' => 'Bad endpoint']);
		$admin->renderDeviceConfirm($fs, 0);
		$html = (string) ob_get_clean();

		expect($html)->toContain('scratch')
			->and($html)->toContain('oss01')
			->and($html)->toContain('10.1.0.0/24')
			->and($html)->toContain('Bad name')
			->and($html)->toContain('Bad endpoint');

		$exported = $admin->export('0.1.0', 1800000000);
		expect($exported['plugin'])->toBe('pfs')
			->and($exported['filesystems'])->toHaveCount(1)
			->and($exported['nodes'])->toHaveCount(1)
			->and($exported['networks'])->toHaveCount(1);

		// Render device confirm when templates are present.
		$GLOBALS['__pfs_db_rows']['FROM `host_template`'] = [['id' => 11]];
		expect($provisioner->refusal())->toBeNull();
		ob_start();
		$admin->renderDeviceConfirm($fs, 0);
		$confirmHtml = (string) ob_get_clean();
		expect($confirmHtml)->toContain('Create the Device');
	} finally {
		pfs_reset_unit_globals();
	}
});

it('installs, protects, and uninstalls TemplateInstaller query files safely', function () {
	$tmpCacti = sys_get_temp_dir() . '/pfs-tpl-' . bin2hex(random_bytes(4));
	mkdir($tmpCacti . '/resource/script_server', 0755, true);

	try {
		$installer = new TemplateInstaller(dirname(__DIR__, 2), $tmpCacti, new CactiObjectRepository(false));

		expect($installer->isCurrent())->toBeFalse()
			->and($installer->present())->toBeFalse()
			->and($installer->queryFileProblems())->toBe(['pfs_osts.xml', 'pfs_mdts.xml'])
			->and($installer->failure())->toBe('');

		// Pre-place a symlink to verify symlink refusal.
		symlink('/etc/hosts', $tmpCacti . '/resource/script_server/pfs_osts.xml');
		expect($installer->install())->toBeFalse()
			->and($installer->failure())->toContain('symbolic link');
		unlink($tmpCacti . '/resource/script_server/pfs_osts.xml');

		// Pre-place an admin-modified file to verify foreign file protection.
		file_put_contents($tmpCacti . '/resource/script_server/pfs_osts.xml', '<custom/>');
		expect($installer->install())->toBeFalse()
			->and($installer->failure())->toContain('was not written by this plugin');
		unlink($tmpCacti . '/resource/script_server/pfs_osts.xml');

		// Missing template XML fails cleanly after copying query files, then uninstall cleans them up.
		$installerNoTpl = new TemplateInstaller($tmpCacti, $tmpCacti, new CactiObjectRepository(false));
		expect($installerNoTpl->install())->toBeFalse()
			->and($installerNoTpl->failure())->toContain('cannot be installed');

		// Copy both query files via reflection/uninstall path verification.
		$srcOst = dirname(__DIR__, 2) . '/resource/script_server/pfs_osts.xml';
		$srcMdt = dirname(__DIR__, 2) . '/resource/script_server/pfs_mdts.xml';
		$dstOst = $tmpCacti . '/resource/script_server/pfs_osts.xml';
		$dstMdt = $tmpCacti . '/resource/script_server/pfs_mdts.xml';
		copy($srcOst, $dstOst);
		copy($srcMdt, $dstMdt);
		$GLOBALS['__pfs_options'][TemplateInstaller::FILES_SETTING] = json_encode([
			'pfs_osts.xml' => hash_file('sha256', $dstOst),
			'pfs_mdts.xml' => hash_file('sha256', $dstMdt),
		]);

		expect($installer->queryFileProblems())->toBe([]);
		$installer->uninstall();
		expect(is_file($dstOst))->toBeFalse()
			->and(is_file($dstMdt))->toBeFalse();
	} finally {
		foreach ([$tmpCacti . '/resource/script_server/pfs_osts.xml', $tmpCacti . '/resource/script_server/pfs_mdts.xml'] as $path) {
			if (is_link($path) || is_file($path)) {
				unlink($path);
			}
		}
		foreach ([$tmpCacti . '/resource/script_server', $tmpCacti . '/resource', $tmpCacti] as $dir) {
			if (is_dir($dir)) {
				rmdir($dir);
			}
		}
		pfs_reset_unit_globals();
	}
});

it('migrates Schema from version 3 (main) to version 4 cleanly and idempotently in unit tests', function () {
	try {
		$schema = new Schema(false);

		// Simulate stored version 3 with value_kib columns absent initially, then present after ALTER.
		$GLOBALS['__pfs_db_rows'] = [
			'FROM settings'              => [['value' => '3']],
			'information_schema.COLUMNS' => [['COUNT(*)' => 1]],
			'FROM plugin_config'         => [['version' => '0.1.0']],
			'FROM plugin_hooks'          => [['COUNT(DISTINCT hook)' => count(Schema::HOOKS)]],
			'FROM plugin_realms'         => [['COUNT(*)' => 3]],
			'information_schema.TABLES'  => [['COUNT(*)' => 1]],
		];
		$GLOBALS['__pfs_db_exec'] = [];

		expect($schema->storedVersion())->toBe(3)
			->and($schema->isCurrent())->toBeFalse()
			->and($schema->migrate())->toBeTrue()
			->and($schema->recordedPluginVersion())->toBe('0.1.0')
			->and($schema->hooksRegistered())->toBeTrue();

		// Verify schema version was advanced to 4.
		$versionUpdates = array_values(array_filter($GLOBALS['__pfs_db_exec'], static fn (array $q): bool => str_contains($q['sql'], 'REPLACE INTO settings')));
		expect($versionUpdates)->toHaveCount(1)
			->and($versionUpdates[0]['params'])->toBe(['pfs_schema_version', '4']);

		// Re-running migrate at version 4 is a no-op (idempotent).
		$GLOBALS['__pfs_db_rows']['FROM settings'] = [['value' => '4']];
		$GLOBALS['__pfs_db_exec'] = [];
		expect($schema->isCurrent())->toBeTrue()
			->and($schema->migrate())->toBeTrue()
			->and($GLOBALS['__pfs_db_exec'])->toBe([]);

		// Downgrade from a newer schema version is refused.
		$GLOBALS['__pfs_db_rows']['FROM settings'] = [['value' => '99']];
		expect($schema->migrate())->toBeFalse();

		$schema->recordPluginVersion('0.1.0', 'Author', 'https://example.invalid');
		$schema->trackTables();
		$schema->forgetVersion();
		expect($GLOBALS['__pfs_db_exec'])->not->toBeEmpty();
	} finally {
		pfs_reset_unit_globals();
	}
});

it('exercises enum labels, ReadError messages, SystemClock, SystemResolver, and UnknownBackend', function () {
	expect(AccessMethod::Ssh->label())->toBe('SSH forced command')
		->and(TargetType::Ost->label())->toBe('OST')
		->and(TargetType::Mdt->label())->toBe('MDT')
		->and(NodeRole::Mgs->label())->toBe('Management server (MGS)')
		->and(NodeRole::Mds->label())->toBe('Metadata server (MDS)')
		->and(NodeRole::Oss->label())->toBe('Object storage server (OSS)')
		->and(NodeRole::Client->label())->toBe('Client')
		->and((new SystemClock())->now())->toBeGreaterThan(1700000000)
		->and((new SystemResolver())->resolve('127.0.0.1'))->toBe(['127.0.0.1'])
		->and((new SystemResolver())->resolve('::1'))->toBe(['::1']);

	foreach (ReadError::cases() as $err) {
		expect($err->message())->toBeString()->not->toBe('');
	}

	expect(new UnknownBackend('missing'))->toBeInstanceOf(RuntimeException::class);
});
