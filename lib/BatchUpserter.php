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
 * instead of O(targets), reuses one prebuilt SQL string across all full-size
 * batches, streams batches in O(batchSize) memory via streamUpsertBatches(),
 * and provides bounded keyset pagination parameters (WHERE id > ? ORDER BY id
 * ASC LIMIT ?) to avoid O(N) OFFSET scans.
 */
final class BatchUpserter {
	/** Default batch size constant. */
	public const DEFAULT_BATCH_SIZE = 500;
	/** Max batch size constant. */
	public const MAX_BATCH_SIZE     = 2000;
	/** Default page size constant. */
	public const DEFAULT_PAGE_SIZE  = 500;
	/** Max page size constant. */
	public const MAX_PAGE_SIZE      = 5000;

	/**
	 * Build chunked multi-row INSERT ... ON DUPLICATE KEY UPDATE statements.
	 *
	 * @param string            $table
	 * @param list<string>      $columns       column names in row value order
	 * @param list<list<mixed>> $rows          values matching $columns per row
	 * @param list<string>      $updateColumns subset of columns updated on duplicate key
	 * @param int               $batchSize     maximum rows per statement
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
		$batches = [];

		foreach (self::streamUpsertBatches($table, $columns, $rows, $updateColumns, $batchSize) as $batch) {
			$batches[] = $batch;
		}

		return $batches;
	}

	/**
	 * Stream chunked multi-row INSERT ... ON DUPLICATE KEY UPDATE batches in
	 * O(batchSize) working memory without duplicating $rows via array_chunk()
	 * or rebuilding the SQL string for full-size batches.
	 *
	 * @param string                $table
	 * @param list<string>          $columns       column names in row value order
	 * @param iterable<list<mixed>> $rows          row stream matching $columns per row
	 * @param list<string>          $updateColumns subset of columns updated on duplicate key
	 * @param int                   $batchSize     maximum rows per statement
	 *
	 * @return \Generator<int, array{sql: string, params: list<mixed>, row_count: int}>
	 */
	public static function streamUpsertBatches(
		string $table,
		array $columns,
		iterable $rows,
		array $updateColumns,
		int $batchSize = self::DEFAULT_BATCH_SIZE,
	): \Generator {
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

		$size       = max(1, min(self::MAX_BATCH_SIZE, $batchSize));
		$colCount   = count($columns);
		$colList    = '`' . implode('`, `', $columns) . '`';
		$rowPh      = '(' . implode(', ', array_fill(0, $colCount, '?')) . ')';
		$updateList = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = VALUES(`' . $c . '`)', $updateColumns));
		$prefix     = 'INSERT INTO `' . $table . '` (' . $colList . ') VALUES ';
		$suffix     = ' ON DUPLICATE KEY UPDATE ' . $updateList;
		$fullSql    = null;

		$params   = [];
		$rowCount = 0;

		foreach ($rows as $row) {
			if (count($row) !== $colCount) {
				throw new \InvalidArgumentException('Every row must match column count');
			}

			foreach ($row as $value) {
				$params[] = $value;
			}

			$rowCount++;

			if ($rowCount === $size) {
				$fullSql ??= $prefix . implode(', ', array_fill(0, $size, $rowPh)) . $suffix;

				yield [
					'sql'       => $fullSql,
					'params'    => $params,
					'row_count' => $rowCount,
				];

				$params   = [];
				$rowCount = 0;
			}
		}

		if ($rowCount > 0) {
			$sql = $prefix . implode(', ', array_fill(0, $rowCount, $rowPh)) . $suffix;

			yield [
				'sql'       => $sql,
				'params'    => $params,
				'row_count' => $rowCount,
			];
		}
	}

	/**
	 * Execute prepared upsert batches through a repository callback and return
	 * total rows written (stops immediately on the first failed batch).
	 *
	 * @param iterable<array{sql: string, params: list<mixed>, row_count: int}> $batches
	 * @param \Closure(string, list<mixed>): bool                               $executor
	 *
	 * @return int
	 */
	public static function execute(iterable $batches, \Closure $executor): int {
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
	 * @param int $afterId
	 * @param int $limit
	 *
	 * @return array{after_id: int, limit: int}
	 */
	public static function keysetBounds(int $afterId = 0, int $limit = self::DEFAULT_PAGE_SIZE): array {
		return [
			'after_id' => max(0, $afterId),
			'limit'    => max(1, min(self::MAX_PAGE_SIZE, $limit)),
		];
	}

	/**
	 * Assert identifier.
	 *
	 * @param string $name
	 * @param string $label
	 * @throws \InvalidArgumentException
	 */
	private static function assertIdentifier(string $name, string $label): void {
		if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
			throw new \InvalidArgumentException('Invalid SQL ' . $label . ' identifier');
		}
	}
}
