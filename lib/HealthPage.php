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
 * The health page (REQ-HEALTH-001 to REQ-HEALTH-004), for Parallel
 * Filesystem Admin only, limited to filesystems on devices Cacti allows the
 * user (DeviceAccess). It shows what the readers last recorded and never
 * contacts a node. Node output is never printed: the only node-supplied
 * strings it shows are target names and a version that passed their
 * patterns, and every cell is escaped.
 */
final class HealthPage {
	public const FILE = 'pfs_health.php';

	/**
	 * @param ?\Closure(int): ?bool $inMaintenance whether Maint holds a device in maintenance, null without Maint
	 */
	public function __construct(
		private readonly FilesystemRepository $filesystems,
		private readonly NodeRepository $nodes,
		private readonly NodeHealthRepository $health,
		private readonly ReaderRepository $runs,
		private readonly HealthWarnings $warnings,
		private readonly Clock $clock,
		private readonly DeviceAccess $access,
		private readonly ?\Closure $inMaintenance = null,
	) {
	}

	/**
	 * Whether a filesystem's mapped Cacti device is currently in a Maint
	 * maintenance window, or null when Maint is unavailable or the filesystem
	 * is unmapped.
	 */
	public function maintenanceState(Filesystem $filesystem): ?bool {
		if ($this->inMaintenance === null || $filesystem->hostId <= 0) {
			return null;
		}

		return ($this->inMaintenance)($filesystem->hostId);
	}

	/**
	 * @param list<string> $objectWarnings from HealthWarnings::objects()
	 */
	public function render(Settings $settings, array $objectWarnings = []): void {
		$filesystems = [];

		foreach ($this->access->filesystems($this->filesystems->all()) as $filesystem) {
			$filesystems[$filesystem->id] = $filesystem;
		}

		// Nodes of filesystems on devices this user may not see are left
		// out. Reader runs belong to a poller, not a device, and stay.
		$nodes  = array_values(array_filter($this->nodes->all(), static fn (Node $n): bool => isset($filesystems[$n->filesystemId])));
		$health = $this->health->all();
		$runs   = $this->runs->all();
		$now    = $this->clock->now();

		// Exclude filesystems currently in a Cacti Maint window from active failure warnings.
		$activeFilesystems = [];
		$maintNames        = [];

		foreach ($filesystems as $id => $filesystem) {
			if ($this->maintenanceState($filesystem) === true) {
				$maintNames[] = $filesystem->name;
			} else {
				$activeFilesystems[$id] = $filesystem;
			}
		}

		html_start_box(__('Parallel Filesystem Warnings', 'pfs'), '100%', '', '3', 'center', '');

		$warnings = array_merge($objectWarnings, $this->warnings->for($settings, $nodes, $health, $runs, $activeFilesystems));

		if ($maintNames !== []) {
			print '<tr><td class="textInfo">' . html_escape('Maint: ' . implode(', ', $maintNames)) . '</td></tr>';
		}

		foreach ($warnings as $warning) {
			print '<tr><td class="textError">' . html_escape($warning) . '</td></tr>';
		}

		if ($warnings === []) {
			print '<tr><td>' . html_escape(__('No warnings.', 'pfs')) . '</td></tr>';
		}

		if ($objectWarnings !== []) {
			print '<tr><td><form method="post" action="' . html_escape(self::FILE) . '" style="display:inline">'
				. '<input type="hidden" name="action" value="reinstall_templates">'
				. '<input type="submit" class="ui-button" value="' . html_escape(__('Install the Templates Again', 'pfs')) . '"></form></td></tr>';
		}

		html_end_box();

		html_start_box(__('Readers', 'pfs'), '100%', '', '3', 'center', '');
		print Format::header([__('Poller', 'pfs'), __('Last Run', 'pfs'), __('Last Completed Run', 'pfs'), __('Nodes', 'pfs'), __('Read', 'pfs'), __('Failed', 'pfs'), __('Not Reached', 'pfs')]);

		if ($runs === []) {
			print '<tr><td colspan="7"><em>' . html_escape(__('No reader has run yet.', 'pfs')) . '</em></td></tr>';
		}

		foreach ($runs as $run) {
			print Format::row([(string) $run->pollerId, Format::age($run->startedAt, $now), Format::age($run->lastCompleted, $now), (string) $run->nodesTotal, (string) $run->nodesRead, (string) $run->nodesFailed, (string) $run->nodesSkipped]);
		}

		html_end_box();

		html_start_box(__('Nodes', 'pfs'), '100%', '', '3', 'center', '');
		print Format::header([__('Node', 'pfs'), __('Filesystem', 'pfs'), __('Role', 'pfs'), __('Poller', 'pfs'), __('Last Read', 'pfs'), __('Last Success', 'pfs'), __('Duration', 'pfs'), __('Response Size', 'pfs'), __('Failure', 'pfs'), __('Reported Version', 'pfs')]);

		if ($nodes === []) {
			print '<tr><td colspan="10"><em>' . html_escape(__('No nodes registered yet.', 'pfs')) . '</em></td></tr>';
		}

		foreach ($nodes as $node) {
			print Format::row($this->nodeRow($node, $filesystems[$node->filesystemId] ?? null, $health[$node->id] ?? new NodeHealth($node->id), $now));
		}

		html_end_box();
	}

	/**
	 * @return list<string>
	 */
	public function nodeRow(Node $node, ?Filesystem $filesystem, NodeHealth $health, int $now): array {
		$state = $node->enabled() ? $health->outcome->value : __('disabled', 'pfs');

		return [
			$node->name,
			$filesystem->name ?? '-',
			$node->role->label(),
			(string) $node->pollerId,
			$health->lastAttempt > 0 ? $state . ', ' . Format::age($health->lastAttempt, $now) : $state,
			Format::age($health->lastSuccess, $now),
			$health->lastAttempt > 0 ? __('%s s', number_format($health->duration, 2), 'pfs') : '-',
			$health->lastAttempt > 0 ? Format::bytes($health->bytes) : '-',
			$health->error?->message() ?? '-',
			$health->version ?? '-',
		];
	}
}
