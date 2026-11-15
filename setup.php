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
 * Common start of every page script, after include/auth.php has authorized
 * the realm. Refuses on an unsupported runtime or a disabled plugin, because
 * Cacti keeps plugin realms registered while a plugin is disabled.
 */
function pfs_page_start(): bool {
	if (pfs_boot() && api_plugin_is_enabled('pfs')) {
		return true;
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

	$info   = plugin_pfs_version();
	$schema = new \Somethingwithproof\Pfs\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	$schema->trackTables();

	pfs_setup_hooks($grant);

	$schema->recordPluginVersion($info['version'], $info['author'], $info['homepage']);

	return true;
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

	foreach (\Somethingwithproof\Pfs\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('pfs', $hook, $function, 'setup.php');
	}

	foreach (\Somethingwithproof\Pfs\Pages::realms() as $realm) {
		api_plugin_register_realm('pfs', implode(',', $realm['files']), $realm['name'], $grant);
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

	$schema = new \Somethingwithproof\Pfs\Schema(false);

	if (pfs_settings()->keepTablesOnUninstall) {
		$schema->untrackTables();

		\Somethingwithproof\Pfs\Log::info('Uninstall kept the plugin tables as configured');
	} else {
		$schema->forgetVersion();
	}
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
	if (!pfs_boot()) {
		return $nav;
	}

	return array_merge($nav, \Somethingwithproof\Pfs\Pages::navigation());
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
		\Somethingwithproof\Pfs\BackendRegistry::production(),
		$sources,
		$clock,
		$holder !== '' ? $holder : 'poller' . $pollerId . '-' . getmypid() . '-' . bin2hex(random_bytes(4))
	);
}
