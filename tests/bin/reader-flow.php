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
 * Reader scenarios against a real database, included by
 * tests/bin/lifecycle.php for the reader_flow action. Nodes answer from the
 * Lustre fixtures through FixtureNodeSource; nothing contacts a network.
 * Sets $result. Must parse on PHP 8.0 (it uses no 8.1 syntax itself).
 */

use Somethingwithproof\Pfs\AccessMethod;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\FixtureNodeSource;
use Somethingwithproof\Pfs\NodeRead;
use Somethingwithproof\Pfs\NodeSources;
use Somethingwithproof\Pfs\ReadError;
use Somethingwithproof\Pfs\Settings;

include_once($config['base_path'] . '/plugins/pfs/setup.php');
pfs_boot();

const FLOW_TIME = 1800000000;

$fixtures = $config['base_path'] . '/plugins/pfs/tests/fixtures/lustre/';
$oss      = (string) file_get_contents($fixtures . 'oss-2.15.txt');
$mds      = (string) file_get_contents($fixtures . 'mds-2.17.txt');

/**
 * The OSS fixture with only the sections whose path names $keep.
 */
function flow_only($output, $keep) {
	$body     = explode("\n", $output, 2)[1];
	$body     = substr($body, 0, strrpos($body, '#pfs-read end'));
	$sections = preg_split('/^(?=== )/m', $body, -1, PREG_SPLIT_NO_EMPTY);
	$out      = "#pfs-read 1\n";

	foreach ($sections as $section) {
		if (strpos(strtok($section, "\n"), $keep) !== false) {
			$out .= $section;
		}
	}

	return $out . "#pfs-read end\n";
}

function flow_run(FixedClock $clock, FixtureNodeSource $source, $budget = 240, $poller = 1) {
	$sources = new NodeSources();
	$sources->register(AccessMethod::Ssh, $source);
	$source->reads = array();

	$run = pfs_reader_with($poller, $sources, $clock)->run($poller, $budget, 4, Settings::DEFAULT_TIMEOUT);

	return array('total' => $run->nodesTotal, 'read' => $run->nodesRead, 'failed' => $run->nodesFailed, 'skipped' => $run->nodesSkipped, 'stopped' => $run->stoppedEarly, 'reads' => $source->reads);
}

function flow_targets() {
	$out = array();

	foreach (db_fetch_assoc('SELECT t.name, t.review, t.presence, t.condition_state, t.source_node_id, t.last_seen, n.name AS source FROM plugin_pfs_target t LEFT JOIN plugin_pfs_node n ON n.id = t.source_node_id ORDER BY t.name') as $row) {
		$out[$row['name']] = array('presence' => $row['presence'], 'condition' => $row['condition_state'], 'source' => $row['source'], 'last_seen' => (int) $row['last_seen'], 'review' => $row['review']);
	}

	return $out;
}

function flow_value($target, $metric) {
	$row = db_fetch_row_prepared('SELECT o.value, o.observed_at, o.continuity, o.epoch FROM plugin_pfs_observation o INNER JOIN plugin_pfs_target t ON t.id = o.target_id WHERE t.name = ? AND o.metric = ?', array($target, $metric));

	return $row === array() || $row === false ? null : array('value' => $row['value'] === null ? null : (float) $row['value'], 'observed_at' => (int) $row['observed_at'], 'continuity' => $row['continuity'], 'epoch' => (int) $row['epoch']);
}

function flow_health($node) {
	$row = db_fetch_row_prepared('SELECT h.outcome, h.error, h.last_attempt, h.last_success, h.version FROM plugin_pfs_node_health h INNER JOIN plugin_pfs_node n ON n.id = h.node_id WHERE n.name = ?', array($node));

	return is_array($row) ? $row : array();
}

db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('scratch', 'lustre', 1, 300, 'enabled')");
$fs = (int) db_fetch_insert_id();

$ids = array();

foreach (array(array('oss01', 'oss', 1, 'enabled'), array('oss02', 'oss', 1, 'enabled'), array('mds01', 'mds', 1, 'enabled'), array('oss09', 'oss', 2, 'enabled'), array('oss-off', 'oss', 1, 'disabled')) as $n) {
	db_execute_prepared("INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state) VALUES (?, ?, ?, ?, '10.20.0.1', 22, 'pfsread', 'ssh', ?)", array($fs, $n[0], $n[1], $n[2], $n[3]));
	$ids[$n[0]] = (int) db_fetch_insert_id();
}

