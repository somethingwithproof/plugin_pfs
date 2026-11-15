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
 * The Cacti devices the plugin itself created (DeviceProvisioner::create()),
 * kept as a list of host ids in a plugin setting. Only these may be deleted
 * on uninstall, and only when the administrator asked for it (REQ-LIFE-005):
 * an existing device mapped onto a filesystem is never on the list. The list
 * lives in the settings table, so it outlives the filesystem that asked for
 * the device and a kept or dropped plugin table set alike.
 */
final class CreatedDevices {
	public const SETTING = 'pfs_created_devices';

	/**
	 * @return list<int>
	 */
	public static function ids(): array {
		$decoded = json_decode((string) read_config_option(self::SETTING, true), true);
		$ids     = [];

		foreach (is_array($decoded) ? $decoded : [] as $id) {
			if (is_int($id) && $id > 0) {
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	public static function add(int $hostId): void {
		if ($hostId > 0) {
			self::save([...self::ids(), $hostId]);
		}
	}

	/**
	 * Drop deleted devices from the list, so a later uninstall acts only on
	 * devices that still exist.
	 *
	 * @param list<int> $hostIds
	 */
	public static function forget(array $hostIds): void {
		$left = array_values(array_diff(self::ids(), $hostIds));

		if ($left !== self::ids()) {
			self::save($left);
		}
	}

	/**
	 * @param list<int> $ids
	 */
	private static function save(array $ids): void {
		$ids = array_values(array_unique($ids));
		sort($ids);
		set_config_option(self::SETTING, (string) json_encode($ids));
	}
}
