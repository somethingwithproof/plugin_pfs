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
 * Queries plugin configuration tables scoped to a remote poller so
 * replicate_out can push only that poller's filesystems, nodes, and allowed
 * networks (REQ-REMOTE-002).
 */
final class ReplicationRepository {
	/**
	 * Initialize ReplicationRepository.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Rows for every replicated plugin table belonging to $pollerId.
	 *
	 * @return array<string, list<array<array-key, mixed>>>
	 */
	public function tablesForPoller(int $pollerId): array {
		if ($pollerId < 1) {
			return [];
		}

		$filesystems = Row::rows(db_fetch_assoc_prepared(
			'SELECT * FROM plugin_pfs_filesystem WHERE poller_id = ? ORDER BY id',
			[$pollerId],
			true,
			$this->connection,
		));

		$nodes = Row::rows(db_fetch_assoc_prepared(
			'SELECT * FROM plugin_pfs_node WHERE poller_id = ? ORDER BY id',
			[$pollerId],
			true,
			$this->connection,
		));

		$networks = Row::rows(db_fetch_assoc_prepared(
			'SELECT * FROM plugin_pfs_network WHERE poller_id = ? ORDER BY id',
			[$pollerId],
			true,
			$this->connection,
		));

		return [
			'plugin_pfs_filesystem' => $filesystems,
			'plugin_pfs_node'       => $nodes,
			'plugin_pfs_network'    => $networks,
		];
	}
}
