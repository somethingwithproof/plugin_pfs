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
	) {
	}

	public function render(): void {
		$names = [];

		foreach ($this->nodes->all() as $node) {
			$names[$node->id] = $node->name;
		}

		$filesystems = $this->filesystems->all();

		if ($filesystems === []) {
			html_start_box(__('Parallel Filesystems', 'pfs'), '100%', '', '3', 'center', '');
			print '<tr><td><em>' . html_escape(__('No filesystems registered yet.', 'pfs')) . '</em></td></tr>';
			html_end_box();
		}

		foreach ($filesystems as $filesystem) {
			html_start_box(__('Filesystem %s', html_escape($filesystem->name), 'pfs'), '100%', '', '3', 'center', '');
			print Format::header([__('Target', 'pfs'), __('Review', 'pfs'), __('Presence', 'pfs'), __('Condition', 'pfs'), __('Recovery', 'pfs'), __('Freshness', 'pfs'), __('Known Values', 'pfs'), __('Source Node', 'pfs'), __('Last Seen', 'pfs')]);

			$targets      = $this->targets->forFilesystem($filesystem->id);
			$observations = $this->observations->forFilesystem($filesystem->id);

			if ($targets === []) {
				print '<tr><td colspan="9"><em>' . html_escape(__('No targets found yet.', 'pfs')) . '</em></td></tr>';
			}

			foreach ($targets as $target) {
				print Format::row($this->row($target, $observations[$target->id] ?? [], $filesystem, $names));
			}

			html_end_box();
		}
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
