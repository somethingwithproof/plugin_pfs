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
 * one row per device in plugin_pfs_created_devices, so the record has no
 * size limit. Only these may be deleted on uninstall, and only when the
 * administrator asked for it (REQ-LIFE-005): an existing device mapped onto
 * a filesystem is never recorded. The record outlives the filesystem that
 * asked for the device, and uninstall keeps the table while it lists a
 * device even when the other plugin tables are dropped.
 */
final class CreatedDeviceRepository {
	/** Table constant. */
	public const TABLE = 'plugin_pfs_created_devices';

	/** Where milestone 4 kept the list, as JSON; migration 3 moves it. */
	public const LEGACY_SETTING = 'pfs_created_devices';

	/**
	 * Initialize CreatedDeviceRepository.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return list<int>
	 */
	public function ids(): array {
		return array_values(array_filter(array_map(static fn (array $row): int => Row::int($row, 'host_id', 0, 1), Row::rows(db_fetch_assoc_prepared('SELECT host_id FROM plugin_pfs_created_devices ORDER BY host_id', [], true, $this->connection))), static fn (int $id): bool => $id > 0));
	}

	/**
	 * Add.
	 *
	 * @param int $hostId
	 * @param int $now
	 * @return bool
	 */
	public function add(int $hostId, int $now): bool {
		return $hostId > 0 && db_execute_prepared('INSERT IGNORE INTO plugin_pfs_created_devices (host_id, created_at) VALUES (?, ?)', [$hostId, $now], true, $this->connection);
	}

	/** A failed read must keep the ownership record through uninstall. */
	public function hasRecords(): bool {
		$count = db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_pfs_created_devices', [], '', true, $this->connection);

		return $count === false || (int) $count > 0;
	}

	/**
	 * Drop deleted devices from the record, so a later uninstall acts only on
	 * devices that still exist.
	 *
	 * @param list<int> $hostIds
	 */
	public function forget(array $hostIds): bool {
		$ids = array_values(array_unique(array_filter(array_map('intval', $hostIds), static fn (int $id): bool => $id > 0)));

		return $ids === [] || db_execute_prepared('DELETE FROM plugin_pfs_created_devices WHERE host_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids, true, $this->connection);
	}

	/**
	 * Move the milestone 4 record into the table and remove the setting.
	 * Safe to repeat: an id already in the table is kept, and the setting is
	 * removed only once every id it held is in the table.
	 */
	public function importLegacy(int $now): bool {
		// Core documents the cell helper as returning bool, so the row is read.
		$row     = db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', [self::LEGACY_SETTING], true, $this->connection);
		$value   = is_array($row) ? Row::string($row, 'value') : '';
		$decoded = json_decode($value, true);

		if ($row === false || ($value !== '' && !is_array($decoded))) {
			return false;
		}

		$ok      = true;

		foreach (is_array($decoded) ? $decoded : [] as $id) {
			if (is_int($id) && $id > 0) {
				$ok = $this->add($id, $now) && $ok;
			}
		}

		return $ok && db_execute_prepared('DELETE FROM settings WHERE name = ?', [self::LEGACY_SETTING], true, $this->connection);
	}
}
