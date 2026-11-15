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
 * Phase 1 failure qualification (issue #55, REQ-FAIL-001, REQ-FAIL-002),
 * through the production reader and SSH node source, with a stand-in for the
 * ssh client whose behaviour each scenario sets. Driven by
 * tests/bin/failure-replay.sh:
 *
 *   setup          a filesystem whose node is read through the stand-in
 *   arm <mode>     choose what the stand-in does and make the node due
 *   (php plugins/pfs/poller_pfs.php --poller=1 --force, timed)
 *   check <mode>   the state the reader recorded for that mode
 *   cleanup        remove the filesystem and restore the settings
 *
 * Modes: ok, unreachable, hostkey, slow, oversized, unframed, dbloss. The
 * stand-in and its fixture live in PFS_FAULT_DIR. Must parse on PHP 8.0.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/plugins/pfs/setup.php');
require_once($config['base_path'] . '/plugins/pfs/tests/bin/lustre-fixture.php');

$called_by_script_server = true;
require_once($config['base_path'] . '/plugins/pfs/scripts/ss_pfs_targets.php');
require_once($config['base_path'] . '/plugins/pfs/scripts/ss_pfs_summary.php');

const FAULT_MODES = array('ok', 'unreachable', 'hostkey', 'slow', 'oversized', 'unframed', 'dbloss');

/** What the node health records for each failing mode. */
const FAULT_ERRORS = array('unreachable' => 'unreachable', 'hostkey' => 'host_key', 'slow' => 'timeout', 'oversized' => 'too_large', 'unframed' => 'unframed');

$dir  = (string) getenv('PFS_FAULT_DIR');
$step = isset($argv[1]) ? $argv[1] : '';
$mode = isset($argv[2]) ? $argv[2] : '';

if (getenv('PFS_TEST_DATABASE') !== 'disposable' || $dir === '' || !is_dir($dir) || !in_array($step, array('setup', 'arm', 'check', 'cleanup'), true) || (in_array($step, array('arm', 'check'), true) && !in_array($mode, FAULT_MODES, true))) {
	fwrite(STDERR, "Set PFS_TEST_DATABASE=disposable and PFS_FAULT_DIR, and pass setup, arm <mode>, check <mode>, or cleanup\n");
	exit(2);
}

if (!pfs_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

$failures = 0;

function fault_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function fault_state($name, $value = null) {
	if ($value !== null) {
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array('pfs_fault_' . $name, (string) $value));
	}

	return (string) db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', array('pfs_fault_' . $name));
}

function fault_health() {
	$row = db_fetch_row_prepared('SELECT outcome, error, last_attempt, last_success, duration FROM plugin_pfs_node_health WHERE node_id = ?', array((int) fault_state('node')));

	return is_array($row) ? $row : array();
}

function fault_observed() {
	return (int) db_fetch_cell_prepared("SELECT MAX(o.observed_at) FROM plugin_pfs_observation o INNER JOIN plugin_pfs_target t ON t.id = o.target_id WHERE t.filesystem_id = ?", array((int) fault_state('fs')));
}

switch ($step) {
	case 'setup':
		foreach (array('pfs_ssh_binary', 'pfs_ssh_key_file', 'pfs_ssh_known_hosts', 'pfs_read_timeout', 'pfs_max_response') as $name) {
			fault_state('saved_' . $name, json_encode(db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', array($name))));
		}

		file_put_contents("$dir/answer.txt", pfs_fixture_oss('fault', array(0 => array('used' => 40, 'read' => 1000, 'write' => 1000), 1 => array('used' => 50, 'read' => 1000, 'write' => 1000))));
		file_put_contents("$dir/big.txt", "#pfs-read 1\n== version\n" . str_repeat(str_repeat('x', 1023) . "\n", 200) . "#pfs-read end\n");
		file_put_contents("$dir/id_ed25519", "not a key; the stand-in never reads it\n");
		file_put_contents("$dir/known_hosts", "127.0.0.1 ssh-ed25519 AAAA\n");
		chmod("$dir/id_ed25519", 0600);

		// The stand-in for ssh: what it does is chosen by the mode file.
		file_put_contents("$dir/ssh", "#!/bin/sh\n" .
			"case \"\$(cat '$dir/mode')\" in\n" .
			"ok) cat '$dir/answer.txt' ;;\n" .
			"unreachable) echo 'ssh: connect to host 127.0.0.1 port 22: No route to host' >&2; exit 255 ;;\n" .
			"hostkey) echo '@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@' >&2; echo '@    WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!     @' >&2; exit 255 ;;\n" .
			"slow) sleep 30; cat '$dir/answer.txt' ;;\n" .
			"oversized) cat '$dir/big.txt' ;;\n" .
			"unframed) echo 'Welcome to oss01'; echo 'Last login: never' ;;\n" .
			"dbloss) sleep 5; cat '$dir/answer.txt' ;;\n" .
			"esac\n");
		chmod("$dir/ssh", 0755);
		file_put_contents("$dir/mode", "ok\n");

		foreach (array('pfs_ssh_binary' => "$dir/ssh", 'pfs_ssh_key_file' => "$dir/id_ed25519", 'pfs_ssh_known_hosts' => "$dir/known_hosts", 'pfs_read_timeout' => '3', 'pfs_max_response' => '64') as $name => $value) {
			db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $value));
		}

		db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('fault', 'lustre', 1, 300, 'enabled')");
		$fsId = (int) db_fetch_insert_id();
		db_execute("INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state) VALUES ($fsId, 'fault-oss', 'oss', 1, '127.0.0.1', 22, 'pfsread', 'ssh', 'enabled')");
		fault_state('node', (int) db_fetch_insert_id());
		fault_state('fs', $fsId);
		db_execute("INSERT IGNORE INTO plugin_pfs_network (poller_id, cidr, created_at) VALUES (1, '127.0.0.0/8', UNIX_TIMESTAMP())");
		fault_expect($fsId > 0, 'a filesystem whose node is read through the ssh stand-in');

		break;
	case 'arm':
		file_put_contents("$dir/mode", "$mode\n");
		db_execute('UPDATE plugin_pfs_node_health SET last_attempt = last_attempt - 3600');
		fault_state('before', fault_observed());

		break;
	case 'check':
		$health = fault_health();
		$fs     = (new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock()))->find((int) fault_state('fs'));
		print 'EVIDENCE ' . $mode . ' health ' . json_encode($health) . PHP_EOL;

		if ($mode === 'ok') {
			fault_expect(isset($health['outcome']) && $health['error'] === '' && fault_observed() > (int) fault_state('before'), 'ok: the production reader read the node through the stand-in and cached its values');

			if ((int) fault_state('host') === 0) {
				$hostId = pfs_provisioner()->create($fs);
				fault_state('host', $hostId);
				fault_expect($hostId > 0, 'ok: the filesystem device was created');
			}

			fault_expect(ss_pfs_targets((int) fault_state('host'), 'ost', 'get', 'kbytes_total', 'fault-OST0000') === '1000000000', 'ok: the data query serves the value');
		} elseif ($mode === 'dbloss') {
			// The reader's database connection was killed while it waited
			// for the node. Whatever it managed to write, the next run must
			// read normally and nothing may read as zero.
			$lease = db_fetch_row('SELECT holder, expires_at FROM plugin_pfs_reader WHERE poller_id = 1');
			print 'EVIDENCE dbloss lease ' . json_encode($lease) . PHP_EOL;
			fault_expect(ss_pfs_targets((int) fault_state('host'), 'ost', 'get', 'kbytes_total', 'fault-OST0000') !== '0', 'dbloss: no value reads as zero after the reader lost its database connection');
		} else {
			$t = db_fetch_row_prepared("SELECT presence FROM plugin_pfs_target WHERE filesystem_id = ? AND name = 'fault-OST0000'", array((int) fault_state('fs')));
			fault_expect(isset($health['error']) && $health['error'] === FAULT_ERRORS[$mode], "$mode: the node read is recorded as failed with error " . FAULT_ERRORS[$mode] . ' (' . json_encode($health) . ')');
			fault_expect(fault_observed() === (int) fault_state('before') && is_array($t) && $t['presence'] === 'present', "$mode: the cached values and the inventory are left as they were, not zeroed or marked missing");
			fault_expect(ss_pfs_targets((int) fault_state('host'), 'ost', 'get', 'kbytes_total', 'fault-OST0000') === '1000000000', "$mode: the last good gauge is still served while it is current");

			$clock    = new \Somethingwithproof\Pfs\SystemClock();
			$warnings = \Somethingwithproof\Pfs\HealthWarnings::production($clock)->for(pfs_settings(), (new \Somethingwithproof\Pfs\NodeRepository(false, $clock))->all(), (new \Somethingwithproof\Pfs\NodeHealthRepository(false))->all(), (new \Somethingwithproof\Pfs\ReaderRepository(false, $clock))->all(), array($fs->id => $fs));
			print 'EVIDENCE ' . $mode . ' warnings ' . json_encode($warnings) . PHP_EOL;
		}

		break;
	case 'cleanup':
		foreach (array('pfs_ssh_binary', 'pfs_ssh_key_file', 'pfs_ssh_known_hosts', 'pfs_read_timeout', 'pfs_max_response') as $name) {
			$saved = json_decode(fault_state('saved_' . $name), true);

			if (is_string($saved)) {
				db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $saved));
			} else {
				db_execute_prepared('DELETE FROM settings WHERE name = ?', array($name));
			}
		}

		(new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock()))->delete((int) fault_state('fs'));
		db_execute("DELETE FROM plugin_pfs_network WHERE cidr = '127.0.0.0/8'");
		db_execute("DELETE FROM settings WHERE name LIKE 'pfs\\_fault\\_%'");

		break;
}

exit($failures > 0 ? 1 : 0);
