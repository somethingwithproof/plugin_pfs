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
 * A target in the inventory (REQ-INV-001 to REQ-INV-004). Rows are never
 * deleted by the reader: a target that disappears is marked missing and its
 * history stays.
 */
final readonly class Target {
	public function __construct(
		public int $id,
		public int $filesystemId,
		public TargetId $targetId,
		public Review $review,
		public Presence $presence,
		public Condition $condition,
		public ?string $recovery,
		public int $sourceNodeId,
		public int $firstSeen,
		public int $lastSeen,
	) {
	}

	/**
	 * A stored name that no longer parses is dropped, so a hostile value
	 * that reached the table some other way never reaches a page.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id     = Row::int($row, 'id', 0, 1);
		$fs     = Row::int($row, 'filesystem_id', 0, 1);
		$target = TargetId::parse(Row::string($row, 'name'));

		if ($id === 0 || $fs === 0 || $target === null) {
			return null;
		}

		$recovery = Row::string($row, 'recovery');

		return new self(
			id: $id,
			filesystemId: $fs,
			targetId: $target,
			review: Review::tryFrom(Row::string($row, 'review')) ?? Review::Proposed,
			presence: Presence::tryFrom(Row::string($row, 'presence')) ?? Presence::Missing,
			condition: Condition::tryFrom(Row::string($row, 'condition_state')) ?? Condition::Unknown,
			recovery: preg_match('/^[A-Z_]{1,32}$/', $recovery) === 1 ? $recovery : null,
			sourceNodeId: Row::int($row, 'source_node_id', 0, 0),
			firstSeen: Row::int($row, 'first_seen', 0, 0),
			lastSeen: Row::int($row, 'last_seen', 0, 0),
		);
	}

	public function name(): string {
		return $this->targetId->name();
	}
}
