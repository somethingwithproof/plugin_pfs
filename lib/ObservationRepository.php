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
 * Reads the observation cache. Pages and, from milestone 3, the script
 * server input read here; neither contacts a node (REQ-READ-005).
 */
final class ObservationRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return array<int, array<string, Observation>> target id => value name => observation
	 */
	public function forFilesystem(int $filesystemId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT o.* FROM plugin_pfs_observation o INNER JOIN plugin_pfs_target t ON t.id = o.target_id WHERE t.filesystem_id = ?', [$filesystemId], true, $this->connection)) as $row) {
			$observation = Observation::fromRow($row);

			if ($observation !== null) {
				$out[$observation->targetId][$observation->metric] = $observation;
			}
		}

		return $out;
	}
}
