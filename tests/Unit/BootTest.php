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

use Somethingwithproof\Pfs\Log;

beforeEach(function () {
	$GLOBALS['__pfs_log'] = [];
});

it('reads its metadata from INFO, declaring remote collection and remote poller hooks', function () {
	$info = plugin_pfs_version();

	expect($info['name'])->toBe('pfs')
		->and($info['compat'])->toBe('1.2.31')
		->and($info['capabilities'])->toContain('remote_collect:1')
		->and($info['capabilities'])->toContain('remote_poller:1');
});

it('boots and loads plugin classes on a supported runtime', function () {
	expect(pfs_php_supported())->toBeTrue()
		->and(pfs_boot())->toBeTrue()
		->and(class_exists(Log::class))->toBeTrue();
});

it('does not refuse or log on a supported runtime', function () {
	expect(pfs_refuse_unsupported())->toBeFalse()
		->and($GLOBALS['__pfs_log'])->toBe([]);
});

it('ignores classes outside its namespace and nested names', function () {
	expect(class_exists('Somethingwithproof\\Pfs\\Nested\\Log'))->toBeFalse()
		->and(class_exists('Somethingwithproof\\PfsLog'))->toBeFalse();
});

it('logs under the PFS tag at the right verbosity', function () {
	Log::debug('d');
	Log::info('i');
	Log::warning('w');
	Log::error('e');

	expect($GLOBALS['__pfs_log'])->toBe([
		['message' => 'DEBUG: d', 'environ' => 'PFS', 'level' => POLLER_VERBOSITY_DEBUG],
		['message' => 'NOTE: i', 'environ' => 'PFS', 'level' => POLLER_VERBOSITY_MEDIUM],
		['message' => 'WARNING: w', 'environ' => 'PFS', 'level' => ''],
		['message' => 'ERROR: e', 'environ' => 'PFS', 'level' => ''],
	]);
});
