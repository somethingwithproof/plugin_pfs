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
 * Validates the node form (REQ-SRC-001, REQ-SRC-005). The endpoint must be
 * a host name or address, and every address it resolves to must be inside a
 * network allowed for the chosen poller. The reader checks again before each
 * read, because DNS can change.
 *
 * The name is resolved on the server handling the form. For a node read by
 * a remote collector that resolves names differently, register the node by
 * address.
 */
final readonly class NodeInput {
	/**
	 * @param array<string, string> $errors field => message
	 */
	private function __construct(public ?Node $node, public array $errors) {
	}

	/**
	 * @param array<string, string>  $input
	 * @param array<int, Filesystem> $filesystems id => filesystem
	 * @param array<int, string>     $pollers     poller id => name
	 */
	public static function parse(array $input, int $id, array $filesystems, array $pollers, Resolver $resolver, NetworkPolicy $policy): self {
		$errors   = [];
		$fsId     = Row::int($input, 'filesystem_id', 0, 1);
		$name     = trim($input['name'] ?? '');
		$role     = NodeRole::tryFrom(trim($input['role'] ?? ''));
		$poller   = Row::int($input, 'poller_id', 0, 1);
		$endpoint = strtolower(trim($input['endpoint'] ?? ''));
		$port     = Row::int($input, 'port', 0, 1);
		$user     = trim($input['ssh_user'] ?? '');

		if (!isset($filesystems[$fsId])) {
			$errors['filesystem_id'] = __('Filesystem: choose one of the registered filesystems.', 'pfs');
		}

		if (preg_match(Node::NAME_PATTERN, $name) !== 1) {
			$errors['name'] = __('Name: use 1 to 64 letters, digits, dots, dashes, or underscores, starting with a letter or digit.', 'pfs');
		}

		if ($role === null) {
			$errors['role'] = __('Role: choose one of the listed roles.', 'pfs');
		}

		if (!isset($pollers[$poller])) {
			$errors['poller_id'] = __('Poller: choose one of the listed pollers.', 'pfs');
		}

		if ($port < 1 || $port > 65535) {
			$errors['port'] = __('Port: enter a port from 1 to 65535.', 'pfs');
		}

		if (preg_match(Node::USER_PATTERN, $user) !== 1) {
			$errors['ssh_user'] = __('Login Name: use a lower-case account name of at most 32 characters, starting with a letter or underscore.', 'pfs');
		}

		if (!Node::validEndpoint($endpoint)) {
			$errors['endpoint'] = __('Endpoint: enter a host name or an IP address only, without a user, port, or path.', 'pfs');
		} elseif (!isset($errors['poller_id'])) {
			$addresses = $resolver->resolve($endpoint);

			if ($addresses === []) {
				$errors['endpoint'] = __('Endpoint: the name does not resolve on this server.', 'pfs');
			} elseif (!$policy->allowsAll($poller, $addresses)) {
				$errors['endpoint'] = __('Endpoint: it resolves to %s, which is not inside the networks allowed for this poller. Add the network first.', implode(', ', $addresses), 'pfs');
			}
		}

		if ($errors !== [] || $role === null) {
			return new self(null, $errors);
		}

		return new self(new Node($id, $fsId, $name, $role, $poller, $endpoint, $port, $user, AccessMethod::Ssh, Row::bool($input, 'enabled') ? AdminState::Enabled : AdminState::Disabled), []);
	}
}
