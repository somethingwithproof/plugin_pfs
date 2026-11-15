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
	public const PROTOCOL = '#pfs-read 1';
	public const END      = '#pfs-read end';

	private const CAPACITY = [
		'kbytestotal' => 'kbytes_total',
		'kbytesfree'  => 'kbytes_free',
		'kbytesavail' => 'kbytes_avail',
		'filestotal'  => 'files_total',
		'filesfree'   => 'files_free',
	];

	private const FLAGS = ['degraded', 'no_precreate', 'no_create', 'readonly'];

	/** OST stats counter => [samples value, sum value] */
	private const OST_COUNTERS = [
		'read_bytes'  => ['read_ops', 'read_bytes'],
		'write_bytes' => ['write_ops', 'write_bytes'],
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

	private const RECOVERY = ['INACTIVE', 'COMPLETE', 'WAITING', 'WAITING_FOR_CLIENTS', 'RECOVERING'];

	private const MAX_SECTIONS = 50000;

	public function id(): string {
		return 'lustre';
	}

	public function label(): string {
		return 'Lustre';
	}

	public function filesystemPattern(): string {
		return '/^[A-Za-z0-9_-]{1,8}$/';
	}

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

		foreach (array_slice($lines, 1) as $line) {
			if ($complete) {
				// Anything after the end marker is not the script's, so the
				// whole answer is rejected as incomplete.
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
				if (count($paths) >= self::MAX_SECTIONS) {
					$partial = true;
					$current = null;

					continue;
				}

				$current          = count($paths);
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
		$seen = array_count_values($paths);

		$version  = null;
		$rejected = 0;
		/** @var array<string, array{id: TargetId, values: array<string, ?float>, flags: array<string, int>, start: ?string, recovery: ?string}> $targets */
		$targets = [];

		foreach ($paths as $i => $path) {
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
			$targets[$name] ??= ['id' => $id, 'values' => [], 'flags' => [], 'start' => null, 'recovery' => null];

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
					}

					$targets[$name]['start'] = $stats['start'];

					break;
				case 'md_stats':
					$stats = self::stats($body);

					foreach (self::MDT_OPS as $counter => $value) {
						$targets[$name]['values'][$value] = $stats['counters'][$counter]['samples'] ?? null;
					}

					$targets[$name]['start'] = $stats['start'];

					break;
				case 'recovery':
					$targets[$name]['recovery'] = self::recovery($body);

					break;
			}
		}

		$reports = [];

		foreach ($targets as $name => $target) {
			$values = [];

			foreach ($this->values() as $spec) {
				if ($spec->targetType === $target['id']->type) {
					$values[$spec->name] = $target['values'][$spec->name] ?? null;
				}
			}

			$reports[$name] = new TargetReport($target['id'], $values, $target['start'], self::condition($target['flags']), $target['recovery']);
		}

		ksort($reports);

		return new NodeReport(true, true, $partial, $version, $reports, $rejected, $unexpected);
	}

	/**
	 * @return array{kind: string, target: string, item: string, type: ?TargetType}|null
	 */
	private function classify(string $path): ?array {
		return match (true) {
			preg_match('#^osd-[a-z0-9]{1,16}/([^/]{1,64})/(kbytestotal|kbytesfree|kbytesavail|filestotal|filesfree)$#', $path, $m) === 1 => ['kind' => 'capacity', 'target' => $m[1], 'item' => $m[2], 'type' => null],
			preg_match('#^obdfilter/([^/]{1,64})/(' . implode('|', self::FLAGS) . ')$#', $path, $m) === 1                    => ['kind' => 'flag', 'target' => $m[1], 'item' => $m[2], 'type' => TargetType::Ost],
			preg_match('#^obdfilter/([^/]{1,64})/stats$#', $path, $m) === 1                                                       => ['kind' => 'stats', 'target' => $m[1], 'item' => '', 'type' => TargetType::Ost],
			preg_match('#^obdfilter/([^/]{1,64})/recovery_status$#', $path, $m) === 1                                             => ['kind' => 'recovery', 'target' => $m[1], 'item' => '', 'type' => TargetType::Ost],
			preg_match('#^mdt/([^/]{1,64})/md_stats$#', $path, $m) === 1                                                          => ['kind' => 'md_stats', 'target' => $m[1], 'item' => '', 'type' => TargetType::Mdt],
			default                                                                                                               => null,
		};
	}

	/**
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, flags: array<string, int>, start: ?string, recovery: ?string}> $targets
	 */
	private function noteTarget(string $path, array &$targets): void {
		$match = $this->classify($path);
		$id    = $match === null ? null : TargetId::parse($match['target']);

		if ($id !== null && ($match['type'] === null || $id->type === $match['type'])) {
			$targets[$id->name()] ??= ['id' => $id, 'values' => [], 'flags' => [], 'start' => null, 'recovery' => null];
		}
	}

	/**
	 * A file holding one non-negative integer and nothing else.
	 *
	 * @param list<string> $lines
	 */
	private static function integer(array $lines): ?float {
		$text = trim(implode("\n", $lines));

		return preg_match('/^\d{1,20}$/', $text) === 1 ? (float) $text : null;
	}

	/**
	 * @param list<string> $lines
	 */
	private static function version(array $lines): ?string {
		$text = trim(implode("\n", $lines));

		return preg_match('/^(?:lustre: )?([0-9][0-9A-Za-z._-]{0,31})$/', $text, $m) === 1 ? $m[1] : null;
	}

	/**
	 * A stats file: snapshot_time, optional start_time and elapsed_time, then
	 * "<name> <count> samples [<unit>]" with optional min, max, sum, and sum
	 * of squares. A counter printed twice, or a line that does not match the
	 * format, leaves that counter unknown.
	 *
	 * @param list<string> $lines
	 *
	 * @return array{start: ?string, counters: array<string, array{samples: ?float, sum: ?float}>}
	 */
	private static function stats(array $lines): array {
		$start    = null;
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

				continue;
			}

			if (preg_match('/^([a-z_]{1,32})\s+(\d{1,20})\s+samples\s+\[[a-z]{1,16}\](?:\s+(\d{1,20})\s+(\d{1,20})\s+(\d{1,20})(?:\s+(\d{1,40}))?)?$/', $line, $m) === 1) {
				$name = $m[1];

				if (isset($counters[$name])) {
					$broken[$name] = true;
				}

				$counters[$name] = ['samples' => (float) $m[2], 'sum' => isset($m[5]) ? (float) $m[5] : null];

				continue;
			}

			// A line that looks like a counter we want but does not parse
			// makes that counter unknown rather than silently absent.
			if (preg_match('/^([a-z_]{1,32})\s/', $line, $m) === 1) {
				$broken[$m[1]] = true;
			}
		}

		foreach (array_keys($broken) as $name) {
			$counters[$name] = ['samples' => null, 'sum' => null];
		}

		return ['start' => $start, 'counters' => $counters];
	}

	/**
	 * @param list<string> $lines
	 */
	private static function recovery(array $lines): ?string {
		foreach ($lines as $line) {
			if (preg_match('/^status:\s*([A-Z_]{1,32})\s*$/', $line, $m) === 1) {
				return in_array($m[1], self::RECOVERY, true) ? $m[1] : null;
			}
		}

		return null;
	}

	/**
	 * Condition from the OST flags read, worst first. With no flag read, as
	 * for every MDT, the condition is Unknown.
	 *
	 * @param array<string, int> $flags
	 */
	private static function condition(array $flags): Condition {
		return match (true) {
			$flags === []                                                     => Condition::Unknown,
			($flags['readonly'] ?? 0) === 1                                   => Condition::ReadOnly,
			($flags['degraded'] ?? 0) === 1                                   => Condition::Degraded,
			($flags['no_create'] ?? 0) === 1 || ($flags['no_precreate'] ?? 0) === 1 => Condition::NoCreate,
			default                                                           => Condition::InService,
		};
	}
}
