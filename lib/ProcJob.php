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
 * One child process while ProcRunner runs it. Internal to ProcRunner.
 */
final class ProcJob {
	public string $out = '';
	public string $err = '';
	public bool $stdoutEof = false;
	public bool $stderrEof = false;
	public bool $tooLarge = false;
	public ?int $exit = null;

	/**
	 * @param resource $process
	 * @param resource $stdout
	 * @param resource $stderr
	 */
	public function __construct(public readonly mixed $process, public readonly mixed $stdout, public readonly mixed $stderr, public readonly float $started) {
	}
}
