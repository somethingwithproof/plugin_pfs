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
 * never reset), mmdf capacity output, waiter summaries, NSD queue/pagepool
 * telemetry, and optional RPC latency lines.
 *
 * Maps NSD/data pools to TargetType::Ost (<fs>-OST<nnnn>) and metadata pools
 * to TargetType::Mdt (<fs>-MDT<nnnn>) so the unified plugin_pfs target,
 * summary, imbalance, and reset-detection model works across filesystems.
 */
final class GpfsBackend implements Backend {
	/** Protocol constant. */
	public const PROTOCOL = '#pfs-gpfs 1';
	/** End constant. */
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

	/** Max sections constant. */
	private const MAX_SECTIONS = 20000;

	/**
	 * Return the canonical backend identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'gpfs';
	}

	/**
	 * Return the human-readable display label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'IBM Storage Scale (GPFS)';
	}

	/**
	 * Return the validation regex for filesystem names on this backend.
	 *
	 * @return string
	 */
	public function filesystemPattern(): string {
		return '/^[A-Za-z0-9_-]{1,8}$/';
	}

	/**
	 * Return the metric value specifications supported by this backend.
	 *
	 * @return list<ValueSpec>
	 */
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
			new ValueSpec('nsd_queue_depth', ValueKind::Gauge, 'requests', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('pagepool_hit_pct', ValueKind::Gauge, 'percent', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('evicted_clients', ValueKind::Gauge, 'clients', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
			new ValueSpec('recovery_duration_s', ValueKind::Gauge, 'seconds', TargetType::Ost, [NodeRole::Oss, NodeRole::Client]),
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

	/**
	 * Parse a framed node report into structured targets, observations, RPC
	 * latency quantiles, NSD/pagepool stalls, and recovery counters.
	 *
	 * @param string $output
	 * @return NodeReport
	 */
	public function parse(string $output): NodeReport {
		$rpcLatency = new RpcLatencyMetrics();

		return FramedReportParser::parseReport(
			$output,
			self::PROTOCOL,
			self::END,
			self::MAX_SECTIONS,
			$this->values(),
			function (string $section, array $body, array &$targets, int &$rejected, bool &$partial) use ($rpcLatency): bool {
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

				if ($section === 'nsd_stats') {
					$this->parseNsdStats($body, $targets, $rejected);

					return true;
				}

				if ($section === 'rpc_latency') {
					FramedReportParser::parseRpcLatencyLines($body, $rpcLatency);

					return true;
				}

				return false;
			},
			static fn (array $lines): ?string => self::parseVersion($lines),
			$rpcLatency,
			static function (array $reports): ?StallMetrics {
				$maxQueue = null;
				$hitSum   = 0.0;
				$hitCount = 0;

				foreach ($reports as $target) {
					$q = $target->values['nsd_queue_depth'] ?? null;

					if ($q !== null) {
						$maxQueue = $maxQueue === null ? $q : max($maxQueue, $q);
					}

					$hit = $target->values['pagepool_hit_pct'] ?? null;

					if ($hit !== null) {
						$hitSum += $hit;
						$hitCount++;
					}
				}

				if ($maxQueue === null && $hitCount === 0) {
					return null;
				}

				return new StallMetrics(
					nsdQueueDepth: $maxQueue,
					pagepoolHitPct: $hitCount > 0 ? round($hitSum / $hitCount, 2) : null,
				);
			},
		);
	}

	/**
	 * Parse mmpmon -p -s fs_io_s lines (Table 8, docs/research/mmpmon.md).
	 * Rejects non-zero _rc_ or any reset response (REQ-GPFS-001, REQ-GPFS-002).
	 *
	 * @param list<string>                                                                                                                                                                     $lines
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string, recovery?: ?string, recovery_duration_s?: ?float, evicted_clients?: ?int}> $targets
	 */
	private function parseMmpmon(array $lines, array &$targets, int &$rejected, bool &$partial): void {
		foreach ($lines as $rawLine) {
			$line = trim($rawLine);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$tokens = preg_split('/\s+/', $line);

			if (!is_array($tokens)) {
				$rejected++;

				continue;
			}

			$tokenCount = count($tokens);

			if ($tokenCount < 4) {
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

			for ($i = 1; $i + 1 < $tokenCount; $i += 2) {
				$tok = $tokens[$i];

				if (preg_match('/^_[a-z]{1,4}_$/', $tok) !== 1) {
					continue;
				}

				$kv[$tok] = $tokens[$i + 1];
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
	 * Parse mmdf pool/NSD capacity records in key=value form:
	 *   pool fs=<name> type=ost|mdt index=<int> kbytes_total=<n> kbytes_free=<n> kbytes_avail=<n> files_total=<n> files_free=<n> state=up|degraded|recovering|readonly|nocreate
	 *
	 * @param list<string>                                                                                                                                                                     $lines
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string, recovery?: ?string, recovery_duration_s?: ?float, evicted_clients?: ?int}> $targets
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

			if ($type === null || preg_match($this->filesystemPattern(), $fs) !== 1 || !ctype_digit($indexRaw) || strlen($indexRaw) > 5) {
				$rejected++;

				continue;
			}

			$index = (int) $indexRaw;

			if ($index > 65535) {
				$rejected++;

				continue;
			}

			$id       = new TargetId($fs, $type, $index);
			$name     = $id->name();
			$stateRaw = strtolower($fields['state'] ?? '');

			$condition = match ($stateRaw) {
				'up', 'ready', 'in_service', 'ok' => Condition::InService,
				'degraded', 'recovering'          => Condition::Degraded,
				'readonly', 'ro'                  => Condition::ReadOnly,
				'nocreate', 'down', 'unavailable' => Condition::NoCreate,
				default                           => Condition::Unknown,
			};

			if (!isset($targets[$name])) {
				$targets[$name] = ['id' => $id, 'values' => [], 'condition' => Condition::Unknown, 'start' => null];
			}

			$targets[$name]['condition']              = self::worstCondition($targets[$name]['condition'], $condition);
			$targets[$name]['values']['kbytes_total'] = self::uint($fields['kbytes_total'] ?? null);
			$targets[$name]['values']['kbytes_free']  = self::uint($fields['kbytes_free'] ?? null);
			$targets[$name]['values']['kbytes_avail'] = self::uint($fields['kbytes_avail'] ?? ($fields['kbytes_free'] ?? null));
			$targets[$name]['values']['files_total']  = self::uint($fields['files_total'] ?? null);
			$targets[$name]['values']['files_free']   = self::uint($fields['files_free'] ?? null);

			if ($stateRaw === 'recovering') {
				$targets[$name]['recovery'] = 'RECOVERING';
			}

			self::applyOptionalGpfsTelemetry($fields, $targets[$name]);
		}
	}

	/**
	 * Parse GPFS waiter status lines:
	 *   waiters fs=<name> index=<int> count=<n> max_sec=<n> [nsd_queue_depth=<n> evicted_clients=<n> recovery_duration_s=<n>]
	 *
	 * @param list<string>                                                                                                                                                                     $lines
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string, recovery?: ?string, recovery_duration_s?: ?float, evicted_clients?: ?int}> $targets
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

			if (preg_match($this->filesystemPattern(), $fs) !== 1 || !ctype_digit($indexRaw) || strlen($indexRaw) > 5) {
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

			if (!isset($targets[$name])) {
				$targets[$name] = ['id' => $id, 'values' => [], 'condition' => Condition::Unknown, 'start' => null];
			}

			$targets[$name]['values']['waiters_count']   = $count;
			$targets[$name]['values']['waiters_max_sec'] = $maxSec;

			if ($maxSec !== null && $maxSec >= 30.0) {
				$targets[$name]['condition'] = self::worstCondition($targets[$name]['condition'], Condition::Degraded);
			}

			self::applyOptionalGpfsTelemetry($fields, $targets[$name]);
		}
	}

	/**
	 * Parse GPFS NSD server queue depth, pagepool hit ratio, and recovery/eviction lines:
	 *   nsd fs=<name> [index=<int>] [nsd_queue_depth=<n>|queue_depth=<n>] [pagepool_hit_pct=<0..100>]
	 *       [pagepool_hits=<n> pagepool_misses=<n>] [evicted_clients=<n>] [recovery_duration_s=<n>] [recovery_state=<STATE>]
	 *
	 * @param list<string>                                                                                                                                                                     $lines
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string, recovery?: ?string, recovery_duration_s?: ?float, evicted_clients?: ?int}> $targets
	 */
	private function parseNsdStats(array $lines, array &$targets, int &$rejected): void {
		foreach ($lines as $rawLine) {
			$line = trim($rawLine);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$fields   = self::parseKeyValues($line);
			$fs       = $fields['fs'] ?? '';
			$indexRaw = $fields['index'] ?? '0';

			if (preg_match($this->filesystemPattern(), $fs) !== 1 || !ctype_digit($indexRaw) || strlen($indexRaw) > 5) {
				$rejected++;

				continue;
			}

			$index = (int) $indexRaw;

			if ($index > 65535) {
				$rejected++;

				continue;
			}

			$id   = new TargetId($fs, TargetType::Ost, $index);
			$name = $id->name();

			if (!isset($targets[$name])) {
				$targets[$name] = ['id' => $id, 'values' => [], 'condition' => Condition::Unknown, 'start' => null];
			}

			self::applyOptionalGpfsTelemetry($fields, $targets[$name]);
		}
	}

	/**
	 * Apply optional NSD queue depth, pagepool hit ratio, eviction, and recovery
	 * fields onto a GPFS target entry.
	 *
	 * @param array<string, string>                                                                                                                                          $fields
	 * @param array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string, recovery?: ?string, recovery_duration_s?: ?float, evicted_clients?: ?int} $entry
	 */
	private static function applyOptionalGpfsTelemetry(array $fields, array &$entry): void {
		$queue = self::nonNegFloat($fields['nsd_queue_depth'] ?? ($fields['queue_depth'] ?? null));

		if ($queue !== null) {
			$entry['values']['nsd_queue_depth'] = $queue;
		}

		$hitPct = FramedReportParser::percent($fields['pagepool_hit_pct'] ?? null);

		if ($hitPct === null && isset($fields['pagepool_hits'], $fields['pagepool_misses'])) {
			$hits   = self::uint($fields['pagepool_hits']);
			$misses = self::uint($fields['pagepool_misses']);

			if ($hits !== null && $misses !== null && ($hits + $misses) > 0.0) {
				$hitPct = round(($hits / ($hits + $misses)) * 100.0, 2);
			}
		}

		if ($hitPct !== null) {
			$entry['values']['pagepool_hit_pct'] = $hitPct;
		}

		$evicted = self::uint($fields['evicted_clients'] ?? null);

		if ($evicted !== null) {
			$entry['values']['evicted_clients'] = $evicted;
			$entry['evicted_clients']           = (int) round($evicted);
		}

		$recDur = self::nonNegFloat($fields['recovery_duration_s'] ?? null);

		if ($recDur !== null) {
			$entry['values']['recovery_duration_s'] = $recDur;
			$entry['recovery_duration_s']           = $recDur;
		}

		if (isset($fields['recovery_state'])) {
			$recState = strtoupper(trim($fields['recovery_state']));

			if (preg_match('/^[A-Z_]{1,32}$/', $recState) === 1) {
				$entry['recovery'] = $recState;
			}
		}
	}

	/**
	 * Parse IBM Storage Scale / GPFS version line.
	 *
	 * @param list<string> $lines
	 *
	 * @return ?string
	 */
	private static function parseVersion(array $lines): ?string {
		$text = trim(implode("\n", $lines));

		return preg_match('/^(?:gpfs:\s*|scale:\s*)?([0-9][0-9A-Za-z._-]{0,31})$/', $text, $m) === 1 ? $m[1] : null;
	}

	/**
	 * Uint.
	 *
	 * @param ?string $raw
	 * @return ?float
	 */
	private static function uint(?string $raw): ?float {
		return FramedReportParser::uint($raw);
	}

	/**
	 * Parse key=value tokens from a single line.
	 *
	 * @param string $line
	 *
	 * @return array<string, string>
	 */
	private static function parseKeyValues(string $line): array {
		return FramedReportParser::parseKeyValues($line);
	}

	/**
	 * Non neg float.
	 *
	 * @param ?string $raw
	 * @return ?float
	 */
	private static function nonNegFloat(?string $raw): ?float {
		return FramedReportParser::nonNegFloat($raw);
	}

	/**
	 * Worst condition.
	 *
	 * @param Condition $a
	 * @param Condition $b
	 * @return Condition
	 */
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
