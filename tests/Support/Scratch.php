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

namespace Somethingwithproof\Pfs\Tests;

/**
 * A private scratch directory for one test, removed again by the test.
 */
final class Scratch {
	public readonly string $dir;

	public function __construct() {
		$base = sys_get_temp_dir() . '/pfs-test-' . bin2hex(random_bytes(6));

		if (!mkdir($base, 0700)) {
			throw new \RuntimeException('cannot create scratch directory');
		}

		$this->dir = $base;
	}

	public function file(string $name, string $content, int $mode = 0600): string {
		$path = $this->dir . '/' . $name;

		if (!is_dir(dirname($path))) {
			mkdir(dirname($path), 0700, true);
		}

		file_put_contents($path, $content);
		chmod($path, $mode);

		return $path;
	}

	public function remove(): void {
		self::delete($this->dir);
	}

	private static function delete(string $path): void {
		if (is_link($path) || is_file($path)) {
			unlink($path);

			return;
		}

		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) ?: [] as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				self::delete($path . '/' . $entry);
			}
		}

		rmdir($path);
	}
}
