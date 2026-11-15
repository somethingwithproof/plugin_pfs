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

final readonly class Filesystem {
	public const DEFAULT_INTERVAL = 300;
	public const MIN_INTERVAL     = 60;
	public const MAX_INTERVAL     = 86400;

	public function __construct(
		public int $id,
		public string $name,
		public string $backend,
		public int $pollerId,
		public int $interval,
		public AdminState $adminState,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id      = Row::int($row, 'id', 0, 1);
		$name    = Row::string($row, 'name');
		$backend = Row::string($row, 'backend');

		if ($id === 0 || $name === '' || $backend === '') {
			return null;
		}

		$interval = Row::int($row, 'expected_interval', self::DEFAULT_INTERVAL, self::MIN_INTERVAL);

		return new self(
			id: $id,
			name: $name,
			backend: $backend,
			pollerId: Row::int($row, 'poller_id', 1, 1),
			interval: min($interval, self::MAX_INTERVAL),
			adminState: AdminState::tryFrom(Row::string($row, 'admin_state')) ?? AdminState::Disabled,
		);
	}

	public function enabled(): bool {
		return $this->adminState === AdminState::Enabled;
	}
}
