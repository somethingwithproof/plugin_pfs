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

/*
 * Cacti device permissions on the plugin's records (DeviceAccess), and the
 * rule that every admin action on an existing record checks them before it
 * changes anything.
 */

use Somethingwithproof\Pfs\AdminState;
use Somethingwithproof\Pfs\DeviceAccess;
use Somethingwithproof\Pfs\Filesystem;

function access_fs(int $id, int $hostId): Filesystem {
	return new Filesystem($id, 'fs' . $id, 'lustre', 1, 300, AdminState::Enabled, $hostId);
}

it('allows a mapped filesystem only while Cacti allows its device, and an unmapped one to the page realm', function () {
	$access = new DeviceAccess(fn (int $id): bool => $id === 7);

	expect($access->filesystem(access_fs(1, 7)))->toBeTrue()
		->and($access->filesystem(access_fs(2, 8)))->toBeFalse()
		->and($access->filesystem(access_fs(3, 0)))->toBeTrue()
		->and($access->filesystem(null))->toBeFalse()
		->and($access->device(0))->toBeFalse()
		->and($access->device(-1))->toBeFalse()
		->and(array_map(static fn (Filesystem $f): int => $f->id, $access->filesystems([access_fs(1, 7), access_fs(2, 8), access_fs(3, 0)])))->toBe([1, 3]);
});

it('checks device permission in every registry action that loads an existing record', function () {
	$source = (string) file_get_contents(dirname(__DIR__, 2) . '/pfs_admin.php');
	$switch = substr($source, (int) strpos($source, 'switch ($pfs_action)'));
	$cases  = preg_split("/\n\tcase '/", $switch);
	$checked = [];

	// Networks belong to a poller, and export and the list filter through
	// AdminPage; every other action handles a device-tied record.
	$exempt = ['add_network', 'delete_network', 'export'];

	foreach (array_slice($cases, 1) as $case) {
		$name = substr($case, 0, (int) strpos($case, "'"));

		if (in_array($name, $exempt, true)) {
			continue;
		}

		// Stacked cases share the next case's body.
		$body = strpos($case, "\n") === false || trim(substr($case, (int) strpos($case, ':') + 1)) === '' ? '' : $case;

		if ($body !== '') {
			expect($body)->toContain('pfs_admin_require(', $name);
		}

		$checked[] = $name;
	}

	expect($checked)->toContain('save_fs', 'delete_fs', 'save_node', 'delete_node', 'accept_target', 'ignore_target', 'device', 'save_device', 'unmap_device', 'edit_fs', 'edit_node');
});
