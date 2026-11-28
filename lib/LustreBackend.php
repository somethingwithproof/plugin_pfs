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
 * Lustre 2.15.x, 2.16.x, and 2.17.x, read through node/pfs-read.sh.
 *
 * The script frames its output as:
 *
 *   #pfs-read 1
 *   == <parameter path relative to its sysfs, procfs, or debugfs root>
 *   <file content, at most 64 KiB>
 *   !! read-failed | !! truncated     (only when that happened)
 *   ...
 *   !! total-limit                     (only when the 4 MiB cap was hit)
 *   #pfs-read end
 *
 * Paths and formats are from docs/research/lustre-parameters.md, read from
 * source at v2_15_8, v2_16_1, and v2_17_0 and not yet from a real node. The
 * 2.17 debugfs copies of the OST stats and recovery_status print under the
 * same relative paths as the procfs copies of earlier releases.
 *
 * Rules that keep unknown apart from zero:
 *  - a section that failed, was truncated, or appears twice yields no value;
 *  - a counter line absent from a stats file is unknown, not zero samples,
 *    until real-node fixtures confirm the source's "zero samples print no
 *    line" rule for each release (PLAN, Counter semantics);
 *  - a malformed number is unknown;
 *  - condition is Unknown unless a condition flag was read.
 *
 * MDT operation names are the union of the three releases; a name not in
 * that list is ignored, never guessed at.
 */
final class LustreBackend implements Backend {
	/** Protocol constant. */
	public const PROTOCOL = '#pfs-read 1';
	/** End constant. */
	public const END      = '#pfs-read end';

	/** Capacity constant. */
	private const CAPACITY = [
		'kbytestotal' => 'kbytes_total',
		'kbytesfree'  => 'kbytes_free',
		'kbytesavail' => 'kbytes_avail',
		'filestotal'  => 'files_total',
		'filesfree'   => 'files_free',
	];

	/** Precompiled regex matching allowed read-only OST condition flag paths. */
	private const FLAG_PATH_PATTERN = '#^obdfilter/([^/]{1,64})/(degraded|no_precreate|no_create|readonly)$#';

	/** OST stats counter => [samples value, sum value] */
	private const OST_COUNTERS = [
		'read_bytes'  => ['read_ops', 'read_bytes'],
		'write_bytes' => ['write_ops', 'write_bytes'],
	];

	/** OST [usecs] stats counter names mapped to RPC latency channels. */
	private const OST_LATENCY_CHANNEL = [
		'ost_read'      => 'read',
		'brw_read'      => 'read',
		'read_latency'  => 'read',
		'ost_write'     => 'write',
		'brw_write'     => 'write',
		'write_latency' => 'write',
		'statfs'        => 'metadata',
		'get_info'      => 'metadata',
		'getattr'       => 'metadata',
		'setattr'       => 'metadata',
		'punch'         => 'metadata',
		'sync'          => 'metadata',
	];

	/** md_stats counter => value name (19 characters at most) */
	private const MDT_OPS = [
		'open'                 => 'md_open',
		'close'                => 'md_close',
		'mknod'                => 'md_mknod',
		'link'                 => 'md_link',
		'unlink'               => 'md_unlink',
		'mkdir'                => 'md_mkdir',
		'rmdir'                => 'md_rmdir',
		'rename'               => 'md_rename',
		'getattr'              => 'md_getattr',
		'setattr'              => 'md_setattr',
		'getxattr'             => 'md_getxattr',
		'setxattr'             => 'md_setxattr',
		'statfs'               => 'md_statfs',
		'sync'                 => 'md_sync',
		'samedir_rename'       => 'md_samedir_rename',
		'crossdir_rename'      => 'md_crossdir_rename',
		'migrate'              => 'md_migrate',
		'parallel_rename_file' => 'md_par_rename_file',
		'parallel_rename_dir'  => 'md_par_rename_dir',
		'rename_trylocks'      => 'md_rename_trylocks',
	];

