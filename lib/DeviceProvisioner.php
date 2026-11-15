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
 * Creates or maps the Cacti device of a filesystem and attaches the plugin's
 * data queries and overview graphs to it (ADR 0010, ADR 0011, REQ-DEV-001 to
 * REQ-DEV-005).
 *
 * Everything goes through Cacti's own functions: api_device_save() for the
 * device, api_device_dq_add() for the data queries (which runs the first
 * index), and create_complete_graph_from_template() for graphs. The plugin
 * writes only its own mapping. A mapped device that already existed keeps
 * every setting; only the data queries and overview graphs are added.
 */
final class DeviceProvisioner {
	public function __construct(
		private readonly FilesystemRepository $filesystems,
		private readonly TargetRepository $targets,
		private readonly CactiObjectRepository $objects,
		private readonly BackendRegistry $backends,
		private readonly string $cactiPath,
	) {
	}

	/**
	 * Why the plugin cannot provision now, or null when it can.
	 */
	public function refusal(): ?string {
		if ($this->objects->deviceTemplateId() === 0) {
			return __('The Parallel Filesystem templates are not installed. Reinstall them from the health page.', 'pfs');
		}

		return null;
	}

	/**
	 * The objects that creating or mapping a device would add, for the
	 * confirmation page (REQ-DEV-002). Per-target graphs are not among them:
	 * they are created on demand (ADR 0011).
	 *
	 * @return list<string>
	 */
	public function plan(Filesystem $filesystem, int $hostId = 0): array {
		$lines = [];

		if ($hostId === 0) {
			$lines[] = __('A device "%s" from the %s device template, polled by poller %d, with availability checks off.', $this->description($filesystem), TemplateInstaller::DEVICE_TEMPLATE, $filesystem->pollerId, 'pfs');
		}

		$counts = ['ost' => 0, 'mdt' => 0];

		foreach ($this->targets->forFilesystem($filesystem->id) as $target) {
			if ($target->review !== Review::Ignored) {
				$counts[$target->targetId->type->value]++;
			}
		}

		foreach (TemplateInstaller::QUERIES as $name => [$type]) {
			$queryId = $this->objects->queryId($name);

			if ($hostId === 0 || !$this->objects->hasQuery($hostId, $queryId)) {
				$lines[] = __('The data query "%s", listing %d targets known now. It creates no graph by itself.', $name, $counts[$type], 'pfs');
			}
		}

		$graphs = 0;

		foreach (TemplateInstaller::OVERVIEW as $name) {
			$templateId = $this->objects->graphTemplateId($name);

			if ($hostId === 0 || $this->objects->graphCount($hostId, $templateId) === 0) {
				$lines[] = __('The graph "%s".', $name, 'pfs');
				$graphs++;
			}
		}

		if ($graphs > 0) {
			$lines[] = __('A "%s" data source for each of those graphs, each one script server call per poll cycle, whatever the number of targets.', TemplateInstaller::SUMMARY, 'pfs');
		}

		return $lines;
	}

	/**
	 * Create a device for a filesystem that has none and attach the plugin's
	 * objects. Returns the new device id, or 0 with the reason logged.
	 */
	public function create(Filesystem $filesystem): int {
		$templateId = $this->objects->deviceTemplateId();

		if ($templateId === 0 || $filesystem->hostId > 0) {
			return 0;
		}

		include_once($this->cactiPath . '/lib/api_device.php');

		$hostId = (int) api_device_save(0, $templateId, $this->description($filesystem), $this->hostname($filesystem), '', 0, '', '', 161, 500, '', AVAIL_NONE, 0, 23, 400, 1,
			__('Created by the Parallel Filesystem plugin for filesystem %s. Its values come from the plugin cache; the host name is a reserved name that is never contacted.', $filesystem->name, 'pfs'),
			'MD5', '', 'DES', '', '', 5, 1, $filesystem->pollerId, 0);

		if ($hostId <= 0) {
			Log::error(sprintf('Cacti refused the device for filesystem %d', $filesystem->id));

			return 0;
		}

		if (!$this->filesystems->mapHost($filesystem->id, $hostId)) {
			Log::error(sprintf('Device %d was created for filesystem %d but could not be mapped', $hostId, $filesystem->id));

			return 0;
		}

		$this->attach($filesystem->id, $hostId);

		return $hostId;
	}

	/**
	 * Map an existing device to a filesystem and attach the plugin's objects.
	 * The device's own settings, poller included, are left alone.
	 */
	public function map(Filesystem $filesystem, int $hostId): bool {
		if ($this->objects->device($hostId) === null || !$this->filesystems->mapHost($filesystem->id, $hostId)) {
			return false;
		}

		$this->attach($filesystem->id, $hostId);

		return true;
	}

	/**
	 * Add the data queries and any missing overview graph. Safe to repeat:
	 * a query already on the device is indexed again, and a graph template
	 * that already has a graph on the device is skipped.
	 */
	public function attach(int $filesystemId, int $hostId): void {
		include_once($this->cactiPath . '/lib/api_device.php');
		include_once($this->cactiPath . '/lib/data_query.php');
		include_once($this->cactiPath . '/lib/template.php');
		include_once($this->cactiPath . '/lib/utility.php');

		foreach (array_keys(TemplateInstaller::QUERIES) as $name) {
			$queryId = $this->objects->queryId($name);

			if ($queryId > 0) {
				api_device_dq_add($hostId, $queryId, DATA_QUERY_AUTOINDEX_INDEX_NUM_CHANGE);
			}
		}

		foreach (TemplateInstaller::OVERVIEW as $name) {
			$templateId = $this->objects->graphTemplateId($name);

			if ($templateId === 0 || $this->objects->graphCount($hostId, $templateId) > 0) {
				continue;
			}

			$none      = [];
			$suggested = [];
			$created   = create_complete_graph_from_template($templateId, $hostId, $none, $suggested);

			if (!is_array($created) || !is_numeric($created['local_graph_id'] ?? null)) {
				Log::error(sprintf('Cacti did not create the %s graph for filesystem %d', $name, $filesystemId));
			}
		}

		// Fills the summary data source's host_id input and the poller cache.
		push_out_host($hostId, 0);
	}

	public function description(Filesystem $filesystem): string {
		$label = $this->backends->has($filesystem->backend) ? $this->backends->get($filesystem->backend)->label() : $filesystem->backend;

		return $label . ' ' . $filesystem->name;
	}

	/**
	 * A name in the reserved .invalid domain (RFC 6761), so no lookup or
	 * availability check of the device can reach a real host. Filesystem
	 * names are validated by the backend pattern before they are stored.
	 */
	public function hostname(Filesystem $filesystem): string {
		return strtolower($filesystem->name) . '.' . $filesystem->backend . '.pfs.invalid';
	}
}
