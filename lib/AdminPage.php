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

namespace Somethingwithproof\Pfs;

/**
 * The registry page (Parallel Filesystem Admin): filesystems, nodes, the
 * networks each poller may reach, and targets awaiting review. Every action
 * that changes state is a POST form that csrf-magic signs (REQ-UI-005).
 * Every stored or node-supplied string is escaped where it is printed.
 * Nothing here contacts a node.
 */
final class AdminPage {
	public const FILE = 'pfs_admin.php';

	public function __construct(
		private readonly FilesystemRepository $filesystems,
		private readonly NodeRepository $nodes,
		private readonly NetworkRepository $networks,
		private readonly TargetRepository $targets,
		private readonly BackendRegistry $backends,
		private readonly PollerRepository $pollers,
	) {
	}

	/**
	 * html_start_box() prints its title as markup, so names in titles are
	 * escaped before they go in.
	 */
	public function renderList(): void {
		$filesystems = $this->filesystemMap();
		$pollers     = $this->pollers->names();
		$nodes       = $this->nodes->all();

		html_start_box(__('Filesystems', 'pfs'), '100%', '', '3', 'center', self::FILE . '?action=edit_fs');
		print Format::header([__('Name', 'pfs'), __('Backend', 'pfs'), __('Poller', 'pfs'), __('Expected Interval', 'pfs'), __('State', 'pfs'), __('Nodes', 'pfs'), __('Actions', 'pfs')]);

		if ($filesystems === []) {
			print '<tr><td colspan="7"><em>' . html_escape(__('No filesystems registered yet.', 'pfs')) . '</em></td></tr>';
		}

		foreach ($filesystems as $fs) {
			$count = count(array_filter($nodes, static fn (Node $n): bool => $n->filesystemId === $fs->id));

			print '<tr class="tableRow">'
				. '<td><a class="linkEditMain" href="' . html_escape(self::FILE . '?action=edit_fs&id=' . $fs->id) . '">' . html_escape($fs->name) . '</a></td>'
				. '<td>' . html_escape($this->backends->has($fs->backend) ? $this->backends->get($fs->backend)->label() : $fs->backend) . '</td>'
				. '<td>' . html_escape($pollers[$fs->pollerId] ?? (string) $fs->pollerId) . '</td>'
				. '<td>' . html_escape(__('%d seconds', $fs->interval, 'pfs')) . '</td>'
				. '<td>' . html_escape($fs->adminState->value) . '</td>'
				. '<td>' . $count . '</td>'
				. '<td>' . $this->actionForm('delete_fs', $fs->id, __('Delete', 'pfs')) . '</td>'
				. '</tr>';
		}

		html_end_box();

		html_start_box(__('Nodes', 'pfs'), '100%', '', '3', 'center', $filesystems === [] ? '' : self::FILE . '?action=edit_node');
		print Format::header([__('Name', 'pfs'), __('Filesystem', 'pfs'), __('Role', 'pfs'), __('Poller', 'pfs'), __('Endpoint', 'pfs'), __('Login Name', 'pfs'), __('Access', 'pfs'), __('State', 'pfs'), __('Actions', 'pfs')]);

		if ($nodes === []) {
			print '<tr><td colspan="9"><em>' . html_escape($filesystems === [] ? __('Register a filesystem first.', 'pfs') : __('No nodes registered yet.', 'pfs')) . '</em></td></tr>';
		}

		foreach ($nodes as $node) {
			print '<tr class="tableRow">'
				. '<td><a class="linkEditMain" href="' . html_escape(self::FILE . '?action=edit_node&id=' . $node->id) . '">' . html_escape($node->name) . '</a></td>'
				. '<td>' . html_escape($filesystems[$node->filesystemId]->name ?? '-') . '</td>'
				. '<td>' . html_escape($node->role->label()) . '</td>'
				. '<td>' . html_escape($pollers[$node->pollerId] ?? (string) $node->pollerId) . '</td>'
				. '<td>' . html_escape($node->endpoint . ':' . $node->port) . '</td>'
				. '<td>' . html_escape($node->user) . '</td>'
				. '<td>' . html_escape($node->accessMethod->label()) . '</td>'
				. '<td>' . html_escape($node->adminState->value) . '</td>'
				. '<td>' . $this->actionForm('delete_node', $node->id, __('Delete', 'pfs')) . '</td>'
				. '</tr>';
		}

		html_end_box();

		$this->renderNetworks($pollers);
		$this->renderProposals($filesystems);

		html_start_box(__('Export', 'pfs'), '100%', '', '3', 'center', '');
		print '<tr><td>' . html_escape(__('Download the registry (filesystems, nodes, networks, and target review) as JSON before an uninstall that drops the plugin tables. It holds no key or credential.', 'pfs')) . '</td>'
			. '<td><a class="ui-button" href="' . html_escape(self::FILE . '?action=export') . '">' . html_escape(__('Export', 'pfs')) . '</a></td></tr>';
		html_end_box();
	}

