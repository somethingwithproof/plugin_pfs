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
 * Parallel Filesystem Registry (Parallel Filesystem Admin realm). This file
 * must parse on PHP 8.0: Cacti may include it on an unsupported runtime, and
 * pfs_page_start() refuses there before any plugin class loads. Request
 * values are read through Cacti's request helpers and validated by the
 * input classes before anything is stored (REQ-UI-002). Nothing here
 * contacts a node.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/pfs/setup.php');

if (!pfs_page_start()) {
	exit;
}

pfs_require_post('pfs_admin.php', array('save_fs', 'delete_fs', 'save_node', 'delete_node', 'add_network', 'delete_network', 'accept_target', 'ignore_target'));

$pfs_clock       = new \Somethingwithproof\Pfs\SystemClock();
$pfs_backends    = \Somethingwithproof\Pfs\BackendRegistry::production();
$pfs_filesystems = new \Somethingwithproof\Pfs\FilesystemRepository(false, $pfs_clock);
$pfs_nodes       = new \Somethingwithproof\Pfs\NodeRepository(false, $pfs_clock);
$pfs_networks    = new \Somethingwithproof\Pfs\NetworkRepository(false, $pfs_clock);
$pfs_targets     = new \Somethingwithproof\Pfs\TargetRepository(false);
$pfs_pollers     = new \Somethingwithproof\Pfs\PollerRepository(false);
$pfs_page        = new \Somethingwithproof\Pfs\AdminPage($pfs_filesystems, $pfs_nodes, $pfs_networks, $pfs_targets, $pfs_backends, $pfs_pollers);
$pfs_action      = (string) get_nfilter_request_var('action');

/**
 * @param list<string> $fields
 * @return array<string, string>
 */
function pfs_request_fields(array $fields): array {
	$input = array();

	foreach ($fields as $field) {
		$input[$field] = (string) get_nfilter_request_var($field);
	}

	return $input;
}

switch ($pfs_action) {
	case 'save_fs':
		$pfs_id       = (int) get_filter_request_var('id');
		$pfs_input    = pfs_request_fields(array('name', 'backend', 'poller_id', 'expected_interval', 'enabled'));
		$pfs_existing = $pfs_id > 0 ? $pfs_filesystems->find($pfs_id) : null;

		if ($pfs_id > 0 && $pfs_existing === null) {
			header('Location: pfs_admin.php');
			exit;
		}

		$pfs_parsed = \Somethingwithproof\Pfs\FilesystemInput::parse($pfs_input, $pfs_id, $pfs_backends, $pfs_pollers->names());
		$pfs_errors = $pfs_parsed->errors;

		if ($pfs_parsed->filesystem !== null && $pfs_filesystems->nameTaken($pfs_parsed->filesystem->backend, $pfs_parsed->filesystem->name, $pfs_id)) {
			$pfs_errors['name'] = __('Name: another filesystem of this backend already uses this name.', 'pfs');
		}

		if ($pfs_errors !== array() || $pfs_parsed->filesystem === null) {
			top_header();
			$pfs_page->renderFilesystemEdit($pfs_existing, $pfs_input, $pfs_errors);
			bottom_footer();
			exit;
		}

		$pfs_saved = $pfs_filesystems->save($pfs_parsed->filesystem) > 0;
		raise_message('pfs_saved', $pfs_saved ? __('The filesystem was saved.', 'pfs') : __('The filesystem could not be saved.', 'pfs'), $pfs_saved ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);
		header('Location: pfs_admin.php');
		exit;
	case 'delete_fs':
		if ($pfs_filesystems->delete((int) get_filter_request_var('id'))) {
			raise_message('pfs_deleted', __('The filesystem, its nodes, and its inventory were deleted. No node was contacted.', 'pfs'), MESSAGE_LEVEL_INFO);
		}

		header('Location: pfs_admin.php');
		exit;
	case 'save_node':
		$pfs_id       = (int) get_filter_request_var('id');
		$pfs_input    = pfs_request_fields(array('filesystem_id', 'name', 'role', 'poller_id', 'endpoint', 'port', 'ssh_user', 'enabled'));
		$pfs_existing = $pfs_id > 0 ? $pfs_nodes->find($pfs_id) : null;

		if ($pfs_id > 0 && $pfs_existing === null) {
			header('Location: pfs_admin.php');
			exit;
		}

		$pfs_fs_map = array();

		foreach ($pfs_filesystems->all() as $pfs_fs) {
			$pfs_fs_map[$pfs_fs->id] = $pfs_fs;
		}

		$pfs_parsed = \Somethingwithproof\Pfs\NodeInput::parse($pfs_input, $pfs_id, $pfs_fs_map, $pfs_pollers->names(), new \Somethingwithproof\Pfs\SystemResolver(), $pfs_networks->policy());
		$pfs_errors = $pfs_parsed->errors;

		if ($pfs_parsed->node !== null && $pfs_nodes->nameTaken($pfs_parsed->node->name, $pfs_id)) {
			$pfs_errors['name'] = __('Name: another node already uses this name.', 'pfs');
		}

		if ($pfs_errors !== array() || $pfs_parsed->node === null) {
			top_header();
			$pfs_page->renderNodeEdit($pfs_existing, $pfs_input, $pfs_errors);
			bottom_footer();
			exit;
		}

		$pfs_saved = $pfs_nodes->save($pfs_parsed->node) > 0;
		raise_message('pfs_saved', $pfs_saved ? __('The node was saved.', 'pfs') : __('The node could not be saved.', 'pfs'), $pfs_saved ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);
		header('Location: pfs_admin.php');
		exit;
	case 'delete_node':
		if ($pfs_nodes->delete((int) get_filter_request_var('id'))) {
			raise_message('pfs_deleted', __('The node was deleted. Targets it reported keep their history.', 'pfs'), MESSAGE_LEVEL_INFO);
		}

		header('Location: pfs_admin.php');
		exit;
	case 'add_network':
		if (!$pfs_networks->add((int) get_filter_request_var('poller_id'), (string) get_nfilter_request_var('cidr'))) {
			raise_message('pfs_network', __('Enter a network as an address and prefix length, such as 10.20.0.0/16.', 'pfs'), MESSAGE_LEVEL_ERROR);
		}

		header('Location: pfs_admin.php');
		exit;
	case 'delete_network':
		$pfs_networks->delete((int) get_filter_request_var('id'));
		header('Location: pfs_admin.php');
		exit;
	case 'accept_target':
	case 'ignore_target':
		$pfs_targets->review((int) get_filter_request_var('id'), $pfs_action === 'accept_target' ? \Somethingwithproof\Pfs\Review::Accepted : \Somethingwithproof\Pfs\Review::Ignored);
		header('Location: pfs_admin.php');
		exit;
	case 'edit_fs':
		$pfs_id = (int) get_filter_request_var('id');

		top_header();
		$pfs_page->renderFilesystemEdit($pfs_id > 0 ? $pfs_filesystems->find($pfs_id) : null);
		bottom_footer();
		exit;
	case 'edit_node':
		$pfs_id = (int) get_filter_request_var('id');

		top_header();
		$pfs_page->renderNodeEdit($pfs_id > 0 ? $pfs_nodes->find($pfs_id) : null);
		bottom_footer();
		exit;
	case 'export':
		$pfs_info = plugin_pfs_version();

		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="pfs-registry.json"');
		header('X-Content-Type-Options: nosniff');
		print json_encode($pfs_page->export($pfs_info['version'] ?? '', $pfs_clock->now()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		exit;
	default:
		top_header();
		$pfs_page->renderList();
		bottom_footer();
}
