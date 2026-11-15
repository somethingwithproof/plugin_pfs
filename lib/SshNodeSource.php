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
 * Reads a node by SSH, where the node's authorized_keys entry runs the
 * fixed read script as a forced command (decision #7, provisional; ADR 0008).
 *
 * Trust boundary rules:
 *  - The argument vector is fixed apart from the poller's settings and the
 *    node's validated port, login name, endpoint, and resolved address; no
 *    shell sees it, and no remote command is sent, so the node's forced
 *    command decides what runs (REQ-SRC-002).
 *  - The endpoint is resolved here and every address must be inside the
 *    networks allowed for the node's poller; ssh then connects to that
 *    address, so a later DNS answer cannot redirect it (REQ-SRC-005). The
 *    host key is checked against the plugin's own known_hosts file under the
 *    endpoint name, and an unknown or changed key fails the read.
 *  - No user configuration, agent, forwarding, proxy, or password prompt is
 *    used. The key is passed as a file path, never as its content
 *    (REQ-SRC-004).
 *  - Connect timeout, total timeout, and response size are bounded; any
 *    violation is a failed read, never a partial one (REQ-SRC-003).
 */
final class SshNodeSource implements NodeSource {
	public function __construct(
		private readonly ProcessRunner $runner,
		private readonly Settings $settings,
		private readonly Resolver $resolver,
		private readonly NetworkPolicy $policy,
	) {
	}

	public function readAll(array $nodes, int $concurrency, float $deadline): array {
		$results  = [];
		$commands = [];
		$config   = $this->configUsable();

		foreach ($nodes as $node) {
			if (!$config) {
				$results[$node->id] = NodeRead::failed(ReadError::Config);

				continue;
			}

			$addresses = $this->resolver->resolve($node->endpoint);

			if ($addresses === []) {
				$results[$node->id] = NodeRead::failed(ReadError::Resolve);
			} elseif (!$this->policy->allowsAll($node->pollerId, $addresses)) {
				$results[$node->id] = NodeRead::failed(ReadError::Network);
			} else {
				$commands[$node->id] = $this->argv($node, $addresses[0]);
			}
		}

		$runs = $commands === [] ? [] : $this->runner->runAll($commands, $concurrency, (float) $this->settings->readTimeout, $this->settings->maxResponseBytes(), $deadline);

		foreach ($runs as $id => $run) {
			if ($run->started) {
				$results[$id] = self::classify($run);
			}
		}

		ksort($results);

		return $results;
	}

	/**
	 * @return list<string>
	 */
	public function argv(Node $node, string $address): array {
		$s = $this->settings;

		return [
			$s->sshBinary,
			// Before every -o: neither ~/.ssh/config of the poller user nor
			// the system ssh_config can add options. Per-site options are
			// plugin settings.
			'-F', '/dev/null',
			'-T',
			'-o', 'BatchMode=yes',
			'-o', 'StrictHostKeyChecking=yes',
			'-o', 'UserKnownHostsFile=' . $s->sshKnownHosts,
			'-o', 'GlobalKnownHostsFile=/dev/null',
			'-o', 'HostKeyAlias=' . $node->endpoint,
			'-o', 'CheckHostIP=no',
			'-o', 'UpdateHostKeys=no',
			'-o', 'IdentitiesOnly=yes',
			'-o', 'IdentityAgent=none',
			'-o', 'PasswordAuthentication=no',
			'-o', 'KbdInteractiveAuthentication=no',
			'-o', 'ForwardAgent=no',
			'-o', 'ForwardX11=no',
			'-o', 'ClearAllForwardings=yes',
			'-o', 'PermitLocalCommand=no',
			'-o', 'ProxyCommand=none',
			'-o', 'ConnectTimeout=' . $s->connectTimeout,
			'-o', 'ServerAliveInterval=5',
			'-o', 'ServerAliveCountMax=2',
			'-o', 'LogLevel=ERROR',
			'-i', $s->sshKeyFile,
			'-p', (string) $node->port,
			'-l', $node->user,
			'--',
			$address,
		];
	}

	/**
	 * The binary, key, and known hosts file must exist on this poller; the
	 * health page explains which one is missing.
	 */
	private function configUsable(): bool {
		$s = $this->settings;

		return is_file($s->sshBinary) && is_executable($s->sshBinary) && is_file($s->sshKeyFile) && is_readable($s->sshKeyFile) && is_file($s->sshKnownHosts) && is_readable($s->sshKnownHosts);
	}

	/**
	 * Failure classes from ssh's exit status and its own messages, which are
	 * matched and then dropped.
	 */
	public static function classify(ProcessResult $run): NodeRead {
		if ($run->timedOut) {
			return NodeRead::failed(ReadError::Timeout, strlen($run->stdout), $run->duration);
		}

		if ($run->tooLarge) {
			return NodeRead::failed(ReadError::TooLarge, 0, $run->duration);
		}

		return match (true) {
			$run->exitCode === 0   => NodeRead::ok($run->stdout, $run->duration),
			$run->exitCode === 127 => NodeRead::failed(ReadError::Config, 0, $run->duration),
			$run->exitCode === 255 => NodeRead::failed(match (true) {
				preg_match('/host key|HOST IDENTIFICATION/i', $run->stderr) === 1 => ReadError::HostKey,
				preg_match('/Permission denied/i', $run->stderr) === 1             => ReadError::Auth,
				default                                                           => ReadError::Unreachable,
			}, 0, $run->duration),
			default => NodeRead::failed(ReadError::ExitStatus, 0, $run->duration),
		};
	}
}
