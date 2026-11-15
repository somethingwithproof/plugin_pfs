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
use Somethingwithproof\Pfs\Continuity;
use Somethingwithproof\Pfs\Filesystem;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\Freshness;
use Somethingwithproof\Pfs\FreshnessEvaluator;
use Somethingwithproof\Pfs\InventoryReconciler;
use Somethingwithproof\Pfs\NodeReport;
use Somethingwithproof\Pfs\Observation;
use Somethingwithproof\Pfs\Presence;
use Somethingwithproof\Pfs\ResetDetector;
use Somethingwithproof\Pfs\Review;
use Somethingwithproof\Pfs\Target;
use Somethingwithproof\Pfs\TargetId;
use Somethingwithproof\Pfs\TargetReport;
use Somethingwithproof\Pfs\ValueKind;

function pfs_fs(): Filesystem {
	return new Filesystem(1, 'scratch', 'lustre', 1, 300, AdminState::Enabled);
}

/**
 * @param list<string> $names
 */
function pfs_report(array $names, float $free = 5.0): NodeReport {
	$targets = [];

	foreach ($names as $name) {
		$id             = TargetId::parse($name) ?? throw new LogicException($name);
		$targets[$name] = new TargetReport($id, ['kbytes_free' => $free], null, Condition::InService, null);
	}

	return new NodeReport(true, true, false, null, $targets, 0, 0);
}

function pfs_target(string $name, int $source, Presence $presence = Presence::Present): Target {
	$id = TargetId::parse($name) ?? throw new LogicException($name);

	return new Target(7, 1, $id, Review::Accepted, $presence, Condition::InService, null, $source, 100, 200);
}

describe('freshness', function () {
	it('grades age against the expected interval', function (int $age, Freshness $expected) {
		$clock = new FixedClock(10000);

		expect((new FreshnessEvaluator($clock))->evaluate(10000 - $age, 300))->toBe($expected);
	})->with([
		'just read'          => [0, Freshness::Current],
		'one and a half'     => [450, Freshness::Current],
		'just over'          => [451, Freshness::Delayed],
		'three intervals'    => [900, Freshness::Delayed],
		'past three'         => [901, Freshness::Stale],
		'clock skew, small'  => [-300, Freshness::Current],
		'clock skew, large'  => [-301, Freshness::Stale],
	]);

	it('says never for a value that was never observed', function () {
		expect((new FreshnessEvaluator(new FixedClock(10000)))->evaluate(0, 300))->toBe(Freshness::Never);
	});
});

describe('reset detection', function () {
	$previous = new Observation(1, 'read_bytes', 1000.0, 100, 100, 3, 300, Continuity::Continuous, '1731000000.000000001', 0);

	it('keeps a growing counter from the same source and start continuous', function () use ($previous) {
		expect(ResetDetector::continuity($previous, 1500.0, '1731000000.000000001', 3, ValueKind::Counter))->toBe(Continuity::Continuous);
	});

	it('marks a reset when the counter goes backwards, the start time changes, or the source moves', function (?float $value, ?string $start, int $source) use ($previous) {
		expect(ResetDetector::continuity($previous, $value, $start, $source, ValueKind::Counter))->toBe(Continuity::Reset);
	})->with([
		'decrease'      => [10.0, '1731000000.000000001', 3],
		'new start'     => [1500.0, '1731599000.000000001', 3],
		'failover'      => [1500.0, '1731000000.000000001', 4],
	]);

	it('marks a reset when the previous value was unknown', function () {
		$unknown = new Observation(1, 'read_bytes', null, 100, 100, 3, 300);

		expect(ResetDetector::continuity($unknown, 1500.0, null, 3, ValueKind::Counter))->toBe(Continuity::Reset);
	});

	it('does not publish a counter value across a reset', function () {
		$reset = new Observation(1, 'read_bytes', 10.0, 100, 100, 3, 300, Continuity::Reset);

		expect($reset->published())->toBeNull()
			->and((new Observation(1, 'read_bytes', 10.0, 100, 100, 3, 300))->published())->toBe(10.0);
	});

	it('continues from an absent counter line only with the same start time and source', function () {
		$absent = new Observation(1, 'write_bytes', null, 100, 100, 3, 300, Continuity::Absent, '1731000000.000000001', 0);

		expect(ResetDetector::continuity($absent, 4096.0, '1731000000.000000001', 3, ValueKind::Counter))->toBe(Continuity::Continuous)
			->and(ResetDetector::continuity($absent, 4096.0, '1731599000.000000001', 3, ValueKind::Counter))->toBe(Continuity::Reset)
			->and(ResetDetector::continuity($absent, 4096.0, '1731000000.000000001', 4, ValueKind::Counter))->toBe(Continuity::Reset)
			->and(ResetDetector::continuity($absent, 4096.0, null, 3, ValueKind::Counter))->toBe(Continuity::Reset);
	});

	it('never treats a gauge as reset', function () use ($previous) {
		expect(ResetDetector::continuity($previous, 1.0, null, 9, ValueKind::Gauge))->toBe(Continuity::Continuous);
	});
});

