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
 * bounded by a strict per-filesystem promotion cap so 20k-50k target clusters
 * never cause an RRD file explosion.
 */
final class RollupPolicy {
	public const TIER_FILESYSTEM = 'filesystem';
	public const TIER_POOL       = 'pool';
	public const TIER_SERVER     = 'server';
	public const TIER_TARGET     = 'target';

	public const DEFAULT_WARN_FILL_PCT        = 80.0;
	public const DEFAULT_SKEW_DELTA_PCT       = 15.0;
	public const DEFAULT_MAX_PROMOTED_TARGETS = 200;

	/**
	 * @param list<string> $optInTargets target names explicitly promoted to per-target RRDs
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
	 * @param list<array{name: string, type: string, presence: string, condition: string, fill_pct: ?float, inode_pct: ?float, headroom_pct: ?float, kbytes_avail: ?float, status_flags: list<string>}> $targetDetails
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
		$optIn      = array_fill_keys($this->optInTargets, true);
		$candidates = [];

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

			if ($reason !== null) {
				$candidates[] = [
					'name'           => $name,
					'reason'         => $reason,
					'fill_pct'       => $fill,
					'skew_delta_pct' => $skewDelta,
					'priority'       => $priority,
				];
			}
		}

		usort($candidates, static fn (array $a, array $b): int => [$b['priority'], $a['name']] <=> [$a['priority'], $b['name']]);

		$limit    = max(0, $this->maxPromotedTargets);
		$capped   = count($candidates) > $limit;
		$selected = array_slice($candidates, 0, $limit);
		$promoted = array_map(
			static fn (array $c): array => [
				'name'           => $c['name'],
				'reason'         => $c['reason'],
				'fill_pct'       => $c['fill_pct'],
				'skew_delta_pct' => $c['skew_delta_pct'],
			],
			$selected,
		);

		$total = count($targetDetails);

		return [
			'default_tiers'     => [self::TIER_FILESYSTEM, self::TIER_POOL, self::TIER_SERVER],
			'total_targets'     => $total,
			'promoted_count'    => count($promoted),
			'rollup_only_count' => $total - count($promoted),
			'capped'            => $capped,
			'promoted'          => $promoted,
		];
	}

	/**
	 * Compute storage-server-tier (OSS/MDS/NSD) capacity rollups from target
	 * inventory and observations so operators monitor one RRD set per server
	 * instead of one per OST/NSD.
	 *
	 * @param list<Target>                         $targets
	 * @param array<int, array<string, Observation>> $observations target_id => metric => Observation
	 * @param array<int, string>                   $nodeNames    node_id => node name
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
			$bucket = $buckets[$nodeId] ?? [
				'node_id'           => $nodeId,
				'server_name'       => $nodeNames[$nodeId] ?? ($nodeId > 0 ? 'node-' . $nodeId : 'unassigned'),
				'targets_total'     => 0,
				'targets_unhealthy' => 0,
				'kbytes_total'      => 0.0,
				'kbytes_avail'      => 0.0,
				'fill_max_pct'      => null,
			];

			$bucket['targets_total']++;

			if ($target->condition !== Condition::InService && $target->condition !== Condition::Unknown) {
				$bucket['targets_unhealthy']++;
			}

			$obs   = $observations[$target->id] ?? [];
			$total = $obs['kbytes_total']->value ?? null;
			$free  = $obs['kbytes_free']->value ?? null;
			$avail = $obs['kbytes_avail']->value ?? null;

			if ($total !== null && $avail !== null) {
				$bucket['kbytes_total'] += $total;
				$bucket['kbytes_avail'] += $avail;
			}

			$fill = UsedPercent::space($total, $free, $avail);

			if ($fill !== null) {
				$bucket['fill_max_pct'] = $bucket['fill_max_pct'] === null ? $fill : max($bucket['fill_max_pct'], $fill);
			}

			$buckets[$nodeId] = $bucket;
		}

		ksort($buckets);

		return array_values($buckets);
	}
}
