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

use Somethingwithproof\Pfs\BatchUpserter;
use Somethingwithproof\Pfs\BeegfsBackend;
use Somethingwithproof\Pfs\FramedReportParser;
use Somethingwithproof\Pfs\GpfsBackend;
use Somethingwithproof\Pfs\LatencyHistogram;
use Somethingwithproof\Pfs\LustreBackend;
use Somethingwithproof\Pfs\RecoveryMetrics;
use Somethingwithproof\Pfs\RollupPolicy;
use Somethingwithproof\Pfs\RpcLatencyMetrics;
use Somethingwithproof\Pfs\StallMetrics;
use Somethingwithproof\Pfs\TopKTracker;

describe('LatencyHistogram', function () {
	it('tracks exact base buckets, bounded quantiles across orders of magnitude, and overflow', function () {
		$empty = new LatencyHistogram();
		expect($empty->count())->toBe(0)
			->and($empty->minUs())->toBeNull()
			->and($empty->maxUs())->toBeNull()
			->and($empty->meanUs())->toBeNull()
			->and($empty->p50Us())->toBeNull()
			->and($empty->p95Ms())->toBeNull()
			->and($empty->p99Ms())->toBeNull()
			->and($empty->quantileUs(-0.1))->toBeNull()
			->and($empty->quantileUs(1.1))->toBeNull();

		$h = new LatencyHistogram();

		// Invalid samples are ignored safely.
		$h->recordMicroseconds(-5.0);
		$h->recordMicroseconds(NAN);
		$h->recordMicroseconds(INF);
		$h->recordMicroseconds(10.0, 0);
		$h->recordMilliseconds(-1.0);
		expect($h->count())->toBe(0);

		// Uniform distribution over 1..10,000 us -> true p50=5000, p95=9500, p99=9900.
		for ($v = 1; $v <= 10000; $v++) {
			$h->recordMicroseconds((float) $v);
		}

		expect($h->count())->toBe(10000)
			->and($h->minUs())->toBe(1.0)
			->and($h->maxUs())->toBe(10000.0)
			->and($h->meanUs())->toBe(5000.5)
			->and($h->quantileUs(0.0))->toBe(1.0)
			->and($h->quantileUs(1.0))->toBe(10000.0)
			->and(abs(($h->p50Us() ?? 0.0) - 5000.0))->toBeLessThan(350.0)
			->and(abs(($h->p95Us() ?? 0.0) - 9500.0))->toBeLessThan(650.0)
			->and(abs(($h->p99Us() ?? 0.0) - 9900.0))->toBeLessThan(650.0);

		$snap = $h->snapshot();
		expect($snap['p50_ms'])->toBe(round(($snap['p50_us'] ?? 0.0) / 1000.0, 3))
			->and($snap['p95_ms'])->toBe(round(($snap['p95_us'] ?? 0.0) / 1000.0, 3))
			->and($snap['p99_ms'])->toBe(round(($snap['p99_us'] ?? 0.0) / 1000.0, 3))
			->and($h->p50Ms())->toBe($snap['p50_ms']);

		// Overflow bucket (> 134.2 s) tracks extrema without panicking or growing memory.
		$overflow = new LatencyHistogram();
		$overflow->recordMilliseconds(200000.0);
		expect(LatencyHistogram::bucketIndex(200_000_000.0))->toBe(LatencyHistogram::OVERFLOW_BUCKET)
			->and($overflow->p99Us())->toBe(200_000_000.0)
			->and(LatencyHistogram::bucketBounds(0))->toBe([0.0, 0.0])
			->and(LatencyHistogram::bucketBounds(LatencyHistogram::OVERFLOW_BUCKET))->toBe([LatencyHistogram::MAX_TRACKED_US, LatencyHistogram::MAX_TRACKED_US]);
	});

	it('merges associatively and commutatively across servers and round-trips sparse arrays', function () {
		$a = new LatencyHistogram();
		$a->recordSummary(1, 50.0, 50.0, 50.0);
		$a->recordSummary(2, 100.0, 400.0, 500.0);
		$a->recordSummary(100, 10.0, 5000.0, 25000.0);
		$a->recordSummary(0, 10.0, 20.0, 30.0); // ignored
		$a->recordSummary(5, 50.0, 10.0, 100.0); // invalid min > max ignored

		$b = new LatencyHistogram();
		$b->recordMilliseconds(2.5, 50);
		$b->recordMilliseconds(12.0, 10);

		$ab = $a->merge($b);
		$ba = $b->merge($a);

		expect($ab->toArray())->toEqual($ba->toArray())
			->and($ab->count())->toBe(163)
			->and($ab->minUs())->toBe(10.0)
			->and($ab->maxUs())->toBe(12000.0);

		$restored = LatencyHistogram::fromArray($ab->toArray());
		expect($restored->toArray())->toEqual($ab->toArray())
			->and($restored->p95Us())->toBe($ab->p95Us())
			->and(LatencyHistogram::fromArray(['buckets' => 'bad'])->count())->toBe(0)
			->and(LatencyHistogram::fromArray(['buckets' => [-1 => 5, 999 => 5, 5 => 0]])->count())->toBe(0);
	});
});

