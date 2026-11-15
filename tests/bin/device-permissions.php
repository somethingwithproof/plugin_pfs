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
 * Cacti device permissions over HTTP (REQ-SEC-011, REQ-SEC-012). Sets up a
 * filesystem read from the fixture node output and given a device, plus an
 * unmapped filesystem, then logs in as:
 *
 *   admin       both realms: every filesystem, node names, graphs, data sources
 *   pfsscoped   View realm, device granted by Cacti: that filesystem only,
 *               no node names, its graphs, no data source pages
 *   pfsrevoked  View realm, device and graphs denied by Cacti: nothing
 *   pfsnorealm  Console and graphs, no plugin realm: no plugin page or link
 *   pfsadmin2   both realms, but Cacti grants only the "work" filesystem's
 *               device: the registry, export, and health page hide the
 *               other device's records, and every change to them is refused
 *
 * Graph and data source access is Cacti's own; the plugin adds no layer, so
 * this shows that the plugin's objects follow it. Disposable databases only:
 * it resets the admin password and adds users. Must parse on PHP 8.0.
 *
 *   PFS_TEST_DATABASE=disposable php plugins/pfs/tests/bin/device-permissions.php http://127.0.0.1:8080/cacti/
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/pfs/setup.php');

use Somethingwithproof\Pfs\AccessMethod;
use Somethingwithproof\Pfs\FixedClock;
use Somethingwithproof\Pfs\FixtureNodeSource;
use Somethingwithproof\Pfs\NodeSources;

if (getenv('PFS_TEST_DATABASE') !== 'disposable' || !isset($argv[1])) {
	fwrite(STDERR, "Set PFS_TEST_DATABASE=disposable and pass the Cacti URL\n");
	exit(2);
}

if (!pfs_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

$base     = rtrim($argv[1], '/') . '/plugins/pfs/';
$root     = rtrim($argv[1], '/') . '/';
$password = 'Pfs-' . bin2hex(random_bytes(8));
$failures = 0;

function dp_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function dp_http($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);

	return array((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), $body);
}

function dp_login($user) {
	global $root, $password;

	$jar = tempnam(sys_get_temp_dir(), 'pfsdp');
	list(, $login) = dp_http($root . 'index.php', $jar);
	$token = preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $login, $m) ? $m[1] : '';
	dp_http($root . 'index.php', $jar, array('__csrf_magic' => $token, 'action' => 'login', 'login_username' => $user, 'login_password' => $password));

	return $jar;
}

// The registry starts clean: earlier scripts leave filesystems behind.
$repo = new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock());

foreach ($repo->all() as $old) {
	$repo->delete($old->id);
}

db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('scratch', 'lustre', 1, 300, 'enabled')");
$fsId = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO plugin_pfs_node (filesystem_id, name, role, poller_id, endpoint, port, ssh_user, access_method, admin_state) VALUES (?, 'oss-probe-name', 'oss', 1, '10.20.0.1', 22, 'pfsread', 'ssh', 'enabled')", array($fsId));
$nodeId = (int) db_fetch_insert_id();
db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('orphan', 'lustre', 1, 300, 'enabled')");
$orphanId = (int) db_fetch_insert_id();
db_execute("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES ('work', 'lustre', 1, 300, 'enabled')");
$workId = (int) db_fetch_insert_id();

$source  = new FixtureNodeSource(array());
$sources = new NodeSources();
$source->answer($nodeId, (string) file_get_contents($config['base_path'] . '/plugins/pfs/tests/fixtures/lustre/oss-2.15.txt'));
$sources->register(AccessMethod::Ssh, $source);
pfs_reader_with(1, $sources, new FixedClock(time()))->run(1, 240, 4);

$hostId = pfs_provisioner()->create($repo->find($fsId));
$graph  = (int) db_fetch_cell_prepared('SELECT id FROM graph_local WHERE host_id = ? ORDER BY id LIMIT 1', array($hostId));
$ds     = (int) db_fetch_cell_prepared('SELECT id FROM data_local WHERE host_id = ? ORDER BY id LIMIT 1', array($hostId));
dp_expect($hostId > 0 && $graph > 0 && $ds > 0, 'the filesystem has a device with graphs and data sources');

$workHost = pfs_provisioner()->create($repo->find($workId));
dp_expect($workHost > 0, 'the second filesystem has its own device');

$view  = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'pfs' AND file = 'pfs.php'");
$admin = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'pfs' AND file LIKE 'pfs_admin.php%'");

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

