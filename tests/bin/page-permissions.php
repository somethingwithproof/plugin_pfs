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
 * Page permissions and POST-only actions over HTTP. Logs in to a running
 * Cacti as three users and checks each plugin page:
 *
 *   admin    View and Admin realms: all three pages load
 *   viewer   Console and the View realm: pfs.php loads, the registry and
 *            health pages are denied
 *   norealm  Console only: every page is denied
 *
 * Then shows that stored names are escaped, that a delete sent as GET or as
 * POST without the csrf-magic token has no effect while a proper POST works,
 * that a node is refused until its network is allowed, that the export holds
 * no key path, and that no page view read a node.
 * Disposable databases only: it resets the admin password and adds users.
 * Must parse on PHP 8.0.
 *
 *   PFS_TEST_DATABASE=disposable php plugins/pfs/tests/bin/page-permissions.php http://127.0.0.1:8080/cacti/
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('PFS_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set PFS_TEST_DATABASE=disposable\n");
	exit(2);
}

$base     = rtrim($argv[1], '/') . '/plugins/pfs/';
$root     = rtrim($argv[1], '/') . '/';
$password = 'Pfs-' . bin2hex(random_bytes(8));
$failures = 0;

$view  = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'pfs' AND file = 'pfs.php'");
$admin = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'pfs' AND file LIKE 'pfs_admin.php%'");

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

// Realm 8 is Console access.
foreach (array('viewer' => array(8, $view), 'norealm' => array(8)) as $name => $realms) {
	db_execute_prepared('DELETE FROM user_auth WHERE username = ?', array($name));
	db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES (?, ?, 0, ?, '', '', 1, 1, 1, 1, 'on')", array($name, password_hash($password, PASSWORD_DEFAULT), $name));
	$id = (int) db_fetch_insert_id();

	foreach ($realms as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $id));
	}
}

function perm_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function perm_http($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);

	return array((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body);
}

function perm_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $m) ? $m[1] : '';
}

function perm_login($user) {
	global $root, $password;

	$jar = tempnam(sys_get_temp_dir(), 'pfsperm');
	list(, $login) = perm_http($root . 'index.php', $jar);
	perm_http($root . 'index.php', $jar, array('__csrf_magic' => perm_token($login), 'action' => 'login', 'login_username' => $user, 'login_password' => $password));

	return $jar;
}

// Markers that only the rendered plugin page contains.
$pages = array('pfs.php' => 'No targets found yet', 'pfs_admin.php' => 'Allowed Networks', 'pfs_health.php' => 'Parallel Filesystem Warnings');

// Names are validated on save; this row is stored directly, as a hostile
// database edit would, to show every page escapes what it prints.
db_execute_prepared("INSERT INTO plugin_pfs_filesystem (name, backend, poller_id, expected_interval, admin_state) VALUES (?, 'lustre', 1, 300, 'disabled')", array('<b id="pfsprobe">'));
$probe = (int) db_fetch_insert_id();

$access = array(
	'admin'   => array('pfs.php' => true, 'pfs_admin.php' => true, 'pfs_health.php' => true),
	'viewer'  => array('pfs.php' => true, 'pfs_admin.php' => false, 'pfs_health.php' => false),
	'norealm' => array('pfs.php' => false, 'pfs_admin.php' => false, 'pfs_health.php' => false),
);

foreach ($access as $user => $expect) {
	$jar = perm_login($user);

	foreach ($expect as $page => $allowed) {
		list($code, $body) = perm_http($base . $page, $jar);
		$shown = strpos($body, $pages[$page]) !== false;

		perm_expect($shown === $allowed && preg_match('/(Fatal error|Parse error|Warning:|Uncaught)/', $body) !== 1, "$user " . ($allowed ? 'sees' : 'is denied') . " $page (HTTP $code)");
	}

	unlink($jar);
}

$jar = perm_login('admin');
$exists = function () use ($probe) {
	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_pfs_filesystem WHERE id = ?', array($probe)) === 1;
};

