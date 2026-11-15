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
 * The view page: each filesystem's targets, where they were last seen, their
 * condition, and how fresh their values are. It reads the cache only, so a
 * reload is never a measurement (REQ-STATE-006).
 *
 * Visibility follows Cacti, not a plugin permission layer (REQ-SEC-011,
 * REQ-SEC-012): a filesystem mapped to a device is shown to users Cacti
 * allows to see that device, checked on every request; an unmapped
 * filesystem and every node name are shown to administrators only.
 */
final class TargetsPage {
	public const FILE = 'pfs.php';

	public function __construct(
		private readonly FilesystemRepository $filesystems,
		private readonly NodeRepository $nodes,
		private readonly TargetRepository $targets,
		private readonly ObservationRepository $observations,
		private readonly FreshnessEvaluator $freshness,
		private readonly Clock $clock,
		private readonly bool $admin = true,
		private readonly ?\Closure $deviceAllowed = null,
	) {
	}

	/**
	 * Filesystems this user may see.
	 *
	 * @return list<Filesystem>
	 */
	public function visible(): array {
		return array_values(array_filter($this->filesystems->all(), fn (Filesystem $f): bool => $this->admin || ($f->hostId > 0 && $this->deviceAllowed !== null && ($this->deviceAllowed)($f->hostId) === true)));
	}

	public function render(): void {
		$names = [];

		foreach ($this->nodes->all() as $node) {
			$names[$node->id] = $node->name;
		}

		$filesystems = $this->visible();

		if ($filesystems === []) {
			html_start_box(__('Parallel Filesystems', 'pfs'), '100%', '', '3', 'center', '');
			print '<tr><td><em>' . html_escape($this->admin ? __('No filesystems registered yet.', 'pfs') : __('No filesystem is visible to you.', 'pfs')) . '</em></td></tr>';
			html_end_box();
		}

		foreach ($filesystems as $filesystem) {
			html_start_box(__('Filesystem %s', html_escape($filesystem->name), 'pfs'), '100%', '', '3', 'center', '');

			if ($filesystem->hostId > 0) {
				print '<tr><td colspan="9"><a class="linkEditMain" id="pfs_graphs_' . $filesystem->id . '" href="' . html_escape($this->graphsUrl($filesystem->hostId)) . '">' . html_escape(__('Graphs of this filesystem', 'pfs')) . '</a></td></tr>';
			}

			$labels = [__('Target', 'pfs'), __('Review', 'pfs'), __('Presence', 'pfs'), __('Condition', 'pfs'), __('Recovery', 'pfs'), __('Freshness', 'pfs'), __('Known Values', 'pfs'), __('Source Node', 'pfs'), __('Last Seen', 'pfs')];

			print Format::header($this->admin ? $labels : array_values(array_diff($labels, [__('Source Node', 'pfs')])));

			$targets      = $this->targets->forFilesystem($filesystem->id);
			$observations = $this->observations->forFilesystem($filesystem->id);

			if ($targets === []) {
				print '<tr><td colspan="9"><em>' . html_escape(__('No targets found yet.', 'pfs')) . '</em></td></tr>';
			}

			foreach ($targets as $target) {
				$cells = $this->row($target, $observations[$target->id] ?? [], $filesystem, $names);

				if (!$this->admin) {
					array_splice($cells, 7, 1);
				}

				print Format::row($cells);
			}

			html_end_box();
		}
	}

	/**
	 * Cacti's graph preview of one device, which applies Cacti's own graph
	 * permissions.
	 */
	public function graphsUrl(int $hostId): string {
		return '../../graph_view.php?action=preview&reset=true&host_id=' . $hostId;
	}

	/**
	 * @param array<string, Observation> $values
	 * @param array<int, string>         $names node id => name
	 *
	 * @return list<string>
	 */
	public function row(Target $target, array $values, Filesystem $filesystem, array $names): array {
		$newest = 0;
		$known  = 0;

		foreach ($values as $observation) {
			$newest = max($newest, $observation->observedAt);
			$known += $observation->published() === null ? 0 : 1;
		}

		return [
			$target->name(),
			$target->review->value,
			$target->presence->value,
			$target->condition->value,
			$target->recovery ?? '-',
			$this->freshness->evaluate($newest, $filesystem->interval)->value,
			$known . ' / ' . count($values),
			$names[$target->sourceNodeId] ?? '-',
			Format::age($target->lastSeen, $this->clock->now()),
		];
	}
}
