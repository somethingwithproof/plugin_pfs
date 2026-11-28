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
 * Shared framing, key=value, latency, and 64-bit KiB capacity parsing helpers
 * for read-only multi-filesystem backends (Lustre, GPFS, BeeGFS).
 *
 * Capacity metrics are normalized strictly to integer KiB before entering
 * IEEE-754 double or RRDtool storage so values above 9 PB (the 53-bit double
 * byte-precision ceiling) keep exact integer precision past 8 EiB (9.22 EB).
 */
final class FramedReportParser {
	/** Default max sections constant. */
	public const DEFAULT_MAX_SECTIONS = 20000;

	/** Allowed character mask for key=value token keys (equivalent to [a-z0-9_]). */
	private const KEY_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789_';

	/** Maximum signed 64-bit integer as a decimal string for fast-path bounds checks. */
	private const INT64_MAX_STR = '9223372036854775807';

	/**
	 * Parse a framed node wrapper payload into unique, non-failed sections.
	 *
	 * @param string $output      raw node stdout payload
	 * @param string $header      expected protocol header line
	 * @param string $footer      expected protocol footer line
	 * @param int    $maxSections upper bound on parsed sections
	 *
	 * @return array{
	 *   framed: bool,
	 *   complete: bool,
	 *   partial: bool,
	 *   sections: list<array{name: string, lines: list<string>}>
	 * }
	 */
	public static function parse(string $output, string $header, string $footer, int $maxSections = self::DEFAULT_MAX_SECTIONS): array {
		$normalized = str_contains($output, "\r") ? str_replace("\r\n", "\n", $output) : $output;
		$lines      = explode("\n", $normalized);

		if ($lines[0] !== $header) {
			return ['framed' => false, 'complete' => false, 'partial' => false, 'sections' => []];
		}

		$names      = [];
		$bodies     = [];
		$failed     = [];
		$current    = null;
		$complete   = false;
		$partial    = false;
		$trailing   = false;
		$lineCount  = count($lines);
		$nameCount  = 0;

		for ($idx = 1; $idx < $lineCount; $idx++) {
			$line = $lines[$idx];

			if ($complete) {
				if ($line !== '') {
					$trailing = true;
				}

				continue;
			}

			if ($line === $footer) {
				$complete = true;
				$current  = null;
			} elseif ($line === '!! total-limit') {
				$partial = true;
				$current = null;
			} elseif (str_starts_with($line, '== ')) {
				if ($nameCount >= $maxSections) {
					$partial = true;
					$current = null;

					continue;
				}

				$current          = $nameCount++;
				$names[$current]  = substr($line, 3);
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
			return ['framed' => true, 'complete' => false, 'partial' => true, 'sections' => []];
		}

		$seen     = array_count_values($names);
		$sections = [];

		for ($i = 0; $i < $nameCount; $i++) {
			$name = $names[$i];

			if ($failed[$i] || $seen[$name] > 1) {
				$partial = true;

				continue;
			}

			$sections[] = ['name' => $name, 'lines' => $bodies[$i]];
		}

		return ['framed' => true, 'complete' => true, 'partial' => $partial, 'sections' => $sections];
	}

	/**
	 * Parse space-separated key=value tokens with strict key hygiene.
	 *
	 * @param string $line single report line
	 *
	 * @return array<string, string>
	 */
	public static function parseKeyValues(string $line): array {
		$out    = [];
		$tokens = str_contains($line, "\t") ? (preg_split('/\s+/', $line) ?: []) : explode(' ', $line);

		foreach ($tokens as $token) {
			$eqPos = strpos($token, '=');

			if ($eqPos === false || $eqPos === 0 || $eqPos > 32) {
				continue;
			}

			$k = substr($token, 0, $eqPos);

			if (strspn($k, self::KEY_CHARS) === $eqPos) {
				$out[$k] = substr($token, $eqPos + 1);
			}
		}

		return $out;
	}

	/**
	 * Parse an unsigned decimal integer (up to 20 digits, UINT64 range).
	 *
	 * @param ?string $raw raw decimal token
	 *
	 * @return ?float
	 */
	public static function uint(?string $raw): ?float {
		if ($raw === null) {
			return null;
		}

		$trimmed = trim($raw);
		$len     = strlen($trimmed);

		return ($len >= 1 && $len <= 20 && ctype_digit($trimmed)) ? (float) $trimmed : null;
	}

	/**
	 * Parse a non-negative decimal float (up to 12 integer and 6 fractional digits).
	 *
	 * @param ?string $raw raw numeric token
	 *
	 * @return ?float
	 */
	public static function nonNegFloat(?string $raw): ?float {
		if ($raw === null) {
			return null;
		}

		$trimmed = trim($raw);

		if ($trimmed === '') {
			return null;
		}

		$len = strlen($trimmed);

		if ($len <= 12 && ctype_digit($trimmed)) {
			return (float) $trimmed;
		}

		return preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/', $trimmed) === 1 ? (float) $trimmed : null;
	}

	/**
	 * Parse a percentage value strictly in [0.0, 100.0].
	 *
	 * @param ?string $raw raw percentage token
	 *
	 * @return ?float
	 */
	public static function percent(?string $raw): ?float {
		$val = self::nonNegFloat($raw);

		return ($val !== null && $val <= 100.0) ? $val : null;
	}

	/**
	 * Parse an unsigned decimal integer in bytes and divide by 1024 using exact
	 * integer arithmetic (never converting bytes to IEEE-754 double first),
	 * preserving exact integer KiB precision at 500 PB, 2 EB, and up to 8 EiB.
	 *
	 * @param ?string $raw raw byte count string
	 *
	 * @return ?int
	 */
	public static function bytesToKibInt(?string $raw): ?int {
		if ($raw === null) {
			return null;
		}

		$clean = trim($raw);
		$len   = strlen($clean);

		if ($len === 0 || $len > 20 || !ctype_digit($clean)) {
			return null;
		}

		$trimmed = ltrim($clean, '0');

		if ($trimmed === '') {
			return 0;
		}

		$tLen = strlen($trimmed);

		// Fast path on 64-bit PHP: any decimal string <= PHP_INT_MAX (8 EiB - 1 B)
		// divides by 1024 exactly via a single native right-shift with zero precision loss.
		if (PHP_INT_SIZE >= 8 && ($tLen < 19 || ($tLen === 19 && strcmp($trimmed, self::INT64_MAX_STR) <= 0))) {
			return ((int) $trimmed) >> 10;
		}

		// Long division by 1024 on decimal digits for 19-20 digit uint64 values above PHP_INT_MAX.
		$quotient  = '';
		$remainder = 0;

		for ($i = 0; $i < $tLen; $i++) {
			$current = $remainder * 10 + (int) $trimmed[$i];
			$digit   = intdiv($current, 1024);

			if ($quotient !== '' || $digit > 0) {
				$quotient .= (string) $digit;
			}

			$remainder = $current % 1024;
		}

		if ($quotient === '') {
			return 0;
		}

		$maxStr = (string) PHP_INT_MAX;

		if (strlen($quotient) > strlen($maxStr) || (strlen($quotient) === strlen($maxStr) && strcmp($quotient, $maxStr) > 0)) {
			return null;
		}

		return (int) $quotient;
	}

	/**
	 * Convert a decimal byte string directly to exact KiB float.
	 *
	 * @param ?string $raw raw byte count string
	 *
	 * @return ?float
	 */
	public static function bytesToKib(?string $raw): ?float {
		$kib = self::bytesToKibInt($raw);

		return $kib === null ? null : (float) $kib;
	}

	/**
	 * Validate a non-negative KiB measurement for BIGINT UNSIGNED SQL storage.
	 *
	 * @param ?float $kib capacity in KiB
	 *
	 * @return ?int
	 */
	public static function kibToUint64(?float $kib): ?int {
		if ($kib === null || !is_finite($kib) || $kib < 0.0 || $kib > 9007199254740992.0) {
			return null;
		}

		return (int) round($kib);
	}

	/**
	 * Parse structured RPC latency sample lines into a bounded RpcLatencyMetrics
	 * accumulator. Supports both single-op and multi-channel key=value forms:
	 *   op=read|write|metadata latency_us=<n> [count=<n>]
	 *   op=read|write|metadata latency_ms=<n> [count=<n>]
	 *   [read_us=<n>] [write_us=<n>] [meta_us=<n>] [read_ms=<n>] [write_ms=<n>] [meta_ms=<n>]
	 *
	 * @param list<string>      $lines   section body lines
	 * @param RpcLatencyMetrics $latency accumulator updated in place
	 */
	public static function parseRpcLatencyLines(array $lines, RpcLatencyMetrics $latency): void {
		foreach ($lines as $rawLine) {
			$line = trim($rawLine);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$fields = self::parseKeyValues($line);

			if ($fields === []) {
				continue;
			}

			$weightFloat = self::uint($fields['count'] ?? ($fields['samples'] ?? null));
			$weight      = ($weightFloat !== null && $weightFloat >= 1.0 && $weightFloat <= (float) PHP_INT_MAX) ? (int) $weightFloat : 1;

			if (isset($fields['op'])) {
				$op  = strtolower($fields['op']);
				$us  = self::nonNegFloat($fields['latency_us'] ?? null);
				$ms  = $us === null ? self::nonNegFloat($fields['latency_ms'] ?? null) : null;
				$val = $us ?? ($ms !== null ? $ms * 1000.0 : null);

				if ($val !== null) {
					if ($op === 'read') {
						$latency->recordReadUs($val, $weight);
					} elseif ($op === 'write') {
						$latency->recordWriteUs($val, $weight);
					} elseif ($op === 'meta' || $op === 'metadata' || $op === 'md') {
						$latency->recordMetadataUs($val, $weight);
					}
				}

				continue;
			}

			$readUs = self::nonNegFloat($fields['read_us'] ?? ($fields['read_latency_us'] ?? null));

			if ($readUs !== null) {
				$latency->recordReadUs($readUs, $weight);
			}

			$readMs = self::nonNegFloat($fields['read_ms'] ?? ($fields['read_latency_ms'] ?? null));

			if ($readMs !== null) {
				$latency->recordReadMs($readMs, $weight);
			}

			$writeUs = self::nonNegFloat($fields['write_us'] ?? ($fields['write_latency_us'] ?? null));

			if ($writeUs !== null) {
				$latency->recordWriteUs($writeUs, $weight);
			}

			$writeMs = self::nonNegFloat($fields['write_ms'] ?? ($fields['write_latency_ms'] ?? null));

			if ($writeMs !== null) {
				$latency->recordWriteMs($writeMs, $weight);
			}

			$metaUs = self::nonNegFloat($fields['meta_us'] ?? ($fields['metadata_us'] ?? ($fields['meta_latency_us'] ?? null)));

			if ($metaUs !== null) {
				$latency->recordMetadataUs($metaUs, $weight);
			}

			$metaMs = self::nonNegFloat($fields['meta_ms'] ?? ($fields['metadata_ms'] ?? ($fields['meta_latency_ms'] ?? null)));

			if ($metaMs !== null) {
				$latency->recordMetadataMs($metaMs, $weight);
			}
		}
	}

	/**
	 * Normalize collected target entries into sorted TargetReport instances
	 * filtered strictly to each backend's declared ValueSpec list.
	 *
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string, recovery?: ?string, recovery_duration_s?: ?float, evicted_clients?: ?int}> $targets
	 * @param list<ValueSpec>                                                                                                                                                                   $specs
	 *
	 * @return array<string, TargetReport>
	 */
	public static function buildTargetReports(array $targets, array $specs): array {
		$defaultsByType = [];

		foreach ($specs as $spec) {
			$defaultsByType[$spec->targetType->value][$spec->name] = null;
		}

		$reports = [];

		foreach ($targets as $name => $entry) {
			$values = $defaultsByType[$entry['id']->type->value] ?? [];

			foreach ($entry['values'] as $metric => $val) {
				if (array_key_exists($metric, $values)) {
					$values[$metric] = $val;
				}
			}

			$reports[$name] = new TargetReport(
				$entry['id'],
				$values,
				$entry['start'],
				$entry['condition'],
				$entry['recovery'] ?? null,
				[],
				$entry['recovery_duration_s'] ?? null,
				$entry['evicted_clients'] ?? null,
			);
		}

		ksort($reports);

		return $reports;
	}

	/**
	 * Execute a framed backend parse with a backend-specific section dispatcher
	 * and version parser, returning a normalized NodeReport.
	 *
	 * @param string                                                                                                                                                                                                 $output
	 * @param string                                                                                                                                                                                                 $header
	 * @param string                                                                                                                                                                                                 $footer
	 * @param int                                                                                                                                                                                                    $maxSections
	 * @param list<ValueSpec>                                                                                                                                                                                        $specs
	 * @param \Closure(string, list<string>, array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string, recovery?: ?string, recovery_duration_s?: ?float, evicted_clients?: ?int}>, int, bool): bool $onSection
	 * @param \Closure(list<string>): ?string                                                                                                                                                                        $parseVersion
	 * @param ?RpcLatencyMetrics                                                                                                                                                                                     $rpcLatency
	 * @param ?\Closure(array<string, TargetReport>): ?StallMetrics                                                                                                                                                  $buildStalls
	 *
	 * @return NodeReport
	 */
	public static function parseReport(
		string $output,
		string $header,
		string $footer,
		int $maxSections,
		array $specs,
		\Closure $onSection,
		\Closure $parseVersion,
		?RpcLatencyMetrics $rpcLatency = null,
		?\Closure $buildStalls = null,
	): NodeReport {
		$parsed = self::parse($output, $header, $footer, $maxSections);

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
		/** @var array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string, recovery?: ?string, recovery_duration_s?: ?float, evicted_clients?: ?int}> $targets */
		$targets = [];

		foreach ($parsed['sections'] as $entry) {
			$section = $entry['name'];
			$body    = $entry['lines'];

			if ($section === 'version') {
				$version = $parseVersion($body);
			} elseif (!$onSection($section, $body, $targets, $rejected, $partial)) {
				$unexpected++;
			}
		}

		$reports  = self::buildTargetReports($targets, $specs);
		$latency  = ($rpcLatency !== null && !$rpcLatency->isEmpty()) ? $rpcLatency : null;
		$stalls   = $buildStalls !== null ? $buildStalls($reports) : null;
		$recovery = $reports !== [] ? RecoveryMetrics::fromTargets($reports) : null;

		if ($recovery !== null && $recovery->evictedClients === 0 && $recovery->recoveryActiveTargets === 0 && $recovery->recoveryDurationSeconds === 0.0) {
			$recovery = null;
		}

		return new NodeReport(true, true, $partial, $version, $reports, $rejected, $unexpected, $latency, $stalls, $recovery);
	}
}
