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
use Somethingwithproof\Pfs\AdminState;
use Somethingwithproof\Pfs\BatchUpserter;
use Somethingwithproof\Pfs\BeegfsBackend;
use Somethingwithproof\Pfs\CacheRepository;
use Somethingwithproof\Pfs\Condition;
use Somethingwithproof\Pfs\FramedReportParser;
use Somethingwithproof\Pfs\GpfsBackend;
use Somethingwithproof\Pfs\ImbalanceAnalyzer;
use Somethingwithproof\Pfs\Node;
use Somethingwithproof\Pfs\NodeRole;
use Somethingwithproof\Pfs\Observation;
use Somethingwithproof\Pfs\Presence;
use Somethingwithproof\Pfs\Review;
use Somethingwithproof\Pfs\RollupPolicy;
use Somethingwithproof\Pfs\Schema;
use Somethingwithproof\Pfs\ShardAssigner;
use Somethingwithproof\Pfs\SummaryCalculator;
use Somethingwithproof\Pfs\SummaryTarget;
use Somethingwithproof\Pfs\Target;
use Somethingwithproof\Pfs\TargetId;
use Somethingwithproof\Pfs\TargetRepository;
use Somethingwithproof\Pfs\TopKTracker;
use Somethingwithproof\Pfs\UsedPercent;

it('declares BIGINT UNSIGNED value_kib columns on observation and summary tables in Schema v4', function () {
	$tables = Schema::tables();

	foreach (['plugin_pfs_observation', 'plugin_pfs_summary'] as $table) {
		$cols = array_values(array_filter(
			$tables[$table]['columns'],
			static fn (array $col): bool => $col['name'] === 'value_kib',
		));

		expect($cols)->toHaveCount(1)
			->and($cols[0]['type'])->toBe('bigint(20)')
			->and($cols[0]['unsigned'])->toBeTrue();
	}
});

it('preserves exact 64-bit KiB precision at 500 PB and 2 EB without overflow or float truncation', function () {
	// 500 PiB in bytes = 500 * 1024^5 = 562,949,953,421,312,000 bytes -> 549,755,813,888,000 KiB.
	// 2 EiB in bytes = 2 * 1024^6 = 2,305,843,009,213,693,952 bytes -> 2,251,799,813,685,248 KiB.
	// Both byte values exceed IEEE-754 double's 53-bit exact integer limit (~9 PB),
	// yet their exact KiB values sit cleanly inside 53-bit double and 64-bit BIGINT UNSIGNED.
	$bytes500PiB = '562949953421312000';
	$kib500PiB   = 549755813888000;
	$bytes2EiB   = '2305843009213693952';
	$kib2EiB     = 2251799813685248;

	// Add 1024 bytes (+1 KiB) to prove LSB precision is preserved at 2 EiB.
	$bytes2EiBPlus1K = '2305843009213694976';

	expect(FramedReportParser::bytesToKibInt($bytes500PiB))->toBe($kib500PiB)
		->and(FramedReportParser::bytesToKibInt($bytes2EiB))->toBe($kib2EiB)
		->and(FramedReportParser::bytesToKibInt($bytes2EiBPlus1K))->toBe($kib2EiB + 1)
		->and(FramedReportParser::kibToUint64((float) $kib2EiB))->toBe($kib2EiB);

	// Verify BeeGFS byte-to-KiB parsing at 500 PiB and 2 EiB.
	$beegfsPayload = implode("\n", [
		BeegfsBackend::PROTOCOL,
		'== storage_targets',
		sprintf('target fs=exa1 id=1 reachability=online consistency=GOOD disk_space_total=%s disk_space_free=112589990684262400', $bytes500PiB),
		sprintf('target fs=exa1 id=2 reachability=online consistency=GOOD disk_space_total=%s disk_space_free=576460752303423488', $bytes2EiB),
		BeegfsBackend::END,
	]) . "\n";

	$beegfsReport = (new BeegfsBackend())->parse($beegfsPayload);
	expect($beegfsReport->targets['exa1-OST0001']->values['kbytes_total'])->toBe((float) $kib500PiB)
		->and($beegfsReport->targets['exa1-OST0002']->values['kbytes_total'])->toBe((float) $kib2EiB);

	// Verify GPFS KiB parsing at 500 PiB + 2 EiB and SummaryCalculator + ImbalanceAnalyzer math.
	$gpfsPayload = implode("\n", [
		GpfsBackend::PROTOCOL,
		'== mmdf',
		sprintf('pool fs=exa2 type=ost index=0 kbytes_total=%d kbytes_free=%d kbytes_avail=%d state=up', $kib500PiB, intdiv($kib500PiB, 5), intdiv($kib500PiB, 5)),
		sprintf('pool fs=exa2 type=ost index=1 kbytes_total=%d kbytes_free=%d kbytes_avail=%d state=up', $kib2EiB, intdiv($kib2EiB, 2), intdiv($kib2EiB, 2)),
		GpfsBackend::END,
	]) . "\n";

	$gpfsReport = (new GpfsBackend())->parse($gpfsPayload);
	$ost0       = $gpfsReport->targets['exa2-OST0000']->values;
	$ost1       = $gpfsReport->targets['exa2-OST0001']->values;

	expect(UsedPercent::space($ost0['kbytes_total'], $ost0['kbytes_free'], $ost0['kbytes_avail']))->toBe(80.0)
		->and(UsedPercent::space($ost1['kbytes_total'], $ost1['kbytes_free'], $ost1['kbytes_avail']))->toBe(50.0);

	$now     = 1800000000;
	$targets = [];

	foreach ($gpfsReport->targets as $t) {
		$obs = [];

		foreach ($t->values as $metric => $value) {
			if ($value !== null) {
				$obs[$metric] = new Observation(1, $metric, $value, $now, $now, 1, 300);
			}
		}

		$targets[] = new SummaryTarget($t->id, Review::Accepted, Presence::Present, $t->condition, $obs);
	}

	$summary   = SummaryCalculator::calculate($targets, null, 300, $now);
	$imbalance = ImbalanceAnalyzer::analyze($targets, 300);

	expect($summary->value('kbytes_total'))->toBe((float) ($kib500PiB + $kib2EiB))
		->and($summary->value('fill_max_pct'))->toBe(80.0)
		->and($summary->value('fill_min_pct'))->toBe(50.0)
		->and($imbalance['headroom_kbytes_total'])->toBe((float) (intdiv($kib500PiB, 5) + intdiv($kib2EiB, 2)))
		->and($imbalance['fill_spread_pct'])->toBe(30.0);
});