describe('inventory', function () {
	it('proposes new targets with the reporting node as source', function () {
		$plan = InventoryReconciler::reconcile(pfs_fs(), [3 => pfs_report(['scratch-OST0000', 'scratch-OST0001'])], []);

		expect(array_keys($plan->updates))->toBe(['scratch-OST0000', 'scratch-OST0001'])
			->and($plan->updates['scratch-OST0000']->existing)->toBeNull()
			->and($plan->updates['scratch-OST0000']->presence)->toBe(Presence::Present)
			->and($plan->updates['scratch-OST0000']->sourceNodeId)->toBe(3);
	});

	it('marks a target missing only when its last source was read and no longer reports it', function () {
		$existing = ['scratch-OST0001' => pfs_target('scratch-OST0001', 3)];

		$seen   = InventoryReconciler::reconcile(pfs_fs(), [3 => pfs_report(['scratch-OST0000'])], $existing);
		$failed = InventoryReconciler::reconcile(pfs_fs(), [3 => null], $existing);
		$other  = InventoryReconciler::reconcile(pfs_fs(), [4 => pfs_report(['scratch-OST0000'])], $existing);

		expect($seen->updates['scratch-OST0001']->presence)->toBe(Presence::Missing)
			->and($seen->updates['scratch-OST0001']->report)->toBeNull()
			->and($failed->updates)->toBe([])
			->and($other->updates)->not->toHaveKey('scratch-OST0001');
	});

	it('follows a target to the node that now reports it after failover', function () {
		$existing = ['scratch-OST0001' => pfs_target('scratch-OST0001', 3)];
		$plan     = InventoryReconciler::reconcile(pfs_fs(), [3 => null, 4 => pfs_report(['scratch-OST0001'])], $existing);
		$update   = $plan->updates['scratch-OST0001'];

		expect($update->presence)->toBe(Presence::Present)
			->and($update->sourceNodeId)->toBe(4)
			->and($update->existing?->id)->toBe(7);
	});

	it('uses neither value when two nodes report the same target in one run', function () {
		$plan   = InventoryReconciler::reconcile(pfs_fs(), [3 => pfs_report(['scratch-OST0001'], 5.0), 4 => pfs_report(['scratch-OST0001'], 9.0)], []);
		$update = $plan->updates['scratch-OST0001'];

		expect($update->presence)->toBe(Presence::Conflict)
			->and($update->report)->toBeNull()
			->and($update->sourceNodeId)->toBe(0);
	});

	it('ends a conflict as missing only when every node read answered without it', function () {
		// The stored source of a conflict is the last node that held it alone.
		$existing = ['scratch-OST0001' => pfs_target('scratch-OST0001', 4, Presence::Conflict)];

		$answered = InventoryReconciler::reconcile(pfs_fs(), [3 => pfs_report([]), 4 => pfs_report(['scratch-OST0000'])], $existing);
		$oneDown  = InventoryReconciler::reconcile(pfs_fs(), [3 => pfs_report([]), 4 => null], $existing);
		$ownerOk  = InventoryReconciler::reconcile(pfs_fs(), [3 => null, 4 => pfs_report([])], $existing);

		expect($answered->updates['scratch-OST0001']->presence)->toBe(Presence::Missing)
			->and($oneDown->updates)->not->toHaveKey('scratch-OST0001')
			->and($ownerOk->updates)->not->toHaveKey('scratch-OST0001');
	});

	it('treats a renamed target as a new target and the old name as missing', function () {
		$existing = ['scratch-OST0001' => pfs_target('scratch-OST0001', 3)];
		$plan     = InventoryReconciler::reconcile(pfs_fs(), [3 => pfs_report(['scratch-OST0002'])], $existing);

		expect($plan->updates['scratch-OST0001']->presence)->toBe(Presence::Missing)
			->and($plan->updates['scratch-OST0002']->existing)->toBeNull()
			->and($plan->updates['scratch-OST0002']->presence)->toBe(Presence::Present);
	});

	it('does not store targets named for another filesystem, and counts them', function () {
		$plan = InventoryReconciler::reconcile(pfs_fs(), [3 => pfs_report(['other-OST0000', 'scratch-OST0000'])], []);

		expect(array_keys($plan->updates))->toBe(['scratch-OST0000'])
			->and($plan->foreign)->toBe([3 => 1]);
	});

	it('leaves an already missing target alone', function () {
		$existing = ['scratch-OST0001' => pfs_target('scratch-OST0001', 3, Presence::Missing)];

		expect(InventoryReconciler::reconcile(pfs_fs(), [3 => pfs_report([])], $existing)->updates)->toBe([]);
	});
});