foreach (array('pfs.php', 'pfs_admin.php', 'pfs_admin.php?action=edit_fs&id=' . $probe, 'pfs_admin.php?action=edit_node') as $page) {
	list(, $body) = perm_http($base . $page, $jar);
	perm_expect(strpos($body, '<b id="pfsprobe">') === false && strpos($body, '&lt;b id=&quot;pfsprobe&quot;&gt;') !== false, "$page escapes the stored name");
}

perm_http($base . 'pfs_admin.php?action=delete_fs&id=' . $probe, $jar);
perm_expect($exists(), 'a delete sent as GET has no effect');

perm_http($base . 'pfs_admin.php', $jar, array('action' => 'delete_fs', 'id' => $probe));
perm_expect($exists(), 'a delete POST without the csrf-magic token has no effect');

list(, $list) = perm_http($base . 'pfs_admin.php', $jar);
perm_http($base . 'pfs_admin.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'delete_fs', 'id' => $probe));
perm_expect(!$exists(), 'the same delete as a POST with the token removes the filesystem');

// A filesystem and a node through the forms; the node is refused until its
// network is allowed for the poller.
list(, $form) = perm_http($base . 'pfs_admin.php?action=edit_fs', $jar);
list($code, $saved) = perm_http($base . 'pfs_admin.php', $jar, array('__csrf_magic' => perm_token($form), 'action' => 'save_fs', 'id' => 0, 'backend' => 'lustre', 'name' => 'scratch', 'poller_id' => 1, 'expected_interval' => 300, 'enabled' => 'on'));
$fs = (int) db_fetch_cell("SELECT id FROM plugin_pfs_filesystem WHERE name = 'scratch'");
perm_expect($code === 200 && strpos($saved, 'The filesystem was saved.') !== false && $fs > 0, 'a filesystem saves through the form');

$node = array('action' => 'save_node', 'id' => 0, 'filesystem_id' => $fs, 'name' => 'oss01', 'role' => 'oss', 'poller_id' => 1, 'endpoint' => '10.20.0.11', 'port' => 22, 'ssh_user' => 'pfsread', 'enabled' => 'on');

list(, $form) = perm_http($base . 'pfs_admin.php?action=edit_node', $jar);
list(, $refused) = perm_http($base . 'pfs_admin.php', $jar, array('__csrf_magic' => perm_token($form)) + $node);
perm_expect(strpos($refused, 'not inside the networks allowed') !== false && (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_pfs_node') === 0, 'a node outside the allowed networks is refused');

list(, $list) = perm_http($base . 'pfs_admin.php', $jar);
perm_http($base . 'pfs_admin.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'add_network', 'poller_id' => 1, 'cidr' => '10.20.0.0/16'));
list(, $form) = perm_http($base . 'pfs_admin.php?action=edit_node', $jar);
list(, $saved) = perm_http($base . 'pfs_admin.php', $jar, array('__csrf_magic' => perm_token($form)) + $node);
perm_expect(strpos($saved, 'The node was saved.') !== false && (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_pfs_node') === 1, 'the node saves once its network is allowed');

$keyPath = '/etc/cacti/pfs/probe-' . bin2hex(random_bytes(4));
db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('pfs_ssh_key_file', ?)", array($keyPath));
list($code, $export) = perm_http($base . 'pfs_admin.php?action=export', $jar);
$data = json_decode($export, true);
perm_expect($code === 200 && is_array($data) && count($data['nodes']) === 1 && $data['networks'][0]['cidr'] === '10.20.0.0/16' && strpos($export, $keyPath) === false, 'the export holds the registry and no key path');
db_execute("DELETE FROM settings WHERE name = 'pfs_ssh_key_file'");

perm_expect((int) db_fetch_cell('SELECT COUNT(*) FROM plugin_pfs_node_health') === 0 && (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_pfs_reader') <= 1, 'no page view read a node');

unlink($jar);

exit($failures > 0 ? 1 : 0);
