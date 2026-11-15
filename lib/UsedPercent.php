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
 * Used percentage as `lfs df` prints it (REQ-CAP-002,
 * docs/research/used-percentage.md): used / (used + available) * 100,
 * rounded up, with used = total - free. It is not used / total, which
 * differs whenever reserved blocks make available smaller than free.
 *
 * Integer arithmetic keeps the rounding exact: KiB and inode counts are
 * whole numbers well inside 2^53, so a value stored as a double converts
 * back without loss.
 */
final class UsedPercent {
	/** Above this a count could not have come back exactly from a double. */
	private const LIMIT = 9007199254740992.0;

	/**
	 * Space used percentage from kbytestotal, kbytesfree, and kbytesavail,
	 * or null when any is unknown or the three do not fit together.
	 */
	public static function space(?float $total, ?float $free, ?float $avail): ?float {
		if ($total === null || $free === null || $avail === null || !self::count($total) || !self::count($free) || !self::count($avail) || $free > $total) {
			return null;
		}

		return self::ratio((int) $total - (int) $free, (int) $avail);
	}

	/**
	 * Inode used percentage from filestotal and filesfree. For inodes
	 * `lfs df -i` takes available to be free.
	 */
	public static function inodes(?float $total, ?float $free): ?float {
		if ($total === null || $free === null || !self::count($total) || !self::count($free) || $free > $total) {
			return null;
		}

		return self::ratio((int) $total - (int) $free, (int) $free);
	}

	private static function ratio(int $used, int $avail): float {
		$denominator = $used + $avail;

		if ($denominator === 0) {
			return 0.0;
		}

		return (float) intdiv($used * 100 + $denominator - 1, $denominator);
	}

	private static function count(float $value): bool {
		return $value >= 0 && $value < self::LIMIT && floor($value) === $value;
	}
}
