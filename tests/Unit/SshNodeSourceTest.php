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
use Somethingwithproof\Pfs\LustreBackend;
use Somethingwithproof\Pfs\NetworkPolicy;
use Somethingwithproof\Pfs\Node;
use Somethingwithproof\Pfs\NodeRole;
use Somethingwithproof\Pfs\ProcessResult;
use Somethingwithproof\Pfs\ProcessRunner;
use Somethingwithproof\Pfs\ProcRunner;
use Somethingwithproof\Pfs\ReadError;
use Somethingwithproof\Pfs\Resolver;
use Somethingwithproof\Pfs\Settings;
use Somethingwithproof\Pfs\SshNodeSource;
use Somethingwithproof\Pfs\Tests\Scratch;

require_once dirname(__DIR__) . '/Support/Scratch.php';

final class PfsRecordingRunner implements ProcessRunner {
	/** @var array<int, list<string>> */
	public array $commands = [];

	public function __construct(private readonly ProcessResult $result) {
	}

	public function runAll(array $commands, int $concurrency, float $timeout, int $maxBytes, float $deadline): array {
		$this->commands = $commands;

		return array_map(fn (): ProcessResult => $this->result, $commands);
	}
}

final class PfsStaticResolver implements Resolver {
	/**
	 * @param array<string, list<string>> $answers
	 */
	public function __construct(private readonly array $answers) {
	}

	public function resolve(string $host): array {
		return $this->answers[$host] ?? [];
	}
}

function pfs_node(int $id = 3, string $endpoint = 'oss01.example', int $poller = 1): Node {
	return new Node($id, 1, 'oss01', NodeRole::Oss, $poller, $endpoint, 2222, 'pfsread', AccessMethod::Ssh, AdminState::Enabled);
}

beforeEach(function () {
	$this->scratch  = new Scratch();
	$this->settings = Settings::fromRow([
		'pfs_ssh_binary'      => $this->scratch->file('ssh', "#!/bin/sh\nexit 0\n", 0700),
		'pfs_ssh_key_file'    => $this->scratch->file('key', 'not a real key'),
		'pfs_ssh_known_hosts' => $this->scratch->file('known_hosts', ''),
	]);
	$this->resolver = new PfsStaticResolver(['oss01.example' => ['10.20.0.11'], 'oss02.example' => ['10.20.0.12', '198.51.100.7']]);
	$this->policy   = new NetworkPolicy([1 => ['10.20.0.0/16']]);
});

afterEach(function () {
	$this->scratch->remove();
});

it('runs a fixed argument vector with host key checking and no remote command', function () {
	$runner = new PfsRecordingRunner(new ProcessResult(0, '', '', false, false, true, 0.1));
	(new SshNodeSource($runner, $this->settings, $this->resolver, $this->policy))->readAll([pfs_node()], 4, microtime(true) + 60);

	$argv = $runner->commands[3];

	// No ssh_config, the poller user's or the system's, may add options:
	// -F /dev/null comes before every -o option.
	expect($argv[0])->toBe($this->settings->sshBinary)
		->and(array_slice($argv, 1, 2))->toBe(['-F', '/dev/null'])
		->and(array_search('-F', $argv, true))->toBeLessThan(array_search('-o', $argv, true))
		->and(array_keys($argv, '-F', true))->toHaveCount(1)
		->and($argv)->toContain('StrictHostKeyChecking=yes')
		->and($argv)->toContain('BatchMode=yes')
		->and($argv)->toContain('GlobalKnownHostsFile=/dev/null')
		->and($argv)->toContain('UserKnownHostsFile=' . $this->settings->sshKnownHosts)
		->and($argv)->toContain('HostKeyAlias=oss01.example')
		->and($argv)->toContain('ProxyCommand=none')
		->and($argv)->toContain('PermitLocalCommand=no')
		->and($argv)->toContain('PasswordAuthentication=no')
		->and(array_slice($argv, -2))->toBe(['--', '10.20.0.11'])
		->and($argv[array_search('-p', $argv, true) + 1])->toBe('2222')
		->and($argv[array_search('-l', $argv, true) + 1])->toBe('pfsread')
		->and(implode(' ', $argv))->not->toContain('StrictHostKeyChecking=no')
		->and(implode(' ', $argv))->not->toContain('accept-new');
});

