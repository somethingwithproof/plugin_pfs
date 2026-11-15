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
 * One value a backend produces (REQ-BACKEND-002): its kind, unit, the target
 * type it belongs to, and the node roles that report it.
 *
 * The name becomes a Cacti data source name in milestone 3, which core
 * limits to 19 characters of [a-zA-Z0-9_] (data_template_rrd and
 * poller_item.rrd_name are varchar(19)).
 */
final readonly class ValueSpec {
	public const NAME_PATTERN = '/^[a-zA-Z0-9_]{1,19}$/';

	/**
	 * @param list<NodeRole> $roles
	 */
	public function __construct(
		public string $name,
		public ValueKind $kind,
		public string $unit,
		public TargetType $targetType,
		public array $roles,
	) {
	}
}