it('enforces hierarchical opt-in RRD tiering with promotion cap and server-level rollups', function () {
	$details = [
		['name' => 'exa-OST0000', 'type' => 'ost', 'presence' => 'present', 'condition' => 'in_service', 'fill_pct' => 45.0, 'inode_pct' => 10.0, 'headroom_pct' => 55.0, 'kbytes_avail' => 550.0, 'status_flags' => []],
		['name' => 'exa-OST0001', 'type' => 'ost', 'presence' => 'present', 'condition' => 'in_service', 'fill_pct' => 46.0, 'inode_pct' => 10.0, 'headroom_pct' => 54.0, 'kbytes_avail' => 540.0, 'status_flags' => []],
		['name' => 'exa-OST0002', 'type' => 'ost', 'presence' => 'present', 'condition' => 'in_service', 'fill_pct' => 88.0, 'inode_pct' => 10.0, 'headroom_pct' => 12.0, 'kbytes_avail' => 120.0, 'status_flags' => []],
		['name' => 'exa-OST0003', 'type' => 'ost', 'presence' => 'present', 'condition' => 'degraded', 'fill_pct' => 50.0, 'inode_pct' => 10.0, 'headroom_pct' => 50.0, 'kbytes_avail' => 500.0, 'status_flags' => ['degraded']],
		['name' => 'exa-OST0004', 'type' => 'ost', 'presence' => 'present', 'condition' => 'in_service', 'fill_pct' => 70.0, 'inode_pct' => 10.0, 'headroom_pct' => 30.0, 'kbytes_avail' => 300.0, 'status_flags' => []],
	];

	$policy = new RollupPolicy(['exa-OST0000'], 80.0, 15.0, 3);
	$plan   = $policy->selectPromotedTargets($details, 50.0);

	expect($plan['default_tiers'])->toBe(['filesystem', 'pool', 'server'])
		->and($plan['total_targets'])->toBe(5)
		->and($plan['promoted_count'])->toBe(3)
		->and($plan['rollup_only_count'])->toBe(2)
		->and($plan['capped'])->toBeTrue()
		->and(array_column($plan['promoted'], 'name'))->toBe(['exa-OST0000', 'exa-OST0003', 'exa-OST0002'])
		->and(array_column($plan['promoted'], 'reason'))->toBe(['opt_in', 'unhealthy', 'hot_fill']);

	$targets = [
		new Target(1, 1, TargetId::parse('exa-OST0000') ?? throw new LogicException(), Review::Accepted, Presence::Present, Condition::InService, null, 10, 100, 200),
		new Target(2, 1, TargetId::parse('exa-OST0001') ?? throw new LogicException(), Review::Accepted, Presence::Present, Condition::Degraded, null, 10, 100, 200),
		new Target(3, 1, TargetId::parse('exa-OST0002') ?? throw new LogicException(), Review::Accepted, Presence::Present, Condition::InService, null, 11, 100, 200),
	];
	$obs = [
		1 => ['kbytes_total' => new Observation(1, 'kbytes_total', 1000.0, 200, 200, 10, 300), 'kbytes_free' => new Observation(1, 'kbytes_free', 400.0, 200, 200, 10, 300), 'kbytes_avail' => new Observation(1, 'kbytes_avail', 400.0, 200, 200, 10, 300)],
		2 => ['kbytes_total' => new Observation(2, 'kbytes_total', 1000.0, 200, 200, 10, 300), 'kbytes_free' => new Observation(2, 'kbytes_free', 200.0, 200, 200, 10, 300), 'kbytes_avail' => new Observation(2, 'kbytes_avail', 200.0, 200, 200, 10, 300)],
		3 => ['kbytes_total' => new Observation(3, 'kbytes_total', 2000.0, 200, 200, 11, 300), 'kbytes_free' => new Observation(3, 'kbytes_free', 1500.0, 200, 200, 11, 300), 'kbytes_avail' => new Observation(3, 'kbytes_avail', 1500.0, 200, 200, 11, 300)],
	];

	$servers = RollupPolicy::summarizeByServer($targets, $obs, [10 => 'oss01', 11 => 'oss02']);

	expect($servers)->toHaveCount(2)
		->and($servers[0]['server_name'])->toBe('oss01')
		->and($servers[0]['targets_total'])->toBe(2)
		->and($servers[0]['targets_unhealthy'])->toBe(1)
		->and($servers[0]['kbytes_total'])->toBe(2000.0)
		->and($servers[0]['fill_max_pct'])->toBe(80.0)
		->and($servers[1]['server_name'])->toBe('oss02')
		->and($servers[1]['fill_max_pct'])->toBe(25.0);
});

