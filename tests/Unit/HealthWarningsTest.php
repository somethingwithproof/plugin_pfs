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

use Somethingwithproof\Pfs\AccessMethod;
use Somethingwithproof\Pfs\AdminState;
use Somethingwithproof\Pfs\Collection;
use Somethingwithproof\Pfs\Filesystem;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\HealthWarnings;
use Somethingwithproof\Pfs\Node;
use Somethingwithproof\Pfs\NodeHealth;
use Somethingwithproof\Pfs\NodeRole;
use Somethingwithproof\Pfs\ReaderRun;
use Somethingwithproof\Pfs\ReadError;
use Somethingwithproof\Pfs\Settings;
use Somethingwithproof\Pfs\Tests\Scratch;

require_once dirname(__DIR__) . '/Support/Scratch.php';

beforeEach(function () {
	$this->scratch = new Scratch();
	$this->clock   = new FixedClock(100000);
	$this->fs      = [1 => new Filesystem(1, 'scratch', 'lustre', 1, 300, AdminState::Enabled)];
	$this->node    = new Node(3, 1, 'oss01', NodeRole::Oss, 1, '10.20.0.11', 22, 'pfsread', AccessMethod::Ssh, AdminState::Enabled);
	$this->runs    = [1 => new ReaderRun(1, 99900, 99950, 99900)];
	$this->good    = Settings::fromRow([
		'pfs_ssh_binary'      => $this->scratch->file('ssh', '', 0700),
		'pfs_ssh_key_file'    => $this->scratch->file('key', 'k', 0600),
		'pfs_ssh_known_hosts' => $this->scratch->file('known_hosts', '', 0644),
	]);
});

afterEach(function () {
	$this->scratch->remove();
});

it('warns about nothing on a healthy setup', function () {
	$health = [3 => new NodeHealth(3, 99950, 99950, 0.2, 900, Collection::Completed)];

	expect(HealthWarnings::production($this->clock)->for($this->good, [$this->node], $health, $this->runs, $this->fs))->toBe([]);
});

it('warns when the key can be read by others or a file is missing', function () {
	chmod($this->good->sshKeyFile, 0644);
	$settings = Settings::fromRow(['pfs_ssh_binary' => $this->good->sshBinary, 'pfs_ssh_key_file' => $this->good->sshKeyFile, 'pfs_ssh_known_hosts' => $this->scratch->dir . '/absent']);
	$warnings = implode("\n", HealthWarnings::production($this->clock)->for($settings, [$this->node], [], $this->runs, $this->fs));

	expect($warnings)->toContain('mode 0644')
		->and($warnings)->toContain('known hosts file');
});

it('warns about access that looks broader than the read script', function () {
	$health = [3 => new NodeHealth(3, 99950, 99000, 0.2, 900, Collection::Failed, ReadError::Unframed)];
	$extra  = [3 => new NodeHealth(3, 99950, 99950, 0.2, 900, Collection::Completed, null, '2.15.5', 4, 2)];
	$check  = HealthWarnings::production($this->clock);

	expect(implode("\n", $check->for($this->good, [$this->node], $health, $this->runs, $this->fs)))->toContain('forced command')
		->and(implode("\n", $check->for($this->good, [$this->node], $extra, $this->runs, $this->fs)))->toContain('4 parameters')
		->and(implode("\n", $check->for($this->good, [$this->node], $extra, $this->runs, $this->fs)))->toContain('2 target names');
});

it('warns about host keys and endpoints outside the allowed networks', function () {
	$check = HealthWarnings::production($this->clock);

	expect(implode("\n", $check->for($this->good, [$this->node], [3 => new NodeHealth(3, 99950, 0, 0.1, 0, Collection::Failed, ReadError::HostKey)], $this->runs, $this->fs)))->toContain('host key')
		->and(implode("\n", $check->for($this->good, [$this->node], [3 => new NodeHealth(3, 99950, 0, 0.0, 0, Collection::Failed, ReadError::Network)], $this->runs, $this->fs)))->toContain('outside the networks');
});

it('warns when a poller reader has not completed recently', function () {
	$old   = [1 => new ReaderRun(1, 99000, 99010, 99000)];
	$check = HealthWarnings::production($this->clock);

	expect(implode("\n", $check->for($this->good, [$this->node], [], $old, $this->fs)))->toContain('poller 1 has not completed')
		->and(implode("\n", $check->for($this->good, [$this->node], [], [], $this->fs)))->toContain('poller 1 has not completed');
});

it('says nothing about disabled nodes or filesystems', function () {
	$disabled = [1 => new Filesystem(1, 'scratch', 'lustre', 1, 300, AdminState::Disabled)];

	expect(HealthWarnings::production($this->clock)->for(Settings::fromRow(['pfs_ssh_key_file' => '/nonexistent/key']), [$this->node], [], [], $disabled))->toBe([]);
});
