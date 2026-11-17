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

use Somethingwithproof\Pfs\SystemClock;
use Somethingwithproof\Pfs\FilesystemRepository;
use Somethingwithproof\Pfs\NodeRepository;
use Somethingwithproof\Pfs\NodeHealthRepository;

/** @return list<array{id: string, name: string, state: string, observed: int, interval: int, detail: string}> */
function pfs_operator_rows(): array {
	$rows        = [];
	$clock       = new SystemClock();
	$filesystems = [];

	foreach ((new FilesystemRepository(false, $clock))->all() as $fs) {
		if ($fs->hostId > 0 && is_device_allowed($fs->hostId)) {
			$filesystems[$fs->id] = $fs;
		}
	}
	$health = (new NodeHealthRepository(false))->all();

	foreach ((new NodeRepository(false, $clock))->all() as $node) {
		if (!isset($filesystems[$node->filesystemId])) {
			continue;
		}
		$fs     = $filesystems[$node->filesystemId];
		$h      = $health[$node->id] ?? null;
		$rows[] = [
			'id' => (string) $node->id,
			'name' => $fs->name . ' / ' . $node->name,
			'state' => $node->adminState->value . ' / ' . ($h->outcome->value ?? 'never'),
			'observed' => $h->lastAttempt ?? 0,
			'interval' => $fs->interval,
			'detail'       => ($h->error->value ?? '') . ' ' . __('Inspect target capacity, imbalance and freshness on Parallel Filesystems. Escalate node access, target acceptance and device mapping to an administrator.', 'pfs')
		];
	}

	return $rows;
}
