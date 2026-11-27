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
 * Capacity, fill-ratio, target imbalance, headroom, and status-flag analysis
 * across OSTs and MDTs (REQ-CAP-001 to REQ-CAP-005).
 *
 * Enforces REQ-CAP-004: summary imbalance and headroom values are published
 * only when contributing observations fall within one reader interval of the
 * newest observation, and missing_targets is always reported alongside them.
 */
final class ImbalanceAnalyzer {
	public const DEFAULT_WARN_FILL_PCT     = 80.0;
	public const DEFAULT_CRIT_FILL_PCT     = 90.0;
	public const DEFAULT_WARN_SPREAD_PCT   = 15.0;
	public const DEFAULT_CRIT_SPREAD_PCT   = 30.0;

	/**
	 * Compute detailed OST/MDT fill ratios, skew/imbalance index, headroom,
	 * missing target count, and per-target status flags.
	 *
	 * @param list<SummaryTarget> $targets
	 *
	 * @return array{
	 *   severity: string,
	 *   targets_total: int,
	 *   targets_contributing: int,
	 *   missing_targets: int,
	 *   ost_count: int,
	 *   mdt_count: int,
	 *   fill_max_pct: ?float,
	 *   fill_min_pct: ?float,
	 *   fill_mean_pct: ?float,
	 *   fill_spread_pct: ?float,
	 *   imbalance_index: ?float,
	 *   headroom_min_pct: ?float,
	 *   headroom_min_kbytes: ?float,
	 *   headroom_kbytes_total: ?float,
	 *   mdt_fill_max_pct: ?float,
	 *   mdt_inode_max_pct: ?float,
	 *   mdt_headroom_min_pct: ?float,
	 *   targets: list<array{name: string, type: string, presence: string, condition: string, fill_pct: ?float, inode_pct: ?float, headroom_pct: ?float, kbytes_avail: ?float, status_flags: list<string>}>
	 * }
	 */
	public static function analyze(array $targets, int $interval): array {
		$considered = array_values(array_filter($targets, static fn (SummaryTarget $t): bool => $t->review !== Review::Ignored));
		$interval   = max(1, $interval);
		$newest     = 0;

		foreach ($considered as $target) {
			foreach ($target->values as $observation) {
				$newest = max($newest, $observation->observedAt);
			}
		}

		$withinInterval = static fn (?Observation $o): bool => $o !== null && $o->observedAt > 0 && ($newest - $o->observedAt) < $interval;

		$ostFills      = [];
		$ostAvails     = [];
		$mdtFills      = [];
		$mdtInodes     = [];
		$ostCount      = 0;
		$mdtCount      = 0;
		$contributing  = 0;
		$allOstsFresh  = true;
		$kbAvailTotal  = 0.0;
		$targetDetails = [];

		foreach ($considered as $target) {
			$isOst = $target->id->type === TargetType::Ost;

			if ($isOst) {
				$ostCount++;
			} else {
				$mdtCount++;
			}

			$totalObs  = $target->value('kbytes_total');
			$freeObs   = $target->value('kbytes_free');
			$availObs  = $target->value('kbytes_avail');
			$fTotalObs = $target->value('files_total');
			$fFreeObs  = $target->value('files_free');

			$fresh     = $target->presence === Presence::Present && $withinInterval($totalObs);
			$fillPct   = $fresh ? UsedPercent::space($totalObs?->value, $freeObs?->value, $availObs?->value) : null;
			$inodePct  = $fresh ? UsedPercent::inodes($fTotalObs?->value, $fFreeObs?->value) : null;
			$headroom  = $fillPct === null ? null : max(0.0, round(100.0 - $fillPct, 2));
			$availKb   = $fresh ? $availObs?->value : null;

			$flags = self::statusFlags($target->condition, $fillPct, $inodePct, $availKb, $fFreeObs?->value);

			$targetDetails[] = [
				'name'         => $target->id->name(),
				'type'         => $target->id->type->value,
				'presence'     => $target->presence->value,
				'condition'    => $target->condition->value,
				'fill_pct'     => $fillPct,
				'inode_pct'    => $inodePct,
				'headroom_pct' => $headroom,
				'kbytes_avail' => $availKb,
				'status_flags' => $flags,
			];

			if ($fillPct === null) {
				if ($isOst) {
					$allOstsFresh = false;
				}

				continue;
			}

			$contributing++;

			if ($isOst) {
				// REQ-CAP-003: fill summary across in-service OSTs (or unknown-condition backends with valid capacity).
				if ($target->condition === Condition::InService || $target->condition === Condition::Unknown) {
					$ostFills[] = $fillPct;
				}

				if ($availKb !== null) {
					$ostAvails[]   = $availKb;
					$kbAvailTotal += $availKb;
				} else {
					$allOstsFresh = false;
				}
			} else {
				$mdtFills[] = $fillPct;

				if ($inodePct !== null) {
					$mdtInodes[] = $inodePct;
				}
			}
		}

		$missing = count($considered) - $contributing;

		// REQ-CAP-004: summary fill/imbalance values are unknown unless every OST observation is within one reader interval.
		$fillMax        = null;
		$fillMin        = null;
		$fillMean       = null;
		$fillSpread     = null;
		$imbalanceIndex = null;
		$headroomMinPct = null;
		$headroomMinKb  = null;
		$headroomTotal  = null;

		if ($ostCount > 0 && $allOstsFresh && $ostFills !== []) {
			$fillMax        = max($ostFills);
			$fillMin        = min($ostFills);
			$fillMean       = round(array_sum($ostFills) / count($ostFills), 2);
			$fillSpread     = round($fillMax - $fillMin, 2);
			$headroomMinPct = max(0.0, round(100.0 - $fillMax, 2));
			$headroomTotal  = $kbAvailTotal;
			$headroomMinKb  = $ostAvails !== [] ? min($ostAvails) : null;
			$imbalanceIndex = self::computeImbalanceIndex($ostFills, $fillMean);
		}

		$mdtFillMax     = $mdtFills !== [] ? max($mdtFills) : null;
		$mdtInodeMax    = $mdtInodes !== [] ? max($mdtInodes) : null;
		$mdtHeadroomMin = $mdtFillMax !== null ? max(0.0, round(100.0 - $mdtFillMax, 2)) : null;

		$severity = self::classifySeverity($fillMax, $fillSpread, $mdtFillMax, $mdtInodeMax, $missing, $ostCount);

		return [
			'severity'              => $severity,
			'targets_total'         => count($considered),
			'targets_contributing'  => $contributing,
			'missing_targets'       => $missing,
			'ost_count'             => $ostCount,
			'mdt_count'             => $mdtCount,
			'fill_max_pct'          => $fillMax,
			'fill_min_pct'          => $fillMin,
			'fill_mean_pct'         => $fillMean,
			'fill_spread_pct'       => $fillSpread,
			'imbalance_index'       => $imbalanceIndex,
			'headroom_min_pct'      => $headroomMinPct,
			'headroom_min_kbytes'   => $headroomMinKb,
			'headroom_kbytes_total' => $headroomTotal,
			'mdt_fill_max_pct'      => $mdtFillMax,
			'mdt_inode_max_pct'     => $mdtInodeMax,
			'mdt_headroom_min_pct'  => $mdtHeadroomMin,
			'targets'               => $targetDetails,
		];
	}