it('connects to nothing outside the allowed networks or when the name does not resolve', function () {
	$runner = new PfsRecordingRunner(new ProcessResult(0, '', '', false, false, true, 0.1));
	$reads  = (new SshNodeSource($runner, $this->settings, $this->resolver, $this->policy))->readAll([
		pfs_node(3, 'oss02.example'),
		pfs_node(4, 'gone.example'),
		pfs_node(5, 'oss01.example', 2),
	], 4, microtime(true) + 60);

	expect($runner->commands)->toBe([])
		->and($reads[3]->error)->toBe(ReadError::Network)
		->and($reads[4]->error)->toBe(ReadError::Resolve)
		->and($reads[5]->error)->toBe(ReadError::Network);
});

it('reads nothing when the key or known hosts file is missing', function () {
	$settings = Settings::fromRow(['pfs_ssh_binary' => $this->settings->sshBinary, 'pfs_ssh_key_file' => $this->scratch->dir . '/absent', 'pfs_ssh_known_hosts' => $this->settings->sshKnownHosts]);
	$runner   = new PfsRecordingRunner(new ProcessResult(0, '', '', false, false, true, 0.1));
	$reads    = (new SshNodeSource($runner, $settings, $this->resolver, $this->policy))->readAll([pfs_node()], 4, microtime(true) + 60);

	expect($runner->commands)->toBe([])
		->and($reads[3]->error)->toBe(ReadError::Config);
});

it('reports every failure as an error with no output, never as a value', function (ProcessResult $result, ReadError $expected) {
	$read = SshNodeSource::classify($result);

	expect($read->succeeded())->toBeFalse()
		->and($read->error)->toBe($expected)
		->and($read->output)->toBe('');
})->with([
	'timeout'        => [new ProcessResult(null, '', '', true, false, true, 30.0), ReadError::Timeout],
	'too large'      => [new ProcessResult(null, '', '', false, true, true, 1.0), ReadError::TooLarge],
	'host key'       => [new ProcessResult(255, '', 'Host key verification failed.', false, false, true, 0.2), ReadError::HostKey],
	'changed key'    => [new ProcessResult(255, '', '@ WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED! @', false, false, true, 0.2), ReadError::HostKey],
	'auth'           => [new ProcessResult(255, '', 'pfsread@10.20.0.11: Permission denied (publickey).', false, false, true, 0.2), ReadError::Auth],
	'unreachable'    => [new ProcessResult(255, '', 'ssh: connect to host 10.20.0.11 port 22: No route to host', false, false, true, 0.2), ReadError::Unreachable],
	'not started'    => [new ProcessResult(127, '', '', false, false, true, 0.0), ReadError::Config],
	'script failed'  => [new ProcessResult(64, '#pfs-read 1', '', false, false, true, 0.2), ReadError::ExitStatus],
]);

it('reads a node end to end through a real process standing in for ssh', function () {
	$fixture = dirname(__DIR__) . '/fixtures/lustre/oss-2.15.txt';
	$ssh     = $this->scratch->file('fake-ssh', "#!/bin/sh\nexec cat " . escapeshellarg($fixture) . "\n", 0700);
	$config  = Settings::fromRow(['pfs_ssh_binary' => $ssh, 'pfs_ssh_key_file' => $this->settings->sshKeyFile, 'pfs_ssh_known_hosts' => $this->settings->sshKnownHosts]);
	$reads   = (new SshNodeSource(new ProcRunner(), $config, $this->resolver, $this->policy))->readAll([pfs_node()], 2, microtime(true) + 60);

	expect($reads[3]->succeeded())->toBeTrue()
		->and($reads[3]->bytes)->toBe(filesize($fixture))
		->and((new LustreBackend())->parse($reads[3]->output)->targets)->toHaveCount(2);
});