	/** Recovery state allowlist map for O(1) lookup. */
	private const RECOVERY_MAP = [
		'INACTIVE'            => true,
		'COMPLETE'            => true,
		'WAITING'             => true,
		'WAITING_FOR_CLIENTS' => true,
		'RECOVERING'          => true,
	];

	/** Max sections constant. */
	private const MAX_SECTIONS = 50000;

	/**
	 * Return the canonical backend identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'lustre';
	}

	/**
	 * Return the human-readable display label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Lustre';
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
		$values = [];

		foreach ([TargetType::Ost, TargetType::Mdt] as $type) {
			$role = $type === TargetType::Ost ? NodeRole::Oss : NodeRole::Mds;

			foreach (self::CAPACITY as $name) {
				$values[] = new ValueSpec($name, ValueKind::Gauge, str_starts_with($name, 'kbytes') ? 'KiB' : 'files', $type, [$role]);
			}
		}

		foreach (self::OST_COUNTERS as [$samples, $sum]) {
			$values[] = new ValueSpec($sum, ValueKind::Counter, 'bytes', TargetType::Ost, [NodeRole::Oss]);
			$values[] = new ValueSpec($samples, ValueKind::Counter, 'operations', TargetType::Ost, [NodeRole::Oss]);
		}

		foreach (self::MDT_OPS as $name) {
			$values[] = new ValueSpec($name, ValueKind::Counter, 'operations', TargetType::Mdt, [NodeRole::Mds]);
		}

		return $values;
	}

	/**
	 * Parse a framed node report into structured targets, observations, RPC
	 * latency quantiles, LNet stall counters, and recovery/eviction metrics.
	 *
	 * @param string $output
	 * @return NodeReport
	 */
	public function parse(string $output): NodeReport {
		$lines = explode("\n", $output);

		if ($lines[0] !== self::PROTOCOL) {
			return NodeReport::unframed();
		}

		$paths      = [];
		$bodies     = [];
		$failed     = [];
		$current    = null;
		$complete   = false;
		$partial    = false;
		$unexpected = 0;
		$trailing   = false;
		$lineCount  = count($lines);
		$pathCount  = 0;

		for ($idx = 1; $idx < $lineCount; $idx++) {
			$line = $lines[$idx];

			if ($complete) {
				// Anything after the end marker is not the script's, so the
				// whole answer is rejected as incomplete.
				if ($line !== '') {
					$trailing = true;
				}

				continue;
			}

			if ($line === self::END) {
				$complete = true;
				$current  = null;
			} elseif ($line === '!! total-limit') {
				$partial = true;
				$current = null;
			} elseif (str_starts_with($line, '== ')) {
				if ($pathCount >= self::MAX_SECTIONS) {
					$partial = true;
					$current = null;

					continue;
				}

				$current          = $pathCount++;
				$paths[$current]  = substr($line, 3);
				$bodies[$current] = [];
				$failed[$current] = false;
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

		// A path printed twice in one read is ambiguous; neither copy counts.
		$seen       = array_count_values($paths);
		$version    = null;
		$rejected   = 0;
		$rpcLatency = new RpcLatencyMetrics();
		$stalls     = null;
		/** @var array<string, array{id: TargetId, values: array<string, ?float>, flags: array<string, int>, start: ?string, recovery: ?string, recovery_duration_s: ?float, evicted_clients: ?int, absent: list<string>}> $targets */
		$targets = [];

		for ($i = 0; $i < $pathCount; $i++) {
			$path = $paths[$i];
			$body = $bodies[$i];

			if ($failed[$i] || $seen[$path] > 1) {
				$partial = true;

				// Still note the target, so a failed section reads as unknown
				// values of a present target rather than a missing target.
				$this->noteTarget($path, $targets);

				continue;
			}

			if ($path === 'version') {
				$version = self::version($body);

				continue;
			}

			if ($path === 'lnet/stats') {
				$stalls = self::parseLnetStats($body);

				continue;
			}

			$match = $this->classify($path);

			if ($match === null) {
				$unexpected++;

				continue;
			}

			$id = TargetId::parse($match['target']);

			if ($id === null || ($match['type'] !== null && $id->type !== $match['type'])) {
				$rejected++;

				continue;
			}

			$name = $id->name();

			if (!isset($targets[$name])) {
				$targets[$name] = [
					'id'                  => $id,
					'values'              => [],
					'flags'               => [],
					'start'               => null,
					'recovery'            => null,
					'recovery_duration_s' => null,
					'evicted_clients'     => null,
					'absent'              => [],
				];
			}

			switch ($match['kind']) {
				case 'capacity':
					$targets[$name]['values'][self::CAPACITY[$match['item']]] = self::integer($body);

					break;
				case 'flag':
					$flag = self::integer($body);

					if ($flag === 0.0 || $flag === 1.0) {
						$targets[$name]['flags'][$match['item']] = (int) $flag;
					}

					break;
				case 'stats':
					$stats = self::stats($body);

					foreach (self::OST_COUNTERS as $counter => [$samples, $sum]) {
						$targets[$name]['values'][$samples] = $stats['counters'][$counter]['samples'] ?? null;
						$targets[$name]['values'][$sum]     = $stats['counters'][$counter]['sum'] ?? null;

						if (!array_key_exists($counter, $stats['counters']) && $stats['complete']) {
							$targets[$name]['absent'][] = $samples;
							$targets[$name]['absent'][] = $sum;
						}
					}

					if (isset($stats['counters']['evicted_clients']['samples'])) {
						$targets[$name]['evicted_clients'] = (int) round($stats['counters']['evicted_clients']['samples']);
					}

					self::foldOstLatency($stats['counters'], $rpcLatency);
					$targets[$name]['start'] = $stats['start'];

					break;
				case 'md_stats':
					$stats = self::stats($body);

					foreach (self::MDT_OPS as $counter => $value) {
						$targets[$name]['values'][$value] = $stats['counters'][$counter]['samples'] ?? null;

						if (!array_key_exists($counter, $stats['counters']) && $stats['complete']) {
							$targets[$name]['absent'][] = $value;
						}
					}

					if (isset($stats['counters']['evicted_clients']['samples'])) {
						$targets[$name]['evicted_clients'] = (int) round($stats['counters']['evicted_clients']['samples']);
					}

					self::foldMdtLatency($stats['counters'], $rpcLatency);
					$targets[$name]['start'] = $stats['start'];

					break;
				case 'recovery':
					$rec                                     = self::recovery($body);
					$targets[$name]['recovery']              = $rec['status'];
					$targets[$name]['recovery_duration_s']   = $rec['duration_s'];

					if ($rec['evicted_clients'] !== null) {
						$targets[$name]['evicted_clients'] = $rec['evicted_clients'];
					}

					break;
				case 'evicted_clients':
					$evicted = self::integer($body);

					if ($evicted !== null) {
						$targets[$name]['evicted_clients'] = (int) round($evicted);
					}

					break;
				case 'rpc_latency':
					FramedReportParser::parseRpcLatencyLines($body, $rpcLatency);

					break;
			}
		}

		// Pre-index declared ValueSpecs by TargetType once outside the target loop
		// to avoid instantiating 34 ValueSpec objects per target at exascale.
		$defaultsByType = [];

		foreach ($this->values() as $spec) {
			$defaultsByType[$spec->targetType->value][$spec->name] = null;
		}

		$reports = [];

		foreach ($targets as $name => $target) {
			$values = $defaultsByType[$target['id']->type->value] ?? [];

			foreach ($target['values'] as $metric => $val) {
				if (array_key_exists($metric, $values)) {
					$values[$metric] = $val;
				}
			}

			$reports[$name] = new TargetReport(
				$target['id'],
				$values,
				$target['start'],
				self::condition($target['flags']),
				$target['recovery'],
				$target['absent'],
				$target['recovery_duration_s'],
				$target['evicted_clients'],
			);
		}

		ksort($reports);

		$latencyOut  = $rpcLatency->isEmpty() ? null : $rpcLatency;
		$stallsOut   = ($stalls !== null && !$stalls->isEmpty()) ? $stalls : null;
		$recoveryOut = $reports !== [] ? RecoveryMetrics::fromTargets($reports) : null;

		if ($recoveryOut !== null && $recoveryOut->evictedClients === 0 && $recoveryOut->recoveryActiveTargets === 0 && $recoveryOut->recoveryDurationSeconds === 0.0) {
			$recoveryOut = null;
		}

		return new NodeReport(true, true, $partial, $version, $reports, $rejected, $unexpected, $latencyOut, $stallsOut, $recoveryOut);
	}

	/**
	 * Classify a Lustre sysfs/procfs/debugfs relative parameter path using
	 * fast prefix dispatch before regex matching.
	 *
	 * @param string $path relative section path
	 *
	 * @return array{kind: string, target: string, item: string, type: ?TargetType}|null
	 */
	private function classify(string $path): ?array {
		if (str_starts_with($path, 'osd-')) {
			if (preg_match('#^osd-[a-z0-9]{1,16}/([^/]{1,64})/(kbytestotal|kbytesfree|kbytesavail|filestotal|filesfree)$#', $path, $m) === 1) {
				return ['kind' => 'capacity', 'target' => $m[1], 'item' => $m[2], 'type' => null];
			}

			return null;
		}

		if (str_starts_with($path, 'obdfilter/')) {
			if (preg_match(self::FLAG_PATH_PATTERN, $path, $m) === 1) {
				return ['kind' => 'flag', 'target' => $m[1], 'item' => $m[2], 'type' => TargetType::Ost];
			}

			if (preg_match('#^obdfilter/([^/]{1,64})/(stats|recovery_status|evicted_clients|rpc_latency)$#', $path, $m) === 1) {
				$kind = $m[2] === 'recovery_status' ? 'recovery' : $m[2];

				return ['kind' => $kind, 'target' => $m[1], 'item' => '', 'type' => TargetType::Ost];
			}

			return null;
		}

		if (str_starts_with($path, 'mdt/')) {
			if (preg_match('#^mdt/([^/]{1,64})/(md_stats|recovery_status|evicted_clients|rpc_latency)$#', $path, $m) === 1) {
				$kind = $m[2] === 'recovery_status' ? 'recovery' : $m[2];

				return ['kind' => $kind, 'target' => $m[1], 'item' => '', 'type' => TargetType::Mdt];
			}

			return null;
		}

		return null;
	}

	/**
	 * Note a target whose section failed or duplicated so its values read as unknown.
	 *
	 * @param string                                                                                                                                                            $path
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, flags: array<string, int>, start: ?string, recovery: ?string, recovery_duration_s: ?float, evicted_clients: ?int, absent: list<string>}> $targets
	 */
	private function noteTarget(string $path, array &$targets): void {
		$match = $this->classify($path);
		$id    = $match === null ? null : TargetId::parse($match['target']);

		if ($id !== null && ($match['type'] === null || $id->type === $match['type'])) {
			$targets[$id->name()] ??= [
				'id'                  => $id,
				'values'              => [],
				'flags'               => [],
				'start'               => null,
				'recovery'            => null,
				'recovery_duration_s' => null,
				'evicted_clients'     => null,
				'absent'              => [],
			];
		}
	}

	/**
	 * A file holding one non-negative integer and nothing else.
	 *
	 * @param list<string> $lines
	 *
	 * @return ?float
	 */
	private static function integer(array $lines): ?float {
		$text = trim(implode("\n", $lines));
		$len  = strlen($text);

		return ($len >= 1 && $len <= 20 && ctype_digit($text)) ? (float) $text : null;
	}

	/**
	 * Parse Lustre software version line.
	 *
	 * @param list<string> $lines
	 *
	 * @return ?string
	 */
	private static function version(array $lines): ?string {
		$text = trim(implode("\n", $lines));

		return preg_match('/^(?:lustre: )?([0-9][0-9A-Za-z._-]{0,31})$/', $text, $m) === 1 ? $m[1] : null;
	}

	/**
	 * A stats file: snapshot_time, optional start_time and elapsed_time, then
	 * "<name> <count> samples [<unit>]" with optional min, max, sum, and sum
	 * of squares. A counter printed twice, or a line that does not match the
	 * format, leaves that counter unknown. complete is true when the section
	 * carried its snapshot_time line, so a counter missing from it was left
	 * out by Lustre rather than lost in transit.
	 *
	 * @param list<string> $lines
	 *
	 * @return array{start: ?string, complete: bool, counters: array<string, array{samples: ?float, unit: ?string, min: ?float, max: ?float, sum: ?float}>}
	 */
	private static function stats(array $lines): array {
		$start    = null;
		$snapshot = false;
		$counters = [];
		$broken   = [];

		foreach ($lines as $line) {
			$line = trim($line);

			if ($line === '') {
				continue;
			}

			if (preg_match('/^(snapshot_time|start_time|elapsed_time)\s+(\d{1,12}\.\d{1,9})\s+secs\.[nu]secs$/', $line, $m) === 1) {
				if ($m[1] === 'start_time') {
					$start = $m[2];
				}

				if ($m[1] === 'snapshot_time') {
					$snapshot = true;
				}

				continue;
			}

			if (preg_match('/^([a-z_]{1,32})\s+(\d{1,20})\s+samples\s+\[([a-z]{1,16})\](?:\s+(\d{1,20})\s+(\d{1,20})\s+(\d{1,20})(?:\s+(\d{1,40}))?)?$/', $line, $m) === 1) {
				$name = $m[1];

				if (isset($counters[$name])) {
					$broken[$name] = true;
				}

				$counters[$name] = [
					'samples' => (float) $m[2],
					'unit'    => $m[3],
					'min'     => isset($m[4]) ? (float) $m[4] : null,
					'max'     => isset($m[5]) ? (float) $m[5] : null,
					'sum'     => isset($m[6]) ? (float) $m[6] : null,
				];

				continue;
			}

			// A line that looks like a counter we want but does not parse
			// makes that counter unknown rather than silently absent. The
			// line is trimmed, so a bare name ends the line.
			if (preg_match('/^([a-z_]{1,32})(?:\s|$)/', $line, $m) === 1) {
				$broken[$m[1]] = true;
			}
		}

		foreach (array_keys($broken) as $name) {
			$counters[$name] = ['samples' => null, 'unit' => null, 'min' => null, 'max' => null, 'sum' => null];
		}

		return ['start' => $start, 'complete' => $snapshot, 'counters' => $counters];
	}

	/**
	 * Fold OST [usecs] RPC latency lines into the bounded RpcLatencyMetrics accumulator.
	 *
	 * @param array<string, array{samples: ?float, unit: ?string, min: ?float, max: ?float, sum: ?float}> $counters
	 * @param RpcLatencyMetrics                                                                           $latency
	 */
	private static function foldOstLatency(array $counters, RpcLatencyMetrics $latency): void {
		foreach ($counters as $name => $stat) {
			$channel = self::OST_LATENCY_CHANNEL[$name] ?? null;

			if ($channel === null || $stat['unit'] !== 'usecs' || $stat['samples'] === null || $stat['min'] === null || $stat['max'] === null || $stat['sum'] === null) {
				continue;
			}

			$samples = (int) round($stat['samples']);

			if ($samples < 1) {
				continue;
			}

			if ($channel === 'read') {
				$latency->read->recordSummary($samples, $stat['min'], $stat['max'], $stat['sum']);
			} elseif ($channel === 'write') {
				$latency->write->recordSummary($samples, $stat['min'], $stat['max'], $stat['sum']);
			} else {
				$latency->metadata->recordSummary($samples, $stat['min'], $stat['max'], $stat['sum']);
			}
		}
	}

	/**
	 * Fold MDT [usecs] metadata operation latency lines into the metadata histogram.
	 *
	 * @param array<string, array{samples: ?float, unit: ?string, min: ?float, max: ?float, sum: ?float}> $counters
	 * @param RpcLatencyMetrics                                                                           $latency
	 */
	private static function foldMdtLatency(array $counters, RpcLatencyMetrics $latency): void {
		foreach (self::MDT_OPS as $counter => $_value) {
			$stat = $counters[$counter] ?? null;

			if ($stat === null || $stat['unit'] !== 'usecs' || $stat['samples'] === null || $stat['min'] === null || $stat['max'] === null || $stat['sum'] === null) {
				continue;
			}

			$samples = (int) round($stat['samples']);

			if ($samples >= 1) {
				$latency->metadata->recordSummary($samples, $stat['min'], $stat['max'], $stat['sum']);
			}
		}
	}

	/**
	 * Parse Lustre recovery_status section for state, recovery_duration (s),
	 * and evicted_clients / stale_clients counters.
	 *
	 * @param list<string> $lines
	 *
	 * @return array{status: ?string, duration_s: ?float, evicted_clients: ?int}
	 */
	private static function recovery(array $lines): array {
		$status   = null;
		$duration = null;
		$evicted  = null;

		foreach ($lines as $line) {
			if (preg_match('/^status:\s*([A-Z_]{1,32})\s*$/', $line, $m) === 1) {
				$status = isset(self::RECOVERY_MAP[$m[1]]) ? $m[1] : null;
			} elseif (preg_match('/^recovery_duration:\s*([0-9]{1,12}(?:\.[0-9]{1,6})?)\s*$/', $line, $m) === 1) {
				$duration = (float) $m[1];
			} elseif (preg_match('/^(?:evicted_clients|stale_clients):\s*([0-9]{1,12})\s*$/', $line, $m) === 1) {
				$evicted = (int) $m[1];
			}
		}

		return ['status' => $status, 'duration_s' => $duration, 'evicted_clients' => $evicted];
	}

	/**
	 * Parse Lustre LNet statistics from either standard 11-column /proc/sys/lnet/stats
	 * or key=value wrapper output (drops/lnet_drops, retransmits/lnet_retransmits).
	 *
	 * @param list<string> $lines
	 *
	 * @return StallMetrics
	 */
	private static function parseLnetStats(array $lines): StallMetrics {
		$drops       = null;
		$retransmits = null;

		foreach ($lines as $rawLine) {
			$line = trim($rawLine);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			if (str_contains($line, '=')) {
				$fields = FramedReportParser::parseKeyValues($line);
				$d      = FramedReportParser::uint($fields['lnet_drops'] ?? ($fields['drops'] ?? ($fields['drop_count'] ?? null)));
				$r      = FramedReportParser::uint($fields['lnet_retransmits'] ?? ($fields['retransmits'] ?? ($fields['errors'] ?? null)));

				if ($d !== null) {
					$drops = $d;
				}

				if ($r !== null) {
					$retransmits = $r;
				}

				continue;
			}

			// Standard /proc/sys/lnet/stats: 11 unsigned integers where index 3 is
			// errors/retransmit faults and index 7 is drop_count.
			$cols = preg_split('/\s+/', $line);

			if (is_array($cols) && count($cols) >= 11) {
				$allDigits = true;

				for ($i = 0; $i < 11; $i++) {
					if (!ctype_digit($cols[$i]) || strlen($cols[$i]) > 20) {
						$allDigits = false;

						break;
					}
				}

				if ($allDigits) {
					$retransmits = (float) $cols[3];
					$drops       = (float) $cols[7];
				}
			}
		}

		return new StallMetrics($drops, $retransmits);
	}

	/**
	 * Condition from the OST flags read, worst first. With no flag read, as
	 * for every MDT, the condition is Unknown.
	 *
	 * @param array<string, int> $flags
	 *
	 * @return Condition
	 */
	private static function condition(array $flags): Condition {
		return match (true) {
			$flags === []                                                         => Condition::Unknown,
			($flags['readonly'] ?? 0) === 1                                       => Condition::ReadOnly,
			($flags['degraded'] ?? 0) === 1                                       => Condition::Degraded,
			($flags['no_create'] ?? 0) === 1 || ($flags['no_precreate'] ?? 0) === 1 => Condition::NoCreate,
			default                                                               => Condition::InService,
		};
	}
}
