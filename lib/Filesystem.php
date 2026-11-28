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
 * A registered filesystem. hostId is the Cacti device it maps to (ADR
 * 0010), or 0 while it has none.
 */
final readonly class Filesystem {
	/** Default interval constant. */
	public const DEFAULT_INTERVAL = 300;
	/** Min interval constant. */
	public const MIN_INTERVAL     = 60;
	/** Max interval constant. */
	public const MAX_INTERVAL     = 86400;

	/**
	 * Initialize Filesystem.
	 *
	 * @param int $id
	 * @param string $name
	 * @param string $backend
	 * @param int $pollerId
	 * @param int $interval
	 * @param AdminState $adminState
	 * @param int $hostId
	 */
	public function __construct(
		public int $id,
		public string $name,
		public string $backend,
		public int $pollerId,
		public int $interval,
		public AdminState $adminState,
		public int $hostId = 0,
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
			hostId: Row::int($row, 'host_id', 0, 0),
		);
	}

	/**
	 * Return whether this entity is administratively enabled.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->adminState === AdminState::Enabled;
	}
}
