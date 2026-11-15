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
 * Validates the filesystem form. Values arrive as the raw request strings
 * the page script read through Cacti's request helpers.
 */
final readonly class FilesystemInput {
	/**
	 * @param array<string, string> $errors field => message
	 */
	private function __construct(public ?Filesystem $filesystem, public array $errors) {
	}

	/**
	 * @param array<string, string> $input
	 * @param array<int, string>    $pollers poller id => name
	 */
	public static function parse(array $input, int $id, BackendRegistry $backends, array $pollers): self {
		$errors  = [];
		$name    = trim($input['name'] ?? '');
		$backend = trim($input['backend'] ?? '');
		$poller  = Row::int($input, 'poller_id', 0, 1);
		$every   = Row::int($input, 'expected_interval', 0, 1);

		if (!$backends->has($backend)) {
			$errors['backend'] = __('Backend: choose one of the listed backends.', 'pfs');
		} elseif (preg_match($backends->get($backend)->filesystemPattern(), $name) !== 1) {
			$errors['name'] = __('Name: use the filesystem name the nodes print, such as the Lustre fsname of at most 8 letters, digits, dashes, or underscores.', 'pfs');
		}

		if (!isset($pollers[$poller])) {
			$errors['poller_id'] = __('Poller: choose one of the listed pollers.', 'pfs');
		}

		if ($every < Filesystem::MIN_INTERVAL || $every > Filesystem::MAX_INTERVAL) {
			$errors['expected_interval'] = __('Expected Interval: enter whole seconds from %d to %d.', Filesystem::MIN_INTERVAL, Filesystem::MAX_INTERVAL, 'pfs');
		}

		if ($errors !== []) {
			return new self(null, $errors);
		}

		return new self(new Filesystem($id, $name, $backend, $poller, $every, Row::bool($input, 'enabled') ? AdminState::Enabled : AdminState::Disabled), []);
	}
}
