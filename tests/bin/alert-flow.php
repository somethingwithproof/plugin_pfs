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
 * Thold alerting and Maint suppression on the plugin's summary data source,
 * end to end, with Thold and Maint installed unchanged at plugins/thold and
 * plugins/maint (issue #51, REQ-ALERT-010, REQ-ALERT-011, REQ-MAINT-001).
 *
 * tests/bin/alert-replay.sh drives it: for each step in turn it runs
 *
 *   PFS_TEST_DATABASE=disposable php plugins/pfs/tests/bin/alert-flow.php <step>
 *   php poller.php --force
 *   php plugins/thold/poller_thold.php
 *
 * Each step first checks what Thold made of the poll before it, then has the
 * reader read the next fixture answer (FixtureNodeSource, no SSH). The
 * thresholds are created through Thold's own save_thold(), as its edit page
 * does; the maintenance schedule is the two rows Maint's page saves.
 *
 * Every check prints PASS or FAIL; EVIDENCE lines show the Thold state and
 * log rows each check relied on. Must parse on PHP 8.0. State between steps
 * is kept in Cacti's settings table under pfs_alert_*.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/lib/rrd.php');
require_once($config['base_path'] . '/plugins/pfs/setup.php');
require_once($config['base_path'] . '/plugins/pfs/tests/bin/lustre-fixture.php');

use Somethingwithproof\Pfs\AccessMethod;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\FixtureNodeSource;
use Somethingwithproof\Pfs\NodeSources;
use Somethingwithproof\Pfs\TemplateInstaller;

const ALERT_STEPS = array('setup', 'warmup', 'baseline', 'burst', 'burst2', 'rated', 'calm', 'imbalance', 'balanced', 'lost', 'returned', 'silent', 'answering', 'maint', 'maint2', 'unmaint', 'final');

// Thold's log statuses (plugins/thold/includes/settings.php), defined there
// only while Thold's config hook runs.
const ALERT_NOTIFY  = 4;
const ALERT_RESTORE = 5;

if (isset($argv[1]) && $argv[1] === 'list') {
	print implode(' ', ALERT_STEPS) . PHP_EOL;
	exit(0);
}

if (getenv('PFS_TEST_DATABASE') !== 'disposable' || !isset($argv[1]) || !in_array($argv[1], ALERT_STEPS, true)) {
	fwrite(STDERR, 'Set PFS_TEST_DATABASE=disposable and pass one of: ' . implode(' ', ALERT_STEPS) . PHP_EOL);
	exit(2);
}

if (!pfs_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

require_once($config['base_path'] . '/plugins/thold/thold_functions.php');
require_once($config['base_path'] . '/plugins/thold/includes/arrays.php');

$failures = 0;

/** The thresholds: summary value => [high threshold, fail trigger in polls]. */
const ALERT_THOLDS = array(
	'fill_spread_pct' => array('20', 1),
	'targets_missing' => array('0.5', 1),
	'age'             => array('30', 1),
	'write_bytes'     => array('1000000000', 2),
);

function alert_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function alert_state($name, $value = null) {
	if ($value !== null) {
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array('pfs_alert_' . $name, (string) $value));
	}

	return (string) db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', array('pfs_alert_' . $name));
}

/** Two healthy OSTs at 40 and 45 percent; $write is the per-OST write total. */
function alert_osts($write, array $change = array()) {
	$osts = array(
		0 => array('used' => 40, 'read' => 1000000, 'write' => $write),
		1 => array('used' => 45, 'read' => 1000000, 'write' => $write),
	);

	foreach ($change as $index => $fields) {
		if ($fields === null) {
			unset($osts[$index]);
		} else {
			$osts[$index] = array_merge($osts[$index], $fields);
		}
	}

	return $osts;
}

/**
 * Read the fixture answers now. $oss null means the OSS does not answer;
 * the write counters keep growing between steps so a rate is drawn.
 */
function alert_read($oss) {
	$source  = new FixtureNodeSource(array());
	$sources = new NodeSources();

	if ($oss !== null) {
		$source->answer((int) alert_state('oss'), pfs_fixture_oss('scratch', $oss));
	}

	$source->answer((int) alert_state('mds'), pfs_fixture_mds('scratch', array(0 => array('open' => 100, 'close' => 90))));
	$sources->register(AccessMethod::Ssh, $source);

	// Steps are seconds apart, not an interval: make both nodes due again,
	// as they are when the next interval starts.
	db_execute('UPDATE plugin_pfs_node_health SET last_attempt = last_attempt - 3600');

	$run = pfs_reader_with(1, $sources, new FixedClock(time()))->run(1, 240, 4);
	alert_expect($run->nodesRead + $run->nodesFailed === 2, 'the reader read both nodes (' . $run->nodesRead . ' answered, ' . $run->nodesFailed . ' failed)');

	return $run;
}