	/**
	 * @param array<string, string> $input  rejected form values, shown again
	 * @param array<string, string> $errors field => message
	 */
	public function renderFilesystemEdit(?Filesystem $fs, array $input = [], array $errors = []): void {
		$value = static fn (string $field, string $current): string => array_key_exists($field, $input) ? $input[$field] : $current;

		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box($fs === null ? __('New Filesystem', 'pfs') : __('Filesystem: %s', html_escape($fs->name), 'pfs'), '100%', '', '3', 'center', '');
		$this->errors($errors);

		$backends = array_map(static fn (Backend $b): string => $b->label(), $this->backends->all());
		$this->select('backend', __('Backend', 'pfs'), $backends, $value('backend', $fs->backend ?? (string) array_key_first($backends)));
		$this->text('name', __('Name', 'pfs'), $value('name', $fs->name ?? ''), 64);
		$this->select('poller_id', __('Poller', 'pfs'), $this->pollerOptions(), $value('poller_id', (string) ($fs->pollerId ?? 1)));
		$this->text('expected_interval', __('Expected Interval (seconds)', 'pfs'), $value('expected_interval', (string) ($fs->interval ?? Filesystem::DEFAULT_INTERVAL)), 5);
		$this->checkbox('enabled', __('Enabled', 'pfs'), array_key_exists('enabled', $input) ? Row::bool($input, 'enabled') : ($fs?->enabled() ?? true));

		html_end_box();
		$this->submit('save_fs', $fs->id ?? 0);
	}

	/**
	 * @param array<string, string> $input
	 * @param array<string, string> $errors
	 */
	public function renderNodeEdit(?Node $node, array $input = [], array $errors = []): void {
		$value = static fn (string $field, string $current): string => array_key_exists($field, $input) ? $input[$field] : $current;
		$roles = [];

		foreach (NodeRole::cases() as $role) {
			$roles[$role->value] = $role->label();
		}

		$filesystems = array_map(static fn (Filesystem $f): string => $f->name, $this->filesystemMap());

		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box($node === null ? __('New Node', 'pfs') : __('Node: %s', html_escape($node->name), 'pfs'), '100%', '', '3', 'center', '');
		$this->errors($errors);

		$this->select('filesystem_id', __('Filesystem', 'pfs'), $filesystems, $value('filesystem_id', (string) ($node->filesystemId ?? array_key_first($filesystems))));
		$this->text('name', __('Name', 'pfs'), $value('name', $node->name ?? ''), 64);
		$this->select('role', __('Role', 'pfs'), $roles, $value('role', $node->role->value ?? NodeRole::Oss->value));
		$this->select('poller_id', __('Poller', 'pfs'), $this->pollerOptions(), $value('poller_id', (string) ($node->pollerId ?? 1)));
		$this->text('endpoint', __('Endpoint (host name or address)', 'pfs'), $value('endpoint', $node->endpoint ?? ''), 253);
		$this->text('port', __('SSH Port', 'pfs'), $value('port', (string) ($node->port ?? 22)), 5);
		$this->text('ssh_user', __('Login Name', 'pfs'), $value('ssh_user', $node->user ?? 'pfsread'), 32);
		$this->checkbox('enabled', __('Enabled', 'pfs'), array_key_exists('enabled', $input) ? Row::bool($input, 'enabled') : ($node?->enabled() ?? true));

		print '<tr><td colspan="2"><small>' . html_escape(__('The node must list the reader key in authorized_keys for this login with a forced command running pfs-read.sh, for example: restrict,command="/usr/local/libexec/pfs-read.sh" followed by the public key. The host key must be in the known hosts file under the endpoint as written here.', 'pfs')) . '</small></td></tr>';

		html_end_box();
		$this->submit('save_node', $node->id ?? 0);
	}

	/**
	 * The registry as data for the export download.
	 *
	 * @return array<string, mixed>
	 */
	public function export(string $version, int $now): array {
		$filesystems = $this->filesystemMap();

		return [
			'plugin'      => 'pfs',
			'version'     => $version,
			'exported_at' => $now,
			'filesystems' => array_values(array_map(static fn (Filesystem $f): array => ['name' => $f->name, 'backend' => $f->backend, 'poller_id' => $f->pollerId, 'expected_interval' => $f->interval, 'admin_state' => $f->adminState->value], $filesystems)),
			'nodes'       => array_map(static fn (Node $n): array => ['name' => $n->name, 'filesystem' => $filesystems[$n->filesystemId]->name ?? '', 'role' => $n->role->value, 'poller_id' => $n->pollerId, 'endpoint' => $n->endpoint, 'port' => $n->port, 'login' => $n->user, 'access_method' => $n->accessMethod->value, 'admin_state' => $n->adminState->value], $this->nodes->all()),
			'networks'    => array_map(static fn (array $n): array => ['poller_id' => $n['poller_id'], 'cidr' => $n['cidr']], $this->networks->all()),
			'targets'     => array_map(static fn (Target $t): array => ['filesystem' => $filesystems[$t->filesystemId]->name ?? '', 'name' => $t->name(), 'review' => $t->review->value, 'presence' => $t->presence->value], $this->targets->all()),
		];
	}

