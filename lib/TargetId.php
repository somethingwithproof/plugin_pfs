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
 * A target's identity: filesystem name, type, and index, taken from the name
 * a node prints (REQ-INV-001). The node that serves it is never part of the
 * identity, so a target keeps one history across failover.
 *
 * Lustre names targets <fsname>-OST<nnnn> and <fsname>-MDT<nnnn>, with an
 * fsname of at most eight characters and a four-digit lower-case hex index.
 * Anything else is rejected, not repaired (REQ-INV-005).
 */
final readonly class TargetId {
	public const PATTERN = '/^([A-Za-z0-9_-]{1,8})-(OST|MDT)([0-9a-f]{4})$/';

	public function __construct(
		public string $filesystem,
		public TargetType $type,
		public int $index,
	) {
	}

	public static function parse(string $name): ?self {
		if (preg_match(self::PATTERN, $name, $match) !== 1) {
			return null;
		}

		return new self($match[1], $match[2] === 'OST' ? TargetType::Ost : TargetType::Mdt, (int) hexdec($match[3]));
	}

	public function name(): string {
		return sprintf('%s-%s%04x', $this->filesystem, strtoupper($this->type->value), $this->index);
	}

	public function key(): string {
		return $this->type->value . ':' . $this->index;
	}
}
