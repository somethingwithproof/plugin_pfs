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
 * Bounded-memory RPC latency percentile accumulator across read, write, and
 * metadata operation classes for parallel filesystem clusters.
 *
 * Each channel owns a fixed 201-bucket LatencyHistogram (~O(1) memory) so
 * histograms from thousands of OSS, MDS, NSD, or BeeGFS servers merge
 * associatively to yield cluster-wide p50, p95, and p99 RPC latencies.
 */
final class RpcLatencyMetrics {
	/** Read RPC latency histogram (microseconds). */
	public readonly LatencyHistogram $read;
	/** Write RPC latency histogram (microseconds). */
	public readonly LatencyHistogram $write;
	/** Metadata RPC latency histogram (microseconds). */
	public readonly LatencyHistogram $metadata;

	/**
	 * Initialize an RPC latency accumulator across read, write, and metadata channels.
	 *
	 * @param ?LatencyHistogram $read     optional read RPC histogram
	 * @param ?LatencyHistogram $write    optional write RPC histogram
	 * @param ?LatencyHistogram $metadata optional metadata RPC histogram
	 */
	public function __construct(
		?LatencyHistogram $read = null,
		?LatencyHistogram $write = null,
		?LatencyHistogram $metadata = null,
	) {
		$this->read     = $read ?? new LatencyHistogram();
		$this->write    = $write ?? new LatencyHistogram();
		$this->metadata = $metadata ?? new LatencyHistogram();
	}

	/**
	 * Record read RPC latency in microseconds.
	 *
	 * @param float $microseconds latency in microseconds
	 * @param int   $weight       sample weight (>= 1)
	 */
	public function recordReadUs(float $microseconds, int $weight = 1): void {
		$this->read->recordMicroseconds($microseconds, $weight);
	}

	/**
	 * Record write RPC latency in microseconds.
	 *
	 * @param float $microseconds latency in microseconds
	 * @param int   $weight       sample weight (>= 1)
	 */
	public function recordWriteUs(float $microseconds, int $weight = 1): void {
		$this->write->recordMicroseconds($microseconds, $weight);
	}

	/**
	 * Record metadata RPC latency in microseconds.
	 *
	 * @param float $microseconds latency in microseconds
	 * @param int   $weight       sample weight (>= 1)
	 */
	public function recordMetadataUs(float $microseconds, int $weight = 1): void {
		$this->metadata->recordMicroseconds($microseconds, $weight);
	}

	/**
	 * Record read RPC latency in milliseconds.
	 *
	 * @param float $milliseconds latency in milliseconds
	 * @param int   $weight       sample weight (>= 1)
	 */
	public function recordReadMs(float $milliseconds, int $weight = 1): void {
		$this->read->recordMilliseconds($milliseconds, $weight);
	}

	/**
	 * Record write RPC latency in milliseconds.
	 *
	 * @param float $milliseconds latency in milliseconds
	 * @param int   $weight       sample weight (>= 1)
	 */
	public function recordWriteMs(float $milliseconds, int $weight = 1): void {
		$this->write->recordMilliseconds($milliseconds, $weight);
	}

	/**
	 * Record metadata RPC latency in milliseconds.
	 *
	 * @param float $milliseconds latency in milliseconds
	 * @param int   $weight       sample weight (>= 1)
	 */
	public function recordMetadataMs(float $milliseconds, int $weight = 1): void {
		$this->metadata->recordMilliseconds($milliseconds, $weight);
	}

	/**
	 * Merge another RPC latency accumulator into this one in place.
	 *
	 * @param self $other accumulator to merge
	 */
	public function mergeInPlace(self $other): void {
		$this->read->mergeInPlace($other->read);
		$this->write->mergeInPlace($other->write);
		$this->metadata->mergeInPlace($other->metadata);
	}

	/**
	 * Return a new accumulator holding the merged histograms of this and $other.
	 *
	 * @param self $other accumulator to merge
	 *
	 * @return self
	 */
	public function merge(self $other): self {
		return new self(
			$this->read->merge($other->read),
			$this->write->merge($other->write),
			$this->metadata->merge($other->metadata),
		);
	}

	/**
	 * Merge a stream of NodeReport or RpcLatencyMetrics instances in O(1) memory.
	 *
	 * @param iterable<NodeReport|self|null> $items source reports or accumulators
	 *
	 * @return self
	 */
	public static function mergeAll(iterable $items): self {
		$merged = new self();

		foreach ($items as $item) {
			if ($item instanceof self) {
				$merged->mergeInPlace($item);
			} elseif ($item instanceof NodeReport && $item->rpcLatency !== null) {
				$merged->mergeInPlace($item->rpcLatency);
			}
		}

		return $merged;
	}

	/**
	 * Return true when no read, write, or metadata samples have been recorded.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->read->count() === 0
			&& $this->write->count() === 0
			&& $this->metadata->count() === 0;
	}

	/**
	 * Export p50/p95/p99 snapshots for read, write, and metadata RPC channels.
	 *
	 * @return array{
	 *   read: array{count: int, min_us: ?float, max_us: ?float, mean_us: ?float, p50_us: ?float, p95_us: ?float, p99_us: ?float, p50_ms: ?float, p95_ms: ?float, p99_ms: ?float},
	 *   write: array{count: int, min_us: ?float, max_us: ?float, mean_us: ?float, p50_us: ?float, p95_us: ?float, p99_us: ?float, p50_ms: ?float, p95_ms: ?float, p99_ms: ?float},
	 *   metadata: array{count: int, min_us: ?float, max_us: ?float, mean_us: ?float, p50_us: ?float, p95_us: ?float, p99_us: ?float, p50_ms: ?float, p95_ms: ?float, p99_ms: ?float}
	 * }
	 */
	public function toArray(): array {
		return [
			'read'     => $this->read->snapshot(),
			'write'    => $this->write->snapshot(),
			'metadata' => $this->metadata->snapshot(),
		];
	}
}
