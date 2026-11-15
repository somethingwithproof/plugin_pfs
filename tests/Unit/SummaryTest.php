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

/*
 * The values Cacti polls in milestone 3: used percentages, number output,
 * the per-filesystem summary the reader computes, and the summary input's
 * answer.
 */

use Somethingwithproof\Pfs\Condition;
use Somethingwithproof\Pfs\Continuity;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\Number;
use Somethingwithproof\Pfs\Observation;
use Somethingwithproof\Pfs\Presence;
use Somethingwithproof\Pfs\Review;
use Somethingwithproof\Pfs\Summary;
use Somethingwithproof\Pfs\SummaryCalculator;
use Somethingwithproof\Pfs\SummaryScript;
use Somethingwithproof\Pfs\SummaryTarget;
use Somethingwithproof\Pfs\TargetId;
use Somethingwithproof\Pfs\UsedPercent;

const SUM_T = 1800000000;

/**
 * @param array<string, float|null> $values metric => value, observed at $at
 */
function sum_target(string $name, array $values, int $at = SUM_T, Presence $presence = Presence::Present, Condition $condition = Condition::InService, array $reset = [], Review $review = Review::Accepted): SummaryTarget {
	$observations = [];

	foreach ($values as $metric => $value) {
		$observations[$metric] = new Observation(1, $metric, $value, $at, $at, 1, 300, in_array($metric, $reset, true) ? Continuity::Reset : Continuity::Continuous);
	}

	return new SummaryTarget(TargetId::parse($name), $review, $presence, $condition, $observations);
}

/**
 * The target with $metric recorded as a counter line Lustre left out of a
 * stats section in the same read as its other values.
 */
function sum_absent(SummaryTarget $target, string $metric): SummaryTarget {
	$at     = max(array_map(static fn (Observation $o): int => $o->observedAt, $target->values));
	$values = $target->values;

	$values[$metric] = new Observation(1, $metric, null, $at, $at, 1, 300, Continuity::Absent);

	return new SummaryTarget($target->id, $target->review, $target->presence, $target->condition, $values);
}

function sum_ost(string $name, float $used, float $read = 1000.0, float $write = 500.0, array $extra = []): array {
	return ['kbytes_total' => 1000.0, 'kbytes_free' => 1000.0 - $used, 'kbytes_avail' => 1000.0 - $used, 'files_total' => 100.0, 'files_free' => 90.0, 'read_bytes' => $read, 'write_bytes' => $write] + $extra;
}

describe('used percentage', function () {
	it('follows lfs df: used over used plus available, rounded up', function () {
		// 1000 total, 400 free, 380 available: used 600, 600 / 980 = 61.2 %.
		expect(UsedPercent::space(1000.0, 400.0, 380.0))->toBe(62.0)
			->and(UsedPercent::space(1000.0, 0.0, 0.0))->toBe(100.0)
			->and(UsedPercent::space(1000.0, 1000.0, 1000.0))->toBe(0.0)
			->and(UsedPercent::inodes(65536000.0, 60000000.0))->toBe(9.0);
	});

	it('is unknown for missing or inconsistent values', function () {
		expect(UsedPercent::space(null, 1.0, 1.0))->toBeNull()
			->and(UsedPercent::space(100.0, 200.0, 100.0))->toBeNull()
			->and(UsedPercent::space(100.0, -1.0, 100.0))->toBeNull()
			->and(UsedPercent::inodes(100.0, null))->toBeNull();
	});
});

describe('number output', function () {
	it('prints plain decimals and U for anything else', function () {
		expect(Number::format(1234567890123.0))->toBe('1234567890123')
			->and(Number::format(1.5))->toBe('1.5')
			->and(Number::format(0.0))->toBe('0')
			->and(Number::format(null))->toBe('U')
			->and(Number::format(-1.0))->toBe('U')
			->and(Number::format(INF))->toBe('U')
			->and(Number::format(NAN))->toBe('U')
			->and(Number::format(1e20))->not->toContain('E')
			->and(Number::format(1e20))->not->toContain('e');
	});
});

