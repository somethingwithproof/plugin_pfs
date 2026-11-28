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
 * Backend-specific stall counters and rates across Lustre, IBM Storage Scale
 * (GPFS), and BeeGFS storage servers:
 * - Lustre: lnet_drops, lnet_retransmits, and their per-second rates
 * - GPFS: nsd_queue_depth and pagepool_hit_pct
 * - BeeGFS: chunk_queue_depth and lock_waiters
 */
final readonly class StallMetrics {
	/**
	 * Initialize backend-specific stall telemetry.
	 *
	 * @param ?float $lnetDrops            Lustre LNet cumulative dropped message count
	 * @param ?float $lnetRetransmits      Lustre LNet cumulative retransmit/error count
	 * @param ?float $lnetDropsRate        Lustre LNet drops per second
	 * @param ?float $lnetRetransmitsRate  Lustre LNet retransmits per second
	 * @param ?float $nsdQueueDepth        GPFS NSD server queue depth
	 * @param ?float $pagepoolHitPct       GPFS pagepool hit ratio percentage (0..100)
	 * @param ?float $chunkQueueDepth      BeeGFS chunkserver disk queue depth
	 * @param ?float $lockWaiters          BeeGFS lock contention waiter count
	 */
	public function __construct(
		public ?float $lnetDrops = null,
		public ?float $lnetRetransmits = null,
		public ?float $lnetDropsRate = null,
		public ?float $lnetRetransmitsRate = null,
		public ?float $nsdQueueDepth = null,
		public ?float $pagepoolHitPct = null,
		public ?float $chunkQueueDepth = null,
		public ?float $lockWaiters = null,
	) {
	}

	/**
	 * Compute per-second LNet drop and retransmit rates relative to a previous
	 * reading. Returns null rates on missing history, non-positive elapsed time,
	 * or counter reset (value decreased).
	 *
	 * @param ?self $previous       previous stall reading from the same server
	 * @param int   $elapsedSeconds seconds between readings (> 0)
	 *
	 * @return self
	 */
	public function withLnetRates(?self $previous, int $elapsedSeconds): self {
		$dropsRate = self::counterRate($this->lnetDrops, $previous?->lnetDrops, $elapsedSeconds);
		$retxRate  = self::counterRate($this->lnetRetransmits, $previous?->lnetRetransmits, $elapsedSeconds);

		return new self(
			$this->lnetDrops,
			$this->lnetRetransmits,
			$dropsRate,
			$retxRate,
			$this->nsdQueueDepth,
			$this->pagepoolHitPct,
			$this->chunkQueueDepth,
			$this->lockWaiters,
		);
	}

	/**
	 * Aggregate stall counters across multiple NodeReport or StallMetrics entries.
	 *
	 * Sums cumulative LNet counters, rates, and BeeGFS lock waiters; takes the
	 * peak queue depth for GPFS NSD and BeeGFS chunkservers; and averages
	 * reported GPFS pagepool hit ratios across contributing servers.
	 *
	 * @param iterable<NodeReport|self|null> $items source reports or stall models
	 *
	 * @return self
	 */
	public static function aggregate(iterable $items): self {
		$lnetDrops       = null;
		$lnetRetransmits = null;
		$lnetDropsRate   = null;
		$lnetRetxRate    = null;
		$nsdQueueMax     = null;
		$ppHitSum        = 0.0;
		$ppHitCount      = 0;
		$chunkQueueMax   = null;
		$lockWaiters     = null;

		foreach ($items as $item) {
			$s = $item instanceof NodeReport ? $item->stalls : $item;

			if ($s === null) {
				continue;
			}

			if ($s->lnetDrops !== null) {
				$lnetDrops = ($lnetDrops ?? 0.0) + $s->lnetDrops;
			}

			if ($s->lnetRetransmits !== null) {
				$lnetRetransmits = ($lnetRetransmits ?? 0.0) + $s->lnetRetransmits;
			}

			if ($s->lnetDropsRate !== null) {
				$lnetDropsRate = ($lnetDropsRate ?? 0.0) + $s->lnetDropsRate;
			}

			if ($s->lnetRetransmitsRate !== null) {
				$lnetRetxRate = ($lnetRetxRate ?? 0.0) + $s->lnetRetransmitsRate;
			}

			if ($s->nsdQueueDepth !== null) {
				$nsdQueueMax = $nsdQueueMax === null ? $s->nsdQueueDepth : max($nsdQueueMax, $s->nsdQueueDepth);
			}

			if ($s->pagepoolHitPct !== null) {
				$ppHitSum += $s->pagepoolHitPct;
				$ppHitCount++;
			}

			if ($s->chunkQueueDepth !== null) {
				$chunkQueueMax = $chunkQueueMax === null ? $s->chunkQueueDepth : max($chunkQueueMax, $s->chunkQueueDepth);
			}

			if ($s->lockWaiters !== null) {
				$lockWaiters = ($lockWaiters ?? 0.0) + $s->lockWaiters;
			}
		}

		$ppHitMean = $ppHitCount > 0 ? round($ppHitSum / $ppHitCount, 2) : null;

		return new self(
			$lnetDrops,
			$lnetRetransmits,
			$lnetDropsRate !== null ? round($lnetDropsRate, 4) : null,
			$lnetRetxRate !== null ? round($lnetRetxRate, 4) : null,
			$nsdQueueMax,
			$ppHitMean,
			$chunkQueueMax,
			$lockWaiters,
		);
	}

	/**
	 * Return true when no stall counter or rate is populated.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->lnetDrops === null
			&& $this->lnetRetransmits === null
			&& $this->lnetDropsRate === null
			&& $this->lnetRetransmitsRate === null
			&& $this->nsdQueueDepth === null
			&& $this->pagepoolHitPct === null
			&& $this->chunkQueueDepth === null
			&& $this->lockWaiters === null;
	}

	/**
	 * Export canonical metric names for monitoring and diagnostic consumers.
	 *
	 * @return array{
	 *   lnet_drops: ?float,
	 *   lnet_retransmits: ?float,
	 *   lnet_drops_rate: ?float,
	 *   lnet_retransmits_rate: ?float,
	 *   nsd_queue_depth: ?float,
	 *   pagepool_hit_pct: ?float,
	 *   chunk_queue_depth: ?float,
	 *   lock_waiters: ?float
	 * }
	 */
	public function toArray(): array {
		return [
			'lnet_drops'            => $this->lnetDrops,
			'lnet_retransmits'      => $this->lnetRetransmits,
			'lnet_drops_rate'       => $this->lnetDropsRate,
			'lnet_retransmits_rate' => $this->lnetRetransmitsRate,
			'nsd_queue_depth'       => $this->nsdQueueDepth,
			'pagepool_hit_pct'      => $this->pagepoolHitPct,
			'chunk_queue_depth'     => $this->chunkQueueDepth,
			'lock_waiters'          => $this->lockWaiters,
		];
	}

	/**
	 * Compute a non-negative per-second rate from two cumulative counters.
	 *
	 * @param ?float $current        latest cumulative counter
	 * @param ?float $previous       prior cumulative counter
	 * @param int    $elapsedSeconds seconds between samples
	 *
	 * @return ?float
	 */
	private static function counterRate(?float $current, ?float $previous, int $elapsedSeconds): ?float {
		if ($current === null || $previous === null || $elapsedSeconds <= 0 || $current < $previous) {
			return null;
		}

		return round(($current - $previous) / $elapsedSeconds, 4);
	}
}