describe('RpcLatencyMetrics, RecoveryMetrics, and StallMetrics', function () {
	it('accumulates and merges read/write/metadata RPC latency channels across nodes', function () {
		$m1 = new RpcLatencyMetrics();
		expect($m1->isEmpty())->toBeTrue();
		$m1->recordReadUs(250.0, 100);
		$m1->recordWriteMs(1.5, 50);
		$m1->recordMetadataUs(80.0, 200);

		$m2 = new RpcLatencyMetrics();
		$m2->recordReadMs(4.0, 10);
		$m2->recordWriteUs(400.0, 50);
		$m2->recordMetadataMs(2.0, 20);

		$merged = RpcLatencyMetrics::mergeAll([$m1, $m2, null]);
		$arr    = $merged->toArray();

		expect($merged->isEmpty())->toBeFalse()
			->and($arr['read']['count'])->toBe(110)
			->and($arr['write']['count'])->toBe(100)
			->and($arr['metadata']['count'])->toBe(220)
			->and($arr['read']['p99_us'])->toBeGreaterThan($arr['read']['p50_us'] ?? 0.0)
			->and($m1->merge($m2)->toArray())->toEqual($arr);
	});

	it('computes LNet stall rates safely across normal growth and counter resets', function () {
		$prev = new StallMetrics(lnetDrops: 100.0, lnetRetransmits: 50.0);
		$curr = new StallMetrics(lnetDrops: 400.0, lnetRetransmits: 110.0);

		$withRates = $curr->withLnetRates($prev, 300);
		expect($withRates->lnetDropsRate)->toBe(1.0)
			->and($withRates->lnetRetransmitsRate)->toBe(0.2)
			->and($withRates->isEmpty())->toBeFalse();

		// Counter reset or invalid elapsed interval yields null rates.
		$reset = (new StallMetrics(lnetDrops: 50.0, lnetRetransmits: 10.0))->withLnetRates($curr, 300);
		expect($reset->lnetDropsRate)->toBeNull()
			->and($reset->lnetRetransmitsRate)->toBeNull()
			->and($curr->withLnetRates($prev, 0)->lnetDropsRate)->toBeNull()
			->and((new StallMetrics())->isEmpty())->toBeTrue();

		$agg = StallMetrics::aggregate([
			$withRates,
			new StallMetrics(lnetDrops: 50.0, lnetRetransmits: 10.0, lnetDropsRate: 0.5, lnetRetransmitsRate: 0.1, nsdQueueDepth: 12.0, pagepoolHitPct: 96.0, chunkQueueDepth: 8.0, lockWaiters: 3.0),
			new StallMetrics(nsdQueueDepth: 28.0, pagepoolHitPct: 92.0, chunkQueueDepth: 19.0, lockWaiters: 7.0),
		]);

		expect($agg->toArray())->toBe([
			'lnet_drops'            => 450.0,
			'lnet_retransmits'      => 120.0,
			'lnet_drops_rate'       => 1.5,
			'lnet_retransmits_rate' => 0.3,
			'nsd_queue_depth'       => 28.0,
			'pagepool_hit_pct'      => 94.0,
			'chunk_queue_depth'     => 19.0,
			'lock_waiters'          => 10.0,
		]);
	});
});

