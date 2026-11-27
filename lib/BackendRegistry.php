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
 * The backends this plugin knows, by identifier (REQ-BACKEND-004). Shared
 * code asks the registry for a filesystem's backend and uses only the
 * Backend interface; nothing outside a backend class names a product.
 */
final class BackendRegistry {
	/** @var array<string, Backend> */
	private array $backends = [];

	/**
	 * Production.
	 *
	 * @return self
	 */
	public static function production(): self {
		$registry = new self();
		$registry->register(new LustreBackend());
		$registry->register(new GpfsBackend());
		$registry->register(new BeegfsBackend());

		return $registry;
	}

	/**
	 * Register.
	 *
	 * @param Backend $backend
	 * @throws \InvalidArgumentException
	 */
	public function register(Backend $backend): void {
		if (preg_match('/^[a-z]{1,16}$/', $backend->id()) !== 1 || isset($this->backends[$backend->id()])) {
			throw new \InvalidArgumentException('Backend identifiers are unique lower-case words');
		}

		$this->backends[$backend->id()] = $backend;
	}

	/**
	 * @throws UnknownBackend for an identifier no backend registered
	 */
	public function get(string $id): Backend {
		return $this->backends[$id] ?? throw new UnknownBackend(sprintf('Unknown backend %s', preg_replace('/[^a-z0-9_]/', '?', substr($id, 0, 32))));
	}

	/**
	 * Has.
	 *
	 * @param string $id
	 * @return bool
	 */
	public function has(string $id): bool {
		return isset($this->backends[$id]);
	}

	/**
	 * @return array<string, Backend>
	 */
	public function all(): array {
		return $this->backends;
	}

	/**
	 * Values a target of this type adds when accepted, which the review page
	 * shows before an administrator accepts it (REQ-INV-003).
	 */
	public function valueCount(string $backend, TargetType $type): int {
		return count(array_filter($this->get($backend)->values(), static fn (ValueSpec $spec): bool => $spec->targetType === $type));
	}
}