it('tracks bounded Top-K heavy jobs from pre-aggregated feeds and rejects invalid entries', function () {
	$generator = (static function (): Generator {
		yield ['id' => 'bad;id', 'source' => 'job_stats', 'bytes_per_sec' => 999999999];
		yield ['id' => 'job-bad-src', 'source' => 'unknown', 'bytes_per_sec' => 999999999];
		yield ['id' => 'job-neg', 'source' => 'ldms', 'bytes_per_sec' => -50];

		for ($i = 1; $i <= 5000; $i++) {
			yield [
				'id'            => 'slurm-' . $i,
				'source'        => $i % 3 === 0 ? 'ldms' : ($i % 2 === 0 ? 'mmpmon' : 'job_stats'),
				'bytes_per_sec' => $i * 1024.0,
				'ops_per_sec'   => (float) $i,
				'read_bytes'    => $i * 512.0,
				'write_bytes'   => $i * 512.0,
			];
		}
	})();

	$top = TopKTracker::topHeavyHitters($generator, 5);

	expect($top)->toHaveCount(5)
		->and(array_column($top, 'id'))->toBe(['slurm-5000', 'slurm-4999', 'slurm-4998', 'slurm-4997', 'slurm-4996'])
		->and($top[0]['bytes_per_sec'])->toBe(5000 * 1024.0);
});

it('deterministically partitions storage servers across shards without overlap or omission', function () {
	expect(fn () => ShardAssigner::parse('0/4'))->toThrow(InvalidArgumentException::class)
		->and(fn () => ShardAssigner::parse('5/4'))->toThrow(InvalidArgumentException::class)
		->and(fn () => ShardAssigner::parse('bad'))->toThrow(InvalidArgumentException::class);

	$nodes = [];

	for ($i = 1; $i <= 800; $i++) {
		$nodes[] = new Node($i, 1, sprintf('oss-%04d', $i), NodeRole::Oss, 1, '10.20.0.1', 22, 'pfsread', AccessMethod::Ssh, AdminState::Enabled);
	}

	$seen   = [];
	$counts = [];

	for ($k = 1; $k <= 8; $k++) {
		$shard  = ShardAssigner::parse($k . '/8');
		$owned  = $shard->filterNodes($nodes);
		$counts[] = count($owned);

		foreach ($owned as $node) {
			expect($seen)->not->toHaveKey($node->id);
			$seen[$node->id] = $k;
		}
	}

	expect(count($seen))->toBe(800)
		->and(min($counts))->toBeGreaterThan(60)
		->and(max($counts))->toBeLessThan(140)
		->and(ShardAssigner::parse('3/8')->label())->toBe('3/8');
});