describe('Enterprise backend parsers', function () {
	it('parses Lustre RPC latencies, client evictions, recovery states, and LNet drops/retransmits', function () {
		$payload = implode("\n", [
			LustreBackend::PROTOCOL,
			'== version',
			'2.17.0',
			'== osd-ldiskfs/scratch-OST0000/kbytestotal',
			'1000000000',
			'== obdfilter/scratch-OST0000/degraded',
			'0',
			'== obdfilter/scratch-OST0000/stats',
			'snapshot_time 1731600000.000000001 secs.nsecs',
			'start_time 1731000000.000000001 secs.nsecs',
			'read_bytes 100 samples [bytes] 4096 1048576 4096000 1000000',
			'write_bytes 50 samples [bytes] 4096 1048576 2048000 1000000',
			'ost_read 100 samples [usecs] 120 8500 45000 2000000',
			'ost_write 50 samples [usecs] 200 12000 35000 1500000',
			'statfs 20 samples [usecs] 5 40 200 1000',
			'== obdfilter/scratch-OST0000/recovery_status',
			'status: RECOVERING',
			'recovery_duration: 42.5',
			'evicted_clients: 7',
			'== obdfilter/scratch-OST0000/rpc_latency',
			'op=read latency_us=950 count=5',
			'op=write latency_ms=2.2 count=2',
			'== mdt/scratch-MDT0000/md_stats',
			'snapshot_time 1731600000.000000001 secs.nsecs',
			'open 500 samples [usecs] 10 900 15000 500000',
			'== mdt/scratch-MDT0000/recovery_status',
			'status: WAITING_FOR_CLIENTS',
			'recovery_duration: 18',
			'stale_clients: 3',
			'== lnet/stats',
			'lnet_drops=125 lnet_retransmits=14',
			LustreBackend::END,
		]) . "\n";

		$report = (new LustreBackend())->parse($payload);

		expect($report->usable())->toBeTrue()
			->and($report->unexpectedSections)->toBe(0)
			->and($report->stalls?->lnetDrops)->toBe(125.0)
			->and($report->stalls?->lnetRetransmits)->toBe(14.0)
			->and($report->recovery?->toArray())->toBe([
				'evicted_clients'         => 10,
				'recovery_active_targets' => 2,
				'recovery_duration_s'     => 42.5,
			])
			->and($report->rpcLatency?->read->count())->toBe(105)
			->and($report->rpcLatency?->write->count())->toBe(52)
			->and($report->rpcLatency?->metadata->count())->toBe(520)
			->and($report->rpcLatency?->read->p95Us())->toBeGreaterThan(0.0);

		// Also verify standard 11-column /proc/sys/lnet/stats format + evicted_clients file.
		$procPayload = implode("\n", [
			LustreBackend::PROTOCOL,
			'== obdfilter/scratch-OST0000/evicted_clients',
			'4',
			'== lnet/stats',
			'0 100 0 9 50000 49000 0 33 1048576 1048576 0',
			LustreBackend::END,
		]) . "\n";

		$procReport = (new LustreBackend())->parse($procPayload);
		expect($procReport->stalls?->lnetDrops)->toBe(33.0)
			->and($procReport->stalls?->lnetRetransmits)->toBe(9.0)
			->and($procReport->recovery?->evictedClients)->toBe(4);

		// Multi-node cluster recovery merge.
		$mergedRec = RecoveryMetrics::mergeAll([$report, $procReport]);
		expect($mergedRec->evictedClients)->toBe(14)
			->and($mergedRec->recoveryActiveTargets)->toBe(2)
			->and($mergedRec->recoveryDurationSeconds)->toBe(42.5);
	});

	it('parses GPFS NSD queue depth, pagepool hit ratio, evictions, recovery, and RPC latency', function () {
		$payload = implode("\n", [
			GpfsBackend::PROTOCOL,
			'== version',
			'scale: 5.2.2',
			'== mmdf',
			'pool fs=gpfs01 type=ost index=0 kbytes_total=1000000 kbytes_free=400000 kbytes_avail=400000 state=recovering recovery_duration_s=65.5',
			'pool fs=gpfs01 type=ost index=1 kbytes_total=1000000 kbytes_free=600000 kbytes_avail=600000 state=up',
			'== nsd_stats',
			'nsd fs=gpfs01 index=0 nsd_queue_depth=48 pagepool_hit_pct=87.5 evicted_clients=3',
			'nsd fs=gpfs01 index=1 queue_depth=12 pagepool_hits=950 pagepool_misses=50',
			'== rpc_latency',
			'read_us=420 write_us=980 meta_us=150',
			'read_ms=1.2 write_ms=3.4 meta_ms=0.5 count=10',
			GpfsBackend::END,
		]) . "\n";

		$report = (new GpfsBackend())->parse($payload);
		$ost0   = $report->targets['gpfs01-OST0000'];
		$ost1   = $report->targets['gpfs01-OST0001'];

		expect($report->usable())->toBeTrue()
			->and($report->unexpectedSections)->toBe(0)
			->and($ost0->values['nsd_queue_depth'])->toBe(48.0)
			->and($ost0->values['pagepool_hit_pct'])->toBe(87.5)
			->and($ost0->values['evicted_clients'])->toBe(3.0)
			->and($ost0->values['recovery_duration_s'])->toBe(65.5)
			->and($ost1->values['nsd_queue_depth'])->toBe(12.0)
			->and($ost1->values['pagepool_hit_pct'])->toBe(95.0)
			->and($report->stalls?->nsdQueueDepth)->toBe(48.0)
			->and($report->stalls?->pagepoolHitPct)->toBe(91.25)
			->and($report->recovery?->toArray())->toBe([
				'evicted_clients'         => 3,
				'recovery_active_targets' => 1,
				'recovery_duration_s'     => 65.5,
			])
			->and($report->rpcLatency?->read->count())->toBe(11)
			->and($report->rpcLatency?->write->count())->toBe(11)
			->and($report->rpcLatency?->metadata->count())->toBe(11);
	});

	it('parses BeeGFS chunkserver disk queue depth, lock contention waiters, evictions, recovery, and RPC latency', function () {
		$payload = implode("\n", [
			BeegfsBackend::PROTOCOL,
			'== version',
			'8.1.0',
			'== storage_targets',
			'target fs=bgfs1 id=1 reachability=online consistency=GOOD disk_space_total=10737418240 disk_space_free=2147483648 chunk_queue_depth=34 lock_waiters=6 evicted_clients=2 read_latency_us=310 write_latency_us=790',
			'target fs=bgfs1 id=2 reachability=probably-offline consistency=NEEDS_RESYNC disk_space_total=10737418240 disk_space_free=5368709120 chunk_queue_depth=14 lock_waiters=4 recovery_duration_s=120.0',
			'== meta_targets',
			'target fs=bgfs1 id=10 reachability=online consistency=GOOD disk_space_total=2147483648 disk_space_free=1073741824 lock_waiters=9 evicted_clients=1 meta_latency_us=95',
			'== rpc_latency',
			'op=metadata latency_us=180 count=20',
			BeegfsBackend::END,
		]) . "\n";

		$report = (new BeegfsBackend())->parse($payload);
		$ost1   = $report->targets['bgfs1-OST0001'];
		$ost2   = $report->targets['bgfs1-OST0002'];
		$mdt    = $report->targets['bgfs1-MDT000a'];

		expect($report->usable())->toBeTrue()
			->and($report->unexpectedSections)->toBe(0)
			->and($ost1->values['chunk_queue_depth'])->toBe(34.0)
			->and($ost1->values['lock_waiters'])->toBe(6.0)
			->and($ost1->values['evicted_clients'])->toBe(2.0)
			->and($ost2->recovery)->toBe('NEEDS_RESYNC')
			->and($ost2->values['recovery_duration_s'])->toBe(120.0)
			->and($mdt->values['lock_waiters'])->toBe(9.0)
			->and($report->stalls?->chunkQueueDepth)->toBe(34.0)
			->and($report->stalls?->lockWaiters)->toBe(19.0)
			->and($report->recovery?->toArray())->toBe([
				'evicted_clients'         => 3,
				'recovery_active_targets' => 1,
				'recovery_duration_s'     => 120.0,
			])
			->and($report->rpcLatency?->read->count())->toBe(1)
			->and($report->rpcLatency?->write->count())->toBe(1)
			->and($report->rpcLatency?->metadata->count())->toBe(21);
	});
});

