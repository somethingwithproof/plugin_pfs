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
 * Node sources by access method. The reader groups nodes by method and asks
 * each source for its nodes; a method with no source fails its nodes with a
 * configuration error.
 */
final class NodeSources {
	/** @var array<string, NodeSource> */
	private array $sources = [];

	/**
	 * Register.
	 *
	 * @param AccessMethod $method
	 * @param NodeSource $source
	 */
	public function register(AccessMethod $method, NodeSource $source): void {
		$this->sources[$method->value] = $source;
	}

	/**
	 * For.
	 *
	 * @param AccessMethod $method
	 * @return ?NodeSource
	 */
	public function for(AccessMethod $method): ?NodeSource {
		return $this->sources[$method->value] ?? null;
	}
}