it('handles a 10,000-target synthetic scale workload with 500-row batched upserts, keyset pagination, Top-K, and RollupPolicy', function () {
	$now        = 1800000000;
	$sumTargets = [];
	$obsEntries = [];

	for ($i = 0; $i < 10000; $i++) {
		$usedKb       = 4000000.0 + ($i % 50) * 100000.0; // 40% to 89%
		$totalKb      = 10000000.0;
		$availKb      = $totalKb - $usedKb;
		$id           = new TargetId('exa', \Somethingwithproof\Pfs\TargetType::Ost, $i);
		$obsTotal     = new Observation($i + 1, 'kbytes_total', $totalKb, $now, $now, ($i % 100) + 1, 300);
		$obsFree      = new Observation($i + 1, 'kbytes_free', $availKb, $now, $now, ($i % 100) + 1, 300);
		$obsAvail     = new Observation($i + 1, 'kbytes_avail', $availKb, $now, $now, ($i % 100) + 1, 300);
		$sumTargets[] = new SummaryTarget($id, Review::Accepted, Presence::Present, Condition::InService, [
			'kbytes_total' => $obsTotal,
			'kbytes_free'  => $obsFree,
			'kbytes_avail' => $obsAvail,
		]);
		$obsEntries[] = ['target_id' => $i + 1, 'observation' => $obsTotal];
	}

	// 1. Batched SQL upserts: 10,000 rows -> exactly 20 batches of 500 rows.
	$GLOBALS['__pfs_db_exec'] = [];

	try {
		$written = (new CacheRepository(false))->upsertObservationsBatch($obsEntries, BatchUpserter::DEFAULT_BATCH_SIZE);

		expect($written)->toBe(10000)
			->and($GLOBALS['__pfs_db_exec'])->toHaveCount(20)
			->and($GLOBALS['__pfs_db_exec'][0]['sql'])->toContain('INSERT INTO `plugin_pfs_observation`')
			->and($GLOBALS['__pfs_db_exec'][0]['sql'])->toContain('ON DUPLICATE KEY UPDATE');

		// 2. Keyset pagination bounds & repository query.
		$GLOBALS['__pfs_db_rows'] = [
			'FROM plugin_pfs_target WHERE filesystem_id = ? AND id > ?' => [
				['id' => 501, 'filesystem_id' => 1, 'name' => 'exa-OST01f4', 'type' => 'ost', 'target_index' => 500, 'review' => 'accepted', 'presence' => 'present', 'condition_state' => 'in_service', 'recovery' => '', 'source_node_id' => 1, 'first_seen' => $now, 'last_seen' => $now],
			],
		];

		$page = (new TargetRepository(false))->forFilesystemPage(1, 500, 500);
		expect($page)->toHaveCount(1)
			->and($page[0]->id)->toBe(501)
			->and(BatchUpserter::keysetBounds(-5, 99999))->toBe(['after_id' => 0, 'limit' => 5000]);
	} finally {
		$GLOBALS['__pfs_db_exec'] = [];
		$GLOBALS['__pfs_db_rows'] = [];
	}

	// 3. ImbalanceAnalyzer + bounded Top-K (K=20) + RollupPolicy cap (200) across 10,000 targets.
	$start     = microtime(true);
	$analysis  = ImbalanceAnalyzer::analyze($sumTargets, 300, 20);
	$rollup    = (new RollupPolicy())->selectPromotedTargets($analysis['targets'], $analysis['fill_mean_pct']);
	$elapsedMs = (microtime(true) - $start) * 1000.0;

	expect($analysis['ost_count'])->toBe(10000)
		->and($analysis['missing_targets'])->toBe(0)
		->and($analysis['fill_min_pct'])->toBe(40.0)
		->and($analysis['fill_max_pct'])->toBe(89.0)
		->and($analysis['fill_spread_pct'])->toBe(49.0)
		->and($analysis['top_skewed_targets'])->toHaveCount(20)
		->and($rollup['total_targets'])->toBe(10000)
		->and($rollup['promoted_count'])->toBe(200)
		->and($rollup['rollup_only_count'])->toBe(9800)
		->and($rollup['capped'])->toBeTrue()
		->and($elapsedMs)->toBeLessThan(5000.0);
});
