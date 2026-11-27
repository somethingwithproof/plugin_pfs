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
 * BeeGFS read-only backend (REQ-BGFS-001 to REQ-BGFS-004).
 *
 * Parses framed read-only output (#pfs-beegfs 1 ... #pfs-beegfs end) from
 * BeeGFS 7.4 or 8.x storage and metadata target exports (beegfs-mon or
 * read-only beegfs CLI wrapper).
 *
 * Per docs/research/beegfs.md (REQ-BGFS-003), BeeGFS server/target I/O and
 * request statistics are per-interval gauges rather than cumulative counters,
 * so they are declared as ValueKind::Gauge rather than DERIVE counters.
 * Capacity values (bytes) are normalized to KiB into the unified plugin_pfs
 * target model (TargetType::Ost for storage targets, TargetType::Mdt for
 * metadata targets).
 */
final class BeegfsBackend implements Backend {
	public const PROTOCOL = '#pfs-beegfs 1';
	public const END      = '#pfs-beegfs end';

	private const MAX_SECTIONS = 20000;

	public function id(): string {
		return 'beegfs';
	}

	public function label(): string {
		return 'BeeGFS';
	}

	public function filesystemPattern(): string {
		return '/^[A-Za-z0-9_-]{1,8}$/';
	}

	public function values(): array {
		return [
			new ValueSpec('kbytes_total', ValueKind::Gauge, 'KiB', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('kbytes_free', ValueKind::Gauge, 'KiB', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('kbytes_avail', ValueKind::Gauge, 'KiB', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('files_total', ValueKind::Gauge, 'files', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('files_free', ValueKind::Gauge, 'files', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('work_requests', ValueKind::Gauge, 'requests', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('queued_requests', ValueKind::Gauge, 'requests', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('read_bytes_int', ValueKind::Gauge, 'bytes', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('write_bytes_int', ValueKind::Gauge, 'bytes', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('kbytes_total', ValueKind::Gauge, 'KiB', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('kbytes_free', ValueKind::Gauge, 'KiB', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('kbytes_avail', ValueKind::Gauge, 'KiB', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('files_total', ValueKind::Gauge, 'files', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('files_free', ValueKind::Gauge, 'files', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('work_requests', ValueKind::Gauge, 'requests', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('queued_requests', ValueKind::Gauge, 'requests', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
		];
	}

	public function parse(string $output): NodeReport {
		$lines = explode("\n", str_replace("\r\n", "\n", $output));

		if ($lines[0] !== self::PROTOCOL) {
			return NodeReport::unframed();
		}

		$sections   = [];
		$bodies     = [];
		$failed     = [];
		$current    = null;
		$complete   = false;
		$partial    = false;
		$trailing   = false;
		$unexpected = 0;

		foreach (array_slice($lines, 1) as $line) {
			if ($complete) {
				$trailing = $trailing || $line !== '';

				continue;
			}

			if ($line === self::END) {
				$complete = true;
				$current  = null;
			} elseif ($line === '!! total-limit') {
				$partial = true;
				$current = null;
			} elseif (str_starts_with($line, '== ')) {
				if (count($sections) >= self::MAX_SECTIONS) {
					$partial = true;
					$current = null;

					continue;
				}

				$current            = count($sections);
				$sections[$current] = substr($line, 3);
				$bodies[$current]   = [];
				$failed[$current]   = false;
			} elseif ($current !== null && ($line === '!! read-failed' || $line === '!! truncated')) {
				$failed[$current] = true;
				$partial          = true;
			} elseif ($current !== null) {
				$bodies[$current][] = $line;
			}
		}

		if (!$complete || $trailing) {
			return new NodeReport(true, false, true, null, [], 0, 0);
		}

		$seen     = array_count_values($sections);
		$version  = null;
		$rejected = 0;
		/** @var array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition}> $targets */
		$targets  = [];

		foreach ($sections as $i => $section) {
			if ($failed[$i] || $seen[$section] > 1) {
				$partial = true;

				continue;
			}

			$body = $bodies[$i];

			if ($section === 'version') {
				$version = self::parseVersion($body);
			} elseif ($section === 'storage_targets') {
				$this->parseTargets($body, TargetType::Ost, $targets, $rejected, $partial);
			} elseif ($section === 'meta_targets') {
				$this->parseTargets($body, TargetType::Mdt, $targets, $rejected, $partial);
			} else {
				$unexpected++;
			}
		}

		$reports = [];

		foreach ($targets as $name => $entry) {
			$values = [];

			foreach ($this->values() as $spec) {
				if ($spec->targetType === $entry['id']->type) {
					$values[$spec->name] = $entry['values'][$spec->name] ?? null;
				}
			}

			$reports[$name] = new TargetReport($entry['id'], $values, null, $entry['condition'], null, []);
		}

		ksort($reports);

		return new NodeReport(true, true, $partial, $version, $reports, $rejected, $unexpected);
	}

	/**
	 * Parse target lines:
	 *   target fs=<name> id=<int> reachability=online|probably-offline|offline consistency=GOOD|NEEDS_RESYNC|BAD
	 *     disk_space_total=<bytes> disk_space_free=<bytes> inodes_total=<n> inodes_free=<n>
	 *     [work_requests=<n> queued_requests=<n> disk_read_bytes=<n> disk_write_bytes=<n>]
	 *
	 * @param list<string>                                                                   $lines
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition}> $targets
	 */
	private function parseTargets(array $lines, TargetType $type, array &$targets, int &$rejected, bool &$partial): void {
		$seenTarget = [];

		foreach ($lines as $rawLine) {
			$line = trim($rawLine);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$fields = self::parseKeyValues($line);
			$fs     = $fields['fs'] ?? '';
			$idRaw  = $fields['id'] ?? ($fields['target_id'] ?? '');

			if (preg_match($this->filesystemPattern(), $fs) !== 1 || preg_match('/^[0-9]{1,5}$/', $idRaw) !== 1) {
				$rejected++;

				continue;
			}

			$targetIndex = (int) $idRaw;

			if ($targetIndex > 65535) {
				$rejected++;

				continue;
			}

			$targetId = new TargetId($fs, $type, $targetIndex);
			$name     = $targetId->name();

			if (isset($seenTarget[$name])) {
				// Duplicate target in one section is ambiguous; drop its values and flag partial.
				$targets[$name] = ['id' => $targetId, 'values' => [], 'condition' => Condition::Unknown];
				$partial        = true;

				continue;
			}

			$seenTarget[$name] = true;

			$condition = self::mapCondition($fields['reachability'] ?? '', $fields['consistency'] ?? '');

			$totalBytes = self::uint($fields['disk_space_total'] ?? null);
			$freeBytes  = self::uint($fields['disk_space_free'] ?? null);
			$availBytes = self::uint($fields['disk_space_avail'] ?? ($fields['disk_space_free'] ?? null));

			$values = [
				'kbytes_total'    => $totalBytes === null ? null : floor($totalBytes / 1024.0),
				'kbytes_free'     => $freeBytes === null ? null : floor($freeBytes / 1024.0),
				'kbytes_avail'    => $availBytes === null ? null : floor($availBytes / 1024.0),
				'files_total'     => self::uint($fields['inodes_total'] ?? null),
				'files_free'      => self::uint($fields['inodes_free'] ?? null),
				'work_requests'   => self::uint($fields['work_requests'] ?? null),
				'queued_requests' => self::uint($fields['queued_requests'] ?? null),
			];

			if ($type === TargetType::Ost) {
				$values['read_bytes_int']  = self::uint($fields['disk_read_bytes'] ?? null);
				$values['write_bytes_int'] = self::uint($fields['disk_write_bytes'] ?? null);
			}

			$targets[$name] = [
				'id'        => $targetId,
				'values'    => $values,
				'condition' => $condition,
			];
		}
	}

	private static function mapCondition(string $reachability, string $consistency): Condition {
		$r = strtolower(trim($reachability));
		$c = strtoupper(trim($consistency));

		if ($r === '' && $c === '') {
			return Condition::Unknown;
		}

		if ($r === 'offline' || $c === 'BAD') {
			return Condition::NoCreate;
		}

		if ($r === 'probably-offline' || $c === 'NEEDS_RESYNC') {
			return Condition::Degraded;
		}

		if ($r === 'online' && ($c === 'GOOD' || $c === '')) {
			return Condition::InService;
		}

		if ($c === 'GOOD' && $r === '') {
			return Condition::InService;
		}

		return Condition::Unknown;
	}

	/**
	 * @return array<string, string>
	 */
	private static function parseKeyValues(string $line): array {
		$out = [];

		foreach (preg_split('/\s+/', $line) ?: [] as $token) {
			if (str_contains($token, '=')) {
				[$k, $v] = explode('=', $token, 2);

				if (preg_match('/^[a-z0-9_]{1,32}$/', $k) === 1) {
					$out[$k] = $v;
				}
			}
		}

		return $out;
	}

	/**
	 * @param list<string> $lines
	 */
	private static function parseVersion(array $lines): ?string {
		$text = trim(implode("\n", $lines));

		return preg_match('/^(?:beegfs:\s*)?([0-9][0-9A-Za-z._-]{0,31})$/', $text, $m) === 1 ? $m[1] : null;
	}

	private static function uint(?string $raw): ?float {
		if ($raw === null) {
			return null;
		}

		return preg_match('/^[0-9]{1,20}$/', trim($raw)) === 1 ? (float) trim($raw) : null;
	}
}
