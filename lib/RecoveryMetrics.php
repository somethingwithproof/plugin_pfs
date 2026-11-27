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
 * Aggregated client eviction and target recovery state counters across storage
 * and metadata servers (evicted_clients, recovery_active_targets, and
 * recovery_duration_s).
 */
final readonly class RecoveryMetrics {
	/** Recovery state strings representing an actively recovering or resyncing target. */
	private const ACTIVE_STATE_MAP = [
		'RECOVERING'          => true,
		'WAITING'             => true,
		'WAITING_FOR_CLIENTS' => true,
		'RESYNCING'           => true,
		'NEEDS_RESYNC'        => true,
		'RESTRIPING'          => true,
		'REBUILDING'          => true,
	];

	/**
	 * Initialize recovery and client eviction aggregates.
	 *
	 * @param int   $evictedClients          total evicted clients observed across servers/targets
	 * @param int   $recoveryActiveTargets   count of targets currently in an active recovery state
	 * @param float $recoveryDurationSeconds longest observed recovery duration in seconds
	 */
	public function __construct(
		public int $evictedClients = 0,
		public int $recoveryActiveTargets = 0,
		public float $recoveryDurationSeconds = 0.0,
	) {
	}

	/**
	 * Return true when a target recovery state indicates active recovery or resync.
	 *
	 * @param ?string $state normalized recovery state token
	 *
	 * @return bool
	 */
	public static function isActiveState(?string $state): bool {
		if ($state === null || $state === '') {
			return false;
		}

		return isset(self::ACTIVE_STATE_MAP[strtoupper(trim($state))]);
	}

	/**
	 * Aggregate eviction and recovery counters across a stream of TargetReport entries.
	 *
	 * @param iterable<TargetReport> $targets parsed target reports
	 *
	 * @return self
	 */
	public static function fromTargets(iterable $targets): self {
		$evicted     = 0;
		$activeCount = 0;
		$maxDuration = 0.0;

		foreach ($targets as $target) {
			if ($target->evictedClients !== null && $target->evictedClients > 0) {
				$evicted += $target->evictedClients;
			} elseif (isset($target->values['evicted_clients']) && $target->values['evicted_clients'] > 0.0) {
				$evicted += (int) round($target->values['evicted_clients']);
			}

			if (self::isActiveState($target->recovery)) {
				$activeCount++;
			}

			$duration = $target->recoveryDurationSeconds ?? $target->values['recovery_duration_s'] ?? null;

			if ($duration !== null && is_finite($duration) && $duration > $maxDuration) {
				$maxDuration = $duration;
			}
		}

		return new self($evicted, $activeCount, $maxDuration);
	}

	/**
	 * Merge recovery aggregates across multiple NodeReport or RecoveryMetrics instances.
	 *
	 * @param iterable<NodeReport|self|null> $items source reports or aggregates
	 *
	 * @return self
	 */
	public static function mergeAll(iterable $items): self {
		$evicted     = 0;
		$activeCount = 0;
		$maxDuration = 0.0;

		foreach ($items as $item) {
			$m = $item instanceof NodeReport ? $item->recovery : $item;

			if ($m === null) {
				continue;
			}

			$evicted     += $m->evictedClients;
			$activeCount += $m->recoveryActiveTargets;

			if ($m->recoveryDurationSeconds > $maxDuration) {
				$maxDuration = $m->recoveryDurationSeconds;
			}
		}

		return new self($evicted, $activeCount, $maxDuration);
	}

	/**
	 * Export canonical metric names for monitoring, CasC, and MCP surfaces.
	 *
	 * @return array{evicted_clients: int, recovery_active_targets: int, recovery_duration_s: float}
	 */
	public function toArray(): array {
		return [
			'evicted_clients'         => $this->evictedClients,
			'recovery_active_targets' => $this->recoveryActiveTargets,
			'recovery_duration_s'     => $this->recoveryDurationSeconds,
		];
	}
}
