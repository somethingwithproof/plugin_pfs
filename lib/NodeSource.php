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
 * Carries the one allowlisted read from each node to the reader (ADR 0008).
 *
 * A source knows nothing about the filesystem product. It reads each node
 * once, runs at most $concurrency reads at a time, starts no read after
 * $deadline (a microtime(true) value), and never throws: every node gets a
 * NodeRead, failed when the read was refused, timed out, or too large. Nodes
 * left unread at the deadline are absent from the result.
 */
interface NodeSource {
	/**
	 * @param list<Node> $nodes
	 *
	 * @return array<int, NodeRead> node id => read
	 */
	public function readAll(array $nodes, int $concurrency, float $deadline): array;
}