	/**
	 * @param array<int, string> $pollers
	 */
	private function renderNetworks(array $pollers): void {
		html_start_box(__('Allowed Networks', 'pfs'), '100%', '', '3', 'center', '');
		print Format::header([__('Poller', 'pfs'), __('Network', 'pfs'), __('Actions', 'pfs')]);

		$networks = $this->networks->all();

		if ($networks === []) {
			print '<tr><td colspan="3"><em>' . html_escape(__('No networks allowed yet. A poller reaches no node until its networks are listed here.', 'pfs')) . '</em></td></tr>';
		}

		foreach ($networks as $network) {
			print '<tr class="tableRow"><td>' . html_escape($pollers[$network['poller_id']] ?? (string) $network['poller_id']) . '</td><td>' . html_escape($network['cidr']) . '</td><td>' . $this->actionForm('delete_network', $network['id'], __('Delete', 'pfs')) . '</td></tr>';
		}

		print '<tr><td colspan="3"><form method="post" action="' . html_escape(self::FILE) . '" style="display:inline">'
			. '<select name="poller_id">';

		foreach ($this->pollerOptions() as $id => $name) {
			print '<option value="' . html_escape($id) . '">' . html_escape($name) . '</option>';
		}

		print '</select> <input type="text" name="cidr" size="30" maxlength="64" placeholder="10.20.0.0/16"> '
			. '<input type="hidden" name="action" value="add_network">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Allow', 'pfs')) . '"></form></td></tr>';

		html_end_box();
	}

	/**
	 * Targets the readers found that nobody reviewed yet, with the number of
	 * values each would add as data sources in milestone 3 (REQ-INV-003).
	 *
	 * @param array<int, Filesystem> $filesystems
	 */
	private function renderProposals(array $filesystems): void {
		html_start_box(__('Targets Awaiting Review', 'pfs'), '100%', '', '3', 'center', '');
		print Format::header([__('Target', 'pfs'), __('Filesystem', 'pfs'), __('Presence', 'pfs'), __('Values It Adds', 'pfs'), __('Actions', 'pfs')]);

		$shown = 0;

		foreach ($this->targets->all() as $target) {
			$fs = $filesystems[$target->filesystemId] ?? null;

			if ($target->review !== Review::Proposed || $fs === null || !$this->backends->has($fs->backend)) {
				continue;
			}

			$shown++;
			print '<tr class="tableRow"><td>' . html_escape($target->name()) . '</td><td>' . html_escape($fs->name) . '</td><td>' . html_escape($target->presence->value) . '</td>'
				. '<td>' . $this->backends->valueCount($fs->backend, $target->targetId->type) . '</td>'
				. '<td>' . $this->actionForm('accept_target', $target->id, __('Accept', 'pfs')) . ' ' . $this->actionForm('ignore_target', $target->id, __('Ignore', 'pfs')) . '</td></tr>';
		}

		if ($shown === 0) {
			print '<tr><td colspan="5"><em>' . html_escape(__('No targets await review.', 'pfs')) . '</em></td></tr>';
		}

		html_end_box();
	}

	/**
	 * @return array<int, Filesystem>
	 */
	private function filesystemMap(): array {
		$out = [];

		foreach ($this->filesystems->all() as $fs) {
			$out[$fs->id] = $fs;
		}

		return $out;
	}

	/**
	 * @return array<int, string>
	 */
	private function pollerOptions(): array {
		return $this->pollers->names();
	}

	/**
	 * @param array<string, string> $errors
	 */
	private function errors(array $errors): void {
		foreach ($errors as $message) {
			print '<tr><td colspan="2" class="textError">' . html_escape($message) . '</td></tr>';
		}
	}

	private function submit(string $action, int $id): void {
		print '<input type="hidden" name="action" value="' . html_escape($action) . '">'
			. '<input type="hidden" name="id" value="' . $id . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Save', 'pfs')) . '">'
			. '</form>';
	}

	private function actionForm(string $action, int $id, string $label): string {
		return '<form method="post" action="' . html_escape(self::FILE) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="' . html_escape($action) . '">'
			. '<input type="hidden" name="id" value="' . $id . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape($label) . '">'
			. '</form>';
	}

	private function text(string $name, string $label, string $value, int $length): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td>'
			. '<td><input type="text" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="' . html_escape($value) . '" maxlength="' . $length . '" size="' . min(60, $length) . '"></td></tr>';
	}

	private function checkbox(string $name, string $label, bool $checked): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td><td><input type="checkbox" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="on"' . ($checked ? ' checked' : '') . '></td></tr>';
	}

	/**
	 * @param array<array-key, string> $options value => label
	 */
	private function select(string $name, string $label, array $options, string $current): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td><td><select id="' . html_escape($name) . '" name="' . html_escape($name) . '">';

		foreach ($options as $value => $text) {
			print '<option value="' . html_escape((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . html_escape($text) . '</option>';
		}

		print '</select></td></tr>';
	}
}