describe('summary', function () {
	it('summarises fill, capacity, and throughput over every OST', function () {
		$s = SummaryCalculator::calculate([
			sum_target('scratch-OST0000', sum_ost('scratch-OST0000', 200.0)),
			sum_target('scratch-OST0001', sum_ost('scratch-OST0001', 800.0, 3000.0, 1500.0)),
			sum_target('scratch-MDT0000', ['kbytes_total' => 100.0, 'kbytes_free' => 50.0, 'kbytes_avail' => 50.0, 'files_total' => 100.0, 'files_free' => 40.0, 'md_open' => 7.0, 'md_close' => 3.0]),
		], null, 300, SUM_T);

		expect($s->value('fill_max_pct'))->toBe(80.0)
			->and($s->value('fill_min_pct'))->toBe(20.0)
			->and($s->value('fill_mean_pct'))->toBe(50.0)
			->and($s->value('fill_spread_pct'))->toBe(60.0)
			->and($s->value('inode_max_pct'))->toBe(60.0)
			->and($s->value('kbytes_total'))->toBe(2000.0)
			->and($s->value('kbytes_avail'))->toBe(1000.0)
			->and($s->value('read_bytes'))->toBe(4000.0)
			->and($s->value('write_bytes'))->toBe(2000.0)
			->and($s->value('md_ops'))->toBe(10.0)
			->and($s->value('targets_total'))->toBe(3.0)
			->and($s->value('targets_missing'))->toBe(0.0)
			->and($s->value('targets_unhealthy'))->toBe(0.0)
			->and($s->value('ost_contributors'))->toBe(2.0)
			->and($s->value('mdt_contributors'))->toBe(1.0)
			->and($s->observedAt)->toBe(SUM_T);
	});

	it('leaves a missing or stale OST out of fill and makes sums that need it unknown', function () {
		$s = SummaryCalculator::calculate([
			sum_target('scratch-OST0000', sum_ost('scratch-OST0000', 200.0)),
			sum_target('scratch-OST0001', sum_ost('scratch-OST0001', 900.0), SUM_T, Presence::Missing),
			sum_target('scratch-OST0002', sum_ost('scratch-OST0002', 950.0), SUM_T - 600),
		], null, 300, SUM_T);

		expect($s->value('fill_max_pct'))->toBe(20.0)
			->and($s->value('targets_missing'))->toBe(2.0)
			->and($s->value('kbytes_total'))->toBeNull()
			->and($s->value('read_bytes'))->toBeNull()
			->and($s->value('targets_total'))->toBe(3.0);
	});

	it('publishes unknown for a counter sum when any member reset', function () {
		$s = SummaryCalculator::calculate([
			sum_target('scratch-OST0000', sum_ost('scratch-OST0000', 200.0)),
			sum_target('scratch-OST0001', sum_ost('scratch-OST0001', 300.0), SUM_T, Presence::Present, Condition::InService, ['read_bytes']),
		], null, 300, SUM_T);

		expect($s->value('read_bytes'))->toBeNull()
			->and($s->value('write_bytes'))->toBe(1000.0)
			->and($s->value('fill_max_pct'))->toBe(30.0);
	});

	it('keeps a counter with no observation or a rejected line unknown, so a sum that needs it is unknown', function () {
		$ost1 = sum_ost('scratch-OST0001', 300.0);
		unset($ost1['write_bytes']);
		$rejected = sum_ost('scratch-OST0001', 300.0, 1000.0, 0.0);
		$rejected['write_bytes'] = null;

		foreach ([$ost1, $rejected] as $values) {
			$s = SummaryCalculator::calculate([
				sum_target('scratch-OST0000', sum_ost('scratch-OST0000', 200.0)),
				sum_target('scratch-OST0001', $values),
			], null, 300, SUM_T);

			expect($s->value('write_bytes'))->toBeNull()
				->and($s->value('read_bytes'))->toBe(2000.0)
				->and($s->value('ost_contributors'))->toBe(1.0);
		}
	});

	it('counts an absent counter line as zero for a present, in-service OST that reported its other values', function () {
		$s = SummaryCalculator::calculate([
			sum_target('scratch-OST0000', sum_ost('scratch-OST0000', 200.0)),
			sum_absent(sum_target('scratch-OST0001', sum_ost('scratch-OST0001', 300.0)), 'write_bytes'),
		], null, 300, SUM_T);

		expect($s->value('write_bytes'))->toBe(500.0)
			->and($s->value('read_bytes'))->toBe(2000.0)
			->and($s->value('ost_contributors'))->toBe(2.0)
			->and($s->members['write_bytes'])->toBe(sha1("scratch-OST0000:write_bytes\nscratch-OST0001:write_bytes"));
	});

	it('keeps the sum unknown when the OST with an absent line is missing, unhealthy, stale, or reported nothing else', function () {
		$healthy = sum_target('scratch-OST0000', sum_ost('scratch-OST0000', 200.0));
		$cases   = [
			'missing'   => sum_absent(sum_target('scratch-OST0001', sum_ost('scratch-OST0001', 300.0), SUM_T, Presence::Missing), 'write_bytes'),
			'degraded'  => sum_absent(sum_target('scratch-OST0001', sum_ost('scratch-OST0001', 300.0), SUM_T, Presence::Present, Condition::Degraded), 'write_bytes'),
			'unknown'   => sum_absent(sum_target('scratch-OST0001', sum_ost('scratch-OST0001', 300.0), SUM_T, Presence::Present, Condition::Unknown), 'write_bytes'),
			'stale'     => sum_absent(sum_target('scratch-OST0001', sum_ost('scratch-OST0001', 300.0), SUM_T - 600), 'write_bytes'),
			'read lost' => sum_absent(sum_target('scratch-OST0001', array_map(static fn (): ?float => null, sum_ost('scratch-OST0001', 300.0))), 'write_bytes'),
		];

		foreach ($cases as $case => $target) {
			$s = SummaryCalculator::calculate([$healthy, $target], null, 300, SUM_T);

			expect($s->value('write_bytes'))->toBeNull($case);
		}
	});

	it('publishes unknown once when the members of a counter sum change', function () {
		$two   = [sum_target('scratch-OST0000', sum_ost('scratch-OST0000', 200.0)), sum_target('scratch-OST0001', sum_ost('scratch-OST0001', 300.0))];
		$first = SummaryCalculator::calculate([$two[0]], null, 300, SUM_T);
		$grown = SummaryCalculator::calculate($two, $first, 300, SUM_T + 300);
		$next  = SummaryCalculator::calculate($two, $grown, 300, SUM_T + 600);

		expect($first->value('read_bytes'))->toBe(1000.0)
			->and($grown->value('read_bytes'))->toBeNull()
			->and($grown->value('fill_max_pct'))->toBe(30.0)
			->and($next->value('read_bytes'))->toBe(2000.0);
	});

	it('ignores ignored targets and counts unhealthy ones among those whose condition is known', function () {
		$s = SummaryCalculator::calculate([
			sum_target('scratch-OST0000', sum_ost('scratch-OST0000', 200.0)),
			sum_target('scratch-OST0001', sum_ost('scratch-OST0001', 990.0), SUM_T, Presence::Present, Condition::Degraded),
			sum_target('scratch-OST0002', sum_ost('scratch-OST0002', 999.0), SUM_T, Presence::Present, Condition::InService, [], Review::Ignored),
		], null, 300, SUM_T);

		expect($s->value('targets_total'))->toBe(2.0)
			->and($s->value('targets_unhealthy'))->toBe(1.0)
			->and($s->value('fill_max_pct'))->toBe(99.0);
	});

	it('has no values but the counts for a filesystem with no targets', function () {
		$s = SummaryCalculator::calculate([], null, 300, SUM_T);

		expect($s->value('fill_max_pct'))->toBeNull()
			->and($s->value('read_bytes'))->toBeNull()
			->and($s->value('targets_total'))->toBe(0.0);
	});
});

