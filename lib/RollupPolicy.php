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
 * Hierarchical opt-in RRD tiering policy for large-scale parallel filesystems.
 *
 * Defaults to Filesystem -> Pool -> Storage Server (OSS/MDS/NSD) rollups and
 * promotes individual OST/MDT/NSD targets to per-target RRDs only when
 * explicitly opted in or automatically flagged as hot, skewed, or unhealthy,
 * bounded by a strict per-filesystem promotion cap so 20k-100k+ target
 * clusters never cause an RRD file explosion.
 */
final class RollupPolicy {
	/** Tier filesystem constant. */
	public const TIER_FILESYSTEM = 'filesystem';
	/** Tier pool constant. */
	public const TIER_POOL       = 'pool';
	/** Tier server constant. */
	public const TIER_SERVER     = 'server';
	/** Tier target constant. */
	public const TIER_TARGET     = 'target';

	/** Default warn fill pct constant. */
	public const DEFAULT_WARN_FILL_PCT        = 80.0;
	/** Default skew delta pct constant. */
	public const DEFAULT_SKEW_DELTA_PCT       = 15.0;
	/** Default max promoted targets constant. */
	public const DEFAULT_MAX_PROMOTED_TARGETS = 200;

	/**
	 * Initialize RollupPolicy.
	 *
	 * @param list<string> $optInTargets       target names explicitly promoted to per-target RRDs
	 * @param float        $warnFillPct        fill threshold triggering automatic promotion
	 * @param float        $skewDeltaPct       skew threshold triggering automatic promotion
	 * @param int          $maxPromotedTargets hard per-filesystem promotion cap
	 */
	public function __construct(
		public readonly array $optInTargets = [],
		public readonly float $warnFillPct = self::DEFAULT_WARN_FILL_PCT,
		public readonly float $skewDeltaPct = self::DEFAULT_SKEW_DELTA_PCT,
		public readonly int $maxPromotedTargets = self::DEFAULT_MAX_PROMOTED_TARGETS,
	) {
	}

	/**
	 * Decide which individual targets should receive per-target RRD series
	 * while keeping all others aggregated at the Filesystem/Pool/Server tiers.
	 *
	 * Uses a bounded O(K) min-heap (K = maxPromotedTargets) with threshold
	 * pre-filtering instead of sorting all candidates, reducing worst-case
	 * memory from O(N) to O(K) and time from O(N log N) to O(N + K log K).
	 *
	 * @param list<array{name: string, type: string, presence: string, condition: string, fill_pct: ?float, inode_pct: ?float, headroom_pct: ?float, kbytes_avail: ?float, status_flags: list<string>}> $targetDetails
	 * @param ?float $meanFillPct
	 *
	 * @return array{
	 *   default_tiers: list<string>,
	 *   total_targets: int,
	 *   promoted_count: int,
	 *   rollup_only_count: int,
	 *   capped: bool,
	 *   promoted: list<array{name: string, reason: string, fill_pct: ?float, skew_delta_pct: ?float}>
	 * }
	 */
	public function selectPromotedTargets(array $targetDetails, ?float $meanFillPct): array {
		$optIn          = $this->optInTargets !== [] ? array_fill_keys($this->optInTargets, true) : [];
		$limit          = max(0, $this->maxPromotedTargets);
		$total          = count($targetDetails);
		$candidateCount = 0;

		/** @var list<array{0: float, 1: string, 2: string, 3: ?float, 4: ?float}> $heap */
		$heap = [];
		$size = 0;

		foreach ($targetDetails as $detail) {
			$name      = $detail['name'];
			$fill      = $detail['fill_pct'];
			$skewDelta = ($fill !== null && $meanFillPct !== null) ? round(abs($fill - $meanFillPct), 2) : null;
			$reason    = null;
			$priority  = 0.0;

			if (isset($optIn[$name])) {
				$reason   = 'opt_in';
				$priority = 10000.0 + ($fill ?? 0.0);
			} elseif ($detail['status_flags'] !== []) {
				$reason   = 'unhealthy';
				$priority = 5000.0 + ($fill ?? 0.0);
			} elseif ($fill !== null && $fill >= $this->warnFillPct) {
				$reason   = 'hot_fill';
				$priority = 2000.0 + $fill;
			} elseif ($skewDelta !== null && $skewDelta >= $this->skewDeltaPct) {
				$reason   = 'skewed';
				$priority = 1000.0 + $skewDelta;
			}

			if ($reason === null) {
				continue;
			}

			$candidateCount++;

			if ($limit === 0) {
				continue;
			}

			if ($size < $limit) {
				$idx        = $size++;
				$heap[$idx] = [$priority, $name, $reason, $fill, $skewDelta];

				while ($idx > 0) {
					$parent = ($idx - 1) >> 1;

					if (!self::isWorseCandidate($heap[$idx], $heap[$parent])) {
						break;
					}

					$tmp           = $heap[$parent];
					$heap[$parent] = $heap[$idx];
					$heap[$idx]    = $tmp;
					$idx           = $parent;
				}

				continue;
			}

			$root = $heap[0];

			if ($priority < $root[0] || ($priority === $root[0] && strcmp($name, $root[1]) >= 0)) {
				continue;
			}

			$heap[0] = [$priority, $name, $reason, $fill, $skewDelta];
			$idx     = 0;

			while (true) {
				$left     = ($idx << 1) + 1;
				$smallest = $idx;

				if ($left < $size && self::isWorseCandidate($heap[$left], $heap[$smallest])) {
					$smallest = $left;
				}

				$right = $left + 1;

				if ($right < $size && self::isWorseCandidate($heap[$right], $heap[$smallest])) {
					$smallest = $right;
				}

				if ($smallest === $idx) {
					break;
				}

				$tmp             = $heap[$idx];
				$heap[$idx]      = $heap[$smallest];
				$heap[$smallest] = $tmp;
				$idx             = $smallest;
			}
		}

		usort($heap, static function (array $a, array $b): int {
			if ($a[0] !== $b[0]) {
				return $b[0] <=> $a[0];
			}

			return strcmp($a[1], $b[1]);
		});

		$promoted = [];

		foreach ($heap as $c) {
			$promoted[] = [
				'name'           => $c[1],
				'reason'         => $c[2],
				'fill_pct'       => $c[3],
				'skew_delta_pct' => $c[4],
			];
		}

		$promotedCount = count($promoted);

		return [
			'default_tiers'     => [self::TIER_FILESYSTEM, self::TIER_POOL, self::TIER_SERVER],
			'total_targets'     => $total,
			'promoted_count'    => $promotedCount,
			'rollup_only_count' => $total - $promotedCount,
			'capped'            => $candidateCount > $limit,
			'promoted'          => $promoted,
		];
	}

