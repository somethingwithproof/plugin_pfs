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
 * The bytes one node returned, or why it returned none (REQ-SRC-003). A
 * NodeRead with an error carries no output, so nothing downstream can parse
 * a partial answer as if it were whole.
 */
final readonly class NodeRead {
	/**
	 * Initialize NodeRead.
	 *
	 * @param string $output
	 * @param ?ReadError $error
	 * @param int $bytes
	 * @param float $duration
	 */
	private function __construct(
		public string $output,
		public ?ReadError $error,
		public int $bytes,
		public float $duration,
	) {
	}

	/**
	 * Ok.
	 *
	 * @param string $output
	 * @param float $duration
	 * @return self
	 */
	public static function ok(string $output, float $duration): self {
		return new self($output, null, strlen($output), $duration);
	}

	/**
	 * Failed.
	 *
	 * @param ReadError $error
	 * @param int $bytes
	 * @param float $duration
	 * @return self
	 */
	public static function failed(ReadError $error, int $bytes = 0, float $duration = 0.0): self {
		return new self('', $error, $bytes, $duration);
	}

	/**
	 * Succeeded.
	 *
	 * @return bool
	 */
	public function succeeded(): bool {
		return $this->error === null;
	}
}
