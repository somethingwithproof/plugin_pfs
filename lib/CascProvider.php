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
 * Configuration-as-Code (CasC) and operator health provider for plugin_pfs.
 *
 * Supports deterministic YAML and XML export, validation, diff planning with
 * SHA-256 confirmation hash, fail-closed apply, and structured health and
 * imbalance reporting. Uses zero external Composer runtime dependencies.
 */
final class CascProvider {
	public const SCHEMA_VERSION = 1;
	public const FORMATS        = ['yaml', 'xml'];

	/**
	 * @param ?\Closure(int): ?bool $inMaintenance optional Maint callback by host_id
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly FilesystemRepository $filesystems,
		private readonly NodeRepository $nodes,
		private readonly NetworkRepository $networks,
		private readonly TargetRepository $targets,
		private readonly ObservationRepository $observations,
		private readonly NodeHealthRepository $nodeHealth,
		private readonly ReaderRepository $runs,
		private readonly BackendRegistry $backends,
		private readonly Clock $clock,
		private readonly ?\Closure $inMaintenance = null,
	) {
	}

	/**
	 * Current plugin configuration as a canonical array (never exports secrets or key contents).
	 *
	 * @return array{
	 *   schema_version: int,
	 *   plugin: string,
	 *   settings: array<string, int|bool|string>,
	 *   filesystems: list<array{name: string, backend: string, poller_id: int, expected_interval: int, admin_state: string}>,
	 *   networks: list<array{poller_id: int, cidr: string}>
	 * }
	 */
	public function snapshot(): array {
		$current     = $this->settings->load();
		$filesystems = [];

		foreach ($this->filesystems->all() as $fs) {
			$filesystems[] = [
				'name'              => $fs->name,
				'backend'           => $fs->backend,
				'poller_id'         => $fs->pollerId,
				'expected_interval' => $fs->interval,
				'admin_state'       => $fs->adminState->value,
			];
		}

		usort($filesystems, static fn (array $a, array $b): int => [$a['backend'], $a['name']] <=> [$b['backend'], $b['name']]);

		$networks = [];

		foreach ($this->networks->all() as $net) {
			$networks[] = [
				'poller_id' => $net['poller_id'],
				'cidr'      => $net['cidr'],
			];
		}

		usort($networks, static fn (array $a, array $b): int => [$a['poller_id'], $a['cidr']] <=> [$b['poller_id'], $b['cidr']]);

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'plugin'         => 'pfs',
			'settings'       => [
				'pfs_reader_budget'            => $current->readerBudget,
				'pfs_concurrency'              => $current->concurrency,
				'pfs_read_timeout'             => $current->readTimeout,
				'pfs_max_response'             => $current->maxResponseKib,
				'pfs_uninstall_keep_tables'    => $current->keepTablesOnUninstall,
				'pfs_uninstall_delete_devices' => $current->deleteDevicesOnUninstall,
			],
			'filesystems' => $filesystems,
			'networks'    => $networks,
		];
	}

	public function export(string $format = 'yaml'): string {
		$format = strtolower(trim($format));
		$data   = $this->snapshot();

		return match ($format) {
			'yaml'  => self::encodeYaml($data),
			'xml'   => self::encodeXml($data),
			default => throw new \InvalidArgumentException('Unsupported export format: must be yaml or xml'),
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function parse(string $content, string $format = 'yaml'): array {
		$format = strtolower(trim($format));

		return match ($format) {
			'yaml'  => self::decodeYaml($content),
			'xml'   => self::decodeXml($content),
			default => throw new \InvalidArgumentException('Unsupported format: must be yaml or xml'),
		};
	}

	/**
	 * @param array<string, mixed> $desired
	 *
	 * @return list<string>
	 */
	public function validate(array $desired): array {
		$errors = [];

		if (($desired['plugin'] ?? null) !== 'pfs') {
			$errors[] = 'plugin must be "pfs"';
		}

		if (!is_int($desired['schema_version'] ?? null) || $desired['schema_version'] !== self::SCHEMA_VERSION) {
			$errors[] = sprintf('schema_version must be %d', self::SCHEMA_VERSION);
		}

		$allowedSettings = [
			'pfs_reader_budget',
			'pfs_concurrency',
			'pfs_read_timeout',
			'pfs_max_response',
			'pfs_uninstall_keep_tables',
			'pfs_uninstall_delete_devices',
		];

		if (isset($desired['settings'])) {
			if (!is_array($desired['settings'])) {
				$errors[] = 'settings must be a mapping';
			} else {
				foreach ($desired['settings'] as $name => $value) {
					$key = (string) $name;

					if (!in_array($key, $allowedSettings, true)) {
						$errors[] = sprintf('unknown or unmanaged setting "%s"', $key);

						continue;
					}

					if ($key === 'pfs_reader_budget' && (!is_int($value) || $value < 10 || $value > 3600)) {
						$errors[] = 'pfs_reader_budget must be an integer between 10 and 3600';
					}

					if ($key === 'pfs_concurrency' && (!is_int($value) || $value < 1 || $value > 64)) {
						$errors[] = 'pfs_concurrency must be an integer between 1 and 64';
					}

					if ($key === 'pfs_read_timeout' && (!is_int($value) || $value < 2 || $value > 120)) {
						$errors[] = 'pfs_read_timeout must be an integer between 2 and 120';
					}

					if ($key === 'pfs_max_response' && (!is_int($value) || $value < 64 || $value > 16384)) {
						$errors[] = 'pfs_max_response must be an integer between 64 and 16384';
					}

					if (($key === 'pfs_uninstall_keep_tables' || $key === 'pfs_uninstall_delete_devices') && !is_bool($value)) {
						$errors[] = sprintf('%s must be a boolean', $key);
					}
				}
			}
		}

		if (isset($desired['filesystems'])) {
			if (!is_array($desired['filesystems']) || !array_is_list($desired['filesystems'])) {
				$errors[] = 'filesystems must be a list';
			} else {
				$seenFs = [];

				foreach ($desired['filesystems'] as $i => $fs) {
					if (!is_array($fs)) {
						$errors[] = sprintf('filesystems[%d] must be a mapping', $i);

						continue;
					}

					$backend = $fs['backend'] ?? null;
					$name    = $fs['name'] ?? null;

					if (!is_string($backend) || !$this->backends->has($backend)) {
						$errors[] = sprintf('filesystems[%d].backend is not a registered backend', $i);
					} elseif (!is_string($name) || preg_match($this->backends->get($backend)->filesystemPattern(), $name) !== 1) {
						$errors[] = sprintf('filesystems[%d].name does not match backend pattern', $i);
					} else {
						$pair = $backend . '/' . $name;

						if (isset($seenFs[$pair])) {
							$errors[] = sprintf('filesystems[%d] duplicates %s', $i, $pair);
						}

						$seenFs[$pair] = true;
					}

					$poller = $fs['poller_id'] ?? null;

					if (!is_int($poller) || $poller < 1) {
						$errors[] = sprintf('filesystems[%d].poller_id must be a positive integer', $i);
					}

					$interval = $fs['expected_interval'] ?? null;

					if (!is_int($interval) || $interval < Filesystem::MIN_INTERVAL || $interval > Filesystem::MAX_INTERVAL) {
						$errors[] = sprintf('filesystems[%d].expected_interval must be between %d and %d', $i, Filesystem::MIN_INTERVAL, Filesystem::MAX_INTERVAL);
					}

					$state = $fs['admin_state'] ?? null;

					if (!is_string($state) || AdminState::tryFrom($state) === null) {
						$errors[] = sprintf('filesystems[%d].admin_state must be enabled or disabled', $i);
					}
				}
			}
		}

		if (isset($desired['networks'])) {
			if (!is_array($desired['networks']) || !array_is_list($desired['networks'])) {
				$errors[] = 'networks must be a list';
			} else {
				foreach ($desired['networks'] as $i => $net) {
					if (!is_array($net)) {
						$errors[] = sprintf('networks[%d] must be a mapping', $i);

						continue;
					}

					if (!is_int($net['poller_id'] ?? null) || $net['poller_id'] < 1) {
						$errors[] = sprintf('networks[%d].poller_id must be a positive integer', $i);
					}

					if (!is_string($net['cidr'] ?? null) || !NetworkPolicy::validCidr($net['cidr'])) {
						$errors[] = sprintf('networks[%d].cidr is not a valid CIDR', $i);
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * Compute deterministic plan and SHA-256 confirmation hash.
	 *
	 * @param array<string, mixed> $desired
	 *
	 * @return array{
	 *   valid: bool,
	 *   errors: list<string>,
	 *   changes: array{
	 *     settings: array<string, array{from: int|bool|string, to: int|bool}>,
	 *     filesystems: list<array{action: string, backend: string, name: string, poller_id: int, expected_interval: int, admin_state: string}>,
	 *     networks: list<array{action: string, poller_id: int, cidr: string}>
	 *   },
	 *   plan_hash: string
	 * }
	 */
	public function plan(array $desired): array {
		$errors = $this->validate($desired);

		if ($errors !== []) {
			return [
				'valid'     => false,
				'errors'    => $errors,
				'changes'   => ['settings' => [], 'filesystems' => [], 'networks' => []],
				'plan_hash' => '',
			];
		}

		$current         = $this->snapshot();
		$settingChanges  = [];
		$desiredSettings = isset($desired['settings']) && is_array($desired['settings']) ? $desired['settings'] : [];

		foreach ($current['settings'] as $name => $from) {
			if (!array_key_exists($name, $desiredSettings)) {
				continue;
			}

			$to = $desiredSettings[$name];

			if ((is_int($to) || is_bool($to)) && $from !== $to) {
				$settingChanges[$name] = ['from' => $from, 'to' => $to];
			}
		}

		ksort($settingChanges);

		$existingFs = [];

		foreach ($current['filesystems'] as $fs) {
			$existingFs[$fs['backend'] . '/' . $fs['name']] = $fs;
		}

		$fsChanges  = [];
		$desiredFss = isset($desired['filesystems']) && is_array($desired['filesystems']) ? $desired['filesystems'] : [];

		foreach ($desiredFss as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$backend  = is_string($entry['backend'] ?? null) ? $entry['backend'] : '';
			$name     = is_string($entry['name'] ?? null) ? $entry['name'] : '';
			$poller   = is_int($entry['poller_id'] ?? null) ? $entry['poller_id'] : 1;
			$interval = is_int($entry['expected_interval'] ?? null) ? $entry['expected_interval'] : Filesystem::DEFAULT_INTERVAL;
			$state    = is_string($entry['admin_state'] ?? null) ? $entry['admin_state'] : 'enabled';
			$key      = $backend . '/' . $name;

			if (!isset($existingFs[$key])) {
				$fsChanges[] = ['action' => 'create', 'backend' => $backend, 'name' => $name, 'poller_id' => $poller, 'expected_interval' => $interval, 'admin_state' => $state];
			} elseif (
				$existingFs[$key]['poller_id'] !== $poller
				|| $existingFs[$key]['expected_interval'] !== $interval
				|| $existingFs[$key]['admin_state'] !== $state
			) {
				$fsChanges[] = ['action' => 'update', 'backend' => $backend, 'name' => $name, 'poller_id' => $poller, 'expected_interval' => $interval, 'admin_state' => $state];
			}
		}

		usort($fsChanges, static fn (array $a, array $b): int => [$a['backend'], $a['name']] <=> [$b['backend'], $b['name']]);

		$existingNets = [];

		foreach ($current['networks'] as $net) {
			$existingNets[$net['poller_id'] . '|' . $net['cidr']] = true;
		}

		$netChanges  = [];
		$desiredNets = isset($desired['networks']) && is_array($desired['networks']) ? $desired['networks'] : [];

		foreach ($desiredNets as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$poller = is_int($entry['poller_id'] ?? null) ? $entry['poller_id'] : 0;
			$cidr   = is_string($entry['cidr'] ?? null) ? trim($entry['cidr']) : '';

			if ($poller < 1 || $cidr === '' || isset($existingNets[$poller . '|' . $cidr])) {
				continue;
			}

			$netChanges[] = ['action' => 'add', 'poller_id' => $poller, 'cidr' => $cidr];
		}

		usort($netChanges, static fn (array $a, array $b): int => [$a['poller_id'], $a['cidr']] <=> [$b['poller_id'], $b['cidr']]);

		$changes = ['settings' => $settingChanges, 'filesystems' => $fsChanges, 'networks' => $netChanges];
		$hash    = hash('sha256', json_encode($changes, JSON_THROW_ON_ERROR));

		return [
			'valid'     => true,
			'errors'    => [],
			'changes'   => $changes,
			'plan_hash' => $hash,
		];
	}

	/**
	 * @param array<string, mixed> $desired
	 *
	 * @return array{applied: bool, plan_hash: string, settings_updated: int, filesystems_saved: int, networks_added: int, errors: list<string>}
	 */
	public function apply(array $desired, string $confirmHash): array {
		$plan = $this->plan($desired);

		if (!$plan['valid']) {
			return [
				'applied'           => false,
				'plan_hash'         => '',
				'settings_updated'  => 0,
				'filesystems_saved' => 0,
				'networks_added'    => 0,
				'errors'            => $plan['errors'],
			];
		}

		if ($confirmHash === '' || !hash_equals($plan['plan_hash'], trim($confirmHash))) {
			return [
				'applied'           => false,
				'plan_hash'         => $plan['plan_hash'],
				'settings_updated'  => 0,
				'filesystems_saved' => 0,
				'networks_added'    => 0,
				'errors'            => ['Confirmation hash does not match the current plan hash; re-run plan and pass --confirm=' . $plan['plan_hash']],
			];
		}

		$settingsUpdated = 0;

		foreach ($plan['changes']['settings'] as $name => $change) {
			$stored = is_bool($change['to']) ? ($change['to'] ? 'on' : '') : (string) $change['to'];

			if ($this->settings->save($name, $stored)) {
				$settingsUpdated++;
			}
		}

		$existingByPair = [];

		foreach ($this->filesystems->all() as $fs) {
			$existingByPair[$fs->backend . '/' . $fs->name] = $fs;
		}

		$filesystemsSaved = 0;

		foreach ($plan['changes']['filesystems'] as $change) {
			$key      = $change['backend'] . '/' . $change['name'];
			$existing = $existingByPair[$key] ?? null;
			$state    = AdminState::tryFrom($change['admin_state']) ?? AdminState::Disabled;
			$fs       = new Filesystem(
				$existing !== null ? $existing->id : 0,
				$change['name'],
				$change['backend'],
				$change['poller_id'],
				$change['expected_interval'],
				$state,
				$existing !== null ? $existing->hostId : 0,
			);

			if ($this->filesystems->save($fs) > 0) {
				$filesystemsSaved++;
			}
		}

		$networksAdded = 0;

		foreach ($plan['changes']['networks'] as $change) {
			if ($this->networks->add($change['poller_id'], $change['cidr'])) {
				$networksAdded++;
			}
		}

		return [
			'applied'           => true,
			'plan_hash'         => $plan['plan_hash'],
			'settings_updated'  => $settingsUpdated,
			'filesystems_saved' => $filesystemsSaved,
			'networks_added'    => $networksAdded,
			'errors'            => [],
		];
	}

	/**
	 * Build a structured health and imbalance summary from stored records only.
	 *
	 * @return array{plugin: string, status: string, checked_at: string, summary: array<string, int>, filesystems: list<array<string, mixed>>}
	 */
	public function health(): array {
		$now         = $this->clock->now();
		$filesystems = $this->filesystems->all();
		$nodes       = $this->nodes->all();
		$nodeHealth  = $this->nodeHealth->all();
		$runs        = $this->runs->all();

		$nodesByFs = [];

		foreach ($nodes as $node) {
			$nodesByFs[$node->filesystemId][] = $node;
		}

		$summary = [
			'filesystems_total'          => count($filesystems),
			'filesystems_enabled'        => 0,
			'filesystems_in_maintenance' => 0,
			'filesystems_imbalanced'     => 0,
			'nodes_total'                => count($nodes),
			'nodes_failed'               => 0,
			'readers_recorded'           => count($runs),
		];

		$fsRows   = [];
		$critical = false;
		$warning  = false;

		foreach ($filesystems as $fs) {
			if ($fs->enabled()) {
				$summary['filesystems_enabled']++;
			}

			$inMaint = ($this->inMaintenance !== null && $fs->hostId > 0) ? ($this->inMaintenance)($fs->hostId) : null;

			if ($inMaint === true) {
				$summary['filesystems_in_maintenance']++;
			}

			$fsTargets = $this->targets->forFilesystem($fs->id);
			$fsObs     = $this->observations->forFilesystem($fs->id);
			$sumTargets = [];

			foreach ($fsTargets as $target) {
				$sumTargets[] = new SummaryTarget($target->targetId, $target->review, $target->presence, $target->condition, $fsObs[$target->id] ?? []);
			}

			$imbalance = ImbalanceAnalyzer::analyze($sumTargets, $fs->interval);

			if ($imbalance['severity'] === 'critical' || $imbalance['severity'] === 'warning') {
				$summary['filesystems_imbalanced']++;
			}

			$fsNodes  = $nodesByFs[$fs->id] ?? [];
			$fsFailed = 0;

			foreach ($fsNodes as $node) {
				$h = $nodeHealth[$node->id] ?? null;

				if ($node->enabled() && $h !== null && $h->outcome === Collection::Failed) {
					$fsFailed++;
					$summary['nodes_failed']++;
				}
			}

			if ($fs->enabled() && $inMaint !== true) {
				if ($imbalance['severity'] === 'critical' || $fsFailed > 0) {
					$critical = true;
				} elseif ($imbalance['severity'] === 'warning') {
					$warning = true;
				}
			}

			$fsRows[] = [
				'id'              => $fs->id,
				'name'            => $fs->name,
				'backend'         => $fs->backend,
				'poller_id'       => $fs->pollerId,
				'admin_state'     => $fs->adminState->value,
				'in_maintenance'  => $inMaint,
				'nodes'           => count($fsNodes),
				'nodes_failed'    => $fsFailed,
				'severity'        => $imbalance['severity'],
				'fill_max_pct'    => $imbalance['fill_max_pct'],
				'fill_spread_pct' => $imbalance['fill_spread_pct'],
				'imbalance_index' => $imbalance['imbalance_index'],
				'headroom_min_pct' => $imbalance['headroom_min_pct'],
				'missing_targets' => $imbalance['missing_targets'],
			];
		}

		$overall = $critical ? 'degraded' : ($warning ? 'warning' : 'ok');

		return [
			'plugin'      => 'pfs',
			'status'      => $overall,
			'checked_at'  => gmdate('Y-m-d\TH:i:s\Z', $now),
			'summary'     => $summary,
			'filesystems' => $fsRows,
		];
	}

	public function formatHealth(string $format = 'text'): string {
		$report = $this->health();

		if (strtolower(trim($format)) === 'json') {
			return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		}

		$s     = $report['summary'];
		$lines = [
			sprintf('plugin_pfs health: %s (%s)', strtoupper($report['status']), $report['checked_at']),
			sprintf('Filesystems: %d total, %d enabled, %d in maintenance, %d imbalanced', $s['filesystems_total'], $s['filesystems_enabled'], $s['filesystems_in_maintenance'], $s['filesystems_imbalanced']),
			sprintf('Nodes: %d total, %d failed; Readers: %d', $s['nodes_total'], $s['nodes_failed'], $s['readers_recorded']),
		];

		foreach ($report['filesystems'] as $row) {
			$fill   = is_float($row['fill_max_pct'] ?? null) ? sprintf('%.1f%%', $row['fill_max_pct']) : 'U';
			$spread = is_float($row['fill_spread_pct'] ?? null) ? sprintf('%.1f%%', $row['fill_spread_pct']) : 'U';
			$maint  = $row['in_maintenance'] === true ? 'maint' : ($row['in_maintenance'] === false ? 'active' : 'n/a');
			$lines[] = sprintf(
				'  - %s (%s, poller %d): state=%s maint=%s severity=%s fill_max=%s spread=%s failed_nodes=%d',
				Row::string($row, 'name'),
				Row::string($row, 'backend'),
				Row::int($row, 'poller_id'),
				Row::string($row, 'admin_state'),
				$maint,
				Row::string($row, 'severity'),
				$fill,
				$spread,
				Row::int($row, 'nodes_failed'),
			);
		}

		return implode("\n", $lines) . "\n";
	}

	/**
	 * @param array{
	 *   schema_version: int,
	 *   plugin: string,
	 *   settings: array<string, int|bool|string>,
	 *   filesystems: list<array{name: string, backend: string, poller_id: int, expected_interval: int, admin_state: string}>,
	 *   networks: list<array{poller_id: int, cidr: string}>
	 * } $data
	 */
	private static function encodeYaml(array $data): string {
		$lines = [
			'schema_version: ' . $data['schema_version'],
			'plugin: ' . self::yamlScalar($data['plugin']),
			'settings:',
		];

		foreach ($data['settings'] as $key => $value) {
			$lines[] = '  ' . $key . ': ' . self::yamlScalar($value);
		}

		if ($data['filesystems'] === []) {
			$lines[] = 'filesystems: []';
		} else {
			$lines[] = 'filesystems:';

			foreach ($data['filesystems'] as $fs) {
				$lines[] = '  - name: ' . self::yamlScalar($fs['name']);
				$lines[] = '    backend: ' . self::yamlScalar($fs['backend']);
				$lines[] = '    poller_id: ' . $fs['poller_id'];
				$lines[] = '    expected_interval: ' . $fs['expected_interval'];
				$lines[] = '    admin_state: ' . self::yamlScalar($fs['admin_state']);
			}
		}

		if ($data['networks'] === []) {
			$lines[] = 'networks: []';
		} else {
			$lines[] = 'networks:';

			foreach ($data['networks'] as $net) {
				$lines[] = '  - poller_id: ' . $net['poller_id'];
				$lines[] = '    cidr: ' . self::yamlScalar($net['cidr']);
			}
		}

		return implode("\n", $lines) . "\n";
	}

	private static function yamlScalar(int|bool|string $value): string {
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (is_int($value)) {
			return (string) $value;
		}

		if (preg_match('/^[a-zA-Z0-9._\/:-]+$/', $value) === 1 && !in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no'], true)) {
			return $value;
		}

		return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decodeYaml(string $content): array {
		$result  = [];
		$section = '';
		$current = null;

		foreach (explode("\n", str_replace("\r\n", "\n", $content)) as $rawLine) {
			$trimmed = trim($rawLine);

			if ($trimmed === '' || str_starts_with($trimmed, '#') || $trimmed === '---') {
				continue;
			}

			if (preg_match('/^([a-zA-Z0-9_]+):\s*(.*)$/', $rawLine, $m) === 1) {
				$key   = $m[1];
				$value = trim($m[2]);

				if ($value === '') {
					$section      = $key;
					$result[$key] = [];
					$current      = null;
				} elseif ($value === '[]') {
					$section      = '';
					$result[$key] = [];
					$current      = null;
				} else {
					$section      = '';
					$result[$key] = self::parseYamlValue($value);
					$current      = null;
				}

				continue;
			}

			if ($section === 'settings' && preg_match('/^\s+([a-zA-Z0-9_]+):\s*(.+)$/', $rawLine, $m) === 1) {
				if (!isset($result['settings']) || !is_array($result['settings'])) {
					$result['settings'] = [];
				}

				$result['settings'][$m[1]] = self::parseYamlValue(trim($m[2]));

				continue;
			}

			if (($section === 'filesystems' || $section === 'networks') && preg_match('/^\s+-\s+([a-zA-Z0-9_]+):\s*(.*)$/', $rawLine, $m) === 1) {
				if (!isset($result[$section]) || !is_array($result[$section])) {
					$result[$section] = [];
				}

				$result[$section][] = [$m[1] => self::parseYamlValue(trim($m[2]))];
				$current            = count($result[$section]) - 1;

				continue;
			}

			if (($section === 'filesystems' || $section === 'networks') && $current !== null && preg_match('/^\s+([a-zA-Z0-9_]+):\s*(.*)$/', $rawLine, $m) === 1) {
				if (isset($result[$section]) && is_array($result[$section]) && isset($result[$section][$current]) && is_array($result[$section][$current])) {
					$result[$section][$current][$m[1]] = self::parseYamlValue(trim($m[2]));
				}

				continue;
			}

			throw new \InvalidArgumentException('Unsupported or malformed YAML syntax');
		}

		return $result;
	}

	private static function parseYamlValue(string $raw): int|bool|string {
		if ($raw === 'true') {
			return true;
		}

		if ($raw === 'false') {
			return false;
		}

		if (preg_match('/^-?[0-9]+$/', $raw) === 1) {
			return (int) $raw;
		}

		if (strlen($raw) >= 2 && $raw[0] === '"' && $raw[strlen($raw) - 1] === '"') {
			return str_replace(['\\n', '\\r', '\\"', '\\\\'], ["\n", "\r", '"', '\\'], substr($raw, 1, -1));
		}

		if (strlen($raw) >= 2 && $raw[0] === "'" && $raw[strlen($raw) - 1] === "'") {
			return str_replace("''", "'", substr($raw, 1, -1));
		}

		return $raw;
	}

	/**
	 * @param array{
	 *   schema_version: int,
	 *   plugin: string,
	 *   settings: array<string, int|bool|string>,
	 *   filesystems: list<array{name: string, backend: string, poller_id: int, expected_interval: int, admin_state: string}>,
	 *   networks: list<array{poller_id: int, cidr: string}>
	 * } $data
	 */
	private static function encodeXml(array $data): string {
		$doc               = new \DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;

		$root = $doc->createElement('casc');
		$root->setAttribute('plugin', $data['plugin']);
		$root->setAttribute('schema_version', (string) $data['schema_version']);
		$doc->appendChild($root);

		$settingsEl = $doc->createElement('settings');

		foreach ($data['settings'] as $key => $value) {
			$el = $doc->createElement('setting', is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
			$el->setAttribute('name', $key);
			$el->setAttribute('type', is_bool($value) ? 'bool' : (is_int($value) ? 'int' : 'string'));
			$settingsEl->appendChild($el);
		}

		$root->appendChild($settingsEl);

		$fsEl = $doc->createElement('filesystems');

		foreach ($data['filesystems'] as $fs) {
			$el = $doc->createElement('filesystem');
			$el->setAttribute('name', $fs['name']);
			$el->setAttribute('backend', $fs['backend']);
			$el->setAttribute('poller_id', (string) $fs['poller_id']);
			$el->setAttribute('expected_interval', (string) $fs['expected_interval']);
			$el->setAttribute('admin_state', $fs['admin_state']);
			$fsEl->appendChild($el);
		}

		$root->appendChild($fsEl);

		$netsEl = $doc->createElement('networks');

		foreach ($data['networks'] as $net) {
			$el = $doc->createElement('network');
			$el->setAttribute('poller_id', (string) $net['poller_id']);
			$el->setAttribute('cidr', $net['cidr']);
			$netsEl->appendChild($el);
		}

		$root->appendChild($netsEl);

		$xml = $doc->saveXML();

		return is_string($xml) ? $xml : '';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decodeXml(string $content): array {
		if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
			throw new \InvalidArgumentException('XML DOCTYPE and ENTITY declarations are forbidden');
		}

		$previous = libxml_use_internal_errors(true);

		try {
			$doc = new \DOMDocument();
			$ok  = $doc->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA);
			libxml_clear_errors();
		} finally {
			libxml_use_internal_errors($previous);
		}

		if (!$ok || $doc->documentElement === null || $doc->documentElement->nodeName !== 'casc') {
			throw new \InvalidArgumentException('Invalid CasC XML document');
		}

		$root   = $doc->documentElement;
		$result = [
			'schema_version' => ctype_digit($root->getAttribute('schema_version')) ? (int) $root->getAttribute('schema_version') : 0,
			'plugin'         => $root->getAttribute('plugin'),
			'settings'       => [],
			'filesystems'    => [],
			'networks'       => [],
		];

		foreach ($root->getElementsByTagName('setting') as $el) {
			$name = $el->getAttribute('name');
			$type = $el->getAttribute('type');
			$val  = trim($el->textContent);

			if ($type === 'bool') {
				$result['settings'][$name] = $val === 'true';
			} elseif ($type === 'int' && preg_match('/^-?[0-9]+$/', $val) === 1) {
				$result['settings'][$name] = (int) $val;
			} else {
				$result['settings'][$name] = self::parseYamlValue($val);
			}
		}

		foreach ($root->getElementsByTagName('filesystem') as $el) {
			$poller   = $el->getAttribute('poller_id');
			$interval = $el->getAttribute('expected_interval');

			$result['filesystems'][] = [
				'name'              => $el->getAttribute('name'),
				'backend'           => $el->getAttribute('backend'),
				'poller_id'         => ctype_digit($poller) ? (int) $poller : 0,
				'expected_interval' => ctype_digit($interval) ? (int) $interval : 0,
				'admin_state'       => $el->getAttribute('admin_state'),
			];
		}

		foreach ($root->getElementsByTagName('network') as $el) {
			$poller = $el->getAttribute('poller_id');

			$result['networks'][] = [
				'poller_id' => ctype_digit($poller) ? (int) $poller : 0,
				'cidr'      => $el->getAttribute('cidr'),
			];
		}

		return $result;
	}
}
