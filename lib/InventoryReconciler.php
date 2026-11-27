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
 * Turns one run's node reports for one filesystem into inventory decisions
 * (REQ-INV-001 to REQ-INV-004), following the authoritative-node rule in
 * docs/research/failover.md:
 *
 *  1. A target is identified by filesystem, type, and index from its name;
 *     a target named for another filesystem is not stored, only counted.
 *  2. Reported by exactly one node read successfully this run: present, with
 *     that node as its source. Since failed reads report nothing, the
 *     freshest successful observation always wins over an older one from a
 *     node that failed.
 *  3. Reported by two or more nodes in the same run: conflict; neither value
 *     is used, never summed and never the larger.
 *  4. Not reported, while the node that last supplied it was read
 *     successfully this run: missing. Its row and history stay.
 *  5. Not reported, while its last source failed or was not read: unchanged,
 *     so its age shows the outage. A conflict ends as missing only when
 *     every node read this run answered without it.
 *
 * It decides only; the cache writer applies the plan.
 */
final class InventoryReconciler {
	/**
	 * @param array<int, ?NodeReport> $reads    node id => usable report, or null for a failed read
	 * @param array<string, Target>   $existing target name => stored target of this filesystem
	 */
	public static function reconcile(Filesystem $filesystem, array $reads, array $existing): InventoryPlan {
		$reporters = [];
		$foreign   = [];

		foreach ($reads as $nodeId => $report) {
			foreach ($report->targets ?? [] as $name => $target) {
				if ($target->id->filesystem !== $filesystem->name) {
					$foreign[$nodeId] = ($foreign[$nodeId] ?? 0) + 1;

					continue;
				}

				$reporters[$name][$nodeId] = $target;
			}
		}

		$updates = [];

		foreach ($reporters as $name => $byNode) {
			$first = reset($byNode);

			if (count($byNode) > 1) {
				$updates[$name] = new TargetUpdate($first->id, $existing[$name] ?? null, Presence::Conflict, 0, null);

				continue;
			}

			$updates[$name] = new TargetUpdate($first->id, $existing[$name] ?? null, Presence::Present, (int) array_key_first($byNode), $first);
		}

		$allNodesAnswered = $reads !== [] && !in_array(null, $reads, true);

		foreach ($existing as $name => $target) {
			if (isset($updates[$name]) || $target->presence === Presence::Missing) {
				continue;
			}

			// Rule 4 needs its last source read successfully this run. A
			// conflict has no single source (the stored one is only the last
			// node that held it alone), so it ends as missing only when every
			// node read this run answered and none reports it.
			$source = $target->presence === Presence::Conflict ? 0 : $target->sourceNodeId;
			$seenBy = $source > 0
				? isset($reads[$source])
				: $allNodesAnswered;

			if ($seenBy) {
				$updates[$name] = new TargetUpdate($target->targetId, $target, Presence::Missing, $source, null);
			}
		}

		ksort($updates);

		return new InventoryPlan($updates, $foreign);
	}
}
