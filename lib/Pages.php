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
 * Page files, realms, menu entries, and breadcrumbs.
 *
 * Cacti's auth.php denies any page that is not listed in a realm, so every
 * page script the plugin ships appears in exactly one realm below
 * (REQ-UI-001). The registry and the health page change or reveal node
 * access, so they are Parallel Filesystem Admin pages (REQ-UI-002,
 * REQ-HEALTH-004); the view realm sees inventory and freshness only.
 */
final class Pages {
	public const VIEW  = ['pfs.php'];
	public const ADMIN = ['pfs_admin.php', 'pfs_health.php'];

	/**
	 * @return list<array{name: string, files: list<string>}>
	 */
	public static function realms(): array {
		return [
			['name' => __('Parallel Filesystem View', 'pfs'), 'files' => self::VIEW],
			['name' => __('Parallel Filesystem Admin', 'pfs'), 'files' => self::ADMIN],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function files(): array {
		return array_merge(self::VIEW, self::ADMIN);
	}

	/**
	 * Console > Management entries. Cacti shows a menu entry only to users
	 * whose realms allow its file (REQ-UI-004).
	 *
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			'pfs.php'        => __('Parallel Filesystems', 'pfs'),
			'pfs_admin.php'  => __('Parallel Filesystem Registry', 'pfs'),
			'pfs_health.php' => __('Parallel Filesystem Health', 'pfs'),
		];
	}

	/**
	 * Pages whose load re-checks the schema, matching how maintained plugins
	 * apply upgrades in a core that never calls plugin_*_upgrade() itself.
	 *
	 * @return list<string>
	 */
	public static function upgradeTriggers(): array {
		return array_merge(['plugins.php'], self::files());
	}

	/**
	 * @return array<string, array{title: string, mapping: string, url: string, level: string}>
	 */
	public static function navigation(): array {
		$nav = [];

		foreach (self::menu() as $file => $title) {
			$nav[$file . ':'] = [
				'title'   => $title,
				'mapping' => 'index.php:',
				'url'     => $file,
				'level'   => '1',
			];
		}

		return $nav;
	}
}