/** The write total the next healthy read reports: 1 MB more per OST each step. */
function alert_write($extra = 0) {
	$write = (int) alert_state('write') + 1000000 + $extra;
	alert_state('write', $write);

	return $write;
}

function alert_thold($name) {
	return db_fetch_row_prepared('SELECT id, thold_alert, thold_fail_count, lastread, oldvalue FROM thold_data WHERE id = ?', array((int) alert_state('thold_' . $name)));
}

/** Thold log rows for one threshold since the given log id. */
function alert_log($name, $after) {
	return db_fetch_assoc_prepared('SELECT id, status, current, description FROM plugin_thold_log WHERE threshold_id = ? AND id > ? ORDER BY id', array((int) alert_state('thold_' . $name), (int) $after));
}

function alert_statuses($rows) {
	return array_map('intval', array_column($rows, 'status'));
}

/** Print the Thold state of every threshold, and remember the newest log id. */
function alert_evidence($step) {
	foreach (array_keys(ALERT_THOLDS) as $name) {
		$t = alert_thold($name);

		if (!is_array($t) || !isset($t['id'])) {
			continue;
		}

		print "EVIDENCE $step $name alert=" . $t['thold_alert'] . ' fail_count=' . $t['thold_fail_count'] . " lastread='" . $t['lastread'] . "'" . PHP_EOL;
	}

	foreach (db_fetch_assoc_prepared('SELECT l.id, l.status, l.current, t.data_source_name FROM plugin_thold_log l INNER JOIN thold_data t ON t.id = l.threshold_id WHERE l.id > ? ORDER BY l.id', array((int) alert_state('log'))) as $row) {
		print "EVIDENCE $step log id=" . $row['id'] . ' ' . $row['data_source_name'] . ' status=' . $row['status'] . " current='" . $row['current'] . "'" . PHP_EOL;
	}
}

/** Log rows since the step started, by threshold. */
function alert_since($name) {
	return alert_statuses(alert_log($name, (int) alert_state('log')));
}

/**
 * Check a threshold after a poll: $alert is whether it is breached now,
 * $logged the Thold log statuses that poll added for it.
 */
function alert_check($step, $name, $alert, array $logged) {
	$t   = alert_thold($name);
	$got = alert_since($name);

	alert_expect(((int) $t['thold_alert'] !== 0) === $alert && $got === $logged, "$step: $name " . ($alert ? 'breached' : 'normal') . ' with log ' . json_encode($logged) . ' (alert=' . $t['thold_alert'] . ', fail_count=' . $t['thold_fail_count'] . ', log ' . json_encode($got) . ')');
}

function alert_quiet($step, array $names) {
	foreach ($names as $name) {
		alert_check($step, $name, false, array());
	}
}

