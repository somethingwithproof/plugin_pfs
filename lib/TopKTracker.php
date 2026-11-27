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
 * Retains only the top K entries (default 20) in an inline O(K) binary
 * min-heap with threshold pre-filtering before record allocation so scanning
 * 100,000+ storage targets or pre-aggregated heavy jobs/clients from Lustre
 * job_stats, GPFS mmpmon, or LDMS rollups stays O(K) in memory and avoids
 * per-record heap or array churn for rejected items.
 */
final class TopKTracker {
	/** Default k constant. */
	public const DEFAULT_K = 20;
	/** Min k constant. */
	public const MIN_K     = 1;
	/** Max k constant. */
	public const MAX_K     = 500;

	/** Allowed sources constant. */
	public const ALLOWED_SOURCES = ['job_stats', 'mmpmon', 'ldms', 'beegfs'];

	/** O(1) lookup map for allowed telemetry sources. */
	private const ALLOWED_SOURCE_MAP = [
		'job_stats' => true,
		'mmpmon'    => true,
		'ldms'      => true,
		'beegfs'    => true,
	];

	/** Allowed identifier characters (matches /^[A-Za-z0-9._:@\/-]{1,64}$/). */
	private const ID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._:@/-';

	/**
	 * Select the Top-K hottest or most-skewed storage targets using O(K)
	 * working memory.
	 *
	 * @param iterable<array{name: string, type: string, presence: string, condition: string, fill_pct: ?float, inode_pct: ?float, headroom_pct: ?float, kbytes_avail: ?float, status_flags: list<string>}> $targetDetails
	 * @param ?float $meanFillPct
	 * @param int    $k
	 *
	 * @return list<array{name: string, type: string, fill_pct: float, skew_delta_pct: ?float, headroom_pct: ?float, status_flags: list<string>}>
	 */
	public static function topSkewedTargets(iterable $targetDetails, ?float $meanFillPct, int $k = self::DEFAULT_K): array {
		$limit = self::clampK($k);
		/** @var list<array{0: float, 1: float, 2: string, 3: array{name: string, type: string, fill_pct: float, skew_delta_pct: ?float, headroom_pct: ?float, status_flags: list<string>}}> $heap */
		$heap = [];
		$size = 0;

		foreach ($targetDetails as $detail) {
			$fill = $detail['fill_pct'];

			if ($fill === null) {
				continue;
			}

			$skewDelta = $meanFillPct !== null ? round($fill - $meanFillPct, 2) : null;
			$score     = $skewDelta !== null ? abs($skewDelta) * 100.0 + $fill : $fill;
			$name      = $detail['name'];

			// Fast threshold reject before allocating the output entry array.
			if ($size === $limit && !self::beatsRoot($score, $fill, $name, $heap[0])) {
				continue;
			}

			$entry = [
				'name'           => $name,
				'type'           => $detail['type'],
				'fill_pct'       => $fill,
				'skew_delta_pct' => $skewDelta,
				'headroom_pct'   => $detail['headroom_pct'],
				'status_flags'   => $detail['status_flags'],
			];

			self::heapPush($heap, $size, $limit, [$score, $fill, $name, $entry]);
		}

		return self::sortDescending($heap);
	}

	/**
	 * Ingest a stream of pre-aggregated heavy job/client summary records
	 * (e.g. Lustre job_stats, GPFS mmpmon, LDMS rollups) and return only the
	 * Top-K heavy hitters in bounded O(K) memory. Invalid records are skipped.
	 *
	 * @param iterable<array<string, mixed>> $records
	 * @param int                            $k
	 *
	 * @return list<array{id: string, source: string, bytes_per_sec: float, ops_per_sec: float, read_bytes: float, write_bytes: float}>
	 */
	public static function topHeavyHitters(iterable $records, int $k = self::DEFAULT_K): array {
		$limit = self::clampK($k);
		/** @var list<array{0: float, 1: float, 2: string, 3: array{id: string, source: string, bytes_per_sec: float, ops_per_sec: float, read_bytes: float, write_bytes: float}}> $heap */
		$heap = [];
		$size = 0;

		foreach ($records as $record) {
			$rawSource = $record['source'] ?? null;

			if (!is_string($rawSource)) {
				continue;
			}

			$source = strtolower(trim($rawSource));

			if (!isset(self::ALLOWED_SOURCE_MAP[$source])) {
				continue;
			}

			$rawId = $record['id'] ?? null;

			if (!is_string($rawId)) {
				continue;
			}

			$id    = trim($rawId);
			$idLen = strlen($id);

			if ($idLen === 0 || $idLen > 64 || strspn($id, self::ID_CHARS) !== $idLen) {
				continue;
			}

			$bytesPerSec = self::nonNegFloat($record['bytes_per_sec'] ?? null);
			$opsPerSec   = self::nonNegFloat($record['ops_per_sec'] ?? null);

			if ($bytesPerSec === null && $opsPerSec === null) {
				continue;
			}

			$bps = $bytesPerSec ?? 0.0;
			$ops = $opsPerSec ?? 0.0;

			// Reject below-threshold records before parsing secondary fields or allocating entry arrays.
			if ($size === $limit && !self::beatsRoot($bps, $ops, $id, $heap[0])) {
				continue;
			}

			$readBytes  = self::nonNegFloat($record['read_bytes'] ?? 0.0) ?? 0.0;
			$writeBytes = self::nonNegFloat($record['write_bytes'] ?? 0.0) ?? 0.0;

			$entry = [
				'id'            => $id,
				'source'        => $source,
				'bytes_per_sec' => $bps,
				'ops_per_sec'   => $ops,
				'read_bytes'    => $readBytes,
				'write_bytes'   => $writeBytes,
			];

			self::heapPush($heap, $size, $limit, [$bps, $ops, $id, $entry]);
		}

		return self::sortDescending($heap);
	}

