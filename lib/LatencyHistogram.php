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
 * Bounded-memory, mergeable logarithmic-linear latency histogram in microseconds.
 *
 * Uses 8 exact 1-microsecond base buckets (0..7 us) followed by 24 power-of-2
 * octaves with 8 linear sub-buckets each (8 us up to 134,217,727 us, ~134.2 s)
 * plus 1 overflow bucket (201 fixed integer slots total). Memory stays O(1)
 * regardless of whether a cluster records 10 or 10^9 RPC samples, and merging
 * across thousands of storage servers is associative, commutative, and O(201).
 */
final class LatencyHistogram {
	/** Number of exact 1-microsecond base buckets (0..7 us). */
	public const BASE_BUCKETS = 8;
	/** Sub-buckets per power-of-2 octave (gives <= 6.25% max midpoint error). */
	public const SUB_BUCKETS = 8;
	/** Number of power-of-2 octaves above the base buckets (covers up to 2^27 - 1 us). */
	public const OCTAVES = 24;
	/** Index of the final overflow bucket (>= 2^27 us). */
	public const OVERFLOW_BUCKET = 200;
	/** Total fixed bucket count. */
	public const BUCKET_COUNT = 201;
	/** Exclusive upper microsecond bound before the overflow bucket (2^27 us ~ 134.2 s). */
	public const MAX_TRACKED_US = 134217728.0;

	/**
	 * Fixed bucket sample counts indexed 0..200.
	 *
	 * @var array<int, int>
	 */
	private array $buckets;

	/** Total number of recorded RPC samples. */
	private int $count = 0;

	/** Minimum observed latency in microseconds, or null when empty. */
	private ?float $minUs = null;

	/** Maximum observed latency in microseconds, or null when empty. */
	private ?float $maxUs = null;

	/** Running sum of observed latencies in microseconds. */
	private float $sumUs = 0.0;

	/**
	 * Initialize an empty bounded latency histogram.
	 */
	public function __construct() {
		$this->buckets = array_fill(0, self::BUCKET_COUNT, 0);
	}

	/**
	 * Record one or more identical latency observations in microseconds.
	 *
	 * Negative, non-finite, or non-positive-weight inputs are ignored so
	 * malformed node output can never corrupt quantile state.
	 *
	 * @param float $microseconds latency sample in microseconds (>= 0)
	 * @param int   $weight       number of observations represented (>= 1)
	 */
	public function recordMicroseconds(float $microseconds, int $weight = 1): void {
		if (!is_finite($microseconds) || $microseconds < 0.0 || $weight < 1) {
			return;
		}

		$idx                 = self::bucketIndex($microseconds);
		$this->buckets[$idx] = $this->buckets[$idx] + $weight;
		$this->count        += $weight;
		$this->sumUs        += $microseconds * $weight;

		if ($this->minUs === null || $microseconds < $this->minUs) {
			$this->minUs = $microseconds;
		}

		if ($this->maxUs === null || $microseconds > $this->maxUs) {
			$this->maxUs = $microseconds;
		}
	}

	/**
	 * Record one or more latency observations given in milliseconds.
	 *
	 * @param float $milliseconds latency sample in milliseconds (>= 0)
	 * @param int   $weight       number of observations represented (>= 1)
	 */
	public function recordMilliseconds(float $milliseconds, int $weight = 1): void {
		if (!is_finite($milliseconds) || $milliseconds < 0.0) {
			return;
		}

		$this->recordMicroseconds($milliseconds * 1000.0, $weight);
	}

