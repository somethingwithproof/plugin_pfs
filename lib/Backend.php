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
 * One filesystem product (ADR 0003). A backend declares the values it
 * produces and turns a node's output into a NodeReport. It has no operation
 * that writes to a node or filesystem, and parse() contacts nothing
 * (REQ-BACKEND-001).
 *
 * parse() never throws and never turns text it cannot read into zero: an
 * unparsable value is null (REQ-BACKEND-003).
 */
interface Backend {
	/**
	 * Stored on the filesystem row; lower-case letters only.
	 */
	public function id(): string;

	/**
	 * Return the human-readable display label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * @return list<ValueSpec>
	 */
	public function values(): array;

	/**
	 * Pattern a filesystem name must match to be registered.
	 */
	public function filesystemPattern(): string;

	/**
	 * Parse a framed node report into structured targets and observations.
	 *
	 * @param string $output
	 * @return NodeReport
	 */
	public function parse(string $output): NodeReport;
}
