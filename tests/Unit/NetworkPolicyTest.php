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

use Somethingwithproof\Pfs\NetworkPolicy;

it('allows addresses inside a poller network and nothing else', function (string $cidr, string $address, bool $expected) {
	expect(NetworkPolicy::contains($cidr, $address))->toBe($expected);
})->with([
	['10.20.0.0/16', '10.20.255.1', true],
	['10.20.0.0/16', '10.21.0.1', false],
	['10.20.0.0/20', '10.20.15.255', true],
	['10.20.0.0/20', '10.20.16.0', false],
	['10.20.0.5', '10.20.0.5', true],
	['10.20.0.5', '10.20.0.6', false],
	['0.0.0.0/0', '192.0.2.1', true],
	['fd00:1::/64', 'fd00:1::1234', true],
	['fd00:1::/64', 'fd00:2::1', false],
	['10.0.0.0/8', '::ffff:10.0.0.1', false],
	['fd00::/8', '10.0.0.1', false],
	['10.0.0.0/8', 'oss01', false],
	['10.0.0.0/8', '', false],
]);

it('accepts only an address and prefix of the same family as a network', function (string $cidr, bool $expected) {
	expect(NetworkPolicy::validCidr($cidr))->toBe($expected);
})->with([
	['10.20.0.0/16', true],
	['fd00:1::/64', true],
	['10.20.0.1', true],
	['10.20.0.0/33', false],
	['fd00::/129', false],
	['10.20.0.0/-1', false],
	['10.20.0.0/16/3', false],
	['oss01/24', false],
	['', false],
]);

it('denies a poller with no networks, and requires every resolved address to be allowed', function () {
	$policy = new NetworkPolicy([1 => ['10.20.0.0/16']]);

	expect($policy->allowsAll(2, ['10.20.0.1']))->toBeFalse()
		->and($policy->allowsAll(1, []))->toBeFalse()
		->and($policy->allowsAll(1, ['10.20.0.1', '10.20.0.2']))->toBeTrue()
		->and($policy->allowsAll(1, ['10.20.0.1', '203.0.113.9']))->toBeFalse();
});
