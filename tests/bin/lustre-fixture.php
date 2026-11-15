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
 * Generated Lustre read-script answers for the end-to-end flows and the
 * scale harness, in the format of tests/fixtures/lustre/oss-2.15.txt. There
 * is no Lustre simulator; these reproduce what a node prints, not how a
 * filesystem behaves.
 *
 * Must parse on PHP 8.0, like the other tests/bin scripts.
 */

/**
 * One OSS answer. $osts maps an OST index to its state:
 *   used     percent used, 0 to 100 (lfs df: used over used plus available)
 *   read     read_bytes stats sum; null leaves the line out
 *   write    write_bytes stats sum; null leaves the line out, as Lustre does
 *            for an OST that has not written since its start time
 *   degraded 1 to set the degraded flag
 *   start    the stats start_time
 */
function pfs_fixture_oss($fs, array $osts, $version = '2.15.5') {
	$out = array('#pfs-read 1', '== version', $version, '');

	foreach ($osts as $index => $ost) {
		$name  = sprintf('%s-OST%04x', $fs, $index);
		$total = 1000000000;
		$avail = (int) round($total * (100 - $ost['used']) / 100);

		foreach (array('kbytestotal' => $total, 'kbytesfree' => $avail, 'kbytesavail' => $avail, 'filestotal' => 65536000, 'filesfree' => 60000000) as $item => $value) {
			array_push($out, "== osd-ldiskfs/$name/$item", (string) $value, '');
		}

		foreach (array('degraded', 'no_precreate', 'no_create', 'readonly') as $flag) {
			array_push($out, "== obdfilter/$name/$flag", ($flag === 'degraded' && !empty($ost['degraded'])) ? '1' : '0', '');
		}

		$out[] = "== obdfilter/$name/stats";
		$out[] = 'snapshot_time             1731600000.123456789 secs.nsecs';
		$out[] = 'start_time                ' . (isset($ost['start']) ? $ost['start'] : '1731000000.000000001') . ' secs.nsecs';
		$out[] = 'elapsed_time              600000.123456788 secs.nsecs';

		foreach (array('read' => 'read_bytes', 'write' => 'write_bytes') as $key => $counter) {
			if (isset($ost[$key])) {
				$bytes   = (int) $ost[$key];
				$samples = max(1, intdiv($bytes, 1048576));
				$out[]   = sprintf('%-25s %d samples [bytes] 4096 1048576 %d %d', $counter, $samples, $bytes, $bytes * 4096);
			}
		}

		array_push($out, 'statfs                    300 samples [usecs] 0 9 400 900', '');
	}

	$out[] = '#pfs-read end';

	return implode("\n", $out) . "\n";
}

/**
 * One MDS answer. $mdts maps an MDT index to its md_stats counters
 * (operation => samples); an operation left out is not printed, as on a
 * release that lacks it.
 */
function pfs_fixture_mds($fs, array $mdts, $version = '2.15.5') {
	$out = array('#pfs-read 1', '== version', $version, '');

	foreach ($mdts as $index => $ops) {
		$name = sprintf('%s-MDT%04x', $fs, $index);

		foreach (array('kbytestotal' => 200000000, 'kbytesfree' => 150000000, 'kbytesavail' => 140000000, 'filestotal' => 100000000, 'filesfree' => 90000000) as $item => $value) {
			array_push($out, "== osd-ldiskfs/$name/$item", (string) $value, '');
		}

		$out[] = "== mdt/$name/md_stats";
		$out[] = 'snapshot_time             1731600000.000000001 secs.nsecs';
		$out[] = 'start_time                1730000000.000000001 secs.nsecs';
		$out[] = 'elapsed_time              1600000.000000000 secs.nsecs';

		foreach ($ops as $op => $samples) {
			$out[] = sprintf('%-25s %d samples [usecs] 1 900 %d %d', $op, (int) $samples, (int) $samples * 10, (int) $samples * 100);
		}

		$out[] = '';
	}

	$out[] = '#pfs-read end';

	return implode("\n", $out) . "\n";
}