	/**
	 * Population standard deviation of OST fill percentages (0.0 when uniform,
	 * null when fewer than 2 contributing OSTs exist).
	 *
	 * @param list<float> $fills
	 */
	private static function computeImbalanceIndex(array $fills, float $mean): ?float {
		$n = count($fills);

		if ($n < 2) {
			return null;
		}

		$sumSq = 0.0;

		foreach ($fills as $v) {
			$diff   = $v - $mean;
			$sumSq += $diff * $diff;
		}

		return round(sqrt($sumSq / $n), 2);
	}

	/**
	 * Target status flags (REQ-CAP-005).
	 *
	 * @return list<string>
	 */
	private static function statusFlags(Condition $condition, ?float $fillPct, ?float $inodePct, ?float $availKb, ?float $filesFree): array {
		$flags = [];

		if ($condition === Condition::Degraded) {
			$flags[] = 'degraded';
		} elseif ($condition === Condition::NoCreate) {
			$flags[] = 'no_create';
		} elseif ($condition === Condition::ReadOnly) {
			$flags[] = 'readonly';
		}

		if ($fillPct === 100.0 || ($availKb !== null && $availKb === 0.0)) {
			$flags[] = 'out_of_space';
		}

		if ($inodePct === 100.0 || ($filesFree !== null && $filesFree === 0.0)) {
			$flags[] = 'out_of_inodes';
		}

		return $flags;
	}

	private static function classifySeverity(?float $fillMax, ?float $spread, ?float $mdtFillMax, ?float $mdtInodeMax, int $missing, int $ostCount): string {
		if ($ostCount === 0 || $fillMax === null) {
			return $missing > 0 ? 'unknown' : 'ok';
		}

		if (
			$fillMax >= self::DEFAULT_CRIT_FILL_PCT
			|| ($spread !== null && $spread >= self::DEFAULT_CRIT_SPREAD_PCT)
			|| ($mdtFillMax !== null && $mdtFillMax >= self::DEFAULT_CRIT_FILL_PCT)
			|| ($mdtInodeMax !== null && $mdtInodeMax >= self::DEFAULT_CRIT_FILL_PCT)
		) {
			return 'critical';
		}

		if (
			$fillMax >= self::DEFAULT_WARN_FILL_PCT
			|| ($spread !== null && $spread >= self::DEFAULT_WARN_SPREAD_PCT)
			|| ($mdtFillMax !== null && $mdtFillMax >= self::DEFAULT_WARN_FILL_PCT)
			|| ($mdtInodeMax !== null && $mdtInodeMax >= self::DEFAULT_WARN_FILL_PCT)
			|| $missing > 0
		) {
			return 'warning';
		}

		return 'ok';
	}
}
