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
 * Plain-text formatting for page cells. Callers escape the result.
 */
final class Format {
	public static function age(int $time, int $now): string {
		if ($time <= 0) {
			return __('Never', 'pfs');
		}

		$seconds = max(0, $now - $time);

		return match (true) {
			$seconds < 120    => __('%d seconds ago', $seconds, 'pfs'),
			$seconds < 7200   => __('%d minutes ago', intdiv($seconds, 60), 'pfs'),
			$seconds < 172800 => __('%d hours ago', intdiv($seconds, 3600), 'pfs'),
			default           => __('%d days ago', intdiv($seconds, 86400), 'pfs'),
		};
	}

	public static function bytes(int $bytes): string {
		return match (true) {
			$bytes < 1024        => __('%d B', $bytes, 'pfs'),
			$bytes < 1048576     => __('%s KiB', number_format($bytes / 1024, 1), 'pfs'),
			default              => __('%s MiB', number_format($bytes / 1048576, 1), 'pfs'),
		};
	}

	/**
	 * A table header row from plain labels.
	 *
	 * @param list<string> $labels
	 */
	public static function header(array $labels): string {
		return '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', $labels)) . '</th></tr>';
	}

	/**
	 * A table row from plain cells, each escaped.
	 *
	 * @param list<string> $cells
	 */
	public static function row(array $cells): string {
		return '<tr class="tableRow"><td>' . implode('</td><td>', array_map('html_escape', $cells)) . '</td></tr>';
	}
}
