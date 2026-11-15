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

use Somethingwithproof\Pfs\DeviceAccess;
use Somethingwithproof\Pfs\AccessMethod;
use Somethingwithproof\Pfs\AdminState;
use Somethingwithproof\Pfs\Collection;
use Somethingwithproof\Pfs\Condition;
use Somethingwithproof\Pfs\Continuity;
use Somethingwithproof\Pfs\Filesystem;
use Somethingwithproof\Pfs\FilesystemRepository;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\FreshnessEvaluator;
use Somethingwithproof\Pfs\HealthPage;
use Somethingwithproof\Pfs\Node;
use Somethingwithproof\Pfs\NodeHealth;
use Somethingwithproof\Pfs\NodeHealthRepository;
use Somethingwithproof\Pfs\NodeRepository;
use Somethingwithproof\Pfs\NodeRole;
use Somethingwithproof\Pfs\Observation;
use Somethingwithproof\Pfs\ObservationRepository;
use Somethingwithproof\Pfs\Presence;
use Somethingwithproof\Pfs\ReadError;
use Somethingwithproof\Pfs\ReaderRepository;
use Somethingwithproof\Pfs\Review;
use Somethingwithproof\Pfs\Target;
use Somethingwithproof\Pfs\TargetId;
use Somethingwithproof\Pfs\TargetRepository;
use Somethingwithproof\Pfs\TargetsPage;
use Somethingwithproof\Pfs\HealthWarnings;

function pfs_targets_page(FixedClock $clock): TargetsPage {
	return new TargetsPage(new FilesystemRepository(false, $clock), new NodeRepository(false, $clock), new TargetRepository(false), new ObservationRepository(false), new FreshnessEvaluator($clock), $clock);
}

it('shows a present target with unknown values as unknown, not zero', function () {
	$clock  = new FixedClock(10000);
	$target = new Target(7, 1, TargetId::parse('scratch-OST0001') ?? throw new LogicException(), Review::Accepted, Presence::Present, Condition::Unknown, null, 3, 100, 9900);
	$values = [
		'kbytes_free' => new Observation(7, 'kbytes_free', null, 9900, 9900, 3, 300),
		'read_bytes'  => new Observation(7, 'read_bytes', 5.0, 9900, 9900, 3, 300, Continuity::Reset),
		'kbytes_avail' => new Observation(7, 'kbytes_avail', 0.0, 9900, 9900, 3, 300),
	];
	$row = pfs_targets_page($clock)->row($target, $values, new Filesystem(1, 'scratch', 'lustre', 1, 300, AdminState::Enabled), [3 => 'oss01']);

	expect($row)->toBe(['scratch-OST0001', 'accepted', 'present', 'unknown', '-', 'current', '1 / 3', 'oss01', '100 seconds ago']);
});

it('ages a missing target by its last observation', function () {
	$clock  = new FixedClock(10000);
	$target = new Target(7, 1, TargetId::parse('scratch-OST0001') ?? throw new LogicException(), Review::Accepted, Presence::Missing, Condition::InService, null, 3, 100, 8000);
	$row    = pfs_targets_page($clock)->row($target, ['kbytes_free' => new Observation(7, 'kbytes_free', 5.0, 8000, 8000, 3, 300)], new Filesystem(1, 'scratch', 'lustre', 1, 300, AdminState::Enabled), []);

	expect($row[2])->toBe('missing')
		->and($row[5])->toBe('stale')
		->and($row[7])->toBe('-');
});

it('escapes every stored string the health page prints', function () {
	$clock = new FixedClock(10000);
	$GLOBALS['__pfs_db_rows'] = [
		'FROM plugin_pfs_filesystem' => [['id' => 1, 'name' => 'scratch', 'backend' => 'lustre', 'poller_id' => 1, 'expected_interval' => 300, 'admin_state' => 'enabled']],
		'FROM plugin_pfs_node_health' => [['node_id' => 3, 'last_attempt' => 9990, 'last_success' => 0, 'duration' => 0.1, 'bytes' => 10, 'outcome' => 'failed', 'error' => 'unframed', 'version' => '"><script>x</script>', 'unexpected_sections' => 0, 'rejected_names' => 0]],
		'FROM plugin_pfs_node' => [['id' => 3, 'filesystem_id' => 1, 'name' => 'oss01', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.20.0.11', 'port' => 22, 'ssh_user' => 'pfsread', 'access_method' => 'ssh', 'admin_state' => 'enabled']],
	];

	$page = new HealthPage(new FilesystemRepository(false, $clock), new NodeRepository(false, $clock), new NodeHealthRepository(false), new ReaderRepository(false, $clock), new HealthWarnings(fn (string $p): ?int => 0600, $clock), $clock, new DeviceAccess(fn (int $id): bool => true));

	ob_start();
	$page->render(\Somethingwithproof\Pfs\Settings::fromRow([]));
	$html = (string) ob_get_clean();
	$GLOBALS['__pfs_db_rows'] = [];

	expect($html)->toContain('oss01')
		->and($html)->not->toContain('<script>')
		->and($html)->toContain('forced command');
});

it('keeps unknown apart from zero in a node row', function () {
	$clock = new FixedClock(10000);
	$page  = new HealthPage(new FilesystemRepository(false, $clock), new NodeRepository(false, $clock), new NodeHealthRepository(false), new ReaderRepository(false, $clock), new HealthWarnings(fn (string $p): ?int => 0600, $clock), $clock, new DeviceAccess(fn (int $id): bool => true));
	$node  = new Node(3, 1, 'oss01', NodeRole::Oss, 1, '10.20.0.11', 22, 'pfsread', AccessMethod::Ssh, AdminState::Enabled);

	expect($page->nodeRow($node, null, new NodeHealth(3), 10000))->toBe(['oss01', '-', 'Object storage server (OSS)', '1', 'never', 'Never', '-', '-', '-', '-'])
		->and($page->nodeRow($node, null, new NodeHealth(3, 9990, 0, 0.5, 0, Collection::Failed, ReadError::Timeout), 10000)[8])->toBe(ReadError::Timeout->message());
});
