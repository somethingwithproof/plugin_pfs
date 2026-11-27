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
 * IBM Storage Scale (GPFS) read-only backend (REQ-GPFS-001 to REQ-GPFS-005).
 *
 * Parses framed output from a restricted node-side wrapper (#pfs-gpfs 1 ...
 * #pfs-gpfs end) that runs read-only mmpmon (-p -s with fs_io_s/io_s only,
 * never reset), mmdf capacity output, and waiter summaries.
 *
 * Maps NSD/data pools to TargetType::Ost (<fs>-OST<nnnn>) and metadata pools
 * to TargetType::Mdt (<fs>-MDT<nnnn>) so the unified plugin_pfs target,
 * summary, imbalance, and reset-detection model works across filesystems.
 */
final class GpfsBackend implements Backend {
	public const PROTOCOL = '#pfs-gpfs 1';
	public const END      = '#pfs-gpfs end';

	/**
	 * Privilege boundary declaration (REQ-GPFS-004): the Cacti host never runs
	 * as root; a fixed node-side wrapper executes only read-only mmpmon/mmdf.
	 */
	public const PRIVILEGE_MODEL = [
		'cacti_host_root_required' => false,
		'node_wrapper_only'        => true,
		'allowed_mmpmon_requests'  => ['fs_io_s', 'io_s'],
		'mutating_requests_banned' => ['reset', 'vio_s_reset'],
	];

	private const MAX_SECTIONS = 20000;

	public function id(): string {
		return 'gpfs';
	}

	public function label(): string {
		return 'IBM Storage Scale (GPFS)';
	}

	public function filesystemPattern(): string {
		return '/^[A-Za-z0-9_-]{1,8}$/';
	}