// Realm 7 is View Graphs, 8 Console access, 27 the graph tools column
// where graph_buttons links appear. Policies: 1 allows all except the listed items, 2 denies all except them.
// Permission rows: type 1 graph, 3 device, 4 graph template. Cacti's default
// graph_auth_method (permissive) allows a graph when any of its graph,
// device, or template policies allows it.
$users = array(
	'pfsscoped'  => array('realms' => array(7, 8, 27, $view), 'policy' => 2, 'perms' => array(3 => $hostId)),
	'pfsrevoked' => array('realms' => array(7, 8, 27, $view), 'policy' => 2, 'perms' => array()),
	'pfsnorealm' => array('realms' => array(7, 8), 'policy' => 1, 'perms' => array()),
	'pfsadmin2'  => array('realms' => array(7, 8, 27, $view, $admin), 'policy' => 2, 'perms' => array(3 => $workHost)),
);

foreach ($users as $name => $spec) {
	$old = (int) db_fetch_cell_prepared('SELECT id FROM user_auth WHERE username = ?', array($name));
	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ?', array($old));
	db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ?', array($old));
	db_execute_prepared('DELETE FROM user_auth WHERE id = ?', array($old));
	db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES (?, ?, 0, ?, '', '', ?, 1, ?, ?, 'on')", array($name, password_hash($password, PASSWORD_DEFAULT), $name, $spec['policy'], $spec['policy'], $spec['policy']));
	$id = (int) db_fetch_insert_id();

	foreach ($spec['realms'] as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $id));
	}

	foreach ($spec['perms'] as $type => $item) {
		db_execute_prepared('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, ?)', array($id, $item, $type));
	}
}

// What each user should see.
$expect = array(
	'admin'      => array('scratch' => true, 'orphan' => true, 'node' => true, 'graph' => true, 'ds' => true, 'link' => true),
	'pfsscoped'  => array('scratch' => true, 'orphan' => false, 'node' => false, 'graph' => true, 'ds' => false, 'link' => true),
	'pfsrevoked' => array('scratch' => false, 'orphan' => false, 'node' => false, 'graph' => false, 'ds' => false, 'link' => false),
	'pfsnorealm' => array('scratch' => false, 'orphan' => false, 'node' => false, 'graph' => true, 'ds' => false, 'link' => false),
);

foreach ($expect as $user => $want) {
	$jar = dp_login($user);

	list(, $page) = dp_http($base . 'pfs.php', $jar);
	dp_expect((strpos($page, 'Filesystem scratch') !== false) === $want['scratch'], "$user " . ($want['scratch'] ? 'sees' : 'does not see') . ' the filesystem with a device on the view page');
	dp_expect((strpos($page, 'Filesystem orphan') !== false) === $want['orphan'], "$user " . ($want['orphan'] ? 'sees' : 'does not see') . ' the filesystem with no device');
	dp_expect((strpos($page, 'oss-probe-name') !== false) === $want['node'], "$user " . ($want['node'] ? 'sees' : 'does not see') . ' node names');

	list($url, $body) = dp_http($root . 'graph.php?action=view&rra_id=all&local_graph_id=' . $graph, $jar);
	$shown = strpos($url, 'permission_denied') === false && strpos($body, "id='wrapper_" . $graph . "'") !== false;
	dp_expect($shown === $want['graph'], "$user " . ($want['graph'] ? 'may' : 'may not') . ' view the filesystem graph');
	dp_expect((strpos($body, 'plugins/pfs/pfs.php#pfs_graphs_' . $fsId) !== false) === $want['link'], "$user " . ($want['link'] ? 'gets' : 'does not get') . ' the graph link to the filesystem view');

	list($url, $body) = dp_http($root . 'data_sources.php?action=ds_edit&id=' . $ds, $jar);
	$shown = strpos($url, 'permission_denied') === false && strpos($body, 'Parallel Filesystem Summary') !== false;
	dp_expect($shown === $want['ds'], "$user " . ($want['ds'] ? 'may' : 'may not') . ' edit the filesystem data source');

	if (preg_match('/(Fatal error|Parse error|Uncaught)/', $page . $body) === 1) {
		dp_expect(false, "$user pages render without PHP errors");
	}

	unlink($jar);
}

// A plugin administrator whom Cacti grants only the work device.
function dp_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $m) ? $m[1] : '';
}

