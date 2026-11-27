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

namespace Somethingwithproof\Pfs;

/**
 * Computes a filesystem's summary from the reader's cache (REQ-CAP-003,
 * REQ-CAP-004, REQ-OST-003, REQ-MDT-003, ADR 0011).
 *
 * Targets an administrator ignored are left out entirely. Of the rest, a
 * target contributes to fill when it is present and its capacity is known
 * and was observed within one interval of the newest observation; every
 * other target is counted in targets_missing. Fill statistics are over the
 * contributing OSTs. Sums that would be partial are unknown instead:
 * capacity totals need every OST, and the aggregate counters need every OST
 * (or MDT) present with a current, continuous value.
 *
 * A counter Lustre left out of a target's stats section has not counted
 * since its start time. In a sum it counts as zero, but only for a target
 * that is present, in service, and reported other values in the same read;
 * a missing, stale, or failed target still makes the sum unknown (ADR 0013).
 * The target's own value stays unknown.
 *
 * An aggregate counter is a sum of counters, so its member set must not
 * change between two published values: a target joining would add its whole
 * history to the sum. Each counter's member set is kept as a hash, and a
 * change publishes unknown for that run. If Cacti misses the poll of that
 * unknown value the change is still drawn as one large interval; that is the
 * sampling alignment limit in the plan.
 *
 * Each aggregate counter also has a per-second rate, published as a gauge
 * for Thold (ADR 0012): the change in the sum since the previous summary,
 * over the time between the two reads. A rate is known only when both sums
 * are known, have the same member set, were each read in one run (every
 * summed value shares its read time), are at most two intervals apart, and
 * the sum did not go down. So a reset, a missing or stale target, a member
 * change, and the first read all give unknown, as the DERIVE value does. A
 * reset of some targets gives three unknown runs: the reset leaves them out
 * of the sum, the next run puts them back (a member change), and the run
 * after that has no known previous sum. When every target resets at once
 * the sum has no members to compare, so it is two.
 */
final class SummaryCalculator {
	/** Ost counters constant. */
	private const OST_COUNTERS = ['read_bytes', 'write_bytes'];

	/**
	 * @param list<SummaryTarget> $targets
	 */
	public static function calculate(array $targets, ?Summary $previous, int $interval, int $now): Summary {
		$considered = array_values(array_filter($targets, static fn (SummaryTarget $t): bool => $t->review !== Review::Ignored));
		$interval   = max(1, $interval);
		$newest     = 0;

		foreach ($considered as $target) {
			foreach ($target->values as $observation) {
				$newest = max($newest, $observation->observedAt);
			}
		}

		$current = static fn (?Observation $o): bool => $o !== null && $o->observedAt > 0 && $newest - $o->observedAt < $interval;

		$osts         = [];
		$mdts         = [];
		$fill         = [];
		$inodes       = [];
		$contributors = 0;
		$oldest       = 0;
		$kbTotal      = 0.0;
		$kbAvail      = 0.0;
		$allOsts      = true;

		foreach ($considered as $target) {
			$isOst = $target->id->type === TargetType::Ost;

			if ($isOst) {
				$osts[] = $target;
			} else {
				$mdts[] = $target;
			}

			$total = $target->value('kbytes_total');
			$space = UsedPercent::space($total?->value, $target->value('kbytes_free')?->value, $target->value('kbytes_avail')?->value);
			$ok    = $target->presence === Presence::Present && $space !== null && $current($total);

			if (!$ok) {
				$allOsts = $allOsts && !$isOst;

				continue;
			}

			$contributors++;
			$oldest = $oldest === 0 ? (int) $total?->observedAt : min($oldest, (int) $total?->observedAt);
			$inode  = UsedPercent::inodes($target->value('files_total')?->value, $target->value('files_free')?->value);

			if ($inode !== null) {
				$inodes[] = $inode;
			}

			if ($isOst) {
				$fill[]   = $space;
				$kbTotal += (float) $total?->value;
				$kbAvail += (float) $target->value('kbytes_avail')?->value;
			}
		}

		$values = array_fill_keys(array_merge(Summary::GAUGES, Summary::COUNTERS, Summary::RATES), null);

		if ($fill !== []) {
			$values['fill_max_pct']    = max($fill);
			$values['fill_min_pct']    = min($fill);
			$values['fill_mean_pct']   = round(array_sum($fill) / count($fill), 2);
			$values['fill_spread_pct'] = max($fill) - min($fill);
		}

		if ($inodes !== []) {
			$values['inode_max_pct'] = max($inodes);
		}

		if ($osts !== [] && $allOsts) {
			$values['kbytes_total'] = $kbTotal;
			$values['kbytes_avail'] = $kbAvail;
		}

		$values['targets_total']   = (float) count($considered);
		$values['targets_missing'] = (float) (count($considered) - $contributors);

		$known = array_filter($considered, static fn (SummaryTarget $t): bool => $t->condition !== Condition::Unknown);

		if ($known !== []) {
			$values['targets_unhealthy'] = (float) count(array_filter($known, static fn (SummaryTarget $t): bool => $t->condition !== Condition::InService));
		}

		$members = [];
		$at      = [];
		$summed  = PHP_INT_MAX;

		// ost_contributors counts the OSTs behind every OST counter, so it
		// never claims more targets than a drawn sum holds.
		foreach (self::OST_COUNTERS as $counter) {
			$sum    = self::sum($osts, [$counter], $current);
			$summed = min($summed, $sum['contributors']);

			[$values[$counter], $members[$counter]] = self::guard($sum, $previous?->members[$counter] ?? null);
			$at[$counter] = $values[$counter] === null ? 0 : (int) $sum['at'];
		}

		$values['ost_contributors'] = (float) $summed;

		$mdOps = [];

		foreach ($mdts as $mdt) {
			foreach (array_keys($mdt->values) as $metric) {
				if (str_starts_with($metric, 'md_')) {
					$mdOps[$metric] = true;
				}
			}
		}

		$sum                        = self::sum($mdts, array_keys($mdOps), $current);
		$values['mdt_contributors'] = (float) $sum['contributors'];
		[$values['md_ops'], $members['md_ops']] = self::guard($sum, $previous?->members['md_ops'] ?? null);
		$at['md_ops'] = $values['md_ops'] === null ? 0 : (int) $sum['at'];

		foreach (Summary::COUNTERS as $counter) {
			$values[$counter . Summary::RATE_SUFFIX] = self::rate($counter, $values[$counter], $members[$counter], $at[$counter], $previous, $interval);
		}

		return new Summary($values, $members, $oldest, $now, $at);
	}

