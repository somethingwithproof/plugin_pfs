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
 * Deterministic CRC32-based collector sharding (--shard=K/N).
 *
 * Partitions storage servers across concurrent reader workers or remote
 * Cacti pollers using platform-independent unsigned CRC32 modulo N so 5,000
 * to 100,000+ targets complete reliably within a 5-minute polling window.
 */
final readonly class ShardAssigner {
	/** Max shards constant. */
	public const MAX_SHARDS = 1024;

	/**
	 * Initialize ShardAssigner.
	 *
	 * @param int $shardIndex
	 * @param int $shardTotal
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		public int $shardIndex,
		public int $shardTotal,
	) {
		if ($shardTotal < 1 || $shardTotal > self::MAX_SHARDS) {
			throw new \InvalidArgumentException('Shard total must be between 1 and ' . self::MAX_SHARDS);
		}

		if ($shardIndex < 1 || $shardIndex > $shardTotal) {
			throw new \InvalidArgumentException('Shard index must be between 1 and shard total');
		}
	}

	/**
	 * Parse a 1-indexed shard specification of the form "K/N" (for example "1/4").
	 *
	 * @param string $spec shard specification
	 *
	 * @return self
	 */
	public static function parse(string $spec): self {
		$trimmed = trim($spec);

		if (preg_match('/^([1-9][0-9]*)\/([1-9][0-9]*)$/', $trimmed, $m) !== 1) {
			throw new \InvalidArgumentException('Shard specification must have the form K/N (1 <= K <= N)');
		}

		return new self((int) $m[1], (int) $m[2]);
	}

	/**
	 * Compute the 1-indexed shard number (1..shardTotal) for an arbitrary key.
	 *
	 * On 64-bit PHP, masking crc32() with 0xFFFFFFFF produces the exact
	 * unsigned 32-bit integer in one bitwise op without sprintf() string
	 * allocation or re-parsing.
	 *
	 * @param string $key shard routing key
	 *
	 * @return int
	 */
	public function shardFor(string $key): int {
		$crc      = crc32($key);
		$unsigned = PHP_INT_SIZE >= 8 ? ($crc & 0xFFFFFFFF) : (int) sprintf('%u', $crc);

		return ($unsigned % $this->shardTotal) + 1;
	}

	/**
	 * Owns.
	 *
	 * @param string $key
	 * @return bool
	 */
	public function owns(string $key): bool {
		return $this->shardFor($key) === $this->shardIndex;
	}

	/**
	 * Owns node.
	 *
	 * @param Node $node
	 * @return bool
	 */
	public function ownsNode(Node $node): bool {
		return $this->shardFor('node:' . $node->id . ':' . $node->name) === $this->shardIndex;
	}

	/**
	 * Filter a list of storage nodes down to the subset owned by this shard.
	 *
	 * @param list<Node> $nodes
	 *
	 * @return list<Node>
	 */
	public function filterNodes(array $nodes): array {
		$owned = [];

		foreach ($nodes as $node) {
			if ($this->shardFor('node:' . $node->id . ':' . $node->name) === $this->shardIndex) {
				$owned[] = $node;
			}
		}

		return $owned;
	}

	/**
	 * Return the human-readable display label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->shardIndex . '/' . $this->shardTotal;
	}
}