describe('summary input', function () {
	$summary = fn (int $at): Summary => SummaryCalculator::calculate([sum_target('scratch-OST0000', sum_ost('scratch-OST0000', 200.0), $at)], null, 300, $at);

	it('serves every value as a name:value pair in one answer', function () use ($summary) {
		$answer = SummaryScript::format($summary(SUM_T), 300, new FixedClock(SUM_T + 10));
		$pairs  = [];

		foreach (explode(' ', $answer) as $pair) {
			[$name, $value] = explode(':', $pair);
			$pairs[$name]   = $value;
		}

		expect(array_keys($pairs))->toBe(array_merge(Summary::GAUGES, Summary::COUNTERS, [Summary::AGE]))
			->and($pairs['fill_max_pct'])->toBe('20')
			->and($pairs['read_bytes'])->toBe('1000')
			->and($pairs['age'])->toBe('10');
	});

	it('serves gauges but not counters while delayed, and nothing when stale or never computed', function () use ($summary) {
		$delayed = SummaryScript::format($summary(SUM_T), 300, new FixedClock(SUM_T + 600));
		$stale   = SummaryScript::format($summary(SUM_T), 300, new FixedClock(SUM_T + 1000));
		$never   = SummaryScript::format(null, 300, new FixedClock(SUM_T));

		expect($delayed)->toContain('fill_max_pct:20')->toContain('read_bytes:U')
			->and($stale)->toContain('fill_max_pct:U')->toContain('targets_total:U')
			->and($never)->toContain('fill_max_pct:U')->toContain('age:U');
	});

	it('keeps every summary name a valid RRD data source name', function () {
		foreach (Summary::names() as $name) {
			expect($name)->toMatch('/^[a-zA-Z0-9_]{1,19}$/');
		}
	});
});
