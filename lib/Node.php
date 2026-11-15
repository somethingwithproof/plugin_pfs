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
 * A registered node (REQ-SRC-001): its filesystem, role, poller, endpoint,
 * and access method. The endpoint is a host name or address the reader on
 * that poller connects to; the account it logs in as is a fixed name, never
 * a credential.
 */
final readonly class Node {
	public const NAME_PATTERN     = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';
	public const ENDPOINT_PATTERN = '/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/';
	public const USER_PATTERN     = '/^[a-z_][a-z0-9_-]{0,31}$/';

	public function __construct(
		public int $id,
		public int $filesystemId,
		public string $name,
		public NodeRole $role,
		public int $pollerId,
		public string $endpoint,
		public int $port,
		public string $user,
		public AccessMethod $accessMethod,
		public AdminState $adminState,
	) {
	}

	/**
	 * An IPv4 or IPv6 literal, or a DNS name. No user, port, or option
	 * syntax can pass, so an endpoint never reads as a command line option.
	 */
	public static function validEndpoint(string $endpoint): bool {
		return filter_var($endpoint, FILTER_VALIDATE_IP) !== false || preg_match(self::ENDPOINT_PATTERN, $endpoint) === 1;
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id       = Row::int($row, 'id', 0, 1);
		$fs       = Row::int($row, 'filesystem_id', 0, 1);
		$role     = NodeRole::tryFrom(Row::string($row, 'role'));
		$method   = AccessMethod::tryFrom(Row::string($row, 'access_method'));
		$endpoint = Row::string($row, 'endpoint');
		$user     = Row::string($row, 'ssh_user');

		if ($id === 0 || $fs === 0 || $role === null || $method === null || !self::validEndpoint($endpoint) || preg_match(self::USER_PATTERN, $user) !== 1) {
			return null;
		}

		$port = Row::int($row, 'port', 22, 1);

		return new self(
			id: $id,
			filesystemId: $fs,
			name: Row::string($row, 'name'),
			role: $role,
			pollerId: Row::int($row, 'poller_id', 1, 1),
			endpoint: $endpoint,
			port: $port > 65535 ? 22 : $port,
			user: $user,
			accessMethod: $method,
			adminState: AdminState::tryFrom(Row::string($row, 'admin_state')) ?? AdminState::Disabled,
		);
	}

	public function enabled(): bool {
		return $this->adminState === AdminState::Enabled;
	}
}
