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

namespace Somethingwithproof\Pfs\Tests;

use Somethingwithproof\Pfs\Backend;
use Somethingwithproof\Pfs\Condition;
use Somethingwithproof\Pfs\NodeReport;
use Somethingwithproof\Pfs\NodeRole;
use Somethingwithproof\Pfs\TargetId;
use Somethingwithproof\Pfs\TargetReport;
use Somethingwithproof\Pfs\TargetType;
use Somethingwithproof\Pfs\ValueKind;
use Somethingwithproof\Pfs\ValueSpec;

/**
 * A second backend that exists only in tests, so the backend contract is
 * shown to hold for more than Lustre (REQ-BACKEND-001). Its output is
 * "#demo 1", then "<target> <free>" lines, then "#demo end".
 */
final class DemoBackend implements Backend {
	public function id(): string {
		return 'demo';
	}

	public function label(): string {
		return 'Demo';
	}

	public function values(): array {
		return [new ValueSpec('free', ValueKind::Gauge, 'KiB', TargetType::Ost, [NodeRole::Oss])];
	}

	public function filesystemPattern(): string {
		return '/^[a-z]{1,8}$/';
	}

	public function parse(string $output): NodeReport {
		$lines = explode("\n", $output);

		if ($lines[0] !== '#demo 1') {
			return NodeReport::unframed();
		}

		if (!in_array('#demo end', $lines, true)) {
			return new NodeReport(true, false, true, null, [], 0, 0);
		}

		$targets  = [];
		$rejected = 0;

		foreach (array_slice($lines, 1) as $line) {
			if ($line === '#demo end') {
				break;
			}

			if (preg_match('/^(\S+) (\d{1,20})$/', $line, $m) !== 1) {
				continue;
			}

			$id = TargetId::parse($m[1]);

			if ($id === null) {
				$rejected++;

				continue;
			}

			$targets[$id->name()] = new TargetReport($id, ['free' => (float) $m[2]], null, Condition::Unknown, null);
		}

		ksort($targets);

		return new NodeReport(true, true, false, null, $targets, $rejected, 0);
	}
}