	/**
	 * Compute storage-server-tier (OSS/MDS/NSD) capacity rollups from target
	 * inventory and observations so operators monitor one RRD set per server
	 * instead of one per OST/NSD.
	 *
	 * @param list<Target>                           $targets
	 * @param array<int, array<string, Observation>> $observations target_id => metric => Observation
	 * @param array<int, string>                     $nodeNames    node_id => node name
	 *
	 * @return list<array{
	 *   node_id: int,
	 *   server_name: string,
	 *   targets_total: int,
	 *   targets_unhealthy: int,
	 *   kbytes_total: float,
	 *   kbytes_avail: float,
	 *   fill_max_pct: ?float
	 * }>
	 */
	public static function summarizeByServer(array $targets, array $observations, array $nodeNames = []): array {
		/** @var array<int, array{node_id: int, server_name: string, targets_total: int, targets_unhealthy: int, kbytes_total: float, kbytes_avail: float, fill_max_pct: ?float}> $buckets */
		$buckets = [];

		foreach ($targets as $target) {
			if ($target->review === Review::Ignored) {
				continue;
			}

			$nodeId = $target->sourceNodeId;

			if (!isset($buckets[$nodeId])) {
				$buckets[$nodeId] = [
					'node_id'           => $nodeId,
					'server_name'       => $nodeNames[$nodeId] ?? ($nodeId > 0 ? 'node-' . $nodeId : 'unassigned'),
					'targets_total'     => 0,
					'targets_unhealthy' => 0,
					'kbytes_total'      => 0.0,
					'kbytes_avail'      => 0.0,
					'fill_max_pct'      => null,
				];
			}

			// Mutate bucket elements directly in place to avoid copy-on-write
			// HashTable duplication on every target.
			$buckets[$nodeId]['targets_total']++;

			if ($target->condition !== Condition::InService && $target->condition !== Condition::Unknown) {
				$buckets[$nodeId]['targets_unhealthy']++;
			}

			$obs   = $observations[$target->id] ?? null;
			$total = $obs['kbytes_total']->value ?? null;
			$free  = $obs['kbytes_free']->value ?? null;
			$avail = $obs['kbytes_avail']->value ?? null;

			if ($total !== null && $avail !== null) {
				$buckets[$nodeId]['kbytes_total'] += $total;
				$buckets[$nodeId]['kbytes_avail'] += $avail;
			}

			$fill = UsedPercent::space($total, $free, $avail);

			if ($fill !== null) {
				$curMax = $buckets[$nodeId]['fill_max_pct'];

				if ($curMax === null || $fill > $curMax) {
					$buckets[$nodeId]['fill_max_pct'] = $fill;
				}
			}
		}

		ksort($buckets);

		return array_values($buckets);
	}

	/**
	 * Compare two promotion heap tuples so the lowest-priority (or
	 * lexicographically largest name on tie) stays at the min-heap root.
	 *
	 * @param array{0: float, 1: string, 2: string, 3: ?float, 4: ?float} $a
	 * @param array{0: float, 1: string, 2: string, 3: ?float, 4: ?float} $b
	 *
	 * @return bool
	 */
	private static function isWorseCandidate(array $a, array $b): bool {
		if ($a[0] !== $b[0]) {
			return $a[0] < $b[0];
		}

		return strcmp($a[1], $b[1]) > 0;
	}
}
