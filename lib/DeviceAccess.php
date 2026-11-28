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
 * Cacti device permissions applied to the plugin's own records (REQ-SEC-011,
 * REQ-SEC-012). A filesystem mapped to a device, and every node and target
 * of it, belongs to that device: a user, administrators of the plugin
 * included, may see or change it only while Cacti allows them the device.
 * An unmapped filesystem belongs to no device and is left to the realm that
 * opened the page.
 *
 * Every admin action checks the record it loads, on the request that
 * changes it, before any write, attach, reindex, or provisioning. Lists and
 * the export show only records that pass. Allowed networks belong to a
 * poller, not a device, so they stay visible to every plugin administrator.
 */
final class DeviceAccess {
	/**
	 * @param \Closure(int): bool $deviceAllowed Cacti's is_device_allowed() for the current user
	 */
	public function __construct(private readonly \Closure $deviceAllowed) {
	}

	/**
	 * Device.
	 *
	 * @param int $hostId
	 * @return bool
	 */
	public function device(int $hostId): bool {
		return $hostId > 0 && ($this->deviceAllowed)($hostId) === true;
	}

	/**
	 * Filesystem.
	 *
	 * @param ?Filesystem $filesystem
	 * @return bool
	 */
	public function filesystem(?Filesystem $filesystem): bool {
		return $filesystem !== null && ($filesystem->hostId === 0 || $this->device($filesystem->hostId));
	}

	/**
	 * @param list<Filesystem> $filesystems
	 * @return list<Filesystem>
	 */
	public function filesystems(array $filesystems): array {
		return array_values(array_filter($filesystems, fn (Filesystem $f): bool => $this->filesystem($f)));
	}
}
