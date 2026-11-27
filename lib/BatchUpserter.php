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
 * Chunked multi-row SQL upsert builder and keyset pagination helper for
 * high-target-count parallel filesystems.
 *
 * Builds parameterized multi-row INSERT ... ON DUPLICATE KEY UPDATE statements
 * in 500-row batches so repositories execute O(targets / 500) round-trips
 * instead of O(targets), and provides bounded keyset pagination parameters
 * (WHERE id > ? ORDER BY id ASC LIMIT ?) to avoid O(N) OFFSET scans.
 */
final class BatchUpserter {
	public const DEFAULT_BATCH_SIZE = 500;
	public const MAX_BATCH_SIZE     = 2000;
	public const DEFAULT_PAGE_SIZE  = 500;
	public const MAX_PAGE_SIZE      = 5000;

	/**
	 * Build chunked multi-row INSERT ... ON DUPLICATE KEY UPDATE statements.
	 *
	 * @param list<string>      $columns       column names in row value order
	 * @param list<list<mixed>> $rows          values matching $columns per row
	 * @param list<string>      $updateColumns subset of columns updated on duplicate key
	 *
	 * @return list<array{sql: string, params: list<mixed>, row_count: int}>
	 */
	public static function buildUpsertBatches(
		string $table,
		array $columns,
		array $rows,
		array $updateColumns,
		int $batchSize = self::DEFAULT_BATCH_SIZE,
	): array {
		self::assertIdentifier($table, 'table');

		if ($columns === [] || $updateColumns === []) {
			throw new \InvalidArgumentException('Columns and updateColumns must be non-empty');
		}

		foreach ($columns as $col) {
			self::assertIdentifier($col, 'column');
		}

		foreach ($updateColumns as $col) {
			self::assertIdentifier($col, 'update column');
		}

		if ($rows === []) {
			return [];
		}

		$size       = max(1, min(self::MAX_BATCH_SIZE, $batchSize));
		$colCount   = count($columns);
		$colList    = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
		$rowPh      = '(' . implode(', ', array_fill(0, $colCount, '?')) . ')';
		$updateList = implode(', ', array_map(static fn (string $c): string => sprintf('`%s` = VALUES(`%s`)', $c, $c), $updateColumns));

		$batches = [];

		foreach (array_chunk($rows, $size) as $chunk) {
			$params = [];

			foreach ($chunk as $row) {
				if (count($row) !== $colCount) {
					throw new \InvalidArgumentException('Every row must match column count');
				}

				foreach ($row as $value) {
					$params[] = $value;
				}
			}

			$placeholders = implode(', ', array_fill(0, count($chunk), $rowPh));
			$sql          = sprintf('INSERT INTO `%s` (%s) VALUES %s ON DUPLICATE KEY UPDATE %s', $table, $colList, $placeholders, $updateList);

			$batches[] = [
				'sql'       => $sql,
				'params'    => $params,
				'row_count' => count($chunk),
			];
		}

		return $batches;
	}

	/**
	 * Execute prepared upsert batches through a repository callback and return
	 * total rows written (stops immediately on the first failed batch).
	 *
	 * @param list<array{sql: string, params: list<mixed>, row_count: int}> $batches
	 * @param \Closure(string, list<mixed>): bool                           $executor
	 */
	public static function execute(array $batches, \Closure $executor): int {
		$written = 0;

		foreach ($batches as $batch) {
			if (!$executor($batch['sql'], $batch['params'])) {
				return -1;
			}

			$written += $batch['row_count'];
		}

		return $written;
	}

	/**
	 * Normalize keyset pagination cursor and limit bounds.
	 *
	 * @return array{after_id: int, limit: int}
	 */
	public static function keysetBounds(int $afterId = 0, int $limit = self::DEFAULT_PAGE_SIZE): array {
		return [
			'after_id' => max(0, $afterId),
			'limit'    => max(1, min(self::MAX_PAGE_SIZE, $limit)),
		];
	}

	private static function assertIdentifier(string $name, string $label): void {
		if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
			throw new \InvalidArgumentException('Invalid SQL ' . $label . ' identifier');
		}
	}
}
