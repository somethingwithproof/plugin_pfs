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

final class NodeRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public function find(int $id): ?Node {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_pfs_node WHERE id = ?', [$id], true, $this->connection);

		return is_array($row) ? Node::fromRow($row) : null;
	}

	/**
	 * @return list<Node>
	 */
	public function all(): array {
		return $this->nodes(Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_pfs_node ORDER BY name, id', [], true, $this->connection)));
	}

	/**
	 * Nodes this poller's reader handles (REQ-READ-002).
	 *
	 * @return list<Node>
	 */
	public function forPoller(int $pollerId): array {
		return $this->nodes(Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_pfs_node WHERE poller_id = ? ORDER BY id', [$pollerId], true, $this->connection)));
	}

	public function nameTaken(string $name, int $exceptId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_pfs_node WHERE name = ? AND id != ?', [$name, $exceptId], '', true, $this->connection) > 0;
	}

	public function save(Node $node): int {
		$now    = $this->clock->now();
		$values = [$node->filesystemId, $node->name, $node->role->value, $node->pollerId, $node->endpoint, $node->port, $node->user, $node->accessMethod->value, $node->adminState->value];

		if ($node->id === 0) {
			$ok = db_execute_prepared('INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($values, [$now, $now]), true, $this->connection);

			return $ok ? (int) db_fetch_insert_id($this->connection) : 0;
		}

		if ($this->find($node->id) === null) {
			return 0;
		}

		$ok = db_execute_prepared('UPDATE plugin_pfs_node SET filesystem_id = ?, name = ?, role = ?, poller_id = ?, endpoint = ?, port = ?, ssh_user = ?, access_method = ?, admin_state = ?, updated_at = ? WHERE id = ?', array_merge($values, [$now, $node->id]), true, $this->connection);

		return $ok ? $node->id : 0;
	}

	/**
	 * Remove a node and its health row. Targets it supplied keep their
	 * history; they go missing or move on the next run.
	 */
	public function delete(int $id): bool {
		if ($this->find($id) === null) {
			return false;
		}

		return db_execute_prepared('DELETE FROM plugin_pfs_node_health WHERE node_id = ?', [$id], true, $this->connection)
			&& db_execute_prepared('DELETE FROM plugin_pfs_node WHERE id = ?', [$id], true, $this->connection);
	}

	/**
	 * @param list<array<array-key, mixed>> $rows
	 *
	 * @return list<Node>
	 */
	private function nodes(array $rows): array {
		$out = [];

		foreach ($rows as $row) {
			$node = Node::fromRow($row);

			if ($node === null) {
				Log::warning(sprintf('Node row %d is malformed and was skipped', Row::int($row, 'id')));

				continue;
			}

			$out[] = $node;
		}

		return $out;
	}
}
