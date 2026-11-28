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
 * Networks an administrator allows each poller's reader to reach
 * (REQ-SRC-005). A poller with no allowed network reaches nothing: the
 * registry refuses its nodes and the reader does not connect.
 *
 * Every address an endpoint resolves to must be inside an allowed network,
 * because the transport may pick any of them.
 */
final readonly class NetworkPolicy {
	/**
	 * @param array<int, list<string>> $allowed poller id => CIDR blocks
	 */
	public function __construct(private array $allowed) {
	}

	/**
	 * @param list<string> $addresses
	 */
	public function allowsAll(int $pollerId, array $addresses): bool {
		if ($addresses === []) {
			return false;
		}

		foreach ($addresses as $address) {
			if (!$this->allows($pollerId, $address)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Allows.
	 *
	 * @param int $pollerId
	 * @param string $address
	 * @return bool
	 */
	public function allows(int $pollerId, string $address): bool {
		foreach ($this->allowed[$pollerId] ?? [] as $cidr) {
			if (self::contains($cidr, $address)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An address and prefix length of the same family, such as 10.20.0.0/16
	 * or fd00:1::/64. A bare address is a single host.
	 */
	public static function validCidr(string $cidr): bool {
		return self::split($cidr) !== null;
	}

	/**
	 * Contains.
	 *
	 * @param string $cidr
	 * @param string $address
	 * @return bool
	 */
	public static function contains(string $cidr, string $address): bool {
		$block = self::split($cidr);
		$addr  = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);

		if ($block === null || $addr === false || strlen($addr) !== strlen($block[0])) {
			return false;
		}

		[$network, $bits] = $block;
		$whole            = intdiv($bits, 8);

		if (strncmp($network, $addr, $whole) !== 0) {
			return false;
		}

		if ($bits % 8 === 0) {
			return true;
		}

		$mask = (0xff << (8 - $bits % 8)) & 0xff;

		return (ord($network[$whole]) & $mask) === (ord($addr[$whole]) & $mask);
	}

	/**
	 * @return array{0: string, 1: int}|null packed network and prefix length
	 */
	private static function split(string $cidr): ?array {
		$parts = explode('/', trim($cidr));

		if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
			return null;
		}

		$packed = inet_pton($parts[0]);

		if ($packed === false) {
			return null;
		}

		$max = strlen($packed) * 8;

		if (!isset($parts[1])) {
			return [$packed, $max];
		}

		if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $max) {
			return null;
		}

		return [$packed, (int) $parts[1]];
	}
}
