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
use Somethingwithproof\Pfs\Condition;
use Somethingwithproof\Pfs\DeviceAccess;
use Somethingwithproof\Pfs\Filesystem;
use Somethingwithproof\Pfs\FilesystemRepository;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\HealthPage;
use Somethingwithproof\Pfs\HealthWarnings;
use Somethingwithproof\Pfs\ImbalanceAnalyzer;
use Somethingwithproof\Pfs\NodeHealthRepository;
use Somethingwithproof\Pfs\NodeRepository;
use Somethingwithproof\Pfs\Observation;
use Somethingwithproof\Pfs\Presence;
use Somethingwithproof\Pfs\ReaderRepository;
use Somethingwithproof\Pfs\Review;
use Somethingwithproof\Pfs\Settings;
use Somethingwithproof\Pfs\SummaryTarget;
use Somethingwithproof\Pfs\TargetId;

function imb_target(
	string $name,
	float $totalKb,
	float $freeKb,
	float $availKb,
	float $filesTotal = 1000.0,
	float $filesFree = 800.0,
	int $at = 1800000000,
	Presence $presence = Presence::Present,
	Condition $condition = Condition::InService,
	Review $review = Review::Accepted,
): SummaryTarget {
	$id = TargetId::parse($name) ?? throw new LogicException('Bad target name: ' . $name);

	return new SummaryTarget($id, $review, $presence, $condition, [
		'kbytes_total' => new Observation(1, 'kbytes_total', $totalKb, $at, $at, 1, 300),
		'kbytes_free'  => new Observation(1, 'kbytes_free', $freeKb, $at, $at, 1, 300),
		'kbytes_avail' => new Observation(1, 'kbytes_avail', $availKb, $at, $at, 1, 300),
		'files_total'  => new Observation(1, 'files_total', $filesTotal, $at, $at, 1, 300),
		'files_free'   => new Observation(1, 'files_free', $filesFree, $at, $at, 1, 300),
	]);
}

it('computes uniform OST fill ratios, zero imbalance index, and exact headroom', function () {
	$result = ImbalanceAnalyzer::analyze([
		imb_target('scratch-OST0000', 1000.0, 600.0, 600.0),
		imb_target('scratch-OST0001', 1000.0, 600.0, 600.0),
		imb_target('scratch-MDT0000', 500.0, 400.0, 400.0, 10000.0, 9000.0),
	], 300);

	expect($result['severity'])->toBe('ok')
		->and($result['targets_total'])->toBe(3)
		->and($result['targets_contributing'])->toBe(3)
		->and($result['missing_targets'])->toBe(0)
		->and($result['fill_max_pct'])->toBe(40.0)
		->and($result['fill_min_pct'])->toBe(40.0)
		->and($result['fill_mean_pct'])->toBe(40.0)
		->and($result['fill_spread_pct'])->toBe(0.0)
		->and($result['imbalance_index'])->toBe(0.0)
		->and($result['headroom_min_pct'])->toBe(60.0)
		->and($result['headroom_min_kbytes'])->toBe(600.0)
		->and($result['headroom_kbytes_total'])->toBe(1200.0)
		->and($result['mdt_fill_max_pct'])->toBe(20.0)
		->and($result['mdt_inode_max_pct'])->toBe(10.0)
		->and($result['mdt_headroom_min_pct'])->toBe(80.0);
});

it('detects skewed OSTs, status flags, and critical severity', function () {
	$result = ImbalanceAnalyzer::analyze([
		imb_target('scratch-OST0000', 1000.0, 800.0, 800.0),
		imb_target('scratch-OST0001', 1000.0, 50.0, 50.0),
		imb_target('scratch-OST0002', 1000.0, 0.0, 0.0, 1000.0, 0.0, 1800000000, Presence::Present, Condition::ReadOnly),
		imb_target('scratch-OST0003', 1000.0, 500.0, 500.0, 1000.0, 500.0, 1800000000, Presence::Present, Condition::Degraded),
		imb_target('scratch-OST0004', 1000.0, 500.0, 500.0, 1000.0, 500.0, 1800000000, Presence::Present, Condition::NoCreate),
		imb_target('scratch-OST0005', 1000.0, 10.0, 10.0, 1000.0, 10.0, 1800000000, Presence::Present, Condition::InService, Review::Ignored),
	], 300);

	// Ignored OST0005 is excluded; ReadOnly/Degraded/NoCreate OSTs are excluded from in-service fill summary.
	expect($result['targets_total'])->toBe(5)
		->and($result['severity'])->toBe('critical')
		->and($result['fill_max_pct'])->toBe(95.0)
		->and($result['fill_min_pct'])->toBe(20.0)
		->and($result['fill_spread_pct'])->toBe(75.0)
		->and($result['imbalance_index'])->toBe(37.5)
		->and($result['headroom_min_pct'])->toBe(5.0);

	$flagsByName = [];

	foreach ($result['targets'] as $t) {
		$flagsByName[$t['name']] = $t['status_flags'];
	}

	expect($flagsByName['scratch-OST0002'])->toBe(['readonly', 'out_of_space', 'out_of_inodes'])
		->and($flagsByName['scratch-OST0003'])->toBe(['degraded'])
		->and($flagsByName['scratch-OST0004'])->toBe(['no_create'])
		->and($flagsByName)->not->toHaveKey('scratch-OST0005');
});

