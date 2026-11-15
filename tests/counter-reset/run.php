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
 * Offline check of how an RRDtool DERIVE data source with minimum 0 treats
 * counter resets, and of a reader-side reset rule. Needs only the rrdtool
 * binary; no Lustre, no Cacti, no network.
 *   php run.php [path to rrdtool]
 * Scratch files go to a new directory under the system temp directory and are
 * left in place (the script never deletes anything).
 */

const STEP = 60;
const HEARTBEAT = 120;
const T0 = 1700000040; // multiple of STEP

$rrdtool = $argv[1] ?? 'rrdtool';
$dir = sys_get_temp_dir() . '/pfs-counter-reset-' . getmypid();
if (!mkdir($dir, 0700)) {
	fwrite(STDERR, "cannot create $dir\n");
	exit(2);
}

/** Run rrdtool with an argument list (no shell). */
function rrd(string $bin, array $args): string
{
	$p = proc_open(array_merge([$bin], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($p)) {
		fwrite(STDERR, "cannot run $bin\n");
		exit(2);
	}
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	$rc = proc_close($p);
	if ($rc !== 0) {
		fwrite(STDERR, "rrdtool failed: $err\n");
		exit(2);
	}

	return $out;
}

/**
 * Feed samples (offset in steps from T0 => counter value) and return the
 * per-step rate that RRDtool stored (null = unknown).
 */
function scenario(string $bin, string $dir, string $name, array $samples, string $max): array
{
	$f = "$dir/$name.rrd";
	rrd($bin, ['create', $f, '--start', (string)(T0 - STEP), '--step', (string)STEP,
		"DS:v:DERIVE:" . HEARTBEAT . ":0:$max", 'RRA:AVERAGE:0.5:1:100']);
	foreach ($samples as $k => $v) {
		rrd($bin, ['update', $f, (T0 + $k * STEP) . ':' . $v]);
	}
	$last = T0 + max(array_keys($samples)) * STEP;
	$txt = rrd($bin, ['fetch', $f, 'AVERAGE', '--start', (string)T0, '--end', (string)$last]);
	$rates = [];
	foreach (explode("\n", $txt) as $line) {
		if (preg_match('/^(\d+):\s+(\S+)/', $line, $m)) {
			$k = (int)(((int)$m[1] - T0) / STEP);
			$rates[$k] = stripos($m[2], 'nan') !== false ? null : round((float)$m[2], 2);
		}
	}

	return $rates;
}

/** Reader rule: decrease, or a changed start_time, means the counter reset. */
function reset_detected(float $prev, float $cur, ?string $prev_start, ?string $cur_start): bool
{
	if ($cur < $prev) {
		return true;
	}

	return $prev_start !== null && $cur_start !== null && $prev_start !== $cur_start;
}

$rows = [];
$rows['A reset to 0, then normal (6000 per step = 100/s)'] = scenario($rrdtool, $dir, 'a',
	[0 => 0, 1 => 6000, 2 => 12000, 3 => 18000, 4 => 0, 5 => 6000, 6 => 12000], 'U');
$rows['B one-step jump 18000 -> 90000 (could hide a reset), max U'] = scenario($rrdtool, $dir, 'b',
	[0 => 0, 1 => 6000, 2 => 12000, 3 => 18000, 4 => 90000, 5 => 96000], 'U');
$rows['C same as B, DS maximum 500/s'] = scenario($rrdtool, $dir, 'c',
	[0 => 0, 1 => 6000, 2 => 12000, 3 => 18000, 4 => 90000, 5 => 96000], '500');
$rows['D gap of 4 steps (240 s, above heartbeat 120 s)'] = scenario($rrdtool, $dir, 'd',
	[0 => 0, 1 => 6000, 5 => 30000, 6 => 36000], 'U');

echo 'rrdtool: ' . trim(explode("\n", rrd($rrdtool, ['--help']) . "\n")[0]) . "\n";
echo 'step ' . STEP . ' s, heartbeat ' . HEARTBEAT . " s, DERIVE minimum 0\n\n";
foreach ($rows as $label => $rates) {
	echo $label . "\n  step index => stored rate per second (null = unknown)\n  ";
	$cells = [];
	foreach ($rates as $k => $r) {
		$cells[] = "$k:" . ($r === null ? 'null' : $r);
	}
	echo implode('  ', $cells) . "\n\n";
}

$fail = 0;
$check = function (string $what, bool $ok) use (&$fail): void {
	echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
	$fail += $ok ? 0 : 1;
};
$check('reader rule: decrease is a reset', reset_detected(18000, 0, null, null));
$check('reader rule: equal value is not a reset', !reset_detected(5, 5, null, null));
$check('reader rule: new start_time is a reset even when the value rose',
	reset_detected(18000, 90000, '100.0', '160.0'));
$check('reader rule: value rise without start_time evidence is not detectable',
	!reset_detected(18000, 90000, null, null));
exit($fail === 0 ? 0 : 1);
