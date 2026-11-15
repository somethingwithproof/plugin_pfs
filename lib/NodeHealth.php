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
 * The last read of one node, for the health page (REQ-HEALTH-001). The
 * reported version is validated by the backend before it gets here; nothing
 * else the node printed is kept.
 */
final readonly class NodeHealth {
	public function __construct(
		public int $nodeId,
		public int $lastAttempt = 0,
		public int $lastSuccess = 0,
		public float $duration = 0.0,
		public int $bytes = 0,
		public Collection $outcome = Collection::Never,
		public ?ReadError $error = null,
		public ?string $version = null,
		public int $unexpectedSections = 0,
		public int $rejectedNames = 0,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id = Row::int($row, 'node_id', 0, 1);

		if ($id === 0) {
			return null;
		}

		$error   = Row::string($row, 'error');
		$version = Row::string($row, 'version');

		return new self(
			nodeId: $id,
			lastAttempt: Row::int($row, 'last_attempt', 0, 0),
			lastSuccess: Row::int($row, 'last_success', 0, 0),
			duration: max(0.0, Row::float($row, 'duration') ?? 0.0),
			bytes: Row::int($row, 'bytes', 0, 0),
			outcome: Collection::tryFrom(Row::string($row, 'outcome')) ?? Collection::Never,
			error: $error === '' ? null : ReadError::tryFrom($error),
			version: preg_match('/^[0-9][0-9A-Za-z._-]{0,31}$/', $version) === 1 ? $version : null,
			unexpectedSections: Row::int($row, 'unexpected_sections', 0, 0),
			rejectedNames: Row::int($row, 'rejected_names', 0, 0),
		);
	}
}
