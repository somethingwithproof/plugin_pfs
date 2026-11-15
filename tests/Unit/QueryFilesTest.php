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
 * The data query files the install copies into Cacti's resource tree:
 * ownership is recorded only for files the plugin wrote, and a symbolic
 * link at the destination is refused, dangling or not.
 */

use Somethingwithproof\Pfs\CactiObjectRepository;
use Somethingwithproof\Pfs\TemplateInstaller;
use Somethingwithproof\Pfs\Tests\Scratch;

require_once dirname(__DIR__) . '/Support/Scratch.php';

beforeEach(function () {
	$GLOBALS['__pfs_options'] = [];
	$this->scratch = new Scratch();
	$this->plugin  = $this->scratch->dir . '/plugin';
	$this->cacti   = $this->scratch->dir . '/cacti';
	$this->dest    = $this->cacti . '/resource/script_server';

	foreach (TemplateInstaller::QUERIES as [, $file]) {
		$this->scratch->file('plugin/resource/script_server/' . $file, "<query>$file</query>\n", 0644);
	}

	mkdir($this->dest, 0700, true);

	$this->installer = new TemplateInstaller($this->plugin, $this->cacti, new CactiObjectRepository(false));
	$this->copy      = fn (): ?string => (new ReflectionMethod(TemplateInstaller::class, 'installQueryFiles'))->invoke($this->installer);
});

afterEach(function () {
	$this->scratch->remove();
	$GLOBALS['__pfs_options'] = [];
});

it('copies the files, records what it wrote, and removes them on uninstall', function () {
	expect(($this->copy)())->toBeNull()
		->and(file_get_contents($this->dest . '/pfs_osts.xml'))->toBe("<query>pfs_osts.xml</query>\n")
		->and(array_keys(json_decode($GLOBALS['__pfs_options'][TemplateInstaller::FILES_SETTING], true)))->toBe(['pfs_osts.xml', 'pfs_mdts.xml'])
		->and(glob($this->dest . '/.pfs-*'))->toBe([]);

	$this->installer->uninstall();

	expect(file_exists($this->dest . '/pfs_osts.xml'))->toBeFalse()
		->and(file_exists($this->dest . '/pfs_mdts.xml'))->toBeFalse();
});

it('uses an identical file an administrator copied without taking it over, so uninstall keeps it', function () {
	copy($this->plugin . '/resource/script_server/pfs_osts.xml', $this->dest . '/pfs_osts.xml');

	expect(($this->copy)())->toBeNull()
		->and(json_decode($GLOBALS['__pfs_options'][TemplateInstaller::FILES_SETTING], true))->toHaveKeys(['pfs_mdts.xml'])->not->toHaveKey('pfs_osts.xml');

	$this->installer->uninstall();

	expect(file_exists($this->dest . '/pfs_osts.xml'))->toBeTrue()
		->and(file_exists($this->dest . '/pfs_mdts.xml'))->toBeFalse();
});

it('keeps owning a file it wrote when a later file fails, so a retry and uninstall still treat it as its own', function () {
	file_put_contents($this->dest . '/pfs_mdts.xml', "<query>local</query>\n");

	expect(($this->copy)())->toContain('pfs_mdts.xml exists and was not written by this plugin')
		->and(json_decode($GLOBALS['__pfs_options'][TemplateInstaller::FILES_SETTING], true))->toHaveKey('pfs_osts.xml');

	// The operator clears the conflict and installs again.
	unlink($this->dest . '/pfs_mdts.xml');

	expect(($this->copy)())->toBeNull()
		->and(array_keys(json_decode($GLOBALS['__pfs_options'][TemplateInstaller::FILES_SETTING], true)))->toBe(['pfs_osts.xml', 'pfs_mdts.xml']);

	$this->installer->uninstall();

	expect(file_exists($this->dest . '/pfs_osts.xml'))->toBeFalse()
		->and(file_exists($this->dest . '/pfs_mdts.xml'))->toBeFalse();
});

it('leaves a different file it did not write alone', function () {
	file_put_contents($this->dest . '/pfs_osts.xml', "<query>local</query>\n");

	expect(($this->copy)())->toContain('was not written by this plugin')
		->and(file_get_contents($this->dest . '/pfs_osts.xml'))->toBe("<query>local</query>\n");
});

it('refuses a symbolic link at the destination, dangling or not, and writes nothing through it', function () {
	$elsewhere = $this->scratch->dir . '/elsewhere.xml';
	symlink($elsewhere, $this->dest . '/pfs_osts.xml');

	expect(($this->copy)())->toContain('is a symbolic link')
		->and(file_exists($elsewhere))->toBeFalse()
		->and(is_link($this->dest . '/pfs_osts.xml'))->toBeTrue();

	$existing = $this->scratch->file('existing.xml', "<query>pfs_osts.xml</query>\n", 0644);
	unlink($this->dest . '/pfs_osts.xml');
	symlink($existing, $this->dest . '/pfs_osts.xml');

	expect(($this->copy)())->toContain('is a symbolic link');

	$this->installer->uninstall();

	expect(is_file($existing))->toBeTrue();
});

it('replaces a file it wrote when the shipped version changes', function () {
	expect(($this->copy)())->toBeNull();

	file_put_contents($this->plugin . '/resource/script_server/pfs_osts.xml', "<query>v2</query>\n");

	expect(($this->copy)())->toBeNull()
		->and(file_get_contents($this->dest . '/pfs_osts.xml'))->toBe("<query>v2</query>\n")
		->and(substr(sprintf('%o', fileperms($this->dest . '/pfs_osts.xml')), -4))->toBe('0644');
});
