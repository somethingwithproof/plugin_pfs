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
 * Warnings on the health page (REQ-HEALTH-003): signs that node access
 * allows more than the plugin needs, or that the plugin cannot see.
 *
 * As far as the plugin can tell means: from the last read of each node (an
 * answer without the fixed script's framing, sections the shipped script
 * never prints, target names that do not parse, a refused or unknown host
 * key, an endpoint outside the allowed networks) and from the files on the
 * server rendering the page. A reader on a remote collector checks its own
 * copies of the files, so those warnings describe this server only.
 */
final class HealthWarnings {
	/**
	 * @param \Closure(string): ?int $mode file permission bits, or null when the file is missing
	 */
	public function __construct(private readonly \Closure $mode, private readonly Clock $clock) {
	}

	public static function production(Clock $clock): self {
		return new self(static function (string $path): ?int {
			clearstatcache(true, $path);

			$mode = is_file($path) ? @fileperms($path) : false;

			return $mode === false ? null : $mode & 0777;
		}, $clock);
	}

	/**
	 * @param list<Node>              $nodes
	 * @param array<int, NodeHealth>  $health  node id => last read
	 * @param array<int, ReaderRun>   $runs    poller id => last run
	 * @param array<int, Filesystem>  $filesystems id => filesystem
	 *
	 * @return list<string>
	 */
	public function for(Settings $settings, array $nodes, array $health, array $runs, array $filesystems): array {
		$warnings = [];
		$enabled  = array_values(array_filter($nodes, static fn (Node $n): bool => $n->enabled() && ($filesystems[$n->filesystemId] ?? null)?->enabled() === true));

		if ($enabled !== []) {
			$warnings = array_merge($warnings, $this->files($settings));
		}

		foreach ($enabled as $node) {
			$last = $health[$node->id] ?? null;

			if ($last === null) {
				continue;
			}

			$warning = match ($last->error) {
				ReadError::Unframed => __('%s: the node answered without the fixed read script framing. Its key may not be limited to a forced command running pfs-read.sh.', $node->name, 'pfs'),
				ReadError::HostKey  => __('%s: the node host key is not in the known hosts file, or it changed. Check the key before adding it.', $node->name, 'pfs'),
				ReadError::Network  => __('%s: the endpoint resolves outside the networks allowed for poller %d, so it is not read.', $node->name, $node->pollerId, 'pfs'),
				default             => null,
			};

			if ($warning !== null) {
				$warnings[] = $warning;
			}

			if ($last->unexpectedSections > 0) {
				$warnings[] = __('%s: the read script printed %d parameters the plugin does not read, so it is not the shipped pfs-read.sh. The node may be exposing more than the plugin needs.', $node->name, $last->unexpectedSections, 'pfs');
			}

			if ($last->rejectedNames > 0) {
				$warnings[] = __('%s: %d target names in the node output did not match the expected pattern or filesystem and were ignored.', $node->name, $last->rejectedNames, 'pfs');
			}
		}

		$pollers = [];

		foreach ($enabled as $node) {
			$interval = $filesystems[$node->filesystemId]->interval ?? Filesystem::DEFAULT_INTERVAL;
			$pollers[$node->pollerId] = min($pollers[$node->pollerId] ?? PHP_INT_MAX, $interval);
		}

		ksort($pollers);

		foreach ($pollers as $poller => $interval) {
			$completed = $runs[$poller]->lastCompleted ?? 0;

			if ($completed === 0 || $this->clock->now() - $completed > 3 * $interval) {
				$warnings[] = __('The reader on poller %d has not completed a run in the last %d seconds. Values from its nodes are getting stale.', $poller, 3 * $interval, 'pfs');
			}
		}

		return $warnings;
	}

	/**
	 * Warnings about the Cacti objects the plugin depends on: the imported
	 * templates, the data query files under Cacti's resource tree, and the
	 * device each filesystem maps to.
	 *
	 * @param list<string>                                     $queryFiles  query files missing or changed
	 * @param array<int, Filesystem>                           $filesystems id => filesystem
	 * @param array<int, array{id: int, poller_id: int}|null>  $devices     filesystem id => its device, null when it no longer exists
	 *
	 * @return list<string>
	 */
	public function objects(string $templateFailure, bool $templatesPresent, array $queryFiles, array $filesystems, array $devices): array {
		$warnings = [];

		if ($templateFailure !== '') {
			$warnings[] = __('Installing the Parallel Filesystem templates failed: %s', $templateFailure, 'pfs');
		} elseif (!$templatesPresent) {
			$warnings[] = __('The Parallel Filesystem templates are not installed, so no device can be added.', 'pfs');
		}

		foreach ($queryFiles as $file) {
			$warnings[] = __('The data query file resource/script_server/%s is missing from Cacti or differs from the one this plugin ships, so the data queries cannot list targets.', $file, 'pfs');
		}

		foreach ($filesystems as $id => $filesystem) {
			if ($filesystem->hostId === 0) {
				continue;
			}

			$device = $devices[$id] ?? null;

			if ($device === null) {
				$warnings[] = __('Filesystem %s is mapped to device %d, which no longer exists.', $filesystem->name, $filesystem->hostId, 'pfs');
			} elseif ($device['poller_id'] !== $filesystem->pollerId) {
				$warnings[] = __('The device of filesystem %s is polled by poller %d, but its nodes are read by poller %d, whose cache the device\'s data sources need.', $filesystem->name, $device['poller_id'], $filesystem->pollerId, 'pfs');
			}
		}

		return $warnings;
	}

	/**
	 * @return list<string>
	 */
	private function files(Settings $settings): array {
		$warnings = [];
		$key      = ($this->mode)($settings->sshKeyFile);

		if ($key === null) {
			$warnings[] = __('The SSH key file %s does not exist on this server.', $settings->sshKeyFile, 'pfs');
		} elseif (($key & 0077) !== 0) {
			$warnings[] = __('The SSH key file %s can be read or written by users other than its owner (mode %s). Limit it to the poller user.', $settings->sshKeyFile, sprintf('%04o', $key), 'pfs');
		}

		if (($this->mode)($settings->sshKnownHosts) === null) {
			$warnings[] = __('The known hosts file %s does not exist on this server, so no node can be read from it.', $settings->sshKnownHosts, 'pfs');
		}

		if (($this->mode)($settings->sshBinary) === null) {
			$warnings[] = __('The SSH client %s does not exist on this server.', $settings->sshBinary, 'pfs');
		}

		return $warnings;
	}
}