$target   = (int) db_fetch_cell_prepared("SELECT id FROM plugin_pfs_target WHERE filesystem_id = ? ORDER BY id LIMIT 1", array($fsId));
$review   = (string) db_fetch_cell_prepared('SELECT review FROM plugin_pfs_target WHERE id = ?', array($target));
$fsState  = function () use ($fsId, $orphanId, $nodeId, $target) {
	return array(
		'scratch' => db_fetch_row_prepared('SELECT name, host_id, poller_id FROM plugin_pfs_filesystem WHERE id = ?', array($fsId)),
		'orphan'  => (int) db_fetch_cell_prepared('SELECT host_id FROM plugin_pfs_filesystem WHERE id = ?', array($orphanId)),
		'node'    => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_pfs_node WHERE id = ?', array($nodeId)),
		'review'  => (string) db_fetch_cell_prepared('SELECT review FROM plugin_pfs_target WHERE id = ?', array($target)),
	);
};
$before = $fsState();
$jar    = dp_login('pfsadmin2');

list(, $list) = dp_http($base . 'pfs_admin.php', $jar);
dp_expect(strpos($list, 'action=edit_fs&amp;id=' . $fsId . '"') === false && strpos($list, 'oss-probe-name') === false && strpos($list, 'action=edit_fs&amp;id=' . $workId . '"') !== false && strpos($list, 'action=edit_fs&amp;id=' . $orphanId . '"') !== false, 'a restricted administrator\'s registry lists only filesystems on allowed devices, and unmapped ones');

list(, $export) = dp_http($base . 'pfs_admin.php?action=export', $jar);
$data = json_decode($export, true);
dp_expect(is_array($data) && !in_array('scratch', array_column($data['filesystems'], 'name'), true) && $data['nodes'] === array() && $data['targets'] === array(), 'a restricted administrator\'s export leaves out records on denied devices');

list(, $health) = dp_http($base . 'pfs_health.php', $jar);
dp_expect(strpos($health, 'Parallel Filesystem Warnings') !== false && strpos($health, 'oss-probe-name') === false, 'a restricted administrator\'s health page leaves out nodes on denied devices');

list(, $edit) = dp_http($base . 'pfs_admin.php?action=edit_fs&id=' . $fsId, $jar);
dp_expect(strpos($edit, 'value="scratch"') === false && strpos($edit, 'do not have permission for') !== false, 'a restricted administrator cannot open a filesystem on a denied device');

list(, $edit) = dp_http($base . 'pfs_admin.php?action=edit_node&id=' . $nodeId, $jar);
dp_expect(strpos($edit, 'oss-probe-name') === false, 'a restricted administrator cannot open a node on a denied device');

$posts = array(
	'save_fs'       => array('id' => $fsId, 'backend' => 'lustre', 'name' => 'renamed', 'poller_id' => 1, 'expected_interval' => 600, 'enabled' => 'on'),
	'delete_fs'     => array('id' => $fsId),
	'delete_node'   => array('id' => $nodeId),
	'ignore_target' => array('id' => $target),
	'unmap_device'  => array('id' => $fsId),
	'save_device'   => array('id' => $orphanId, 'host_id' => $hostId),
	'save_node'     => array('id' => 0, 'filesystem_id' => $fsId, 'name' => 'oss-intruder', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.20.0.9', 'port' => 22, 'ssh_user' => 'pfsread', 'enabled' => 'on'),
);

foreach ($posts as $action => $fields) {
	list(, $page) = dp_http($base . 'pfs_admin.php', $jar);
	dp_http($base . 'pfs_admin.php', $jar, array('__csrf_magic' => dp_token($page), 'action' => $action) + $fields);
}

$after = $fsState();
dp_expect($after === $before && $review !== '' && (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_pfs_node WHERE name = 'oss-intruder'") === 0, 'every change a restricted administrator posts to records on a denied device is refused: ' . json_encode(array($before, $after)));

list(, $page) = dp_http($base . 'pfs_admin.php', $jar);
dp_http($base . 'pfs_admin.php', $jar, array('__csrf_magic' => dp_token($page), 'action' => 'unmap_device', 'id' => $workId));
dp_expect((int) db_fetch_cell_prepared('SELECT host_id FROM plugin_pfs_filesystem WHERE id = ?', array($workId)) === 0, 'the same administrator can still change the filesystem on its allowed device');
unlink($jar);

// A device permission revoked between two requests applies to the next one.
$scoped = (int) db_fetch_cell("SELECT id FROM user_auth WHERE username = 'pfsscoped'");
$jar    = dp_login('pfsscoped');
db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ?', array($scoped));
list(, $page) = dp_http($base . 'pfs.php', $jar);
dp_expect(strpos($page, 'Filesystem scratch') === false, 'a device permission revoked during a session hides the filesystem on the next request');
unlink($jar);

exit($failures > 0 ? 1 : 0);