	/**
	 * Return true when candidate ($s0, $s1, $name) ranks strictly higher than
	 * the current worst element at the min-heap root.
	 *
	 * @template T
	 * @param float                                       $s0
	 * @param float                                       $s1
	 * @param string                                      $name
	 * @param array{0: float, 1: float, 2: string, 3: T} $root
	 *
	 * @return bool
	 */
	private static function beatsRoot(float $s0, float $s1, string $name, array $root): bool {
		if ($s0 !== $root[0]) {
			return $s0 > $root[0];
		}

		if ($s1 !== $root[1]) {
			return $s1 > $root[1];
		}

		return strcmp($name, $root[2]) < 0;
	}

	/**
	 * Return true when heap element $a is worse (smaller in min-heap order)
	 * than element $b.
	 *
	 * @template T
	 * @param array{0: float, 1: float, 2: string, 3: T} $a
	 * @param array{0: float, 1: float, 2: string, 3: T} $b
	 *
	 * @return bool
	 */
	private static function isWorse(array $a, array $b): bool {
		if ($a[0] !== $b[0]) {
			return $a[0] < $b[0];
		}

		if ($a[1] !== $b[1]) {
			return $a[1] < $b[1];
		}

		return strcmp($a[2], $b[2]) > 0;
	}

	/**
	 * Insert or replace the root of a bounded binary min-heap in O(log K).
	 *
	 * @template T
	 * @param list<array{0: float, 1: float, 2: string, 3: T}> $heap
	 * @param int                                              $size
	 * @param int                                              $limit
	 * @param array{0: float, 1: float, 2: string, 3: T}       $tuple
	 */
	private static function heapPush(array &$heap, int &$size, int $limit, array $tuple): void {
		if ($size < $limit) {
			$idx        = $size++;
			$heap[$idx] = $tuple;

			while ($idx > 0) {
				$parent = ($idx - 1) >> 1;

				if (!self::isWorse($heap[$idx], $heap[$parent])) {
					break;
				}

				$tmp           = $heap[$parent];
				$heap[$parent] = $heap[$idx];
				$heap[$idx]    = $tmp;
				$idx           = $parent;
			}

			return;
		}

		$heap[0] = $tuple;
		$idx     = 0;

		while (true) {
			$left     = ($idx << 1) + 1;
			$smallest = $idx;

			if ($left < $size && self::isWorse($heap[$left], $heap[$smallest])) {
				$smallest = $left;
			}

			$right = $left + 1;

			if ($right < $size && self::isWorse($heap[$right], $heap[$smallest])) {
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

	/**
	 * Sort the bounded K heap elements descending by primary score, secondary
	 * score, and ascending by name using scalar comparisons.
	 *
	 * @template T
	 * @param list<array{0: float, 1: float, 2: string, 3: T}> $heap
	 *
	 * @return list<T>
	 */
	private static function sortDescending(array $heap): array {
		usort($heap, static function (array $a, array $b): int {
			if ($a[0] !== $b[0]) {
				return $b[0] <=> $a[0];
			}

			if ($a[1] !== $b[1]) {
				return $b[1] <=> $a[1];
			}

			return strcmp($a[2], $b[2]);
		});

		$out = [];

		foreach ($heap as $row) {
			$out[] = $row[3];
		}

		return $out;
	}

	/**
	 * Clamp k.
	 *
	 * @param int $k
	 * @return int
	 */
	private static function clampK(int $k): int {
		return max(self::MIN_K, min(self::MAX_K, $k));
	}

	/**
	 * Non neg float.
	 *
	 * @param mixed $value
	 * @return ?float
	 */
	private static function nonNegFloat(mixed $value): ?float {
		if (is_int($value) || is_float($value)) {
			$f = (float) $value;

			return is_finite($f) && $f >= 0.0 ? $f : null;
		}

		return null;
	}
}
