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
 * A clock that stays where it is put, for tests and for replaying a run.
 */
final class FixedClock implements Clock {
	/**
	 * Initialize FixedClock.
	 *
	 * @param int $time
	 */
	public function __construct(private int $time) {
	}

	/**
	 * Return the current Unix timestamp in seconds.
	 *
	 * @return int
	 */
	public function now(): int {
		return $this->time;
	}

	/**
	 * Advance.
	 *
	 * @param int $seconds
	 */
	public function advance(int $seconds): void {
		$this->time += $seconds;
	}
}
