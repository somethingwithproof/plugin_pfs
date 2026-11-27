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
 * Shared framing, key=value, and 64-bit KiB capacity parsing helpers for
 * read-only multi-filesystem backends (GPFS, BeeGFS).
 *
 * Capacity metrics are normalized strictly to integer KiB before entering
 * IEEE-754 double or RRDtool storage so values above 9 PB (the 53-bit double
 * byte-precision ceiling) keep exact integer precision past 8 EiB (9.22 EB).
 */
final class FramedReportParser {
	public const DEFAULT_MAX_SECTIONS = 20000;

	/**
	 * Parse a framed node wrapper payload into unique, non-failed sections.
	 *
	 * @return array{
	 *   framed: bool,
	 *   complete: bool,
	 *   partial: bool,
	 *   sections: list<array{name: string, lines: list<string>}>
	 * }
	 */
	public static function parse(string $output, string $header, string $footer, int $maxSections = self::DEFAULT_MAX_SECTIONS): array {
		$lines = explode("\n", str_replace("\r\n", "\n", $output));

		if ($lines[0] !== $header) {
			return ['framed' => false, 'complete' => false, 'partial' => false, 'sections' => []];
		}

		$names    = [];
		$bodies   = [];
		$failed   = [];
		$current  = null;
		$complete = false;
		$partial  = false;
		$trailing = false;

		foreach (array_slice($lines, 1) as $line) {
			if ($complete) {
				$trailing = $trailing || $line !== '';

				continue;
			}

			if ($line === $footer) {
				$complete = true;
				$current  = null;
			} elseif ($line === '!! total-limit') {
				$partial = true;
				$current = null;
			} elseif (str_starts_with($line, '== ')) {
				if (count($names) >= $maxSections) {
					$partial = true;
					$current = null;

					continue;
				}

				$current          = count($names);
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

		foreach ($names as $i => $name) {
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
	 * @return array<string, string>
	 */
	public static function parseKeyValues(string $line): array {
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
	 * Parse an unsigned decimal integer (up to 20 digits, UINT64 range).
	 */
	public static function uint(?string $raw): ?float {
		if ($raw === null) {
			return null;
		}

		$trimmed = trim($raw);

		return preg_match('/^[0-9]{1,20}$/', $trimmed) === 1 ? (float) $trimmed : null;
	}

	/**
	 * Parse an unsigned decimal integer in bytes and divide by 1024 using exact
	 * decimal/integer arithmetic (never converting bytes to IEEE-754 double
	 * first), preserving exact integer KiB precision at 500 PB, 2 EB, and up to
	 * 8 EiB (9.22 EB).
	 */
	public static function bytesToKibInt(?string $raw): ?int {
		if ($raw === null) {
			return null;
		}

		$trimmed = ltrim(trim($raw), '0');

		if (trim($raw) === '' || preg_match('/^[0-9]{1,20}$/', trim($raw)) !== 1) {
			return null;
		}

		if ($trimmed === '') {
			return 0;
		}

		// Long division by 1024 on decimal digits avoids any 64-bit signed or
		// 53-bit IEEE-754 float precision loss up to full uint64_max (16 EiB).
		$quotient  = '';
		$remainder = 0;
		$length    = strlen($trimmed);

		for ($i = 0; $i < $length; $i++) {
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

		// Maximum unsigned 64-bit value / 1024 is 18,014,398,509,481,983 (< PHP_INT_MAX on 64-bit PHP).
		$maxStr = (string) PHP_INT_MAX;

		if (strlen($quotient) > strlen($maxStr) || (strlen($quotient) === strlen($maxStr) && strcmp($quotient, $maxStr) > 0)) {
			return null;
		}

		return (int) $quotient;
	}

	/**
	 * Convert a decimal byte string directly to exact KiB float.
	 */
	public static function bytesToKib(?string $raw): ?float {
		$kib = self::bytesToKibInt($raw);

		return $kib === null ? null : (float) $kib;
	}

	/**
	 * Validate a non-negative KiB measurement for BIGINT UNSIGNED SQL storage.
	 */
	public static function kibToUint64(?float $kib): ?int {
		if ($kib === null || !is_finite($kib) || $kib < 0.0 || $kib > 9007199254740992.0) {
			return null;
		}

		return (int) round($kib);
	}

	/**
	 * Normalize collected target entries into sorted TargetReport instances
	 * filtered strictly to each backend's declared ValueSpec list.
	 *
	 * @param array<string, array{id: TargetId, values: array<string, ?float>, condition: Condition, start: ?string}> $targets
	 * @param list<ValueSpec>                                                                                          $specs
	 *
	 * @return array<string, TargetReport>
	 */
	public static function buildTargetReports(array $targets, array $specs): array {
		$reports = [];

		foreach ($targets as $name => $entry) {
			$values = [];

			foreach ($specs as $spec) {
				if ($spec->targetType === $entry['id']->type) {
					$values[$spec->name] = $entry['values'][$spec->name] ?? null;
				}
			}

			$reports[$name] = new TargetReport($entry['id'], $values, $entry['start'], $entry['condition'], null, []);
		}

		ksort($reports);

		return $reports;
	}
}
