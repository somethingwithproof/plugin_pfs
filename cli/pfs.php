#!/usr/bin/env php
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
 * Operator and Configuration-as-Code (CasC) CLI for plugin_pfs:
 *
 *   php plugins/pfs/cli/pfs.php export [--format=yaml|xml]
 *   php plugins/pfs/cli/pfs.php validate <file> [--format=yaml|xml]
 *   php plugins/pfs/cli/pfs.php plan <file> [--format=yaml|xml] [--output=text|json]
 *   php plugins/pfs/cli/pfs.php apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]
 *   php plugins/pfs/cli/pfs.php health [--format=text|json]
 *
 * Parses on PHP 8.0 and refuses to run below PHP 8.2.
 */

chdir(__DIR__ . '/../../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/pfs/setup.php');

if (pfs_refuse_unsupported()) {
	exit(1);
}

pfs_boot();

/** @var list<string> $argv */
$pfs_args = array_slice($argv, 1);

if ($pfs_args === array() || in_array($pfs_args[0], array('-h', '--help', 'help'), true)) {
	print "usage: pfs.php <export|validate|plan|apply|health> [options]\n";
	print "  export [--format=yaml|xml]\n";
	print "  validate <file> [--format=yaml|xml]\n";
	print "  plan <file> [--format=yaml|xml] [--output=text|json]\n";
	print "  apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]\n";
	print "  health [--format=text|json]\n";
	exit($pfs_args === array() ? 2 : 0);
}

$pfs_cmd     = array_shift($pfs_args);
$pfs_format  = '';
$pfs_output  = 'text';
$pfs_confirm = '';
$pfs_file    = '';

foreach ($pfs_args as $pfs_arg) {
	if (strpos($pfs_arg, '--format=') === 0) {
		$pfs_format = strtolower(substr($pfs_arg, 9));
	} elseif (strpos($pfs_arg, '--output=') === 0) {
		$pfs_output = strtolower(substr($pfs_arg, 9));
	} elseif (strpos($pfs_arg, '--confirm=') === 0) {
		$pfs_confirm = substr($pfs_arg, 10);
	} elseif (strpos($pfs_arg, '-') === 0) {
		fwrite(STDERR, 'ERROR: Unknown option ' . $pfs_arg . PHP_EOL);
		exit(2);
	} elseif ($pfs_file === '') {
		$pfs_file = $pfs_arg;
	} else {
		fwrite(STDERR, 'ERROR: Unexpected argument ' . $pfs_arg . PHP_EOL);
		exit(2);
	}
}

$pfs_casc = pfs_casc();

if ($pfs_cmd === 'export') {
	$fmt = $pfs_format !== '' ? $pfs_format : 'yaml';

	try {
		print $pfs_casc->export($fmt);
		exit(0);
	} catch (InvalidArgumentException $e) {
		fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
		exit(2);
	}
}

if ($pfs_cmd === 'health') {
	$fmt = $pfs_format !== '' ? $pfs_format : 'text';

	if (!in_array($fmt, array('text', 'json'), true)) {
		fwrite(STDERR, 'ERROR: health --format must be text or json' . PHP_EOL);
		exit(2);
	}

	print $pfs_casc->formatHealth($fmt);
	exit(0);
}

if (!in_array($pfs_cmd, array('validate', 'plan', 'apply'), true)) {
	fwrite(STDERR, 'ERROR: Unknown command ' . $pfs_cmd . PHP_EOL);
	exit(2);
}

if ($pfs_file === '' || !is_file($pfs_file) || !is_readable($pfs_file)) {
	fwrite(STDERR, 'ERROR: Input configuration file is missing or unreadable' . PHP_EOL);
	exit(1);
}

$pfs_raw = file_get_contents($pfs_file);

if ($pfs_raw === false) {
	fwrite(STDERR, 'ERROR: Could not read input configuration file' . PHP_EOL);
	exit(1);
}

$fmt = $pfs_format;

if ($fmt === '') {
	$fmt = substr(strtolower($pfs_file), -4) === '.xml' ? 'xml' : 'yaml';
}

try {
	$pfs_desired = \Somethingwithproof\Pfs\CascProvider::parse($pfs_raw, $fmt);
} catch (InvalidArgumentException $e) {
	fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
	exit(1);
}

if ($pfs_cmd === 'validate') {
	$pfs_errors = $pfs_casc->validate($pfs_desired);

	if ($pfs_errors !== array()) {
		foreach ($pfs_errors as $err) {
			fwrite(STDERR, 'INVALID: ' . $err . PHP_EOL);
		}
		exit(1);
	}

	print "VALID\n";
	exit(0);
}

if ($pfs_cmd === 'plan') {
	$pfs_plan = $pfs_casc->plan($pfs_desired);

	if ($pfs_output === 'json') {
		print json_encode($pfs_plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	} elseif (!$pfs_plan['valid']) {
		foreach ($pfs_plan['errors'] as $err) {
			fwrite(STDERR, 'INVALID: ' . $err . PHP_EOL);
		}
	} else {
		print 'Plan Hash: ' . $pfs_plan['plan_hash'] . "\n";
		print 'Settings changes: ' . count($pfs_plan['changes']['settings']) . "\n";
		print 'Filesystem changes: ' . count($pfs_plan['changes']['filesystems']) . "\n";
		print 'Network additions: ' . count($pfs_plan['changes']['networks']) . "\n";
	}

	exit($pfs_plan['valid'] ? 0 : 1);
}

$pfs_result = $pfs_casc->apply($pfs_desired, $pfs_confirm);

if (!$pfs_result['applied']) {
	foreach ($pfs_result['errors'] as $err) {
		fwrite(STDERR, 'ERROR: ' . $err . PHP_EOL);
	}
	exit(1);
}

print sprintf(
	"APPLIED plan=%s settings_updated=%d filesystems_saved=%d networks_added=%d\n",
	$pfs_result['plan_hash'],
	$pfs_result['settings_updated'],
	$pfs_result['filesystems_saved'],
	$pfs_result['networks_added']
);
exit(0);
