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
 * Plugin settings, read once per request from Cacti's settings table
 * (REQ-UI-003). Paths name files on the poller that runs the reader; none of
 * the settings holds a credential, only where the SSH key file is.
 */
final readonly class Settings {
	public const NAMES = [
		'pfs_ssh_binary',
		'pfs_ssh_key_file',
		'pfs_ssh_known_hosts',
		'pfs_connect_timeout',
		'pfs_read_timeout',
		'pfs_max_response',
		'pfs_reader_budget',
		'pfs_concurrency',
		'pfs_uninstall_keep_tables',
		'pfs_uninstall_delete_devices',
	];

	public const DEFAULT_SSH         = '/usr/bin/ssh';
	public const DEFAULT_KEY_FILE    = '/etc/cacti/pfs/id_ed25519';
	public const DEFAULT_KNOWN_HOSTS = '/etc/cacti/pfs/known_hosts';
	public const DEFAULT_CONNECT     = 5;
	public const DEFAULT_TIMEOUT     = 30;
	public const DEFAULT_MAX_KIB     = 4352;
	public const DEFAULT_BUDGET      = 240;
	public const DEFAULT_CONCURRENCY = 8;

	public function __construct(
		public string $sshBinary,
		public string $sshKeyFile,
		public string $sshKnownHosts,
		public int $connectTimeout,
		public int $readTimeout,
		public int $maxResponseKib,
		public int $readerBudget,
		public int $concurrency,
		public bool $keepTablesOnUninstall,
		public bool $deleteDevicesOnUninstall = false,
	) {
	}

	/**
	 * A setting that was never saved reads as its default, and an out of
	 * range number as its default or its bound. Keeping tables is the
	 * default, so an uninstall drops the registry only after an
	 * administrator cleared the box (REQ-LIFE-005). Deleting the devices the
	 * plugin created is off unless an administrator checked it.
	 *
	 * @param array<string, mixed> $row setting name => stored value, absent when never saved
	 */
	public static function fromRow(array $row): self {
		return new self(
			sshBinary: self::path($row, 'pfs_ssh_binary', self::DEFAULT_SSH),
			sshKeyFile: self::path($row, 'pfs_ssh_key_file', self::DEFAULT_KEY_FILE),
			sshKnownHosts: self::path($row, 'pfs_ssh_known_hosts', self::DEFAULT_KNOWN_HOSTS),
			connectTimeout: min(Row::int($row, 'pfs_connect_timeout', self::DEFAULT_CONNECT, 1), 60),
			readTimeout: min(Row::int($row, 'pfs_read_timeout', self::DEFAULT_TIMEOUT, 2), 300),
			maxResponseKib: min(Row::int($row, 'pfs_max_response', self::DEFAULT_MAX_KIB, 64), 65536),
			readerBudget: min(Row::int($row, 'pfs_reader_budget', self::DEFAULT_BUDGET, 10), 3600),
			concurrency: min(Row::int($row, 'pfs_concurrency', self::DEFAULT_CONCURRENCY, 1), 64),
			keepTablesOnUninstall: array_key_exists('pfs_uninstall_keep_tables', $row) ? Row::bool($row, 'pfs_uninstall_keep_tables') : true,
			deleteDevicesOnUninstall: array_key_exists('pfs_uninstall_delete_devices', $row) && Row::bool($row, 'pfs_uninstall_delete_devices'),
		);
	}

	public function maxResponseBytes(): int {
		return $this->maxResponseKib * 1024;
	}

	/**
	 * An absolute path without traversal or control characters, or the
	 * default.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function path(array $row, string $name, string $default): string {
		$value = Row::string($row, $name);

		return preg_match('#^/[A-Za-z0-9._/+-]{1,254}$#', $value) === 1 && !str_contains($value, '/../') && !str_ends_with($value, '/..') ? $value : $default;
	}

	/**
	 * Settings tab for the config_settings hook.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		$number = static fn (string $label, string $description, int $default, int $min, int $max): array => [
			'friendly_name' => $label,
			'description'   => $description,
			'method'        => 'textbox',
			'default'       => (string) $default,
			'filter'        => FILTER_VALIDATE_INT,
			'options'       => ['options' => ['min_range' => $min, 'max_range' => $max]],
			'max_length'    => '5',
			'size'          => '5',
		];

		$path = static fn (string $label, string $description, string $default): array => [
			'friendly_name' => $label,
			'description'   => $description,
			'method'        => 'textbox',
			'default'       => $default,
			'max_length'    => '255',
			'size'          => '60',
		];

		return [
			'pfs_header_access' => [
				'friendly_name' => __('Node Access', 'pfs'),
				'method'        => 'spacer',
			],
			'pfs_ssh_binary'      => $path(__('SSH Client', 'pfs'), __('Absolute path to the ssh client on each poller that reads nodes.', 'pfs'), self::DEFAULT_SSH),
			'pfs_ssh_key_file'    => $path(__('SSH Key File', 'pfs'), __('Absolute path, on each poller that reads nodes, to the private key whose public half each node lists in authorized_keys with a forced command running pfs-read.sh. Readable only by the poller user. The plugin never creates, copies, or prints it.', 'pfs'), self::DEFAULT_KEY_FILE),
			'pfs_ssh_known_hosts' => $path(__('Known Hosts File', 'pfs'), __('Absolute path to the known_hosts file holding each node host key under the node endpoint name. A node whose key is missing or changed is not read.', 'pfs'), self::DEFAULT_KNOWN_HOSTS),
			'pfs_connect_timeout' => $number(__('Connect Timeout', 'pfs'), __('Seconds to wait for a node to accept the connection.', 'pfs'), self::DEFAULT_CONNECT, 1, 60),
			'pfs_read_timeout'    => $number(__('Read Timeout', 'pfs'), __('Seconds one node read may take in total before it is stopped and recorded as failed.', 'pfs'), self::DEFAULT_TIMEOUT, 2, 300),
			'pfs_max_response'    => $number(__('Maximum Response', 'pfs'), __('KiB one node may return. A larger answer is discarded and recorded as failed.', 'pfs'), self::DEFAULT_MAX_KIB, 64, 65536),
			'pfs_header_reader' => [
				'friendly_name' => __('Reader', 'pfs'),
				'method'        => 'spacer',
			],
			'pfs_reader_budget' => $number(__('Reader Time Budget', 'pfs'), __('Seconds one reader run may spend before it stops starting node reads. Keep it below the poller interval; unread nodes are read on the next run.', 'pfs'), self::DEFAULT_BUDGET, 10, 3600),
			'pfs_concurrency'   => $number(__('Concurrent Reads', 'pfs'), __('Node reads one reader runs at the same time.', 'pfs'), self::DEFAULT_CONCURRENCY, 1, 64),
			'pfs_header_uninstall' => [
				'friendly_name' => __('Uninstall', 'pfs'),
				'method'        => 'spacer',
			],
			'pfs_uninstall_keep_tables' => [
				'friendly_name' => __('Keep Plugin Tables on Uninstall', 'pfs'),
				'description'   => __('When checked, uninstalling the plugin keeps its filesystems, nodes, inventory, and cache. Clear it to have the uninstall drop them; export the registry first from the Parallel Filesystem Admin page. Storage nodes are never contacted either way.', 'pfs'),
				'method'        => 'checkbox',
				'default'       => 'on',
			],
			'pfs_uninstall_delete_devices' => [
				'friendly_name' => __('Delete the Devices This Plugin Created on Uninstall', 'pfs'),
				'description'   => __('When checked, uninstalling the plugin deletes the devices it created for filesystems, with their graphs and data sources, as deleting them in Cacti would. Existing devices that were mapped to a filesystem are never deleted. RRD files are removed only if Cacti\'s own RRD cleanup setting removes them. Cleared, the devices stay.', 'pfs'),
				'method'        => 'checkbox',
				'default'       => '',
			],
		];
	}
}
