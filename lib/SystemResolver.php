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
 * Production Resolver backed by DNS and IP literal validation.
 */
final class SystemResolver implements Resolver {
	/**
	 * Resolve.
	 *
	 * @param string $host
	 * @return list<string>
	 */
	public function resolve(string $host): array {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}

		$addresses = [];

		foreach (@gethostbynamel($host) ?: [] as $address) {
			$addresses[] = $address;
		}

		$records = @dns_get_record($host, DNS_AAAA);

		foreach (is_array($records) ? $records : [] as $record) {
			if (isset($record['ipv6']) && is_string($record['ipv6'])) {
				$addresses[] = $record['ipv6'];
			}
		}

		return array_values(array_unique($addresses));
	}
}
