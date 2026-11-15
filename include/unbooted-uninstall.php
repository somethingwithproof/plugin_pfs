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

function pfs_uninstall_unbooted(): void {
	// Deleting the devices the plugin created needs only core functions, so
	// it is honoured here too (REQ-LIFE-005); setup.php is already loaded.
	$delete = db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', array('pfs_uninstall_delete_devices'));

	if (is_array($delete) && isset($delete['value']) && $delete['value'] === 'on') {
		$row      = db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', array('pfs_created_devices'));
		$ids      = is_array($row) && isset($row['value']) && is_string($row['value']) ? json_decode($row['value'], true) : array();
		$existing = array();

		foreach (is_array($ids) ? $ids : array() as $id) {
			if (is_int($id) && $id > 0 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', array($id)) > 0) {
				$existing[] = $id;
			}
		}

		pfs_uninstall_devices($existing);

		// No plugin hook runs here, so the mapping and the record are
		// cleared directly; kept tables must not point at a deleted device.
		if ($existing !== array() && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_pfs_filesystem' AND COLUMN_NAME = 'host_id'") > 0) {
			db_execute_prepared('UPDATE plugin_pfs_filesystem SET host_id = 0 WHERE host_id IN (' . implode(', ', array_fill(0, count($existing), '?')) . ')', $existing);
		}

		db_execute_prepared('DELETE FROM settings WHERE name = ?', array('pfs_created_devices'));
	}

	$row  = db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', array('pfs_uninstall_keep_tables'));
	$keep = !is_array($row) || $row === array() || (isset($row['value']) && $row['value'] === 'on');

	if ($keep) {
		db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", array('pfs'));
		cacti_log('NOTE: Uninstall kept the plugin tables as configured', false, 'PFS');
	} else {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', array('pfs_schema_version'));
	}
}