describe('Optimized hot-path invariants', function () {
	it('breaks ties deterministically in TopKTracker and RollupPolicy and streams BatchUpserter identically', function () {
		$details = [
			['name' => 'exa-OST0003', 'type' => 'ost', 'presence' => 'present', 'condition' => 'in_service', 'fill_pct' => 90.0, 'inode_pct' => 10.0, 'headroom_pct' => 10.0, 'kbytes_avail' => 100.0, 'status_flags' => []],
			['name' => 'exa-OST0001', 'type' => 'ost', 'presence' => 'present', 'condition' => 'in_service', 'fill_pct' => 90.0, 'inode_pct' => 10.0, 'headroom_pct' => 10.0, 'kbytes_avail' => 100.0, 'status_flags' => []],
			['name' => 'exa-OST0002', 'type' => 'ost', 'presence' => 'present', 'condition' => 'in_service', 'fill_pct' => 90.0, 'inode_pct' => 10.0, 'headroom_pct' => 10.0, 'kbytes_avail' => 100.0, 'status_flags' => []],
		];

		$top = TopKTracker::topSkewedTargets($details, 50.0, 2);
		expect(array_column($top, 'name'))->toBe(['exa-OST0001', 'exa-OST0002']);

		$zeroCap = (new RollupPolicy([], 80.0, 15.0, 0))->selectPromotedTargets($details, 50.0);
		expect($zeroCap['promoted_count'])->toBe(0)
			->and($zeroCap['capped'])->toBeTrue();

		$twoCap = (new RollupPolicy([], 80.0, 15.0, 2))->selectPromotedTargets($details, 50.0);
		expect(array_column($twoCap['promoted'], 'name'))->toBe(['exa-OST0001', 'exa-OST0002']);

		// Verify 10 EiB (> PHP_INT_MAX) decimal long-division fallback in bytesToKibInt().
		// 10 EiB = 10 * 1024^6 = 11,529,215,046,068,469,760 bytes -> 11,258,999,068,426,240 KiB.
		expect(FramedReportParser::bytesToKibInt('11529215046068469760'))->toBe(11258999068426240)
			->and(FramedReportParser::bytesToKibInt('0000'))->toBe(0)
			->and(FramedReportParser::bytesToKibInt('512'))->toBe(0);

		// Verify BatchUpserter::streamUpsertBatches matches buildUpsertBatches identically.
		$rows     = [[1, 'a'], [2, 'b'], [3, 'c']];
		$built    = BatchUpserter::buildUpsertBatches('plugin_pfs_test', ['id', 'val'], $rows, ['val'], 2);
		$streamed = iterator_to_array(BatchUpserter::streamUpsertBatches('plugin_pfs_test', ['id', 'val'], $rows, ['val'], 2));
		expect($streamed)->toBe($built)
			->and($built)->toHaveCount(2)
			->and($built[0]['row_count'])->toBe(2)
			->and($built[1]['row_count'])->toBe(1);
	});
});
