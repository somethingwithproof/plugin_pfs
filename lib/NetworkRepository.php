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

final class NetworkRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * @return list<array{id: int, poller_id: int, cidr: string}>
	 */
	public function all(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT id, poller_id, cidr FROM plugin_pfs_network ORDER BY poller_id, cidr', [], true, $this->connection)) as $row) {
			$cidr = Row::string($row, 'cidr');

			if (NetworkPolicy::validCidr($cidr)) {
				$out[] = ['id' => Row::int($row, 'id'), 'poller_id' => Row::int($row, 'poller_id'), 'cidr' => $cidr];
			}
		}

		return $out;
	}

	public function policy(): NetworkPolicy {
		$allowed = [];

		foreach ($this->all() as $network) {
			$allowed[$network['poller_id']][] = $network['cidr'];
		}

		return new NetworkPolicy($allowed);
	}

	public function add(int $pollerId, string $cidr): bool {
		if ($pollerId < 1 || !NetworkPolicy::validCidr($cidr)) {
			return false;
		}

		return db_execute_prepared('INSERT IGNORE INTO plugin_pfs_network (poller_id, cidr, created_at) VALUES (?, ?, ?)', [$pollerId, trim($cidr), $this->clock->now()], true, $this->connection);
	}

	public function delete(int $id): bool {
		return db_execute_prepared('DELETE FROM plugin_pfs_network WHERE id = ?', [$id], true, $this->connection);
	}
}