	/**
	 * Fold kernel-aggregated summary statistics (count, min, max, sum in us)
	 * such as Lustre obdfilter/md_stats [usecs] lines into the histogram.
	 *
	 * Preserves exact sample count, min, max, and mean while remaining
	 * order-independent across servers and targets.
	 *
	 * @param int   $samples number of RPC samples (>= 1)
	 * @param float $minUs   minimum latency in microseconds (>= 0)
	 * @param float $maxUs   maximum latency in microseconds (>= $minUs)
	 * @param float $sumUs   cumulative latency sum in microseconds (>= 0)
	 */
	public function recordSummary(int $samples, float $minUs, float $maxUs, float $sumUs): void {
		if (
			$samples < 1
			|| !is_finite($minUs) || !is_finite($maxUs) || !is_finite($sumUs)
			|| $minUs < 0.0 || $maxUs < $minUs || $sumUs < 0.0
		) {
			return;
		}

		$mean = $sumUs / $samples;

		if ($mean < $minUs) {
			$mean = $minUs;
		} elseif ($mean > $maxUs) {
			$mean = $maxUs;
		}

		if ($samples === 1) {
			$this->recordMicroseconds($mean, 1);

			return;
		}

		if ($samples === 2) {
			$this->recordMicroseconds($minUs, 1);
			$this->recordMicroseconds($maxUs, 1);

			return;
		}

		$minIdx = self::bucketIndex($minUs);
		$maxIdx = self::bucketIndex($maxUs);
		$midIdx = self::bucketIndex($mean);

		$this->buckets[$minIdx] = $this->buckets[$minIdx] + 1;
		$this->buckets[$maxIdx] = $this->buckets[$maxIdx] + 1;
		$this->buckets[$midIdx] = $this->buckets[$midIdx] + ($samples - 2);

		$this->count += $samples;
		$this->sumUs += $sumUs;

		if ($this->minUs === null || $minUs < $this->minUs) {
			$this->minUs = $minUs;
		}

		if ($this->maxUs === null || $maxUs > $this->maxUs) {
			$this->maxUs = $maxUs;
		}
	}

	/**
	 * Merge another histogram into this instance in O(BUCKET_COUNT) time.
	 *
	 * @param self $other source histogram to add
	 */
	public function mergeInPlace(self $other): void {
		if ($other->count === 0) {
			return;
		}

		for ($i = 0; $i < self::BUCKET_COUNT; $i++) {
			$c = $other->buckets[$i];

			if ($c > 0) {
				$this->buckets[$i] += $c;
			}
		}

		$this->count += $other->count;
		$this->sumUs += $other->sumUs;

		if ($other->minUs !== null && ($this->minUs === null || $other->minUs < $this->minUs)) {
			$this->minUs = $other->minUs;
		}

		if ($other->maxUs !== null && ($this->maxUs === null || $other->maxUs > $this->maxUs)) {
			$this->maxUs = $other->maxUs;
		}
	}

	/**
	 * Return a new histogram containing the merged counts of this and $other.
	 *
	 * @param self $other source histogram to merge
	 *
	 * @return self
	 */
	public function merge(self $other): self {
		$copy = clone $this;
		$copy->mergeInPlace($other);

		return $copy;
	}

	/**
	 * Return the total number of recorded samples.
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->count;
	}

	/**
	 * Return the exact minimum latency in microseconds, or null when empty.
	 *
	 * @return ?float
	 */
	public function minUs(): ?float {
		return $this->minUs;
	}

	/**
	 * Return the exact maximum latency in microseconds, or null when empty.
	 *
	 * @return ?float
	 */
	public function maxUs(): ?float {
		return $this->maxUs;
	}

	/**
	 * Return the arithmetic mean latency in microseconds, or null when empty.
	 *
	 * @return ?float
	 */
	public function meanUs(): ?float {
		return $this->count > 0 ? round($this->sumUs / $this->count, 2) : null;
	}

