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
 * Offline check of which INFO capability declaration lets a plugin hook run on
 * a Cacti remote data collector (poller_id > 1). It extracts the gating
 * functions from a copy of Cacti's lib/plugins.php, runs them with a stubbed
 * environment and temporary INFO files, and prints which hooks fire.
 *   php run.php <lib/plugins.php from the Cacti ref under test>
 * Produce the input with, for example:
 *   git -C <cacti clone> show refs/tags/release/1.2.31:lib/plugins.php > /path/outside/repo/plugins-1.2.31.php
 * Only the extracted functions are evaluated; Cacti itself is not loaded.
 */

if ($argc < 2 || !is_readable($argv[1])) {
	fwrite(STDERR, "usage: php run.php <lib/plugins.php>\n");
	exit(2);
}

$src = file_get_contents($argv[1]);
$want = ['api_plugin_run_plugin_hook', 'api_plugin_run_plugin_hook_function',
	'api_plugin_hook_is_remote_collect', 'api_plugin_status_run',
	'api_plugin_remote_capabilities', 'api_plugin_has_capability'];
foreach ($want as $fn) {
	if (!preg_match('/^function ' . $fn . '\(.*?^}\s*$/ms', $src, $m)) {
		fwrite(STDERR, "$fn not found\n");
		exit(2);
	}
	eval($m[0]);
}

function cacti_log($msg, $out = false, $env = '', $lvl = '')
{
}
function cacti_sizeof($a)
{
	return is_array($a) ? count($a) : 0;
}

$base = sys_get_temp_dir() . '/pfs-capgate-' . getmypid();
mkdir($base . '/plugins', 0700, true);

$declarations = [
	'servcheck-style: remote_collect:1 only' => 'online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:1',
	'remote_collect:1, remote_poller:1' => 'online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:1, remote_poller:1',
	'remote_poller:1 only' => 'remote_poller:1',
	'no capabilities line' => null,
];
$hooks = [
	['poller_bottom', 'hook'],
	['poller_output', 'hook_function'],
	['poller_top', 'hook'],
	['replicate_out', 'hook_function'],
	['remote_agent', 'hook_function'],
	['graph_buttons', 'hook'],
];

$GLOBALS['ran'] = false;
function probe($args = null)
{
	$GLOBALS['ran'] = true;

	return $args;
}

$config = ['poller_id' => 2, 'connection' => 'online', 'base_path' => $base];
$info_data = [];
$menu = [];

echo 'plugins.php: ' . basename($argv[1]) . " (poller_id 2, connection online)\n\n";
$i = 0;
foreach ($declarations as $label => $caps) {
	$plugin = 'p' . (++$i);
	mkdir("$base/plugins/$plugin");
	file_put_contents("$base/plugins/$plugin/INFO", "[info]\nname = $plugin\n" . ($caps === null ? '' : "capabilities = $caps\n"));
	$cells = [];
	foreach ($hooks as [$hook, $kind]) {
		$GLOBALS['ran'] = false;
		if ($kind === 'hook') {
			api_plugin_run_plugin_hook($hook, $plugin, 'probe', [$hook]);
		} else {
			api_plugin_run_plugin_hook_function($hook, $plugin, 'probe', 'x');
		}
		$cells[] = $hook . '=' . ($GLOBALS['ran'] ? 'runs' : 'skipped');
	}
	echo $label . "\n  " . implode('  ', $cells) . "\n";
}
