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
 * Bounded-memory Top-K heavy-hitter and target-skew aggregator.
 *
 * Retains only the top K entries (default 20) in an O(K) min-heap so scanning
 * tens of thousands of storage targets or pre-aggregated heavy jobs/clients
 * from Lustre job_stats, GPFS mmpmon, or LDMS rollups stays bounded in memory
 * and never creates per-client Cacti devices or RRD files.
 */
final class TopKTracker {
	public const DEFAULT_K = 20;
	public const MIN_K     = 1;
	public const MAX_K     = 500;

	public const ALLOWED_SOURCES = ['job_stats', 'mmpmon', 'ldms', 'beegfs'];

	/**
	 * Select the Top-K hottest or most-skewed storage targets using O(K)
	 * working memory.
	 *
	 * @param iterable<array{name: string, type: string, presence: string, condition: string, fill_pct: ?float, inode_pct: ?float, headroom_pct: ?float, kbytes_avail: ?float, status_flags: list<string>}> $targetDetails
	 *
	 * @return list<array{name: string, type: string, fill_pct: float, skew_delta_pct: ?float, headroom_pct: ?float, status_flags: list<string>}>
	 */
	public static function topSkewedTargets(iterable $targetDetails, ?float $meanFillPct, int $k = self::DEFAULT_K): array {
		$limit = self::clampK($k);
		/** @var \SplMinHeap<array{0: float, 1: float, 2: string, 3: array{name: string, type: string, fill_pct: float, skew_delta_pct: ?float, headroom_pct: ?float, status_flags: list<string>}}> $heap */
		$heap = new \SplMinHeap();

		foreach ($targetDetails as $detail) {
			$fill = $detail['fill_pct'];

			if ($fill === null) {
				continue;
			}

			$skewDelta = $meanFillPct !== null ? round($fill - $meanFillPct, 2) : null;
			$score     = $skewDelta !== null ? abs($skewDelta) * 100.0 + $fill : $fill;
			$entry     = [
				'name'           => $detail['name'],
				'type'           => $detail['type'],
				'fill_pct'       => $fill,
				'skew_delta_pct' => $skewDelta,
				'headroom_pct'   => $detail['headroom_pct'],
				'status_flags'   => $detail['status_flags'],
			];

			$tuple = [$score, $fill, $detail['name'], $entry];

			if ($heap->count() < $limit) {
				$heap->insert($tuple);
			} elseif ($tuple[0] > $heap->top()[0] || ($tuple[0] === $heap->top()[0] && $tuple[1] > $heap->top()[1])) {
				$heap->extract();
				$heap->insert($tuple);
			}
		}

		$ranked = [];

		while (!$heap->isEmpty()) {
			$ranked[] = $heap->extract();
		}

		usort($ranked, static fn (array $a, array $b): int => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);

		return array_map(static fn (array $row): array => $row[3], $ranked);
	}

	/**
	 * Ingest a stream of pre-aggregated heavy job/client summary records
	 * (e.g. Lustre job_stats, GPFS mmpmon, LDMS rollups) and return only the
	 * Top-K heavy hitters in bounded O(K) memory. Invalid records are skipped.
	 *
	 * @param iterable<array<string, mixed>> $records
	 *
	 * @return list<array{id: string, source: string, bytes_per_sec: float, ops_per_sec: float, read_bytes: float, write_bytes: float}>
	 */
	public static function topHeavyHitters(iterable $records, int $k = self::DEFAULT_K): array {
		$limit = self::clampK($k);
		/** @var \SplMinHeap<array{0: float, 1: float, 2: string, 3: array{id: string, source: string, bytes_per_sec: float, ops_per_sec: float, read_bytes: float, write_bytes: float}}> $heap */
		$heap = new \SplMinHeap();

		foreach ($records as $record) {
			$id     = is_string($record['id'] ?? null) ? trim($record['id']) : '';
			$source = is_string($record['source'] ?? null) ? strtolower(trim($record['source'])) : '';

			if (
				preg_match('/^[A-Za-z0-9._:@\/-]{1,64}$/', $id) !== 1
				|| !in_array($source, self::ALLOWED_SOURCES, true)
			) {
				continue;
			}

			$bytesPerSec = self::nonNegFloat($record['bytes_per_sec'] ?? null);
			$opsPerSec   = self::nonNegFloat($record['ops_per_sec'] ?? null);
			$readBytes   = self::nonNegFloat($record['read_bytes'] ?? 0.0) ?? 0.0;
			$writeBytes  = self::nonNegFloat($record['write_bytes'] ?? 0.0) ?? 0.0;

			if ($bytesPerSec === null && $opsPerSec === null) {
				continue;
			}

			$bps   = $bytesPerSec ?? 0.0;
			$ops   = $opsPerSec ?? 0.0;
			$entry = [
				'id'            => $id,
				'source'        => $source,
				'bytes_per_sec' => $bps,
				'ops_per_sec'   => $ops,
				'read_bytes'    => $readBytes,
				'write_bytes'   => $writeBytes,
			];
			$tuple = [$bps, $ops, $id, $entry];

			if ($heap->count() < $limit) {
				$heap->insert($tuple);
			} elseif ($tuple[0] > $heap->top()[0] || ($tuple[0] === $heap->top()[0] && $tuple[1] > $heap->top()[1])) {
				$heap->extract();
				$heap->insert($tuple);
			}
		}

		$ranked = [];

		while (!$heap->isEmpty()) {
			$ranked[] = $heap->extract();
		}

		usort($ranked, static fn (array $a, array $b): int => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);

		return array_map(static fn (array $row): array => $row[3], $ranked);
	}

	private static function clampK(int $k): int {
		return max(self::MIN_K, min(self::MAX_K, $k));
	}

	private static function nonNegFloat(mixed $value): ?float {
		if (is_int($value) || is_float($value)) {
			$f = (float) $value;

			return is_finite($f) && $f >= 0.0 ? $f : null;
		}

		return null;
	}
}