	/**
	 * Estimate an arbitrary quantile (0.0 <= $q <= 1.0) in microseconds using
	 * intra-bucket linear interpolation clamped to observed [minUs, maxUs].
	 *
	 * @param float $quantile target quantile in [0.0, 1.0]
	 *
	 * @return ?float
	 */
	public function quantileUs(float $quantile): ?float {
		if ($this->count === 0 || !is_finite($quantile) || $quantile < 0.0 || $quantile > 1.0) {
			return null;
		}

		if ($quantile === 0.0) {
			return $this->minUs;
		}

		if ($quantile === 1.0) {
			return $this->maxUs;
		}

		$rank       = $quantile * $this->count;
		$cumulative = 0;

		for ($i = 0; $i < self::BUCKET_COUNT; $i++) {
			$bucketCount = $this->buckets[$i];

			if ($bucketCount === 0) {
				continue;
			}

			$next = $cumulative + $bucketCount;

			if ($next >= $rank) {
				[$lo, $hi] = self::bucketBounds($i);

				if ($i === self::OVERFLOW_BUCKET) {
					$hi = $this->maxUs !== null && $this->maxUs > $lo ? $this->maxUs : $lo;
				}

				if ($this->minUs !== null && $lo < $this->minUs) {
					$lo = $this->minUs;
				}

				if ($this->maxUs !== null && $hi > $this->maxUs) {
					$hi = $this->maxUs;
				}

				$fraction = ($rank - $cumulative) / $bucketCount;
				$value    = $lo + ($hi - $lo) * $fraction;

				return round($value, 2);
			}

			$cumulative = $next;
		}

		return $this->maxUs;
	}

	/**
	 * Return the 50th percentile (median) latency in microseconds.
	 *
	 * @return ?float
	 */
	public function p50Us(): ?float {
		return $this->quantileUs(0.50);
	}

	/**
	 * Return the 95th percentile latency in microseconds.
	 *
	 * @return ?float
	 */
	public function p95Us(): ?float {
		return $this->quantileUs(0.95);
	}

	/**
	 * Return the 99th percentile latency in microseconds.
	 *
	 * @return ?float
	 */
	public function p99Us(): ?float {
		return $this->quantileUs(0.99);
	}

	/**
	 * Return the 50th percentile latency in milliseconds.
	 *
	 * @return ?float
	 */
	public function p50Ms(): ?float {
		$v = $this->p50Us();

		return $v === null ? null : round($v / 1000.0, 3);
	}

	/**
	 * Return the 95th percentile latency in milliseconds.
	 *
	 * @return ?float
	 */
	public function p95Ms(): ?float {
		$v = $this->p95Us();

		return $v === null ? null : round($v / 1000.0, 3);
	}

	/**
	 * Return the 99th percentile latency in milliseconds.
	 *
	 * @return ?float
	 */
	public function p99Ms(): ?float {
		$v = $this->p99Us();

		return $v === null ? null : round($v / 1000.0, 3);
	}

	/**
	 * Produce a summary snapshot of count, extrema, mean, and p50/p95/p99
	 * percentiles in both microseconds and milliseconds.
	 *
	 * @return array{
	 *   count: int,
	 *   min_us: ?float,
	 *   max_us: ?float,
	 *   mean_us: ?float,
	 *   p50_us: ?float,
	 *   p95_us: ?float,
	 *   p99_us: ?float,
	 *   p50_ms: ?float,
	 *   p95_ms: ?float,
	 *   p99_ms: ?float
	 * }
	 */
	public function snapshot(): array {
		$p50 = $this->p50Us();
		$p95 = $this->p95Us();
		$p99 = $this->p99Us();

		return [
			'count'   => $this->count,
			'min_us'  => $this->minUs,
			'max_us'  => $this->maxUs,
			'mean_us' => $this->meanUs(),
			'p50_us'  => $p50,
			'p95_us'  => $p95,
			'p99_us'  => $p99,
			'p50_ms'  => $p50 === null ? null : round($p50 / 1000.0, 3),
			'p95_ms'  => $p95 === null ? null : round($p95 / 1000.0, 3),
			'p99_ms'  => $p99 === null ? null : round($p99 / 1000.0, 3),
		];
	}

	/**
	 * Export a compact sparse representation containing only non-zero buckets.
	 *
	 * @return array{count: int, min_us: ?float, max_us: ?float, sum_us: float, buckets: array<int, int>}
	 */
	public function toArray(): array {
		$sparse = [];

		for ($i = 0; $i < self::BUCKET_COUNT; $i++) {
			if ($this->buckets[$i] > 0) {
				$sparse[$i] = $this->buckets[$i];
			}
		}

		return [
			'count'   => $this->count,
			'min_us'  => $this->minUs,
			'max_us'  => $this->maxUs,
			'sum_us'  => $this->sumUs,
			'buckets' => $sparse,
		];
	}

