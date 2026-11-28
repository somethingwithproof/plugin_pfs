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
 * A node source that answers from captured output, for tests and for
 * replaying a capture. It never touches the network or a process.
 */
final class FixtureNodeSource implements NodeSource {
	/** @var list<int> */
	public array $reads = [];

	/**
	 * @param array<int, NodeRead|string> $answers node id => output or a prepared read
	 */
	public function __construct(private array $answers) {
	}

	/**
	 * @param NodeRead|string $answer
	 */
	public function answer(int $nodeId, NodeRead|string $answer): void {
		$this->answers[$nodeId] = $answer;
	}

	/**
	 * Read all.
	 *
	 * @param list<Node> $nodes
	 * @param int $concurrency
	 * @param float $deadline
	 * @return array<int, NodeRead>
	 */
	public function readAll(array $nodes, int $concurrency, float $deadline): array {
		$out = [];

		foreach ($nodes as $node) {
			if (microtime(true) >= $deadline) {
				break;
			}

			$this->reads[] = $node->id;
			$answer        = $this->answers[$node->id] ?? NodeRead::failed(ReadError::Unreachable);
			$out[$node->id] = is_string($answer) ? NodeRead::ok($answer, 0.0) : $answer;
		}

		return $out;
	}
}
