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
 * Cacti includes this file on every request, on whatever PHP version the site
 * runs. It must parse on PHP 8.0 (CI lints it there), and nothing under lib/
 * is loaded until pfs_boot() has confirmed PHP 8.2 or later (ADR 0006).
 * Every hook and lifecycle function therefore starts with that check, so an
 * unsupported runtime gets a refusal instead of a parse error that would take
 * down the whole Cacti UI (REQ-COMPAT-001 to REQ-COMPAT-003).
 */

function pfs_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

function pfs_boot(): bool {
	if (!pfs_php_supported()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Refuse with a message naming the required version.
 *
 * Used by the lifecycle functions only. Hook shims return silently instead,
 * because they run on every page and a refused plugin must not flood the log.
 */
function pfs_refuse_unsupported(): bool {
	if (pfs_php_supported()) {
		return false;
	}

	$message = __('The Parallel Filesystem plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'pfs');

	cacti_log('ERROR: ' . $message, false, 'PFS');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('pfs_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * @return array<string, string>
 */
function plugin_pfs_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

function plugin_pfs_install(): void {
	if (pfs_refuse_unsupported()) {
		return;
	}

	plugin_pfs_upgrade(true);
}

function plugin_pfs_check_config(): bool {
	if (pfs_refuse_unsupported()) {
		return false;
	}

	return plugin_pfs_upgrade(true);
}

/**
 * Common start after include/auth.php establishes the user. Recheck the
 * realm here because cookie and Basic login restore the user after the
 * alternate-realms hook. Disabled plugins and unsupported runtimes refuse.
 */
function pfs_page_start(): bool {
	if (pfs_boot() && api_plugin_is_enabled('pfs')) {
		if (pfs_page_allowed()) {
			return true;
		}
		http_response_code(403);

		return false;
	}

	top_header();

	html_start_box(__('Parallel Filesystems', 'pfs'), '100%', '', '3', 'center', '');

	print '<tr><td>' . html_escape(pfs_php_supported() ? __('The Parallel Filesystem plugin is not enabled.', 'pfs') : __('The Parallel Filesystem plugin requires PHP %s or later.', '8.2.0', 'pfs')) . '</td></tr>';

	html_end_box();

	bottom_footer();

	return false;
}

/**
 * State-changing actions are accepted only as POST (REQ-UI-005). A GET that
 * names one is sent back to the page without effect; csrf-magic checks the
 * token of every POST.
 *
 * @param list<string> $actions
 */
function pfs_require_post(string $page, array $actions): void {
	$action = (string) get_nfilter_request_var('action');

	if (in_array($action, $actions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Location: ' . $page);
		exit;
	}
}

function pfs_settings(): \Somethingwithproof\Pfs\Settings {
	return (new \Somethingwithproof\Pfs\SettingsRepository(false))->load();
}

/**
 * Bring the schema, hooks, and realms up to the code's version.
 *
 * Cacti 1.2.31 never calls this itself; check_config and the config_arrays
 * hook do. Fresh installs take the same path as upgrades. The version stored
 * in plugin_config changes only after every migration has succeeded, so an
 * interrupted run is finished by the next one (REQ-LIFE-002, REQ-LIFE-003).
 *
 * $grant gives newly registered realms to the current user. Only install and
 * check_config pass it: both run from the Plugins page, which needs plugin
 * management. A repair triggered from config_arrays can run for anyone who
 * opens a plugin page, so it must never grant anything.
 */
function plugin_pfs_upgrade(bool $grant = false): bool {
	if (!pfs_boot()) {
		return false;
	}

	$info      = plugin_pfs_version();
	$schema    = new \Somethingwithproof\Pfs\Schema(false);
	$templates = pfs_template_installer();

	// A template install that failed for this template version is retried
	// only from the Plugins page or the health page, not on every request.
	$templatesSettled = $templates->isCurrent() || ($templates->failure() !== '' && !$grant);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered() && $templatesSettled) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	$schema->trackTables();

	pfs_setup_hooks($grant);

	// A missing template only stops device provisioning, which the health
	// page reports; the plugin's own pages and reader still work.
	if (!$templates->isCurrent() && ($grant || $templates->failure() === '')) {
		$templates->install();
	}

	$schema->recordPluginVersion($info['version'], $info['author'], $info['homepage']);

	return true;
}

function pfs_template_installer(): \Somethingwithproof\Pfs\TemplateInstaller {
	global $config;

	return new \Somethingwithproof\Pfs\TemplateInstaller(__DIR__, $config['base_path'], new \Somethingwithproof\Pfs\CactiObjectRepository(false));
}

function pfs_provisioner(): \Somethingwithproof\Pfs\DeviceProvisioner {
	global $config;

	$clock = new \Somethingwithproof\Pfs\SystemClock();

	return new \Somethingwithproof\Pfs\DeviceProvisioner(
		new \Somethingwithproof\Pfs\FilesystemRepository(false, $clock),
		new \Somethingwithproof\Pfs\TargetRepository(false),
		new \Somethingwithproof\Pfs\CactiObjectRepository(false),
		\Somethingwithproof\Pfs\BackendRegistry::production(),
		$config['base_path'],
		new \Somethingwithproof\Pfs\CreatedDeviceRepository(false),
		$clock
	);
}

/**
 * The data query answerer used by scripts/ss_pfs_targets.php.
 */
function pfs_target_query(): \Somethingwithproof\Pfs\TargetQuery {
	$clock = new \Somethingwithproof\Pfs\SystemClock();

	return new \Somethingwithproof\Pfs\TargetQuery(
		new \Somethingwithproof\Pfs\FilesystemRepository(false, $clock),
		new \Somethingwithproof\Pfs\TargetRepository(false),
		new \Somethingwithproof\Pfs\ObservationRepository(false),
		\Somethingwithproof\Pfs\BackendRegistry::production(),
		$clock
	);
}

/**
 * The summary answerer used by scripts/ss_pfs_summary.php.
 */
function pfs_summary_script(): \Somethingwithproof\Pfs\SummaryScript {
	$clock = new \Somethingwithproof\Pfs\SystemClock();

	return new \Somethingwithproof\Pfs\SummaryScript(new \Somethingwithproof\Pfs\FilesystemRepository(false, $clock), new \Somethingwithproof\Pfs\SummaryRepository(false), $clock);
}

/**
 * Register hooks and realms (REQ-LIFE-001).
 *
 * Kept procedural and named *_setup_* because api_plugin_register_hook() and
 * api_plugin_register_realm() refuse callers whose function name does not
 * contain install, upgrade, or setup.
 */
function pfs_setup_hooks(bool $grant = false): void {
	global $config;

	// Registering a hook replicates plugin config, which needs core lib/poller.php.
	if (!function_exists('replicate_out')) {
		include_once($config['library_path'] . '/poller.php');
	}

	$enabled = (new \Somethingwithproof\Pfs\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Pfs\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('pfs', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Pfs\Pages::realms() as $realm) {
		api_plugin_register_realm('pfs', implode(',', $realm['files']), $realm['name'], $grant && $realm['files'] !== \Somethingwithproof\Pfs\Pages::OPERATOR);
	}
}

/**
 * Uninstall never contacts a storage node (REQ-LIFE-006). Core drops the
 * tracked plugin tables afterwards unless the administrator left "Keep
 * Plugin Tables on Uninstall" checked, which is the default. The registry
 * export on the admin page is the copy to take before clearing it.
 */
function plugin_pfs_uninstall(): void {
	if (!pfs_boot()) {
		include_once(__DIR__ . '/include/unbooted-uninstall.php');
		pfs_uninstall_unbooted();

		return;
	}

	$schema  = new \Somethingwithproof\Pfs\Schema(false);
	$objects = new \Somethingwithproof\Pfs\CactiObjectRepository(false);
	$record  = new \Somethingwithproof\Pfs\CreatedDeviceRepository(false);

	// Code replaced without the upgrade being run still has the record in
	// its old place; finish the pending migrations first, which never
	// contact a node.
	if ($schema->storedVersion() > 0 && $schema->storedVersion() < \Somethingwithproof\Pfs\Schema::VERSION) {
		if (!$schema->migrate()) {
			throw new \RuntimeException('Uninstall stopped because the created-device record could not be migrated.');
		}
	}

	// A remotely deleted Cacti device can remain as a soft-deleted host row.
	// Clear its mapping even when uninstall was not asked to delete devices.
	$filesystemRepository = new \Somethingwithproof\Pfs\FilesystemRepository(
		false,
		new \Somethingwithproof\Pfs\SystemClock()
	);
	$removedHosts          = array_values(array_filter(
		$filesystemRepository->mappedHostIds(),
		static fn (int $id): bool => $objects->deviceRemoved($id)
	));
	$filesystemRepository->unmapHosts($removedHosts);

	// Forget ownership only after mappings for confirmed-removed devices clear.
	$removedCreated = array_values(array_filter($record->ids(), static fn (int $id): bool => $objects->deviceRemoved($id)));
	$record->forget($removedCreated);

	if (pfs_settings()->deleteDevicesOnUninstall) {
		$created = $record->ids();

		pfs_uninstall_devices($created);
		$created = array_values(array_filter($created, static fn (int $id): bool => $objects->deviceRemoved($id)));

		// Core runs no hook of a disabled plugin, so device_remove did not
		// clear the mapping or the record; kept tables must not point at a
		// deleted device.
		$filesystemRepository->unmapHosts($created);
		$record->forget($created);
	}

	// Removes only the query files the plugin wrote and nobody changed. The
	// templates stay, and so do devices unless deleted above: they are the
	// site's.
	pfs_template_installer()->uninstall();

	if (pfs_settings()->keepTablesOnUninstall) {
		$schema->untrackTables();

		\Somethingwithproof\Pfs\Log::info('Uninstall kept the plugin tables as configured');
	} else {
		if ($schema->keepCreatedDevices()) {
			\Somethingwithproof\Pfs\Log::info('Uninstall dropped the plugin tables but kept the record of the devices the plugin created, which are still in Cacti');
		}

		$schema->forgetVersion();
	}
}

/**
 * Delete the devices the plugin created, through Cacti's own device delete
 * with their graphs and data sources (delete type 2, as the Devices page
 * offers). Cacti's RRD cleanup setting decides what happens to the RRD
 * files. The callers pass only recorded ids that still name a device, and
 * unmap and forget them after. Shared with the unbooted uninstall, so PHP
 * 8.0 syntax (REQ-LIFE-005).
 *
 * @param list<int> $hostIds existing devices recorded as created by the plugin
 */
function pfs_uninstall_devices(array $hostIds): void {
	global $config;

	if ($hostIds === array()) {
		return;
	}

	include_once($config['base_path'] . '/lib/api_device.php');
	include_once($config['base_path'] . '/lib/api_data_source.php');
	include_once($config['base_path'] . '/lib/api_graph.php');

	api_device_remove_multi($hostIds, 2);

	cacti_log(sprintf('NOTE: Uninstall deleted the %d devices the plugin created, as configured', count($hostIds)), false, 'PFS');
}

function pfs_config_arrays(): void {
	global $menu;

	if (!pfs_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Pfs\Pages::menu() as $file => $title) {
		$menu[__('Management')]['plugins/pfs/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Pfs\Pages::upgradeTriggers(), true)) {
		plugin_pfs_upgrade();
	}
}

function pfs_config_settings(): void {
	global $tabs, $settings;

	if (PHP_SAPI !== 'cli' && !api_plugin_user_realm_auth('pfs_admin.php')) {
		return;
	}

	if (!pfs_boot()) {
		return;
	}

	$tabs['pfs']     = __('Parallel Filesystems', 'pfs');
	$settings['pfs'] = \Somethingwithproof\Pfs\Settings::form();
}

/**
 * @param array<string, mixed> $nav
 * @return array<string, mixed>
 */
function pfs_draw_navigation_text($nav) {
	global $current_user;

	if (!pfs_boot()) {
		return $nav;
	}

	// Refresh settings after core restores cookie or Basic authentication.
	if (get_current_page() === 'settings.php' && (PHP_SAPI === 'cli' || ($current_user['enabled'] ?? '') === 'on')) {
		pfs_config_settings();
	}

	return array_merge($nav, \Somethingwithproof\Pfs\Pages::navigation());
}

/**
 * device_remove hook: a deleted device no longer carries a filesystem. The
 * filesystem, its nodes, its targets, and the reader's cache stay, and other
 * devices are untouched (REQ-DEV-004).
 *
 * @param mixed $hostIds
 * @return mixed
 */
function pfs_device_remove($hostIds) {
	if (!pfs_boot()) {
		return $hostIds;
	}

	$ids = array();

	foreach (is_array($hostIds) ? $hostIds : array($hostIds) as $id) {
		if (is_numeric($id) && (int) $id > 0) {
			$ids[] = (int) $id;
		}
	}

	$count = (new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock()))->unmapHosts($ids);
	// Core invokes this hook before deleting the host. Keep ownership
	// until uninstall can confirm deletion, including a remote soft delete.

	if ($count > 0) {
		\Somethingwithproof\Pfs\Log::info(sprintf('%d filesystems lost their device when it was deleted; they can be given a device again from the registry', $count));
	}

	return $hostIds;
}

/**
 * api_device_new hook, which Cacti runs after every device save: a mapped
 * device moved to another poller than its filesystem's is reported, not
 * changed back (REQ-DEV-003, REQ-DEV-004). The health page shows it.
 *
 * @param mixed $save
 * @return mixed
 */
function pfs_api_device_new($save) {
	if (!pfs_boot() || !is_array($save) || !isset($save['id']) || !is_numeric($save['id'])) {
		return $save;
	}

	$filesystem = (new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock()))->findByHost((int) $save['id']);
	$poller     = isset($save['poller_id']) && is_numeric($save['poller_id']) ? (int) $save['poller_id'] : 0;

	if ($filesystem !== null && $poller > 0 && $poller !== $filesystem->pollerId) {
		\Somethingwithproof\Pfs\Log::warning(sprintf('Device %d of filesystem %d is now polled by poller %d, but the filesystem is read by poller %d', (int) $save['id'], $filesystem->id, $poller, $filesystem->pollerId));
	}

	return $save;
}

/**
 * graph_buttons hook: link a filesystem device's graphs to the plugin's
 * view of the filesystem, for users with the view realm (REQ-GRAPH-004).
 *
 * @param mixed $data
 * @return mixed
 */
function pfs_graph_buttons($data) {
	global $config;

	if (!pfs_boot() || !api_plugin_is_enabled('pfs') || !api_plugin_user_realm_auth('pfs.php')) {
		return $data;
	}

	$payload = is_array($data) && isset($data[1]) && is_array($data[1]) ? $data[1] : array();
	$graphId = isset($payload['local_graph_id']) && is_numeric($payload['local_graph_id']) ? (int) $payload['local_graph_id'] : 0;
	$links   = new \Somethingwithproof\Pfs\GraphLinks(new \Somethingwithproof\Pfs\FilesystemRepository(false, new \Somethingwithproof\Pfs\SystemClock()), new \Somethingwithproof\Pfs\CactiObjectRepository(false));
	$fs      = $links->filesystemFor($graphId);

	if ($fs !== null) {
		print \Somethingwithproof\Pfs\GraphLinks::html($config['url_path'], $fs);
	}

	return $data;
}

/**
 * poller_bottom hook: start this poller's reader in the background
 * (REQ-READ-001). The reader itself handles only its own poller's nodes.
 * Cacti does not call the hook for a disabled plugin, so disabling the
 * plugin stops the reader (REQ-LIFE-004).
 */
function pfs_poller_bottom(): void {
	global $config;

	if (!pfs_boot()) {
		return;
	}

	if (!function_exists('exec_background')) {
		include_once($config['library_path'] . '/poller.php');
	}

	// exec_background() silently does nothing for a binary that does not
	// exist, so a bare 'php' fallback would never start the reader.
	$php = trim((string) read_config_option('path_php_binary'));

	if ($php === '' || !is_file($php) || !is_executable($php)) {
		cacti_log('ERROR: The parallel filesystem reader was not started because the PHP binary path setting is empty or not an executable file', false, 'PFS');

		return;
	}

	// One executable path and an argument array: release/1.2.31 does not
	// quote the path and 1.2.x does, and both escape each array element,
	// although core's docblock declares a string.
	// @phpstan-ignore argument.type
	exec_background($php, array('-q', __DIR__ . '/poller_pfs.php', '--poller=' . (int) $config['poller_id']));
}

/**
 * The production reader for one poller. Node access goes through the SSH
 * source only; nothing else in the plugin builds a node source.
 */
function pfs_reader(int $pollerId, \Somethingwithproof\Pfs\Settings $settings): \Somethingwithproof\Pfs\Reader {
	$clock    = new \Somethingwithproof\Pfs\SystemClock();
	$networks = new \Somethingwithproof\Pfs\NetworkRepository(false, $clock);
	$sources  = new \Somethingwithproof\Pfs\NodeSources();

	$sources->register(\Somethingwithproof\Pfs\AccessMethod::Ssh, new \Somethingwithproof\Pfs\SshNodeSource(new \Somethingwithproof\Pfs\ProcRunner(), $settings, new \Somethingwithproof\Pfs\SystemResolver(), $networks->policy()));

	return pfs_reader_with($pollerId, $sources, $clock);
}

/**
 * $holder names the run in the lease; each run gets a unique one unless a
 * test names it.
 */
function pfs_reader_with(int $pollerId, \Somethingwithproof\Pfs\NodeSources $sources, \Somethingwithproof\Pfs\Clock $clock, string $holder = ''): \Somethingwithproof\Pfs\Reader {
	return new \Somethingwithproof\Pfs\Reader(
		new \Somethingwithproof\Pfs\FilesystemRepository(false, $clock),
		new \Somethingwithproof\Pfs\NodeRepository(false, $clock),
		new \Somethingwithproof\Pfs\TargetRepository(false),
		new \Somethingwithproof\Pfs\ObservationRepository(false),
		new \Somethingwithproof\Pfs\NodeHealthRepository(false),
		new \Somethingwithproof\Pfs\ReaderRepository(false, $clock),
		new \Somethingwithproof\Pfs\CacheRepository(false),
		new \Somethingwithproof\Pfs\SummaryRepository(false),
		\Somethingwithproof\Pfs\BackendRegistry::production(),
		$sources,
		$clock,
		$holder !== '' ? $holder : 'poller' . $pollerId . '-' . getmypid() . '-' . bin2hex(random_bytes(4))
	);
}

/**
 * Whether Maint holds a device in maintenance, or null when Maint is not
 * enabled.
 */
function pfs_in_maintenance(int $host_id): ?bool {
	global $config;

	if ($host_id <= 0 || !api_plugin_is_enabled('maint') || !is_file($config['base_path'] . '/plugins/maint/functions.php')) {
		return null;
	}

	include_once($config['base_path'] . '/plugins/maint/functions.php');

	return function_exists('plugin_maint_check_cacti_host') ? (bool) plugin_maint_check_cacti_host($host_id) : null;
}

function pfs_casc(): \Somethingwithproof\Pfs\CascProvider {
	$clock = new \Somethingwithproof\Pfs\SystemClock();

	return new \Somethingwithproof\Pfs\CascProvider(
		new \Somethingwithproof\Pfs\SettingsRepository(false),
		new \Somethingwithproof\Pfs\FilesystemRepository(false, $clock),
		new \Somethingwithproof\Pfs\NodeRepository(false, $clock),
		new \Somethingwithproof\Pfs\NetworkRepository(false, $clock),
		new \Somethingwithproof\Pfs\TargetRepository(false),
		new \Somethingwithproof\Pfs\ObservationRepository(false),
		new \Somethingwithproof\Pfs\NodeHealthRepository(false),
		new \Somethingwithproof\Pfs\ReaderRepository(false, $clock),
		\Somethingwithproof\Pfs\BackendRegistry::production(),
		$clock,
		function (int $hostId): ?bool {
			return pfs_in_maintenance($hostId);
		},
	);
}

function pfs_ecosystem(): \Somethingwithproof\Pfs\EcosystemIntegration {
	$clock = new \Somethingwithproof\Pfs\SystemClock();

	return new \Somethingwithproof\Pfs\EcosystemIntegration(
		new \Somethingwithproof\Pfs\Schema(false),
		new \Somethingwithproof\Pfs\SettingsRepository(false),
		new \Somethingwithproof\Pfs\FilesystemRepository(false, $clock),
		new \Somethingwithproof\Pfs\NodeRepository(false, $clock),
		new \Somethingwithproof\Pfs\NetworkRepository(false, $clock),
		new \Somethingwithproof\Pfs\TargetRepository(false),
		new \Somethingwithproof\Pfs\ObservationRepository(false),
		new \Somethingwithproof\Pfs\NodeHealthRepository(false),
		new \Somethingwithproof\Pfs\ReaderRepository(false, $clock),
		\Somethingwithproof\Pfs\BackendRegistry::production(),
		$clock,
		function (int $userId): bool {
			return api_plugin_user_realm_auth('pfs.php');
		},
		function (int $userId, int $hostId): bool {
			return is_device_allowed($hostId, $userId);
		},
		function (int $hostId): ?bool {
			return pfs_in_maintenance($hostId);
		},
	);
}

/**
 * replicate_out hook: push plugin configuration tables scoped to the target
 * remote data collector (REQ-REMOTE-002).
 *
 * @param mixed $data
 * @return mixed
 */
function pfs_replicate_out($data) {
	if (!pfs_boot() || !is_array($data)) {
		return $data;
	}

	$pollerId = isset($data['remote_poller_id']) && is_numeric($data['remote_poller_id']) ? (int) $data['remote_poller_id'] : 0;
	$rcnnId   = $data['rcnn_id'] ?? false;

	if ($pollerId < 1 || !is_object($rcnnId) || !function_exists('replicate_out_table')) {
		return $data;
	}

	$repo = new \Somethingwithproof\Pfs\ReplicationRepository(false);

	foreach ($repo->tablesForPoller($pollerId) as $table => $rows) {
		replicate_out_table($rcnnId, $rows, $table, $pollerId);
	}

	return $data;
}

/**
 * remote_agent hook: serve read-only inventory and health actions on a remote
 * data collector (REQ-REMOTE-020, REQ-REMOTE-021).
 *
 * @param mixed $data
 * @return mixed
 */
function pfs_remote_agent($data) {
	if (!pfs_boot() || !is_array($data)) {
		return $data;
	}

	$action   = isset($data['action']) && is_string($data['action']) ? $data['action'] : '';
	$pollerId = isset($data['poller_id']) && is_numeric($data['poller_id']) ? max(0, (int) $data['poller_id']) : 0;
	$result   = pfs_ecosystem()->handleRemoteAgent($action, $pollerId);

	if ($result !== null) {
		$data['result'] = $result;
	}

	return $data;
}

/**
 * abilities_register hook: expose read-only PFS abilities gated by View realm.
 *
 * @param mixed $registrar
 * @return mixed
 */
function pfs_abilities_register($registrar) {
	if (!pfs_boot()) {
		return $registrar;
	}

	return pfs_ecosystem()->registerAbilities($registrar);
}

/**
 * doctor_register_checks hook: register PFS diagnostic checks with Cacti Doctor.
 *
 * @param mixed $checks
 * @return mixed
 */
function pfs_doctor_register_checks($checks) {
	if (!pfs_boot()) {
		return $checks;
	}

	$registered = pfs_ecosystem()->doctorChecks();

	if (is_array($checks)) {
		return array_merge($checks, $registered);
	}

	return $registered;
}

/**
 * casc_register_providers hook: register the PFS CasC provider.
 *
 * @param mixed $providers
 * @return mixed
 */
function pfs_casc_register_providers($providers) {
	if (!pfs_boot()) {
		return $providers;
	}

	$descriptor = pfs_ecosystem()->cascDescriptor();

	if (is_array($providers)) {
		$providers['pfs'] = $descriptor;

		return $providers;
	}

	return array('pfs' => $descriptor);
}

/** Resolve role inheritance against Cacti's current user and group grants. */
function pfs_auth_alternate_realms(): void {
	global $user_auth_realm_filenames;

	static $deferred = array();

	foreach ($deferred as $file => $realm) {
		$user_auth_realm_filenames[$file] = $realm;
	}
	$deferred = array();

	if (!api_plugin_is_enabled('pfs')) {
		return;
	}

	if (empty($_SESSION['sess_user_id'])) {
		// Core restores cookie and Basic authentication after this hook. Only
		// these entrypoints defer the realm check to their post-auth guard.
		$page = get_current_page();

		if (in_array($page, array('pfs.php', 'pfs_operator.php'), true) && isset($user_auth_realm_filenames[$page])) {
			$deferred[$page]                  = $user_auth_realm_filenames[$page];
			$user_auth_realm_filenames[$page] = -1;
		}

		return;
	}
	$operator = 'pfs_operator.php';
	$admin = 'pfs_admin.php';
	$files = array('pfs.php');
	$realm = $user_auth_realm_filenames[$admin] ?? 0;
	if ($realm > 0 && is_realm_allowed($realm)) {
		$files[] = $operator;
	} else {
		$realm = $user_auth_realm_filenames[$operator] ?? 0;
		if ($realm <= 0 || !is_realm_allowed($realm)) {
			return;
		}
	}
	foreach ($files as $file) {
		$user_auth_realm_filenames[$file] = $realm;
	}
}

/** Recheck the authenticated user before a plugin page reads data or acts. */
function pfs_page_allowed(): bool {
	global $current_user;

	pfs_auth_alternate_realms();
	$userId    = $current_user['id'] ?? null;
	$sessionId = $_SESSION['sess_user_id'] ?? null;

	return (is_int($userId) || (is_string($userId) && ctype_digit($userId)))
		&& (is_int($sessionId) || (is_string($sessionId) && ctype_digit($sessionId)))
		&& (int) $userId > 0 && (int) $userId === (int) $sessionId
		&& api_plugin_is_enabled('pfs')
		&& ($current_user['enabled'] ?? '') === 'on'
		&& api_plugin_user_realm_auth(get_current_page());
}