it('enforces REQ-CAP-004 1-interval freshness and reports missing targets when an OST is stale or absent', function () {
	$result = ImbalanceAnalyzer::analyze([
		imb_target('scratch-OST0000', 1000.0, 500.0, 500.0, 1000.0, 900.0, 1800000000),
		imb_target('scratch-OST0001', 1000.0, 100.0, 100.0, 1000.0, 900.0, 1800000000 - 300),
		imb_target('scratch-OST0002', 1000.0, 500.0, 500.0, 1000.0, 900.0, 1800000000, Presence::Missing),
	], 300);

	expect($result['missing_targets'])->toBe(2)
		->and($result['targets_contributing'])->toBe(1)
		->and($result['fill_max_pct'])->toBeNull()
		->and($result['fill_spread_pct'])->toBeNull()
		->and($result['imbalance_index'])->toBeNull()
		->and($result['headroom_min_pct'])->toBeNull()
		->and($result['severity'])->toBe('unknown');
});

it('handles a 500-OST and 8-MDT scale fixture accurately', function () {
	$targets = [];

	for ($i = 0; $i < 500; $i++) {
		$used      = 200.0 + ($i % 50) * 10.0; // 20% to 69%
		$targets[] = imb_target(sprintf('scale-OST%04x', $i), 1000.0, 1000.0 - $used, 1000.0 - $used);
	}

	for ($m = 0; $m < 8; $m++) {
		$targets[] = imb_target(sprintf('scale-MDT%04x', $m), 1000.0, 700.0, 700.0, 100000.0, 80000.0);
	}

	$result = ImbalanceAnalyzer::analyze($targets, 300);

	expect($result['ost_count'])->toBe(500)
		->and($result['mdt_count'])->toBe(8)
		->and($result['missing_targets'])->toBe(0)
		->and($result['fill_min_pct'])->toBe(20.0)
		->and($result['fill_max_pct'])->toBe(69.0)
		->and($result['fill_spread_pct'])->toBe(49.0)
		->and($result['headroom_min_pct'])->toBe(31.0)
		->and($result['imbalance_index'])->toBeGreaterThan(14.0)
		->and($result['severity'])->toBe('critical');
});

it('suppresses active failure warnings and shows a Maint banner for filesystems in maintenance', function () {
	$clock = new FixedClock(10000);
	$GLOBALS['__pfs_db_rows'] = [
		'FROM plugin_pfs_filesystem' => [
			['id' => 1, 'name' => 'maintfs', 'backend' => 'lustre', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 42],
			['id' => 2, 'name' => 'livefs', 'backend' => 'gpfs', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled', 'host_id' => 43],
		],
		'FROM plugin_pfs_node_health' => [
			['node_id' => 10, 'last_attempt' => 9990, 'last_success' => 0, 'duration' => 0.1, 'bytes' => 10, 'outcome' => 'failed', 'error' => 'timeout', 'version' => null, 'unexpected_sections' => 0, 'rejected_names' => 0],
			['node_id' => 11, 'last_attempt' => 9990, 'last_success' => 9990, 'duration' => 0.1, 'bytes' => 100, 'outcome' => 'ok', 'error' => null, 'version' => '5.2.1', 'unexpected_sections' => 0, 'rejected_names' => 0],
		],
		'FROM plugin_pfs_reader' => [
			['poller_id' => 1, 'holder' => null, 'lease_until' => 0, 'started_at' => 9980, 'finished_at' => 9990, 'last_completed' => 9990, 'nodes_total' => 2, 'nodes_read' => 1, 'nodes_failed' => 1, 'nodes_skipped' => 0],
		],
		'FROM plugin_pfs_node' => [
			['id' => 10, 'filesystem_id' => 1, 'name' => 'oss-maint', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.20.0.10', 'port' => 22, 'ssh_user' => 'pfsread', 'access_method' => 'ssh', 'admin_state' => 'enabled'],
			['id' => 11, 'filesystem_id' => 2, 'name' => 'nsd-live', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.20.0.11', 'port' => 22, 'ssh_user' => 'pfsread', 'access_method' => 'ssh', 'admin_state' => 'enabled'],
		],
	];

	try {
		$page = new HealthPage(
			new FilesystemRepository(false, $clock),
			new NodeRepository(false, $clock),
			new NodeHealthRepository(false),
			new ReaderRepository(false, $clock),
			new HealthWarnings(fn (string $p): ?int => 0600, $clock),
			$clock,
			new DeviceAccess(fn (int $id): bool => true),
			static fn (int $hostId): bool => $hostId === 42,
		);

		expect($page->maintenanceState(new Filesystem(1, 'maintfs', 'lustre', 1, 300, AdminState::Enabled, 42)))->toBeTrue()
			->and($page->maintenanceState(new Filesystem(2, 'livefs', 'gpfs', 1, 300, AdminState::Enabled, 43)))->toBeFalse()
			->and($page->maintenanceState(new Filesystem(3, 'unmapped', 'beegfs', 1, 300, AdminState::Enabled, 0)))->toBeNull();

		ob_start();
		$page->render(Settings::fromRow([]));
		$html = (string) ob_get_clean();

		expect($html)->toContain('Maint: maintfs')
			->and($html)->toContain('oss-maint')
			->and($html)->toContain('No warnings.');
	} finally {
		$GLOBALS['__pfs_db_rows'] = [];
	}
});
