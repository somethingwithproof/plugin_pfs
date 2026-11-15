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

/*
 * Checks that the used percentage computed from the server-side kbytes
 * parameters equals what lfs df prints, using the formulas read from Lustre
 * source at tag v2_17_0 (and v2_15_8, identical):
 *   lfs df       lustre/utils/lfs.c obd_statfs_ratio():
 *                used = blocks - bfree; ratio = used / (used + bavail) * 100, rounded up
 *   kbytes files lustre/obdclass/lprocfs_status.c kbytes{total,free,avail}_show():
 *                value = blocks (or bfree, bavail) scaled by bsize / 1024
 * Both are re-implemented here from that source, so this is a consistency
 * check of the two formulas, not a measurement against a live filesystem.
 *   php run.php
 */

function lfs_df_percent(int $blocks, int $bfree, int $bavail): int
{
	$used = $blocks - $bfree;
	$ratio = 0.0;
	if ($used + $bavail > 0) {
		$ratio = $used / ($used + $bavail) * 100;
	}

	return ($ratio - (int)$ratio) > 0 ? (int)($ratio + 1) : (int)$ratio;
}

/** kbytes parameters as the kernel code computes them. */
function kbytes_params(int $blocks, int $bfree, int $bavail, int $bsize): array
{
	$blk = $bsize >> 10;
	$pow = 1;
	while (($pow << 1) <= max($blk, 1)) {
		$pow <<= 1; // rounddown_pow_of_two(blk ?: 1)
	}
	$total = $blocks * $pow;
	$free = $bfree;
	$avail = $bavail;
	for ($b = $blk >> 1; $b; $b >>= 1) { // while (blk_size >>= 1) result <<= 1
		$free <<= 1;
		$avail <<= 1;
	}

	return [$total, $free, $avail];
}

function percent_from_kbytes(int $total, int $free, int $avail): int
{
	$used = $total - $free;
	$ratio = 0.0;
	if ($used + $avail > 0) {
		$ratio = $used / ($used + $avail) * 100;
	}

	return ($ratio - (int)$ratio) > 0 ? (int)($ratio + 1) : (int)$ratio;
}

mt_srand(17);
$fail = 0;
$n = 0;
foreach ([4096, 8192, 131072, 1048576] as $bsize) { // ldiskfs 4 KiB; ZFS recordsize 128 KiB; larger
	for ($i = 0; $i < 5000; $i++) {
		$blocks = mt_rand(1, 4000000000);
		$bfree = mt_rand(0, $blocks);
		// bavail <= bfree (reserved blocks); may be 0
		$bavail = mt_rand(0, $bfree);
		[$t, $f, $a] = kbytes_params($blocks, $bfree, $bavail, $bsize);
		$n++;
		if (lfs_df_percent($blocks, $bfree, $bavail) !== percent_from_kbytes($t, $f, $a)) {
			$fail++;
		}
	}
}
// Edge cases: empty, full, nothing available.
foreach ([[100, 100, 100], [100, 0, 0], [100, 10, 0], [1, 0, 0]] as [$bl, $bf, $ba]) {
	[$t, $f, $a] = kbytes_params($bl, $bf, $ba, 4096);
	$n++;
	if (lfs_df_percent($bl, $bf, $ba) !== percent_from_kbytes($t, $f, $a)) {
		$fail++;
	}
}
echo "$n cases, $fail disagreements\n";
exit($fail === 0 ? 0 : 1);