/** Create one threshold the way Thold's edit page saves it. */
function alert_create_thold($hostId, $name, $hi, $trigger) {
	$row = db_fetch_row_prepared('SELECT dtr.id AS dtr, dtr.local_data_id, gti.local_graph_id
		FROM data_template_rrd dtr
		INNER JOIN data_local dl ON dl.id = dtr.local_data_id
		INNER JOIN graph_templates_item gti ON gti.task_item_id = dtr.id
		WHERE dl.host_id = ? AND dtr.data_source_name = ?
		ORDER BY gti.local_graph_id LIMIT 1', array($hostId, $name));

	if (!is_array($row) || !isset($row['dtr'])) {
		return 0;
	}

	$_REQUEST = array();
	$fields   = array(
		'host_id' => $hostId, 'local_data_id' => $row['local_data_id'], 'local_graph_id' => $row['local_graph_id'],
		'data_template_rrd_id' => $row['dtr'], 'thold_type' => '0', 'thold_hi' => $hi, 'thold_low' => '',
		'thold_fail_trigger' => (string) $trigger, 'thold_warning_hi' => '', 'thold_warning_low' => '',
		'thold_warning_fail_trigger' => '', 'repeat_alert' => '0', 'data_type' => '0', 'cdef' => '0',
		'thold_per_enabled' => 'on', 'name' => 'pfs ' . $name, 'notify_alert' => '0', 'notify_warning' => '0',
		'bl_type' => '0', 'syslog_facility' => '0', 'syslog_priority' => '0', 'time_fail_trigger' => '',
		'time_fail_length' => '', 'time_warning_fail_trigger' => '', 'time_warning_fail_length' => '',
		'bl_ref_time_range' => '', 'bl_fail_trigger' => '', 'decimals' => '2',
	);

	foreach ($fields as $field => $value) {
		set_request_var($field, $value);
	}

	$id = save_thold();

	return is_numeric($id) ? (int) $id : 0;
}

function alert_rrd($localDataId, $name) {
	$path = get_data_source_path($localDataId, true);
	$out  = (string) shell_exec('rrdtool lastupdate ' . escapeshellarg($path) . ' 2>&1');
	$rows = preg_split('/\R/', trim($out));

	if (count($rows) < 3) {
		return array(0, 'none');
	}

	$names  = preg_split('/\s+/', trim($rows[0]));
	$values = preg_split('/\s+/', trim(substr($rows[2], strpos($rows[2], ':') + 1)));
	$map    = array_combine($names, $values);

	return array((int) $rows[2], isset($map[$name]) ? $map[$name] : 'none');
}

$step = $argv[1];

if ($step !== 'setup') {
	alert_evidence($step);
}

switch ($step) {
	case 'setup':
		alert_expect(api_plugin_is_enabled('thold') && api_plugin_is_enabled('maint'), 'Thold and Maint are installed and enabled');

		$repo = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());

		foreach ($repo->all() as $old) {
			$repo->delete($old->id);
		}

		// A 60 second interval keeps the staleness wait short: the summary is
		// current up to 90 seconds old (ADR 0012).
		db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('scratch', 'lustre', 1, 60, 'enabled')");
		$fsId = (int) db_fetch_insert_id();

		foreach (array('oss01' => 'oss', 'mds01' => 'mds') as $name => $role) {
			db_execute_prepared("INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state) VALUES (?, ?, ?, 1, '10.20.0.1', 22, 'pfsread', 'ssh', 'enabled')", array($fsId, $name, $role));
			alert_state(substr($name, 0, 3), (int) db_fetch_insert_id());
		}

		alert_state('write', 1000000);
		alert_state('log', 0);
		alert_read(alert_osts(alert_write()));

		$hostId = pfs_provisioner()->create($repo->find($fsId));
		alert_expect($hostId > 0, 'the filesystem device was created');
		alert_state('host', $hostId);
		alert_state('fs', $fsId);

		foreach (ALERT_THOLDS as $name => $spec) {
			$id = alert_create_thold($hostId, $name, $spec[0], $spec[1]);
			alert_expect($id > 0, "Thold saved a high threshold on $name above " . $spec[0] . ' after ' . $spec[1] . ' poll(s)');
			alert_state('thold_' . $name, $id);
		}

		alert_read(alert_osts(alert_write()));

		break;
	case 'warmup':
		$hostId = (int) alert_state('host');
		alert_expect((int) db_fetch_cell_prepared('SELECT status FROM host WHERE id = ?', array($hostId)) === 3, 'the filesystem device is up, so Thold checks its thresholds');
		alert_expect(is_numeric(alert_thold('fill_spread_pct')['lastread']) && is_numeric(alert_thold('age')['lastread']), 'Thold read the summary values from the poll');
		alert_read(alert_osts(alert_write()));

		break;
	case 'baseline':
		// Thold's first rate reading after it starts is against no previous
		// value; the fail trigger of two polls keeps that from alerting.
		alert_quiet($step, array_keys(ALERT_THOLDS));
		// 10 TB more per OST within one poll: far above 1 GB/s.
		alert_read(alert_osts(alert_write(10000000000000)));

		break;
	case 'burst':
		$t = alert_thold('write_bytes');
		alert_expect((int) $t['thold_alert'] !== 0 && (int) $t['thold_fail_count'] === 1 && alert_since('write_bytes') === array(), 'burst: one poll above the rate threshold breaches it without notifying (fail_count=' . $t['thold_fail_count'] . ', log ' . json_encode(alert_since('write_bytes')) . ')');
		alert_read(alert_osts(alert_write(10000000000000)));

		break;
	case 'burst2':
		alert_check($step, 'write_bytes', true, array(ALERT_NOTIFY));
		alert_read(alert_osts(alert_write()));

		break;
	case 'rated':
		alert_check($step, 'write_bytes', false, array(ALERT_RESTORE));

		// Thold 1.8.2 keeps the U a counter data source reads after a reset
		// or a change of contributing targets as its previous value, and its
		// next rate calculation throws a TypeError in the poller, after
		// which Cacti disables Thold (docs/alerting.md). The rest of this
		// replay changes the target set, so the rate threshold goes first.
		thold_api_thold_remove((int) alert_state('thold_write_bytes'));
		alert_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM thold_data WHERE id = ?', array((int) alert_state('thold_write_bytes'))) === 0, 'rated: the rate threshold is removed before the target set changes');
		alert_read(alert_osts(alert_write()));

		break;
	case 'calm':
		alert_quiet($step, array('fill_spread_pct', 'targets_missing', 'age'));
		alert_read(alert_osts(alert_write(), array(0 => array('used' => 90), 1 => array('used' => 10))));

		break;
	case 'imbalance':
		alert_check($step, 'fill_spread_pct', true, array(ALERT_NOTIFY));
		alert_quiet($step, array('targets_missing', 'age'));
		alert_read(alert_osts(alert_write()));

		break;
	case 'balanced':
		alert_check($step, 'fill_spread_pct', false, array(ALERT_RESTORE));
		alert_read(alert_osts(alert_write(), array(1 => null)));

		break;
	case 'lost':
		alert_check($step, 'targets_missing', true, array(ALERT_NOTIFY));
		alert_quiet($step, array('fill_spread_pct', 'age'));
		alert_read(alert_osts(alert_write()));

		break;
	case 'returned':
		alert_check($step, 'targets_missing', false, array(ALERT_RESTORE));
		// The OSS stops answering; the replay waits 40 seconds before polling.
		alert_read(null);

		break;
	case 'silent':
		alert_check($step, 'age', true, array(ALERT_NOTIFY));
		alert_expect(is_numeric(alert_thold('age')['lastread']), 'silent: age stays numeric while the node is silent (REQ-ALERT-003)');
		alert_read(alert_osts(alert_write()));

		break;
	case 'answering':
		alert_check($step, 'age', false, array(ALERT_RESTORE));

		// A one-off Maint schedule for the filesystem device, from a minute
		// ago to an hour from now, as Maint's schedule page saves it.
		db_execute_prepared("INSERT INTO plugin_maint_schedules (enabled, name, mtype, stime, etime, minterval) VALUES ('on', 'pfs replay', 1, ?, ?, 0)", array(time() - 60, time() + 3600));
		$schedule = (int) db_fetch_insert_id();
		db_execute_prepared('INSERT INTO plugin_maint_hosts (type, host, schedule) VALUES (1, ?, ?)', array((int) alert_state('host'), $schedule));
		alert_state('schedule', $schedule);
		include_once($config['base_path'] . '/plugins/maint/functions.php');
		alert_expect(plugin_maint_check_cacti_host((int) alert_state('host')), 'Maint reports the filesystem device in maintenance');
		alert_read(alert_osts(alert_write(), array(0 => array('used' => 90), 1 => array('used' => 10))));

		break;
	case 'maint':
	case 'maint2':
		alert_check($step, 'fill_spread_pct', true, array());

		$t = alert_thold('fill_spread_pct');
		[$at, $spread] = alert_rrd((int) db_fetch_cell_prepared('SELECT local_data_id FROM thold_data WHERE id = ?', array($t['id'])), 'fill_spread_pct');
		alert_expect((int) $t['thold_fail_count'] === 0 && $spread === '80' && $at >= (int) alert_state('maint_at'), "$step: in maintenance the breach is not counted or notified while the RRD still records it (fill_spread_pct=$spread at $at)");
		alert_state('maint_at', $at + 1);

		if ($step === 'maint2') {
			db_execute_prepared('DELETE FROM plugin_maint_hosts WHERE schedule = ?', array((int) alert_state('schedule')));
			db_execute_prepared('DELETE FROM plugin_maint_schedules WHERE id = ?', array((int) alert_state('schedule')));
		}

		alert_read(alert_osts(alert_write(), array(0 => array('used' => 90), 1 => array('used' => 10))));

		break;
	case 'unmaint':
		alert_check($step, 'fill_spread_pct', true, array(ALERT_NOTIFY));
		alert_read(alert_osts(alert_write()));

		break;
	case 'final':
		alert_check($step, 'fill_spread_pct', false, array(ALERT_RESTORE));
		alert_quiet($step, array('targets_missing', 'age'));

		foreach (db_fetch_assoc('SELECT l.id, l.status, l.current, t.data_source_name, l.description FROM plugin_thold_log l INNER JOIN thold_data t ON t.id = l.threshold_id ORDER BY l.id') as $row) {
			print 'EVIDENCE log ' . $row['id'] . ' ' . $row['data_source_name'] . ' status=' . $row['status'] . " current='" . $row['current'] . "' " . $row['description'] . PHP_EOL;
		}

		// Leave the registry as the setup found it, for the suites after
		// this one: no thresholds, filesystem, nodes, or health rows.
		foreach (db_fetch_assoc('SELECT id FROM thold_data') as $row) {
			thold_api_thold_remove((int) $row['id']);
		}

		$repo = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());

		foreach ($repo->all() as $old) {
			$repo->delete($old->id);
		}

		db_execute("DELETE FROM settings WHERE name LIKE 'pfs\\_alert\\_%'");

		break;
}

if ($step !== 'final') {
	alert_state('log', (int) db_fetch_cell('SELECT COALESCE(MAX(id), 0) FROM plugin_thold_log'));
}

exit($failures > 0 ? 1 : 0);
