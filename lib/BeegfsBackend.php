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
	/** Protocol constant. */
	public const PROTOCOL = '#pfs-beegfs 1';
	/** End constant. */
	public const END      = '#pfs-beegfs end';

	/** Max sections constant. */
	private const MAX_SECTIONS = 20000;

	/**
	 * Return the canonical backend identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'beegfs';
	}

	/**
	 * Return the human-readable display label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'BeeGFS';
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
			new ValueSpec('kbytes_total', ValueKind::Gauge, 'KiB', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('kbytes_free', ValueKind::Gauge, 'KiB', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('kbytes_avail', ValueKind::Gauge, 'KiB', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('files_total', ValueKind::Gauge, 'files', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('files_free', ValueKind::Gauge, 'files', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('work_requests', ValueKind::Gauge, 'requests', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('queued_requests', ValueKind::Gauge, 'requests', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('read_bytes_int', ValueKind::Gauge, 'bytes', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('write_bytes_int', ValueKind::Gauge, 'bytes', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('chunk_queue_depth', ValueKind::Gauge, 'requests', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('lock_waiters', ValueKind::Gauge, 'waiters', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('evicted_clients', ValueKind::Gauge, 'clients', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('recovery_duration_s', ValueKind::Gauge, 'seconds', TargetType::Ost, [NodeRole::Oss, NodeRole::Mgs]),
			new ValueSpec('kbytes_total', ValueKind::Gauge, 'KiB', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('kbytes_free', ValueKind::Gauge, 'KiB', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('kbytes_avail', ValueKind::Gauge, 'KiB', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('files_total', ValueKind::Gauge, 'files', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('files_free', ValueKind::Gauge, 'files', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('work_requests', ValueKind::Gauge, 'requests', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('queued_requests', ValueKind::Gauge, 'requests', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('lock_waiters', ValueKind::Gauge, 'waiters', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('evicted_clients', ValueKind::Gauge, 'clients', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
			new ValueSpec('recovery_duration_s', ValueKind::Gauge, 'seconds', TargetType::Mdt, [NodeRole::Mds, NodeRole::Mgs]),
		];
	}

	/**
	 * Parse a framed node report into structured targets, observations, RPC
	 * latency quantiles, chunkserver/lock stalls, and recovery counters.
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
				if ($section === 'storage_targets') {
					$this->parseTargets($body, TargetType::Ost, $targets, $rejected, $partial, $rpcLatency);

					return true;
				}

				if ($section === 'meta_targets') {
					$this->parseTargets($body, TargetType::Mdt, $targets, $rejected, $partial, $rpcLatency);

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
				$maxChunkQueue = null;
				$lockWaiters   = null;

				foreach ($reports as $target) {
					$q = $target->values['chunk_queue_depth'] ?? null;

					if ($q !== null) {
						$maxChunkQueue = $maxChunkQueue === null ? $q : max($maxChunkQueue, $q);
					}

					$w = $target->values['lock_waiters'] ?? null;

					if ($w !== null) {
						$lockWaiters = ($lockWaiters ?? 0.0) + $w;
					}
				}

				if ($maxChunkQueue === null && $lockWaiters === null) {
					return null;
				}

				return new StallMetrics(
					chunkQueueDepth: $maxChunkQueue,
					lockWaiters: $lockWaiters,
				);
			},
		);
	}

	/**
	 * Parse target lines:
	 *   target fs=<name> id=<int> reachability=online|probably-offline|offline consistency=GOOD|NEEDS_RESYNC|BAD
	 *     disk_space_total=<bytes> disk_space_free=<bytes> inodes_total=<n> inodes_free=<n>
	 *     [work_requests=<n> queued_requests=<n> disk_read_bytes=<n> disk_write_bytes=<n>
	 *      chunk_queue_depth=<n> lock_waiters=<n> evicted_clients=<n> recovery_duration_s=<n>
	 *      read_latency_us=<n> write_latency_us=<n> meta_latency_us=<n>]
	 *
	 * @param list<string>                                                                                                                                                                     $lines
	 * @param TargetType                                                                                                                                                                       $type
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string, recovery?: ?string, recovery_duration_s?: ?float, evicted_clients?: ?int}> $targets
	 * @param int                                                                                                                                                                              $rejected
	 * @param bool                                                                                                                                                                             $partial
	 * @param RpcLatencyMetrics                                                                                                                                                                $rpcLatency
	 */
	private function parseTargets(array $lines, TargetType $type, array &$targets, int &$rejected, bool &$partial, RpcLatencyMetrics $rpcLatency): void {
		$seenTarget = [];

		foreach ($lines as $rawLine) {
			$line = trim($rawLine);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$fields = self::parseKeyValues($line);
			$fs     = $fields['fs'] ?? '';
			$idRaw  = $fields['id'] ?? ($fields['target_id'] ?? '');

			if (preg_match($this->filesystemPattern(), $fs) !== 1 || !ctype_digit($idRaw) || strlen($idRaw) > 5) {
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

			$consistency = strtoupper(trim($fields['consistency'] ?? ''));
			$condition   = self::mapCondition($fields['reachability'] ?? '', $consistency);
			$evicted     = FramedReportParser::uint($fields['evicted_clients'] ?? null);
			$recDur      = FramedReportParser::nonNegFloat($fields['recovery_duration_s'] ?? null);
			$recovery    = null;

			if (isset($fields['recovery_state'])) {
				$rawRec = strtoupper(trim($fields['recovery_state']));

				if (preg_match('/^[A-Z_]{1,32}$/', $rawRec) === 1) {
					$recovery = $rawRec;
				}
			} elseif ($consistency === 'NEEDS_RESYNC') {
				$recovery = 'NEEDS_RESYNC';
			}

			// Convert raw byte counts directly to integer KiB via exact integer
			// division so 500 PB and 2 EB targets keep full integer precision.
			$values = [
				'kbytes_total'        => FramedReportParser::bytesToKib($fields['disk_space_total'] ?? null),
				'kbytes_free'         => FramedReportParser::bytesToKib($fields['disk_space_free'] ?? null),
				'kbytes_avail'        => FramedReportParser::bytesToKib($fields['disk_space_avail'] ?? ($fields['disk_space_free'] ?? null)),
				'files_total'         => FramedReportParser::uint($fields['inodes_total'] ?? null),
				'files_free'          => FramedReportParser::uint($fields['inodes_free'] ?? null),
				'work_requests'       => FramedReportParser::uint($fields['work_requests'] ?? null),
				'queued_requests'     => FramedReportParser::uint($fields['queued_requests'] ?? null),
				'lock_waiters'        => FramedReportParser::uint($fields['lock_waiters'] ?? null),
				'evicted_clients'     => $evicted,
				'recovery_duration_s' => $recDur,
			];

			if ($type === TargetType::Ost) {
				$values['read_bytes_int']    = FramedReportParser::uint($fields['disk_read_bytes'] ?? null);
				$values['write_bytes_int']   = FramedReportParser::uint($fields['disk_write_bytes'] ?? null);
				$values['chunk_queue_depth'] = FramedReportParser::nonNegFloat($fields['chunk_queue_depth'] ?? null);
			}

			$readUs  = FramedReportParser::nonNegFloat($fields['read_latency_us'] ?? null);
			$writeUs = FramedReportParser::nonNegFloat($fields['write_latency_us'] ?? null);
			$metaUs  = FramedReportParser::nonNegFloat($fields['meta_latency_us'] ?? null);

			if ($readUs !== null) {
				$rpcLatency->recordReadUs($readUs);
			}

			if ($writeUs !== null) {
				$rpcLatency->recordWriteUs($writeUs);
			}

			if ($metaUs !== null) {
				$rpcLatency->recordMetadataUs($metaUs);
			}

			$targets[$name] = [
				'id'                  => $targetId,
				'values'              => $values,
				'condition'           => $condition,
				'start'               => null,
				'recovery'            => $recovery,
				'recovery_duration_s' => $recDur,
				'evicted_clients'     => $evicted !== null ? (int) round($evicted) : null,
			];
		}
	}

	/**
	 * Map condition.
	 *
	 * @param string $reachability
	 * @param string $consistency
	 * @return Condition
	 */
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
	 * Parse BeeGFS version line.
	 *
	 * @param list<string> $lines
	 *
	 * @return ?string
	 */
	private static function parseVersion(array $lines): ?string {
		$text = trim(implode("\n", $lines));

		return preg_match('/^(?:beegfs:\s*)?([0-9][0-9A-Za-z._-]{0,31})$/', $text, $m) === 1 ? $m[1] : null;
	}
}
