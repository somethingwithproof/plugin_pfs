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
 * Plugin tables, their migrations, and the plugin's registration records.
 *
 * Tables are created through api_plugin_db_table_create() so Cacti tracks them
 * in plugin_db_changes and drops them on uninstall. The schema version is kept
 * in its own setting because api_plugin_install() writes plugin_config.version
 * before the plugin's install function runs, so that column cannot tell us
 * whether the tables exist.
 *
 * Migrations run once each, in order, and the stored schema version moves
 * past a migration only after it succeeds (REQ-LIFE-003). Every migration is
 * written to be safe to repeat, so one interrupted part way is completed by
 * the next run (REQ-LIFE-002). No migration contacts a storage node
 * (REQ-LIFE-006).
 */
final class Schema {
	public const VERSION = 3;

	public const HOOKS = [
		'config_arrays'        => 'pfs_config_arrays',
		'config_settings'      => 'pfs_config_settings',
		'draw_navigation_text' => 'pfs_draw_navigation_text',
		'poller_bottom'        => 'pfs_poller_bottom',
		'device_remove'        => 'pfs_device_remove',
		'api_device_new'       => 'pfs_api_device_new',
		'graph_buttons'        => 'pfs_graph_buttons',
	];

	private const PLUGIN  = 'pfs';
	private const SETTING = 'pfs_schema_version';

	public function __construct(private readonly \PDO|false $connection) {
	}

	public function storedVersion(): int {
		$value = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [self::SETTING], '', true, $this->connection);

