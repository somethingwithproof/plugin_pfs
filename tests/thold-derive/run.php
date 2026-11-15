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
 * Offline check of what plugin_thold computes for a DERIVE data source
 * across a counter reset. It extracts thold_get_currentval() from a copy of
 * plugin_thold's source and calls it with stubbed Cacti functions; it does
 * not load Cacti, a database, or Thold's other code.
 *   php run.php <file containing thold_get_currentval()> [data source type id: 3 DERIVE (default), 2 COUNTER]
 * Files tested (see docs/research/thold-counters.md):
 *   plugin_thold develop dc76952ee52e8b4877b2d5b830caab1f7c43dde9  includes/functions.php
 *   plugin_thold v1.8.2 1eb406bd0f81f3204358cce7bd43c8bb5adc8e4a   thold_functions.php
 * Fetch them yourself (for example with `gh api -H "Accept: application/vnd.github.raw"
 * "repos/Cacti/plugin_thold/contents/<path>?ref=<sha>"`) into a directory outside this
 * repository. The extracted function is evaluated, so read the file first.
 */

if ($argc < 2 || !is_readable($argv[1])) {
	fwrite(STDERR, "usage: php run.php <thold source file>\n");
	exit(2);
}

$src = file_get_contents($argv[1]);
if (!preg_match('/^function thold_get_currentval\(.*?^}\s*$/ms', $src, $m)) {
	fwrite(STDERR, "thold_get_currentval() not found\n");
	exit(2);
}

// Stubs for the Cacti and Thold helpers the DERIVE branch can reach.
function read_config_option($name)
{
	return $name === 'poller_interval' ? 60 : '';
}
function thold_debug()
{
}
function cacti_sizeof($a)
{
	return is_array($a) ? count($a) : 0;
}

eval($m[0]);
// COUNTER treats a decrease as a wrap and calls this helper when the file has it.
if (preg_match('/^function thold_counter_wrap_delta\(.*?^}\s*$/ms', $src, $w)) {
	eval($w[0]);
}
$type = (int)($argv[2] ?? 3);

$step = 60;
$name = 'v';
// Counter values at one poll per minute. 18000 -> 0 is a reset; the next
// sequence jumps 18000 -> 90000 in one step, which could hide a reset (the
// endpoint difference is only a lower bound on the true rate in that case).
$sequences = [
	'reset to 0, then normal' => [0, 6000, 12000, 18000, 0, 6000, 12000],
	'one-step upward jump that may hide a reset (18000 to 90000)' => [0, 6000, 12000, 18000, 90000, 96000],
];

foreach ($sequences as $label => $values) {
	echo $label . "\n  poll => value Thold evaluates (empty = no value)\n  ";
	$thold = [
		'local_data_id' => 1, 'name' => $name, 'data_source_type_id' => $type,
		'lasttime' => 0, 'oldvalue' => null, 'rrd_step' => $step, 'rrd_maximum' => 'U', 'rrd_heartbeat' => 120,
	];
	$cells = [];
	foreach ($values as $i => $v) {
		$now = 1700000000 + $i * $step;
		$reindexed = [1 => [$name => $v]];
		$times = [1 => $now];
		$item = [];
		$cur = 0;
		$val = thold_get_currentval($thold, $reindexed, $times, $item, $cur);
		$cells[] = "$i:" . ($val === '' ? '(empty)' : round((float)$val, 2));
		// Same re-anchoring Thold does after each poll.
		$thold['lasttime'] = $now;
		$thold['oldvalue'] = $v;
	}
	echo implode('  ', $cells) . "\n\n";
}