$clock  = new FixedClock(FLOW_TIME);
$source = new FixtureNodeSource(array());
$result = array();

// 1. First run: oss01 and mds01 answer, oss02 times out.
$source->answer($ids['oss01'], $oss);
$source->answer($ids['oss02'], NodeRead::failed(ReadError::Timeout, 0, 30.0));
$source->answer($ids['mds01'], $mds);
$source->answer($ids['oss09'], $oss);
$result['run1']         = flow_run($clock, $source);
$result['run1_reads']   = array_map(function ($id) use ($ids) { return array_search($id, $ids, true); }, $result['run1']['reads']);
$result['targets1']     = flow_targets();
$result['values1']      = (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_pfs_observation');
$result['unknown1']     = flow_value('scratch-OST0001', 'write_ops');
$result['zero1']        = flow_value('scratch-OST0001', 'kbytes_avail');
$result['bytes1']       = flow_value('scratch-OST0001', 'read_bytes');
$result['health_oss02'] = flow_health('oss02');
$result['zeros_from_failure'] = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_pfs_observation WHERE source_node_id = ?', array($ids['oss02']));

// 2. A minute later nothing is due.
$clock->advance(60);
$result['run2'] = flow_run($clock, $source);

// 3. Failover: oss01 is down, oss02 now serves OST0001.
$clock->advance(240);
$source->answer($ids['oss01'], NodeRead::failed(ReadError::Unreachable));
$source->answer($ids['oss02'], flow_only($oss, 'OST0001'));
$source->answer($ids['mds01'], $mds);
$result['run3']     = flow_run($clock, $source);
$result['targets3'] = flow_targets();
$result['bytes3']   = flow_value('scratch-OST0001', 'read_bytes');
$result['ost0_3']   = flow_value('scratch-OST0000', 'kbytes_free');

// 4. Both report OST0001 in one run: conflict, values unknown.
$clock->advance(300);
$source->answer($ids['oss01'], $oss);
$result['run4']     = flow_run($clock, $source);
$result['targets4'] = flow_targets();
$result['free4']    = flow_value('scratch-OST0001', 'kbytes_free');

// 5. Every node answers and none reports OST0001: the conflict ends as
// missing, and its last values and times stay as they were.
$clock->advance(300);
$source->answer($ids['oss01'], flow_only($oss, 'OST0000'));
$source->answer($ids['oss02'], "#pfs-read 1\n#pfs-read end\n");
$result['run5']     = flow_run($clock, $source);
$result['targets5'] = flow_targets();
$result['free5']    = flow_value('scratch-OST0001', 'kbytes_free');

// 6. Another run holds the lease: nothing is read or written.
$clock->advance(300);
db_execute_prepared("UPDATE plugin_pfs_reader SET holder = 'other', expires_at = ? WHERE poller_id = 1", array(FLOW_TIME + 100000));
$before            = (int) db_fetch_cell('SELECT SUM(last_attempt) FROM plugin_pfs_node_health');
$result['run6']    = flow_run($clock, $source);
$result['health6'] = (int) db_fetch_cell('SELECT SUM(last_attempt) FROM plugin_pfs_node_health') === $before;
db_execute("UPDATE plugin_pfs_reader SET expires_at = 0 WHERE poller_id = 1");

// 7. No budget left: nothing is read, everything due is reported not reached.
$result['run7']         = flow_run($clock, $source, 0);
$result['health_skip7'] = flow_health('oss01');

// 8. An unframed answer (a key without the forced command) is a failure.
$clock->advance(300);
$source->answer($ids['oss01'], (string) file_get_contents($fixtures . 'unframed.txt'));
$source->answer($ids['oss02'], NodeRead::failed(ReadError::HostKey));
$result['run8']        = flow_run($clock, $source);
$result['health8']     = flow_health('oss01');
$result['ost0_8']      = flow_value('scratch-OST0000', 'kbytes_free');
$result['health8_oss02'] = flow_health('oss02');
$result['reader_rows'] = db_fetch_assoc('SELECT poller_id, last_completed, nodes_total, nodes_read, nodes_failed, nodes_skipped, stopped_early FROM plugin_pfs_reader ORDER BY poller_id');