		return is_numeric($value) ? (int) $value : 0;
	}

	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Apply every pending migration, or stop at the first that fails.
	 *
	 * @param int $through the last migration to run; tests pass a lower
	 *                     number to leave an upgrade interrupted
	 */
	public function migrate(int $through = self::VERSION): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than this code (%d); refusing to downgrade', $from, self::VERSION));

			return false;
		}

		foreach ($this->migrations() as $version => $migration) {
			if ($version <= $from || $version > $through) {
				continue;
			}

			if (!$migration()) {
				Log::error(sprintf('Schema migration %d failed; schema left at version %d', $version, $this->storedVersion()));

				return false;
			}

			db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [self::SETTING, (string) $version], true, $this->connection);

			Log::info(sprintf('Schema migrated to version %d', $version));
		}

		return true;
	}

	public function recordedPluginVersion(): string {
		return (string) db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection);
	}

	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared('UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?', [$version, $author, $homepage, self::PLUGIN], true, $this->connection);
	}

	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));

		$hooks  = (int) db_fetch_cell_prepared("SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)", array_merge([self::PLUGIN], array_keys(self::HOOKS)), '', true, $this->connection);
		$realms = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', [self::PLUGIN], '', true, $this->connection);

		return $hooks === count(self::HOOKS) && $realms === count(Pages::realms());
	}

	/**
	 * Stop Cacti from dropping the plugin tables on uninstall, and keep the
	 * schema version so a reinstall reuses them.
	 */
	public function untrackTables(): void {
		if (!db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], true, $this->connection)) {
			throw new \RuntimeException('Uninstall stopped because the plugin tables could not be preserved.');
		}
	}

	/**
	 * Keep the created-device table through an uninstall that drops the
	 * other tables, while it still lists a device: the devices stay in
	 * Cacti, and a later uninstall may be asked to delete them. An empty
	 * table is dropped with the rest. Returns whether it was kept.
	 */
	public function keepCreatedDevices(): bool {
		if (!(new CreatedDeviceRepository($this->connection))->hasRecords()) {
			return false;
		}

		if (!db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND `table` = ? AND method = 'create'", [self::PLUGIN, CreatedDeviceRepository::TABLE], true, $this->connection)) {
			throw new \RuntimeException('Uninstall stopped because device ownership could not be preserved.');
		}

		return true;
	}

	public function forgetVersion(): void {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', [self::SETTING], true, $this->connection);
	}

	/**
	 * @return array<int, \Closure(): bool> schema version => migration
	 */
	private function migrations(): array {
		return [
			1 => fn (): bool => $this->createTables(self::tables()),
			// Milestone 3: the device each filesystem maps to (ADR 0010) and
			// the stored summary (ADR 0011). A fresh install already has both
			// from migration 1, which creates the current definitions.
			2 => fn (): bool => $this->addColumn('plugin_pfs_filesystem', 'host_id', 'int(10) unsigned NOT NULL DEFAULT 0', 'admin_state')
				&& $this->addIndex('plugin_pfs_filesystem', 'host_id', 'host_id')
				&& $this->createTables(['plugin_pfs_summary' => self::tables()['plugin_pfs_summary']]),
			// The created-device record moves from one size-limited setting
			// to its own table. A fresh install has the table from
			// migration 1 and no setting to move.
			3 => fn (): bool => $this->createTables([CreatedDeviceRepository::TABLE => self::tables()[CreatedDeviceRepository::TABLE]])
				&& (new CreatedDeviceRepository($this->connection))->importLegacy(time()),
		];
	}

	/**
	 * Track every existing plugin table for uninstall again.
	 *
	 * Core records a table only when it creates one, so tables kept through an
	 * uninstall would otherwise never be dropped by a later one. The row is
	 * the one api_plugin_db_table_create() writes; the table has no unique
	 * key, so an existing row is checked for rather than replaced.
	 */
	public function trackTables(): void {
		foreach (array_keys(self::tables()) as $table) {
			if ($this->tableExists($table)) {
				db_execute_prepared("INSERT INTO plugin_db_changes (plugin, `table`, `column`, `method`) SELECT ?, ?, '', 'create' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM plugin_db_changes WHERE plugin = ? AND `table` = ? AND `method` = 'create')", [self::PLUGIN, $table, self::PLUGIN, $table], true, $this->connection);
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $tables
	 */
	private function createTables(array $tables): bool {
		foreach ($tables as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s', $table));

				return false;
			}
		}

		return true;
	}

	/**
	 * Add a column to a plugin table unless it is there. Written directly
	 * rather than through api_plugin_db_add_column(), which records the
	 * column for removal on uninstall even when the tables are kept.
	 */
	private function addColumn(string $table, string $column, string $definition, string $after): bool {
		if (!$this->columnExists($table, $column)) {
			// Identifiers cannot be bound; every argument is a constant of this class.
			db_execute_prepared("ALTER TABLE `$table` ADD COLUMN `$column` $definition AFTER `$after`", [], true, $this->connection);
		}

		return $this->columnExists($table, $column);
	}

	private function addIndex(string $table, string $index, string $column): bool {
		$exists = static fn (\PDO|false $connection): bool => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $index], '', true, $connection) > 0;

		if (!$exists($this->connection)) {
			db_execute_prepared("ALTER TABLE `$table` ADD INDEX `$index` (`$column`)", [], true, $this->connection);
		}

		return $exists($this->connection);
	}

	private function columnExists(string $table, string $column): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column], '', true, $this->connection) > 0;
	}

	private function tableExists(string $table): bool {
		return db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table], '', true, $this->connection) > 0;
	}

	/**
	 * Table definitions in api_plugin_db_table_create() format.
	 *
	 * State values are stored as varchar enum values, never list positions.
	 * Times are Unix seconds from the reader's clock. No table holds a
	 * credential or node output beyond validated names and numbers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		$innodb = ['type' => 'InnoDB', 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_ci', 'row_format' => 'Dynamic'];
		$id     = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
		$time   = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'];
		$text   = static fn (string $name, int $length, string $default = ''): array => ['name' => $name, 'type' => "varchar($length)", 'NULL' => false, 'default' => $default];

		return [
			'plugin_pfs_filesystem' => $innodb + [
				'comment' => 'Parallel filesystems',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$text('name', 64),
					$text('backend', 16),
					$id('poller_id') + ['default' => '1'],
					$id('expected_interval') + ['default' => '300'],
					$text('admin_state', 16, 'enabled'),
					$id('host_id') + ['default' => '0'],
					$time('created_at'),
					$time('updated_at'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'backend_name', 'columns' => ['backend', 'name']]],
				'keys'        => [['name' => 'host_id', 'columns' => ['host_id']]],
			],
			// Endpoint and login name only; the key is a file on the poller.
			'plugin_pfs_node' => $innodb + [
				'comment' => 'Parallel filesystem nodes',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$id('filesystem_id'),
					$text('name', 64),
					$text('role', 8),
					$id('poller_id') + ['default' => '1'],
					$text('endpoint', 253),
					$id('port') + ['default' => '22'],
					$text('ssh_user', 32),
					$text('access_method', 16, 'ssh'),
					$text('admin_state', 16, 'enabled'),
					$time('created_at'),
					$time('updated_at'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'name', 'columns' => ['name']]],
				'keys'        => [['name' => 'poller_id', 'columns' => ['poller_id']], ['name' => 'filesystem_id', 'columns' => ['filesystem_id']]],
			],
			'plugin_pfs_network' => $innodb + [
				'comment' => 'Networks each poller may reach',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$id('poller_id'),
					$text('cidr', 64),
					$time('created_at'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'poller_cidr', 'columns' => ['poller_id', 'cidr']]],
			],
			'plugin_pfs_target' => $innodb + [
				'comment' => 'Parallel filesystem targets found in node output',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$id('filesystem_id'),
					$text('type', 8),
					$id('target_index'),
					$text('name', 32),
					$text('review', 16, 'proposed'),
					$text('presence', 16, 'present'),
					$text('condition_state', 16, 'unknown'),
					$text('recovery', 32),
					$id('source_node_id') + ['default' => '0'],
					$time('first_seen'),
					$time('last_seen'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'filesystem_target', 'columns' => ['filesystem_id', 'type', 'target_index']]],
			],
			// Latest value per target and value name. Freshness is derived at
			// read time from observed_at, so a stale row cannot look current.
			'plugin_pfs_observation' => $innodb + [
				'comment' => 'Latest observation per target and value',
				'columns' => [
					$id('target_id'),
					$text('metric', 19),
					['name' => 'value', 'type' => 'double', 'NULL' => true],
					$time('observed_at'),
					$time('ingested_at'),
					$id('source_node_id') + ['default' => '0'],
					$id('expected_interval') + ['default' => '300'],
					$text('continuity', 16, 'continuous'),
					$text('start_marker', 32),
					$id('epoch') + ['default' => '0'],
				],
				'primary' => 'target_id`,`metric',
			],
			'plugin_pfs_node_health' => $innodb + [
				'comment' => 'Last read result per node',
				'columns' => [
					$id('node_id'),
					$time('last_attempt'),
					$time('last_success'),
					['name' => 'duration', 'type' => 'double', 'NULL' => false, 'default' => '0'],
					$id('bytes') + ['default' => '0'],
					$text('outcome', 16, 'never'),
					$text('error', 16),
					$text('version', 32),
					$id('unexpected_sections') + ['default' => '0'],
					$id('rejected_names') + ['default' => '0'],
				],
				'primary' => 'node_id',
			],
			// The summary the data source serves, one row per value. members
			// hashes the set summed into an aggregate counter (ADR 0011).
			'plugin_pfs_summary' => $innodb + [
				'comment' => 'Filesystem summary values',
				'columns' => [
					$id('filesystem_id'),
					$text('metric', 19),
					['name' => 'value', 'type' => 'double', 'NULL' => true],
					$text('members', 40),
					$time('observed_at'),
					$time('computed_at'),
				],
				'primary' => 'filesystem_id`,`metric',
			],
			// One row per poller: the run lease and the last run's counts.
			'plugin_pfs_reader' => $innodb + [
				'comment' => 'Reader lease and last run per poller',
				'columns' => [
					$id('poller_id'),
					$text('holder', 64),
					$time('expires_at'),
					$time('started_at'),
					$time('finished_at'),
					$time('last_completed'),
					$id('nodes_total') + ['default' => '0'],
					$id('nodes_read') + ['default' => '0'],
					$id('nodes_failed') + ['default' => '0'],
					$id('nodes_skipped') + ['default' => '0'],
					['name' => 'stopped_early', 'type' => 'tinyint(1)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'poller_id',
			],
			// One row per device DeviceProvisioner::create() made, for the
			// optional delete on uninstall (REQ-LIFE-005).
			CreatedDeviceRepository::TABLE => $innodb + [
				'comment' => 'Devices the parallel filesystem plugin created',
				'columns' => [
					$id('host_id'),
					$time('created_at'),
				],
				'primary' => 'host_id',
			],
		];
	}
}
