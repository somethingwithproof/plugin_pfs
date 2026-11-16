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

/*
 * Uninstall on a runtime the plugin refuses (PHP below 8.2), where nothing
 * under lib/ may load. Cacti goes on to drop every tracked plugin table after
 * plugin_pfs_uninstall() returns, so the keep tables setting must be
 * honoured here too, in PHP 8.0 syntax. It mirrors Settings::fromRow() and
 * Schema::untrackTables() / forgetVersion(); the lifecycle integration tests
 * run both paths. No storage node is contacted.
 */

/**
 * The recorded host ids: the plugin_pfs_created_devices table, and the
 * setting milestone 4 kept them in, for a schema not yet migrated. Mirrors
 * CreatedDeviceRepository.
 *
 * @return list<int>
 */
function pfs_unbooted_created_devices(): array {
	$ids = array();

	if (pfs_unbooted_record_exists()) {
		$rows = db_fetch_assoc_prepared('SELECT host_id FROM plugin_pfs_created_devices', array());

		if (!is_array($rows)) {
			throw new RuntimeException('Uninstall stopped because device ownership could not be read.');
		}

		foreach ($rows as $row) {
			$ids[] = (int) $row['host_id'];
		}
	}

	$row    = db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', array('pfs_created_devices'));
	$value = is_array($row) && isset($row['value']) && is_string($row['value']) ? $row['value'] : '';
	$legacy = $value === '' ? array() : json_decode($value, true);
	if ($row === false || !is_array($legacy)) {
		throw new RuntimeException('Uninstall stopped because legacy device ownership could not be read.');
	}

	foreach ($legacy as $id) {
		if (is_int($id)) {
			$ids[] = $id;
		}
	}

	return array_values(array_unique(array_filter($ids, function ($id) {
		return $id > 0;
	})));
}

function pfs_unbooted_record_exists(): bool {
	$count = db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array('plugin_pfs_created_devices'));
	if ($count === false) {
		throw new RuntimeException('Uninstall stopped because the ownership table could not be checked.');
	}
	return (int) $count > 0;
}

/**
 * @param list<int> $ids
 * @return list<int>
 */
function pfs_unbooted_removed_devices(array $ids): array {
	return array_values(array_filter($ids, function ($id) {
		$row = db_fetch_row_prepared('SELECT id, deleted FROM host WHERE id = ?', array($id));
		return $row === array() || (is_array($row) && isset($row['deleted']) && $row['deleted'] === 'on');
	}));
}

function pfs_uninstall_unbooted(): void {
	// A recorded device deleted while the plugin was disabled is no longer
	// the plugin's to track.
	if (pfs_unbooted_record_exists()) {
		db_execute_prepared('DELETE FROM plugin_pfs_created_devices WHERE host_id NOT IN (SELECT id FROM host)', array());
	}

	// Deleting the devices the plugin created needs only core functions, so
	// it is honoured here too (REQ-LIFE-005); setup.php is already loaded.
	$delete = db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', array('pfs_uninstall_delete_devices'));

	if (is_array($delete) && isset($delete['value']) && $delete['value'] === 'on') {
		$recorded = pfs_unbooted_created_devices();
		$existing = array();

		foreach ($recorded as $id) {
			if (is_int($id) && $id > 0 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', array($id)) > 0) {
				$existing[] = $id;
			}
		}

		pfs_uninstall_devices($existing);
		$existing = pfs_unbooted_removed_devices($recorded);

		// No plugin hook runs here, so the mapping and the record are
		// cleared directly; kept tables must not point at a deleted device.
		if ($existing !== array() && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_pfs_filesystem' AND COLUMN_NAME = 'host_id'") > 0) {
			db_execute_prepared('UPDATE plugin_pfs_filesystem SET host_id = 0 WHERE host_id IN (' . implode(', ', array_fill(0, count($existing), '?')) . ')', $existing);
		}

		if ($existing !== array() && pfs_unbooted_record_exists()) {
			db_execute_prepared('DELETE FROM plugin_pfs_created_devices WHERE host_id IN (' . implode(', ', array_fill(0, count($existing), '?')) . ')', $existing);
		}

		if (count($existing) === count($recorded)) {
			db_execute_prepared('DELETE FROM settings WHERE name = ?', array('pfs_created_devices'));
		}
	}

	$row  = db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', array('pfs_uninstall_keep_tables'));
	$keep = !is_array($row) || $row === array() || (isset($row['value']) && $row['value'] === 'on');

	if ($keep) {
		if (!db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", array('pfs'))) {
			throw new RuntimeException('Uninstall stopped because the plugin tables could not be preserved.');
		}
		cacti_log('NOTE: Uninstall kept the plugin tables as configured', false, 'PFS');
	} else {
		// As Schema::keepCreatedDevices(): a record that still lists a
		// device outlives the other tables.
		$count = pfs_unbooted_record_exists() ? db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_pfs_created_devices', array()) : 0;

		if ($count === false || (int) $count > 0) {
			if (!db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND `table` = ? AND method = 'create'", array('pfs', 'plugin_pfs_created_devices'))) {
				throw new RuntimeException('Uninstall stopped because device ownership could not be preserved.');
			}
			cacti_log('NOTE: Uninstall dropped the plugin tables but kept the record of the devices the plugin created, which are still in Cacti', false, 'PFS');
		}

		db_execute_prepared('DELETE FROM settings WHERE name = ?', array('pfs_schema_version'));
	}
}
