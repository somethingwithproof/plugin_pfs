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
 * One finished child process. stdout is complete only when neither limit
 * was hit; stderr is a bounded excerpt kept for classifying the failure and
 * never logged.
 */
final readonly class ProcessResult {
	/**
	 * Initialize ProcessResult.
	 *
	 * @param ?int $exitCode
	 * @param string $stdout
	 * @param string $stderr
	 * @param bool $timedOut
	 * @param bool $tooLarge
	 * @param bool $started
	 * @param float $duration
	 */
	public function __construct(
		public ?int $exitCode,
		public string $stdout,
		public string $stderr,
		public bool $timedOut,
		public bool $tooLarge,
		public bool $started,
		public float $duration,
	) {
	}

	/**
	 * Not started.
	 *
	 * @return self
	 */
	public static function notStarted(): self {
		return new self(null, '', '', false, false, false, 0.0);
	}
}
