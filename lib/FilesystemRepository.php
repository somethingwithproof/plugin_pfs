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
 * Persistence layer for registered parallel filesystems.
 */
final class FilesystemRepository {
	/**
	 * Initialize FilesystemRepository.
	 *
	 * @param \PDO|false $connection
	 * @param Clock $clock
	 */
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * Find.
	 *
	 * @param int $id
	 * @return ?Filesystem
	 */
	public function find(int $id): ?Filesystem {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_pfs_filesystem WHERE id = ?', [$id], true, $this->connection);

		return is_array($row) ? Filesystem::fromRow($row) : null;
	}

	/**
	 * @return list<Filesystem>
	 */
	public function all(bool $strict = false): array {
		$result = db_fetch_assoc_prepared('SELECT * FROM plugin_pfs_filesystem ORDER BY name, id', [], true, $this->connection);

		if ($strict && !is_array($result)) {
			throw new \RuntimeException('Operator observations could not be read');
		}
		$out = [];

		foreach (Row::rows($result) as $row) {
			$filesystem = Filesystem::fromRow($row);

			if ($filesystem !== null) {
				$out[] = $filesystem;
			}
		}

		return $out;
	}

	/**
	 * Find by host.
	 *
	 * @param int $hostId
	 * @return ?Filesystem
	 */
	public function findByHost(int $hostId): ?Filesystem {
		if ($hostId <= 0) {
			return null;
		}

		$row = db_fetch_row_prepared('SELECT * FROM plugin_pfs_filesystem WHERE host_id = ? ORDER BY id LIMIT 1', [$hostId], true, $this->connection);

		return is_array($row) ? Filesystem::fromRow($row) : null;
	}

	/**
	 * @return list<int>
	 */
	public function mappedHostIds(): array {
		$rows = db_fetch_assoc_prepared(
			'SELECT host_id FROM plugin_pfs_filesystem WHERE host_id > 0 ORDER BY host_id',
			[],
			true,
			$this->connection
		);

		if (!is_array($rows)) {
			throw new \RuntimeException('Uninstall stopped because filesystem mappings could not be read.');
		}

		$ids = [];

		foreach ($rows as $row) {
			$id = Row::int($row, 'host_id', 0, 1);

			if ($id > 0) {
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * Map a filesystem to a Cacti device. A device carries at most one
	 * filesystem, so a device already mapped elsewhere is refused.
	 */
	public function mapHost(int $filesystemId, int $hostId): bool {
		if ($hostId <= 0 || $this->find($filesystemId) === null) {
			return false;
		}

		$other = $this->findByHost($hostId);

		if ($other !== null && $other->id !== $filesystemId) {
			return false;
		}

		return db_execute_prepared('UPDATE plugin_pfs_filesystem SET host_id = ?, updated_at = ? WHERE id = ?', [$hostId, $this->clock->now(), $filesystemId], true, $this->connection);
	}

	/**
	 * Forget the device of every filesystem mapped to one of these devices,
	 * for the device_remove hook. Nodes, targets, and history stay.
	 *
	 * @param list<int> $hostIds
	 */
	public function unmapHosts(array $hostIds): int {
		$hostIds = array_values(array_unique(array_filter($hostIds, static fn (int $id): bool => $id > 0)));

		if ($hostIds === []) {
			return 0;
		}

		$placeholders = implode(', ', array_fill(0, count($hostIds), '?'));
		$count        = db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_pfs_filesystem WHERE host_id IN ($placeholders)", $hostIds, '', true, $this->connection);

		if ($count === false) {
			throw new \RuntimeException('Uninstall stopped because filesystem mappings could not be checked.');
		}

		if ((int) $count > 0 && !db_execute_prepared("UPDATE plugin_pfs_filesystem SET host_id = 0, updated_at = ? WHERE host_id IN ($placeholders)", array_merge([$this->clock->now()], $hostIds), true, $this->connection)) {
			throw new \RuntimeException('Uninstall stopped because filesystem mappings could not be cleared.');
		}

		return (int) $count;
	}

	/**
	 * Name taken.
	 *
	 * @param string $backend
	 * @param string $name
	 * @param int $exceptId
	 * @return bool
	 */
	public function nameTaken(string $backend, string $name, int $exceptId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_pfs_filesystem WHERE backend = ? AND name = ? AND id != ?', [$backend, $name, $exceptId], '', true, $this->connection) > 0;
	}

	/**
	 * Insert or update, returning the id, or 0 when the write failed.
	 */
	public function save(Filesystem $filesystem): int {
		$now    = $this->clock->now();
		$values = [$filesystem->name, $filesystem->backend, $filesystem->pollerId, $filesystem->interval, $filesystem->adminState->value];

		if ($filesystem->id === 0) {
			$ok = db_execute_prepared('INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', array_merge($values, [$now, $now]), true, $this->connection);

			return $ok ? (int) db_fetch_insert_id($this->connection) : 0;
		}

		if ($this->find($filesystem->id) === null) {
			return 0;
		}

		$ok = db_execute_prepared('UPDATE plugin_pfs_filesystem SET name = ?, backend = ?, poller_id = ?, expected_interval = ?, admin_state = ?, updated_at = ? WHERE id = ?', array_merge($values, [$now, $filesystem->id]), true, $this->connection);

		return $ok ? $filesystem->id : 0;
	}

	/**
	 * Remove a filesystem with its nodes, inventory, cache, and node health.
	 * Rows go children first, so an interrupted delete leaves a filesystem
	 * that can be deleted again. No node is contacted.
	 */
	public function delete(int $id): bool {
		if ($this->find($id) === null) {
			return false;
		}

		$statements = [
			['DELETE o FROM plugin_pfs_observation o INNER JOIN plugin_pfs_target t ON t.id = o.target_id WHERE t.filesystem_id = ?', [$id]],
			['DELETE FROM plugin_pfs_summary WHERE filesystem_id = ?', [$id]],
			['DELETE FROM plugin_pfs_target WHERE filesystem_id = ?', [$id]],
			['DELETE h FROM plugin_pfs_node_health h INNER JOIN plugin_pfs_node n ON n.id = h.node_id WHERE n.filesystem_id = ?', [$id]],
			['DELETE FROM plugin_pfs_node WHERE filesystem_id = ?', [$id]],
			['DELETE FROM plugin_pfs_filesystem WHERE id = ?', [$id]],
		];

		foreach ($statements as [$sql, $params]) {
			if (!db_execute_prepared($sql, $params, true, $this->connection)) {
				return false;
			}
		}

		return true;
	}
}