	/**
	 * Reconstruct a histogram from a serialized sparse array representation.
	 *
	 * @param array<string, mixed> $data serialized histogram payload
	 *
	 * @return self
	 */
	public static function fromArray(array $data): self {
		$hist = new self();

		if (!isset($data['buckets']) || !is_array($data['buckets'])) {
			return $hist;
		}

		$count = 0;

		foreach ($data['buckets'] as $idx => $val) {
			if (!is_int($idx) || $idx < 0 || $idx >= self::BUCKET_COUNT || !is_int($val) || $val < 1) {
				continue;
			}

			$hist->buckets[$idx] = $val;
			$count              += $val;
		}

		if ($count === 0) {
			return $hist;
		}

		$hist->count = $count;
		$minUs       = isset($data['min_us']) && (is_int($data['min_us']) || is_float($data['min_us'])) && is_finite((float) $data['min_us']) && (float) $data['min_us'] >= 0.0 ? (float) $data['min_us'] : null;
		$maxUs       = isset($data['max_us']) && (is_int($data['max_us']) || is_float($data['max_us'])) && is_finite((float) $data['max_us']) && (float) $data['max_us'] >= 0.0 ? (float) $data['max_us'] : null;
		$sumUs       = isset($data['sum_us']) && (is_int($data['sum_us']) || is_float($data['sum_us'])) && is_finite((float) $data['sum_us']) && (float) $data['sum_us'] >= 0.0 ? (float) $data['sum_us'] : 0.0;

		$hist->minUs = $minUs;
		$hist->maxUs = ($minUs !== null && $maxUs !== null && $maxUs < $minUs) ? $minUs : $maxUs;
		$hist->sumUs = $sumUs;

		return $hist;
	}

	/**
	 * Map a non-negative microsecond latency to its fixed bucket index (0..200)
	 * using integer bit shifts with zero floating-point logarithm calls.
	 *
	 * @param float $microseconds latency in microseconds
	 *
	 * @return int
	 */
	public static function bucketIndex(float $microseconds): int {
		if ($microseconds < 8.0) {
			return $microseconds <= 0.0 ? 0 : (int) $microseconds;
		}

		if ($microseconds >= self::MAX_TRACKED_US) {
			return self::OVERFLOW_BUCKET;
		}

		$v = (int) $microseconds;

		// Locate the highest set bit (3..26) via 5-step binary search.
		$msb = 0;
		$x   = $v;

		if ($x >= 65536) {
			$x  >>= 16;
			$msb += 16;
		}

		if ($x >= 256) {
			$x  >>= 8;
			$msb += 8;
		}

		if ($x >= 16) {
			$x  >>= 4;
			$msb += 4;
		}

		if ($x >= 4) {
			$x  >>= 2;
			$msb += 2;
		}

		if ($x >= 2) {
			$msb += 1;
		}

		$shift = $msb > 3 ? $msb - 3 : 0;
		$sub   = ($v >> $shift) & 7;

		return self::BASE_BUCKETS + ($shift * self::SUB_BUCKETS) + $sub;
	}

	/**
	 * Return the [lower, upper] microsecond bounds of a bucket index.
	 *
	 * @param int $index bucket index (0..200)
	 *
	 * @return array{0: float, 1: float}
	 */
	public static function bucketBounds(int $index): array {
		if ($index < self::BASE_BUCKETS) {
			$lo = (float) max(0, $index);

			return [$lo, $lo];
		}

		if ($index >= self::OVERFLOW_BUCKET) {
			return [self::MAX_TRACKED_US, self::MAX_TRACKED_US];
		}

		$offset = $index - self::BASE_BUCKETS;
		$shift  = intdiv($offset, self::SUB_BUCKETS);
		$sub    = $offset % self::SUB_BUCKETS;
		$step   = 1 << $shift;
		$lo     = (float) ((8 + $sub) * $step);
		$hi     = (float) ((8 + $sub + 1) * $step - 1);

		return [$lo, $hi];
	}
}
