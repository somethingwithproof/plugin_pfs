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
	 * Compute filesystem-wide capacity, throughput, metadata, and rate summary
	 * from cached target observations in single-pass O(1) fill/inode memory.
	 *
	 * @param list<SummaryTarget> $targets
	 * @param ?Summary            $previous
	 * @param int                 $interval
	 * @param int                 $now
	 *
	 * @return Summary
	 */
	public static function calculate(array $targets, ?Summary $previous, int $interval, int $now): Summary {
		$interval = max(1, $interval);
		$newest   = 0;

		foreach ($targets as $target) {
			if ($target->review === Review::Ignored) {
				continue;
			}

			foreach ($target->values as $observation) {
				if ($observation->observedAt > $newest) {
					$newest = $observation->observedAt;
				}
			}
		}

		$osts            = [];
		$mdts            = [];
		$consideredCount = 0;
		$knownCondCount  = 0;
		$unhealthyCount  = 0;
		$contributors    = 0;
		$oldest          = 0;
		$kbTotal         = 0.0;
		$kbAvail         = 0.0;
		$allOsts         = true;
		$fillCount       = 0;
		$fillSum         = 0.0;
		$fillMin         = null;
		$fillMax         = null;
		$inodeMax        = null;

		foreach ($targets as $target) {
			if ($target->review === Review::Ignored) {
				continue;
			}

			$consideredCount++;

			if ($target->condition !== Condition::Unknown) {
				$knownCondCount++;

				if ($target->condition !== Condition::InService) {
					$unhealthyCount++;
				}
			}

			$isOst = $target->id->type === TargetType::Ost;

			if ($isOst) {
				$osts[] = $target;
			} else {
				$mdts[] = $target;
			}

			$total = $target->values['kbytes_total'] ?? null;
			$free  = $target->values['kbytes_free'] ?? null;
			$avail = $target->values['kbytes_avail'] ?? null;
			$space = UsedPercent::space($total?->value, $free?->value, $avail?->value);
			$ok    = $target->presence === Presence::Present
				&& $space !== null
				&& $total !== null
				&& $total->observedAt > 0
				&& ($newest - $total->observedAt) < $interval;

			if (!$ok) {
				if ($isOst) {
					$allOsts = false;
				}

				continue;
			}

			$contributors++;

			if ($oldest === 0 || $total->observedAt < $oldest) {
				$oldest = $total->observedAt;
			}

			$inode = UsedPercent::inodes(($target->values['files_total'] ?? null)?->value, ($target->values['files_free'] ?? null)?->value);

			if ($inode !== null && ($inodeMax === null || $inode > $inodeMax)) {
				$inodeMax = $inode;
			}

			if ($isOst) {
				$fillCount++;
				$fillSum += $space;

				if ($fillMax === null || $space > $fillMax) {
					$fillMax = $space;
				}

				if ($fillMin === null || $space < $fillMin) {
					$fillMin = $space;
				}

				$kbTotal += (float) $total->value;
				$kbAvail += $avail !== null ? (float) $avail->value : 0.0;
			}
		}

		$values = array_fill_keys(array_merge(Summary::GAUGES, Summary::COUNTERS, Summary::RATES), null);

		if ($fillCount > 0 && $fillMax !== null && $fillMin !== null) {
			$values['fill_max_pct']    = $fillMax;
			$values['fill_min_pct']    = $fillMin;
			$values['fill_mean_pct']   = round($fillSum / $fillCount, 2);
			$values['fill_spread_pct'] = $fillMax - $fillMin;
		}

		if ($inodeMax !== null) {
			$values['inode_max_pct'] = $inodeMax;
		}

		if ($osts !== [] && $allOsts) {
			$values['kbytes_total'] = $kbTotal;
			$values['kbytes_avail'] = $kbAvail;
		}

		$values['targets_total']   = (float) $consideredCount;
		$values['targets_missing'] = (float) ($consideredCount - $contributors);

		if ($knownCondCount > 0) {
			$values['targets_unhealthy'] = (float) $unhealthyCount;
		}

		$members = [];
		$at      = [];

		// Sum both OST counters in a single pass and reuse one sorted name list
		// when both counters share identical contributing OST sets (the common case).
		$ostSums = self::sumOstCounters($osts, $newest, $interval);
		$summed  = min($ostSums['read_bytes']['contributors'], $ostSums['write_bytes']['contributors']);

		foreach (self::OST_COUNTERS as $counter) {
			$sum = $ostSums[$counter];

			[$values[$counter], $members[$counter]] = self::guardHashed($sum['value'], $sum['hash'], $previous?->members[$counter] ?? null);
			$at[$counter]                           = $values[$counter] === null ? 0 : $sum['at'];
		}

		$values['ost_contributors'] = (float) $summed;

		$mdOps = [];

		foreach ($mdts as $mdt) {
			foreach ($mdt->values as $metric => $_obs) {
				if (str_starts_with($metric, 'md_')) {
					$mdOps[$metric] = true;
				}
			}
		}

		$sum                                    = self::sum($mdts, array_keys($mdOps), $newest, $interval);
		$values['mdt_contributors']             = (float) $sum['contributors'];
		[$values['md_ops'], $members['md_ops']] = self::guard($sum, $previous?->members['md_ops'] ?? null);
		$at['md_ops']                           = $values['md_ops'] === null ? 0 : (int) $sum['at'];

		foreach (Summary::COUNTERS as $counter) {
			$values[$counter . Summary::RATE_SUFFIX] = self::rate($counter, $values[$counter], $members[$counter], $at[$counter], $previous, $interval);
		}

		return new Summary($values, $members, $oldest, $now, $at);
	}

	/**
	 * Sum both OST counters (read_bytes and write_bytes) in a single pass over
	 * $osts, reusing one sorted target-name list when both counters share the
	 * same contributing member set and skipping sort() when names arrive sorted.
	 *
	 * @param list<SummaryTarget> $osts
	 * @param int                 $newest
	 * @param int                 $interval
	 *
	 * @return array{
	 *   read_bytes: array{value: ?float, hash: string, contributors: int, at: int},
	 *   write_bytes: array{value: ?float, hash: string, contributors: int, at: int}
	 * }
	 */
	private static function sumOstCounters(array $osts, int $newest, int $interval): array {
		$hasOsts    = $osts !== [];
		$readTotal  = 0.0;
		$writeTotal = 0.0;
		$readCont   = 0;
		$writeCont  = 0;
		$readComp   = $hasOsts;
		$writeComp  = $hasOsts;
		$readAt     = 0;
		$readMulti  = false;
		$writeAt    = 0;
		$writeMulti = false;
		$readNames  = [];
		$sameSets   = true;
		$prevRead   = '';
		$readSorted = true;
		$readLen    = -1;

		foreach ($osts as $target) {
			$present     = $target->presence === Presence::Present;
			$canBeAbsent = $present && $target->condition === Condition::InService;
			$knownAtMap  = null;
			$targetName  = null;

			$readVal   = null;
			$readObs   = $target->values['read_bytes'] ?? null;
			$readCur   = $readObs !== null && $readObs->observedAt > 0 && ($newest - $readObs->observedAt) < $interval;
			$readPub   = $readObs?->published();

			if ($readObs !== null) {
				if ($readObs->value !== null && ($readPub === null || !$readCur)) {
					$readComp = false;
				}

				if ($canBeAbsent && $readObs->continuity === Continuity::Absent && $readCur) {
					$knownAtMap = self::knownObservedTimes($target);

					if (isset($knownAtMap[$readObs->observedAt])) {
						$readVal = 0.0;
					}
				} elseif ($readCur) {
					$readVal = $readPub;
				}
			}

			$writeVal = null;
			$writeObs = $target->values['write_bytes'] ?? null;
			$writeCur = $writeObs !== null && $writeObs->observedAt > 0 && ($newest - $writeObs->observedAt) < $interval;
			$writePub = $writeObs?->published();

			if ($writeObs !== null) {
				if ($writeObs->value !== null && ($writePub === null || !$writeCur)) {
					$writeComp = false;
				}

				if ($canBeAbsent && $writeObs->continuity === Continuity::Absent && $writeCur) {
					$knownAtMap ??= self::knownObservedTimes($target);

					if (isset($knownAtMap[$writeObs->observedAt])) {
						$writeVal = 0.0;
					}
				} elseif ($writeCur) {
					$writeVal = $writePub;
				}
			}

			$rCounted = $readVal !== null && $readObs !== null;
			$wCounted = $writeVal !== null && $writeObs !== null;

			if ($rCounted !== $wCounted) {
				$sameSets = false;
			}

			if ($rCounted || $wCounted) {
				$targetName = $target->id->name();
			}

			if ($rCounted && $targetName !== null) {
				$readTotal  += $readVal;
				$readCont++;
				$readNames[] = $targetName;
				$len         = strlen($targetName);

				if ($readLen === -1) {
					$readLen = $len;
				} elseif ($len !== $readLen) {
					$readSorted = false;
				}

				if ($readSorted && $prevRead !== '' && strcmp($prevRead, $targetName) > 0) {
					$readSorted = false;
				}

				$prevRead = $targetName;

				if (!$readMulti) {
					if ($readAt === 0) {
						$readAt = $readObs->observedAt;
					} elseif ($readAt !== $readObs->observedAt) {
						$readMulti = true;
					}
				}
			}

			if (!$rCounted || !$present) {
				$readComp = false;
			}

			if ($wCounted && $targetName !== null) {
				$writeTotal += $writeVal;
				$writeCont++;

				if (!$writeMulti) {
					if ($writeAt === 0) {
						$writeAt = $writeObs->observedAt;
					} elseif ($writeAt !== $writeObs->observedAt) {
						$writeMulti = true;
					}
				}
			}

			if (!$wCounted || !$present) {
				$writeComp = false;
			}
		}

		if ($sameSets) {
			if (!$readSorted) {
				sort($readNames, SORT_STRING);
			}

			$readHash  = self::hashSuffixedMembers($readNames, ':read_bytes');
			$writeHash = self::hashSuffixedMembers($readNames, ':write_bytes');
		} else {
			// Rebuild full writeNames list if sets diverged part way through.
			$writeFull = [];

			foreach ($osts as $target) {
				$writeObs = $target->values['write_bytes'] ?? null;

				if ($writeObs === null) {
					continue;
				}

				$writeCur    = $writeObs->observedAt > 0 && ($newest - $writeObs->observedAt) < $interval;
				$canBeAbsent = $target->presence === Presence::Present && $target->condition === Condition::InService;
				$val         = null;

				if ($canBeAbsent && $writeObs->continuity === Continuity::Absent && $writeCur) {
					$knownAt = self::knownObservedTimes($target);

					if (isset($knownAt[$writeObs->observedAt])) {
						$val = 0.0;
					}
				} elseif ($writeCur) {
					$val = $writeObs->published();
				}

				if ($val !== null) {
					$writeFull[] = $target->id->name() . ':write_bytes';
				}
			}

			$readFull = [];

			foreach ($readNames as $n) {
				$readFull[] = $n . ':read_bytes';
			}

			sort($readFull, SORT_STRING);
			sort($writeFull, SORT_STRING);

			$readHash  = self::hashPlainMembers($readFull);
			$writeHash = self::hashPlainMembers($writeFull);
		}

		return [
			'read_bytes' => [
				'value'        => $readComp ? $readTotal : null,
				'hash'         => $readHash,
				'contributors' => $readCont,
				'at'           => $readMulti ? 0 : $readAt,
			],
			'write_bytes' => [
				'value'        => $writeComp ? $writeTotal : null,
				'hash'         => $writeHash,
				'contributors' => $writeCont,
				'at'           => $writeMulti ? 0 : $writeAt,
			],
		];
	}

	/**
	 * Index the observation timestamps on a target that carried a known value.
	 *
	 * @param SummaryTarget $target
	 *
	 * @return array<int, true>
	 */
	private static function knownObservedTimes(SummaryTarget $target): array {
		$knownAt = [];

		foreach ($target->values as $other) {
			if ($other->value !== null) {
				$knownAt[$other->observedAt] = true;
			}
		}

		return $knownAt;
	}

	/**
	 * Stream-hash sorted target names with a fixed metric suffix without
	 * allocating a joined multi-MB string.
	 *
	 * @param list<string> $sortedNames
	 * @param string       $suffix
	 *
	 * @return string
	 */
	private static function hashSuffixedMembers(array $sortedNames, string $suffix): string {
		$n = count($sortedNames);

		if ($n === 0) {
			return '';
		}

		$ctx = hash_init('sha1');
		hash_update($ctx, $sortedNames[0] . $suffix);

		for ($i = 1; $i < $n; $i++) {
			hash_update($ctx, "\n" . $sortedNames[$i] . $suffix);
		}

		return hash_final($ctx);
	}

	/**
	 * Stream-hash sorted full member strings without allocating a joined string.
	 *
	 * @param list<string> $sortedMembers
	 *
	 * @return string
	 */
	private static function hashPlainMembers(array $sortedMembers): string {
		$n = count($sortedMembers);

		if ($n === 0) {
			return '';
		}

		$ctx = hash_init('sha1');
		hash_update($ctx, $sortedMembers[0]);

		for ($i = 1; $i < $n; $i++) {
			hash_update($ctx, "\n" . $sortedMembers[$i]);
		}

		return hash_final($ctx);
	}

	/**
	 * Guard a pre-hashed counter sum against member-set changes.
	 *
	 * @param ?float  $value
	 * @param string  $hash
	 * @param ?string $previous
	 *
	 * @return array{0: ?float, 1: string}
	 */
	private static function guardHashed(?float $value, string $hash, ?string $previous): array {
		if ($value === null) {
			return [null, $hash];
		}

		if ($previous !== null && $previous !== '' && $previous !== $hash) {
			return [null, $hash];
		}

		return [$value, $hash];
	}

	/**
	 * Sum one or more counters over a set of targets in a single pass. The sum
	 * is complete only when every target is present and contributes at least
	 * one current, continuous value; values that are unknown on this release
	 * are left out and change the member set instead.
	 *
	 * @param list<SummaryTarget> $targets
	 * @param list<string>        $metrics
	 * @param int                 $newest
	 * @param int                 $interval
	 *
	 * @return array{value: ?float, members: list<string>, contributors: int, at: int} at is the read time every summed value shares, or 0
	 */
	private static function sum(array $targets, array $metrics, int $newest, int $interval): array {
		$total        = 0.0;
		$members      = [];
		$contributors = 0;
		$complete     = $targets !== [];
		$singleTime   = 0;
		$multipleTime = false;

		foreach ($targets as $target) {
			$counted     = false;
			$targetName  = null;
			$knownAtMap  = null;
			$canBeAbsent = $target->presence === Presence::Present && $target->condition === Condition::InService;

			foreach ($metrics as $metric) {
				$observation = $target->values[$metric] ?? null;

				if ($observation === null) {
					continue;
				}

				$isCurrent = $observation->observedAt > 0 && ($newest - $observation->observedAt) < $interval;
				$published = $observation->published();

				// A target with a counter that is known but not current or not
				// continuous (a reset) leaves the sum incomplete.
				if ($observation->value !== null && ($published === null || !$isCurrent)) {
					$complete = false;
				}

				$value = null;

				if ($canBeAbsent && $observation->continuity === Continuity::Absent && $isCurrent) {
					if ($knownAtMap === null) {
						$knownAtMap = [];

						foreach ($target->values as $other) {
							if ($other->value !== null) {
								$knownAtMap[$other->observedAt] = true;
							}
						}
					}

					if (isset($knownAtMap[$observation->observedAt])) {
						$value = 0.0;
					}
				} elseif ($isCurrent) {
					$value = $published;
				}

				if ($value === null) {
					continue;
				}

				if ($targetName === null) {
					$targetName = $target->id->name();
				}

				$total    += $value;
				$members[] = $targetName . ':' . $metric;
				$counted   = true;

				if (!$multipleTime) {
					if ($singleTime === 0) {
						$singleTime = $observation->observedAt;
					} elseif ($singleTime !== $observation->observedAt) {
						$multipleTime = true;
					}
				}
			}

			if ($counted) {
				$contributors++;
			}

			if (!$counted || $target->presence !== Presence::Present) {
				$complete = false;
			}
		}

		sort($members);

		return [
			'value'        => $complete ? $total : null,
			'members'      => $members,
			'contributors' => $contributors,
			'at'           => $multipleTime ? 0 : $singleTime,
		];
	}

	/**
	 * The per-second rate of one aggregate counter since the previous summary,
	 * or null when it cannot be trusted (see the class comment).
	 *
	 * @param string   $counter
	 * @param ?float   $value
	 * @param string   $members
	 * @param int      $at
	 * @param ?Summary $previous
	 * @param int      $interval
	 *
	 * @return ?float
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
	 * Guard a summed counter against member-set churn using streaming SHA-1
	 * hashing so 100k+ target member lists never allocate a multi-MB joined string.
	 *
	 * @param array{value: ?float, members: list<string>, contributors: int, at: int} $sum
	 * @param ?string                                                                 $previous
	 *
	 * @return array{0: ?float, 1: string} the value to publish and the member hash to keep
	 */
	private static function guard(array $sum, ?string $previous): array {
		$memberList = $sum['members'];

		if ($memberList === []) {
			$hash = '';
		} else {
			$ctx = hash_init('sha1');
			$n   = count($memberList);

			hash_update($ctx, $memberList[0]);

			for ($i = 1; $i < $n; $i++) {
				hash_update($ctx, "\n" . $memberList[$i]);
			}

			$hash = hash_final($ctx);
		}

		if ($sum['value'] === null) {
			return [null, $hash];
		}

		if ($previous !== null && $previous !== '' && $previous !== $hash) {
			return [null, $hash];
		}

		return [$sum['value'], $hash];
	}
}