	public function values(): array {
		return [
			new ValueSpec('kbytes_total', ValueKind::Gauge, 'KiB', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('kbytes_free', ValueKind::Gauge, 'KiB', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('kbytes_avail', ValueKind::Gauge, 'KiB', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('files_total', ValueKind::Gauge, 'files', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('files_free', ValueKind::Gauge, 'files', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('read_bytes', ValueKind::Counter, 'bytes', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('write_bytes', ValueKind::Counter, 'bytes', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('read_ops', ValueKind::Counter, 'operations', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('write_ops', ValueKind::Counter, 'operations', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('waiters_count', ValueKind::Gauge, 'waiters', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('waiters_max_sec', ValueKind::Gauge, 'seconds', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('kbytes_total', ValueKind::Gauge, 'KiB', TargetType::Mdt, [NodeRole::Mds, NodeRole::Client]),
			new ValueSpec('kbytes_free', ValueKind::Gauge, 'KiB', TargetType::Mdt, [NodeRole::Mds, NodeRole::Client]),
			new ValueSpec('kbytes_avail', ValueKind::Gauge, 'KiB', TargetType::Mdt, [NodeRole::Mds, NodeRole::Client]),
			new ValueSpec('files_total', ValueKind::Gauge, 'files', TargetType::Mdt, [NodeRole::Mds, NodeRole::Client]),
			new ValueSpec('files_free', ValueKind::Gauge, 'files', TargetType::Mdt, [NodeRole::Mds, NodeRole::Client]),
			new ValueSpec('md_open', ValueKind::Counter, 'operations', TargetType::Mdt, [NodeRole::Mds, NodeRole::Client]),
			new ValueSpec('md_close', ValueKind::Counter, 'operations', TargetType::Mdt, [NodeRole::Mds, NodeRole::Client]),
			new ValueSpec('md_readdir', ValueKind::Counter, 'operations', TargetType::Mdt, [NodeRole::Mds, NodeRole::Client]),
			new ValueSpec('md_inode_upd', ValueKind::Counter, 'operations', TargetType::Mdt, [NodeRole::Mds, NodeRole::Client]),
		];
	}

	public function parse(string $output): NodeReport {
		return FramedReportParser::parseReport(
			$output,
			self::PROTOCOL,
			self::END,
			self::MAX_SECTIONS,
			$this->values(),
			function (string $section, array $body, array &$targets, int &$rejected, bool &$partial): bool {
				if ($section === 'mmpmon_fs_io_s') {
					$this->parseMmpmon($body, $targets, $rejected, $partial);

					return true;
				}

				if ($section === 'mmdf') {
					$this->parseMmdf($body, $targets, $rejected);

					return true;
				}

				if ($section === 'waiters') {
					$this->parseWaiters($body, $targets, $rejected);

					return true;
				}

				return false;
			},
			static fn (array $lines): ?string => self::parseVersion($lines),
		);
	}

	/**
	 * Parse mmpmon -p -s fs_io_s lines (Table 8, docs/research/mmpmon.md).
	 * Rejects non-zero _rc_ or any reset response (REQ-GPFS-001, REQ-GPFS-002).
	 *
	 * @param list<string>                                                                                    $lines
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string}> $targets
	 */
	private function parseMmpmon(array $lines, array &$targets, int &$rejected, bool &$partial): void {
		foreach ($lines as $rawLine) {
			$line = trim($rawLine);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$tokens = preg_split('/\s+/', $line);

			if (!is_array($tokens) || count($tokens) < 4) {
				$rejected++;

				continue;
			}

			$prefix = $tokens[0];

			if ($prefix === '_reset_' || $prefix === '_vio_s_reset_') {
				$partial = true;
				$rejected++;

				continue;
			}

			if ($prefix !== '_fs_io_s_' && $prefix !== '_io_s_') {
				$rejected++;

				continue;
			}

			$kv = [];

			for ($i = 1; $i + 1 < count($tokens); $i += 2) {
				if (preg_match('/^_[a-z]{1,4}_$/', $tokens[$i]) !== 1) {
					continue;
				}

				$kv[$tokens[$i]] = $tokens[$i + 1];
			}

			if (($kv['_rc_'] ?? '') !== '0') {
				$partial = true;

				continue;
			}

			$fs = $kv['_fs_'] ?? '';

			if (preg_match($this->filesystemPattern(), $fs) !== 1) {
				$rejected++;

				continue;
			}

			$ostId   = new TargetId($fs, TargetType::Ost, 0);
			$mdtId   = new TargetId($fs, TargetType::Mdt, 0);
			$ostName = $ostId->name();
			$mdtName = $mdtId->name();

			$ost = $targets[$ostName] ?? ['id' => $ostId, 'values' => [], 'condition' => Condition::InService, 'start' => null];
			$mdt = $targets[$mdtName] ?? ['id' => $mdtId, 'values' => [], 'condition' => Condition::InService, 'start' => null];

			$ost['values']['read_bytes']  = self::uint($kv['_br_'] ?? null);
			$ost['values']['write_bytes'] = self::uint($kv['_bw_'] ?? null);
			$ost['values']['read_ops']    = self::uint($kv['_rdc_'] ?? null);
			$ost['values']['write_ops']   = self::uint($kv['_wc_'] ?? null);

			$mdt['values']['md_open']      = self::uint($kv['_oc_'] ?? null);
			$mdt['values']['md_close']     = self::uint($kv['_cc_'] ?? null);
			$mdt['values']['md_readdir']   = self::uint($kv['_dir_'] ?? null);
			$mdt['values']['md_inode_upd'] = self::uint($kv['_iu_'] ?? null);

			$targets[$ostName] = $ost;
			$targets[$mdtName] = $mdt;
		}
	}

	/**
	 * Parse mmdf pool/NSD capacity records in key=value or colon-delimited form:
	 *   pool fs=<name> type=ost|mdt index=<int> kbytes_total=<n> kbytes_free=<n> kbytes_avail=<n> files_total=<n> files_free=<n> state=up|degraded|readonly|nocreate
	 *
	 * @param list<string>                                                                                    $lines
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string}> $targets
	 */
	private function parseMmdf(array $lines, array &$targets, int &$rejected): void {
		foreach ($lines as $rawLine) {
			$line = trim($rawLine);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$fields = self::parseKeyValues($line);
			$fs     = $fields['fs'] ?? '';
			$type   = match (strtolower($fields['type'] ?? 'ost')) {
				'mdt', 'meta', 'metadata' => TargetType::Mdt,
				'ost', 'data', 'nsd'      => TargetType::Ost,
				default                   => null,
			};
			$indexRaw = $fields['index'] ?? '';

			if ($type === null || preg_match($this->filesystemPattern(), $fs) !== 1 || preg_match('/^[0-9]{1,5}$/', $indexRaw) !== 1) {
				$rejected++;

				continue;
			}

			$index = (int) $indexRaw;

			if ($index > 65535) {
				$rejected++;

				continue;
			}

			$id   = new TargetId($fs, $type, $index);
			$name = $id->name();

			$condition = match (strtolower($fields['state'] ?? '')) {
				'up', 'ready', 'in_service', 'ok' => Condition::InService,
				'degraded', 'recovering'          => Condition::Degraded,
				'readonly', 'ro'                  => Condition::ReadOnly,
				'nocreate', 'down', 'unavailable' => Condition::NoCreate,
				default                           => Condition::Unknown,
			};

			$targets[$name] ??= ['id' => $id, 'values' => [], 'condition' => Condition::Unknown, 'start' => null];
			$targets[$name]['condition']              = self::worstCondition($targets[$name]['condition'], $condition);
			$targets[$name]['values']['kbytes_total'] = self::uint($fields['kbytes_total'] ?? null);
			$targets[$name]['values']['kbytes_free']  = self::uint($fields['kbytes_free'] ?? null);
			$targets[$name]['values']['kbytes_avail'] = self::uint($fields['kbytes_avail'] ?? ($fields['kbytes_free'] ?? null));
			$targets[$name]['values']['files_total']  = self::uint($fields['files_total'] ?? null);
			$targets[$name]['values']['files_free']   = self::uint($fields['files_free'] ?? null);
		}
	}

	/**
	 * Parse GPFS waiter status lines:
	 *   waiters fs=<name> index=<int> count=<n> max_sec=<n>
	 *
	 * @param list<string>                                                                                    $lines
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string}> $targets
	 */
	private function parseWaiters(array $lines, array &$targets, int &$rejected): void {
		foreach ($lines as $rawLine) {
			$line = trim($rawLine);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$fields   = self::parseKeyValues($line);
			$fs       = $fields['fs'] ?? '';
			$indexRaw = $fields['index'] ?? '0';

			if (preg_match($this->filesystemPattern(), $fs) !== 1 || preg_match('/^[0-9]{1,5}$/', $indexRaw) !== 1) {
				$rejected++;

				continue;
			}

			$index = (int) $indexRaw;

			if ($index > 65535) {
				$rejected++;

				continue;
			}

			$id     = new TargetId($fs, TargetType::Ost, $index);
			$name   = $id->name();
			$count  = self::uint($fields['count'] ?? null);
			$maxSec = self::nonNegFloat($fields['max_sec'] ?? null);

			$targets[$name] ??= ['id' => $id, 'values' => [], 'condition' => Condition::Unknown, 'start' => null];
			$targets[$name]['values']['waiters_count']   = $count;
			$targets[$name]['values']['waiters_max_sec'] = $maxSec;

			if ($maxSec !== null && $maxSec >= 30.0) {
				$targets[$name]['condition'] = self::worstCondition($targets[$name]['condition'], Condition::Degraded);
			}
		}
	}

	/**
	 * @param list<string> $lines
	 */
	private static function parseVersion(array $lines): ?string {
		$text = trim(implode("\n", $lines));

		return preg_match('/^(?:gpfs:\s*|scale:\s*)?([0-9][0-9A-Za-z._-]{0,31})$/', $text, $m) === 1 ? $m[1] : null;
	}

	private static function uint(?string $raw): ?float {
		return FramedReportParser::uint($raw);
	}

	/**
	 * @return array<string, string>
	 */
	private static function parseKeyValues(string $line): array {
		return FramedReportParser::parseKeyValues($line);
	}

	private static function nonNegFloat(?string $raw): ?float {
		if ($raw === null) {
			return null;
		}

		return preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/', trim($raw)) === 1 ? (float) trim($raw) : null;
	}

	private static function worstCondition(Condition $a, Condition $b): Condition {
		$rank = static fn (Condition $c): int => match ($c) {
			Condition::OutOfSpace => 6,
			Condition::ReadOnly   => 5,
			Condition::Inactive   => 4,
			Condition::Degraded   => 3,
			Condition::NoCreate   => 2,
			Condition::InService  => 1,
			Condition::Unknown    => 0,
		};

		return $rank($b) > $rank($a) ? $b : $a;
	}
}
