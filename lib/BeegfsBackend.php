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
		$parsed = FramedReportParser::parse($output, self::PROTOCOL, self::END, self::MAX_SECTIONS);

		if (!$parsed['framed']) {
			return NodeReport::unframed();
		}

		if (!$parsed['complete']) {
			return new NodeReport(true, false, true, null, [], 0, 0);
		}

		$partial    = $parsed['partial'];
		$version    = null;
		$rejected   = 0;
		$unexpected = 0;
		/** @var array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string}> $targets */
		$targets  = [];

		foreach ($parsed['sections'] as $entry) {
			$section = $entry['name'];
			$body    = $entry['lines'];

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

		$reports = FramedReportParser::buildTargetReports($targets, $this->values());

		return new NodeReport(true, true, $partial, $version, $reports, $rejected, $unexpected);
	}

	/**
	 * Parse target lines:
	 *   target fs=<name> id=<int> reachability=online|probably-offline|offline consistency=GOOD|NEEDS_RESYNC|BAD
	 *     disk_space_total=<bytes> disk_space_free=<bytes> inodes_total=<n> inodes_free=<n>
	 *     [work_requests=<n> queued_requests=<n> disk_read_bytes=<n> disk_write_bytes=<n>]
	 *
	 * @param list<string>                                                                                    $lines
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string}> $targets
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
				$targets[$name] = ['id' => $targetId, 'values' => [], 'condition' => Condition::Unknown, 'start' => null];
				$partial        = true;

				continue;
			}

			$seenTarget[$name] = true;

			$condition = self::mapCondition($fields['reachability'] ?? '', $fields['consistency'] ?? '');

			// Convert raw byte counts directly to integer KiB via exact decimal
			// division so 500 PB and 2 EB targets keep full integer precision.
			$values = [
				'kbytes_total'    => FramedReportParser::bytesToKib($fields['disk_space_total'] ?? null),
				'kbytes_free'     => FramedReportParser::bytesToKib($fields['disk_space_free'] ?? null),
				'kbytes_avail'    => FramedReportParser::bytesToKib($fields['disk_space_avail'] ?? ($fields['disk_space_free'] ?? null)),
				'files_total'     => FramedReportParser::uint($fields['inodes_total'] ?? null),
				'files_free'      => FramedReportParser::uint($fields['inodes_free'] ?? null),
				'work_requests'   => FramedReportParser::uint($fields['work_requests'] ?? null),
				'queued_requests' => FramedReportParser::uint($fields['queued_requests'] ?? null),
			];

			if ($type === TargetType::Ost) {
				$values['read_bytes_int']  = FramedReportParser::uint($fields['disk_read_bytes'] ?? null);
				$values['write_bytes_int'] = FramedReportParser::uint($fields['disk_write_bytes'] ?? null);
			}

			$targets[$name] = [
				'id'        => $targetId,
				'values'    => $values,
				'condition' => $condition,
				'start'     => null,
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
		return FramedReportParser::parseKeyValues($line);
	}

	/**
	 * @param list<string> $lines
	 */
	private static function parseVersion(array $lines): ?string {
		$text = trim(implode("\n", $lines));

		return preg_match('/^(?:beegfs:\s*)?([0-9][0-9A-Za-z._-]{0,31})$/', $text, $m) === 1 ? $m[1] : null;
	}
}
