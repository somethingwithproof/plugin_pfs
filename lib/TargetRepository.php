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
 * Reads the inventory and records administrator review. The reader's
 * writes go through CacheRepository.
 */
final class TargetRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return array<string, Target> target name => target
	 */
	public function forFilesystem(int $filesystemId): array {
		$out = [];

		foreach ($this->targets(Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_pfs_target WHERE filesystem_id = ? ORDER BY type, target_index', [$filesystemId], true, $this->connection))) as $target) {
			$out[$target->name()] = $target;
		}

		return $out;
	}

	/**
	 * @return list<Target>
	 */
	public function all(): array {
		return $this->targets(Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_pfs_target ORDER BY filesystem_id, type, target_index', [], true, $this->connection)));
	}

	public function find(int $id): ?Target {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_pfs_target WHERE id = ?', [$id], true, $this->connection);

		return is_array($row) ? Target::fromRow($row) : null;
	}

	public function review(int $id, Review $review): bool {
		return $this->find($id) !== null && db_execute_prepared('UPDATE plugin_pfs_target SET review = ? WHERE id = ?', [$review->value, $id], true, $this->connection);
	}

	/**
	 * @param list<array<array-key, mixed>> $rows
	 *
	 * @return list<Target>
	 */
	private function targets(array $rows): array {
		$out = [];

		foreach ($rows as $row) {
			$target = Target::fromRow($row);

			if ($target !== null) {
				$out[] = $target;
			}
		}

		return $out;
	}
}
