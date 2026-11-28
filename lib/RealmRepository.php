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
 * Resolves plugin_realms IDs for stateless permission checks outside page auth,
 * such as MCP ability and REST API authorization.
 */
final class RealmRepository {
	/**
	 * Construct a new RealmRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Return Cacti realm IDs (plugin_realms.id + 100) granting View or higher
	 * access to Parallel Filesystems.
	 *
	 * @return list<int>
	 */
	public function viewRealmIds(): array {
		$ids = [];

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT id FROM plugin_realms WHERE plugin = 'pfs' ORDER BY id", [], true, $this->connection)) as $row) {
			$id = Row::int($row, 'id', 0, 1);

			if ($id > 0) {
				$ids[] = $id + 100;
			}
		}

		return $ids;
	}
}