	/**
	 * Sum one or more counters over a set of targets. The sum is complete only
	 * when every target is present and contributes at least one current,
	 * continuous value; values that are unknown on this release are left out
	 * and change the member set instead.
	 *
	 * @param list<SummaryTarget>             $targets
	 * @param list<string>                    $metrics
	 * @param \Closure(?Observation): bool    $current
	 *
	 * @return array{value: ?float, members: list<string>, contributors: int, at: int} at is the read time every summed value shares, or 0
	 */
	private static function sum(array $targets, array $metrics, \Closure $current): array {
		$total        = 0.0;
		$members      = [];
		$contributors = 0;
		$complete     = $targets !== [];
		$times        = [];

		foreach ($targets as $target) {
			$counted = false;

			foreach ($metrics as $metric) {
				$observation = $target->value($metric);
				$value       = self::absentAsZero($target, $observation, $current) ? 0.0 : $observation?->published();

				if ($value === null || !$current($observation)) {
					continue;
				}

				$total    += $value;
				$members[] = $target->id->name() . ':' . $metric;
				$times[$observation->observedAt] = true;
				$counted   = true;
			}

			$contributors += $counted ? 1 : 0;
			$complete      = $complete && $counted && $target->presence === Presence::Present;
		}

		// A target with a counter that is known but not current or not
		// continuous (a reset) leaves the sum incomplete, even when its other
		// counters count.
		foreach ($targets as $target) {
			foreach ($metrics as $metric) {
				$observation = $target->value($metric);

				if ($observation !== null && $observation->value !== null && ($observation->published() === null || !$current($observation))) {
					$complete = false;
				}
			}
		}

		sort($members);

		return ['value' => $complete ? $total : null, 'members' => $members, 'contributors' => $contributors, 'at' => count($times) === 1 ? (int) array_key_first($times) : 0];
	}

	/**
	 * Whether an absent counter counts as zero: the observation says Lustre
	 * left the line out, it is current, and the target is present, in
	 * service, and has a known value from the same read.
	 *
	 * @param \Closure(?Observation): bool $current
	 */
	private static function absentAsZero(SummaryTarget $target, ?Observation $observation, \Closure $current): bool {
		if ($observation === null || $observation->continuity !== Continuity::Absent || !$current($observation)) {
			return false;
		}

		if ($target->presence !== Presence::Present || $target->condition !== Condition::InService) {
			return false;
		}

		foreach ($target->values as $other) {
			if ($other->value !== null && $other->observedAt === $observation->observedAt) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The per-second rate of one aggregate counter since the previous summary,
	 * or null when it cannot be trusted (see the class comment).
	 */
	private static function rate(string $counter, ?float $value, string $members, int $at, ?Summary $previous, int $interval): ?float {
		$before   = $previous?->value($counter);
		$beforeAt = $previous?->counterAt[$counter] ?? 0;

		if ($value === null || $before === null || $at <= 0 || $beforeAt <= 0 || $members === '' || ($previous?->members[$counter] ?? '') !== $members) {
			return null;
		}

		$elapsed = $at - $beforeAt;

		if ($elapsed <= 0 || $elapsed > 2 * $interval || $value < $before) {
			return null;
		}

		return ($value - $before) / $elapsed;
	}

	/**
	 * @param array{value: ?float, members: list<string>, contributors: int, at: int} $sum
	 *
	 * @return array{0: ?float, 1: string} the value to publish and the member hash to keep
	 */
	private static function guard(array $sum, ?string $previous): array {
		$hash = $sum['members'] === [] ? '' : sha1(implode("\n", $sum['members']));

		if ($sum['value'] === null) {
			return [null, $hash];
		}

		if ($previous !== null && $previous !== '' && $previous !== $hash) {
			return [null, $hash];
		}

		return [$sum['value'], $hash];
	}
}
