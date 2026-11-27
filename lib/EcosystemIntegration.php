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
 * Ecosystem integration triad for plugin_pfs:
 * - MCP / Abilities API (abilities_register): pfs-list-filesystems,
 *   pfs-get-imbalance, pfs-get-health (read-only, View realm gated,
 *   device permission filtered).
 * - Doctor diagnostics (doctor_register_checks): schema, reader freshness,
 *   and capacity imbalance.
 * - CasC provider registration (casc_register_providers).
 * - Remote data collector read-only agent actions (remote_agent).
 */
final class EcosystemIntegration {
	/** Standard MCP read-only, non-destructive, idempotent tool annotations. */
	private const READ_ONLY_ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * @param \Closure(int): bool      $hasViewRealm  whether a user holds Parallel Filesystem View realm
	 * @param \Closure(int, int): bool $deviceAllowed whether a user may view a Cacti host_id
	 * @param ?\Closure(int): ?bool    $inMaintenance optional Maint callback by host_id
	 */
	public function __construct(
		private readonly Schema $schema,
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
		private readonly \Closure $hasViewRealm,
		private readonly \Closure $deviceAllowed,
		private readonly ?\Closure $inMaintenance = null,
	) {
	}

	/**
	 * Read-only MCP / Abilities definitions for abilities_register.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		$permission = fn (int $userId): bool => ($this->hasViewRealm)($userId);

		return [
			'pfs-list-filesystems' => [
				'label'        => 'List parallel filesystems',
				'description'  => 'List registered parallel filesystems visible to the caller (read-only).',
				'category'     => 'inventory',
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'backend' => ['type' => 'string', 'enum' => ['lustre', 'gpfs', 'beegfs']],
					],
					'additionalProperties' => false,
				],
				'output_schema' => self::filesystemsOutputSchema(
					[
						'id'               => ['type' => 'integer'],
						'name'             => ['type' => 'string'],
						'backend'          => ['type' => 'string'],
						'pollerId'         => ['type' => 'integer'],
						'expectedInterval' => ['type' => 'integer'],
						'adminState'       => ['type' => 'string'],
						'hostId'           => ['type' => 'integer'],
						'inMaintenance'    => ['type' => ['boolean', 'null']],
					],
					['id', 'name', 'backend', 'pollerId', 'expectedInterval', 'adminState', 'hostId', 'inMaintenance'],
				),
				'annotations' => self::READ_ONLY_ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => function (int $userId, \stdClass $args): array {
					$backendFilter = isset($args->backend) && is_string($args->backend) ? $args->backend : null;
					$items         = [];

					foreach ($this->permittedFilesystems($userId) as $fs) {
						if ($backendFilter !== null && $fs->backend !== $backendFilter) {
							continue;
						}

						$inMaint = ($this->inMaintenance !== null && $fs->hostId > 0) ? ($this->inMaintenance)($fs->hostId) : null;

						$items[] = [
							'id'               => $fs->id,
							'name'             => $fs->name,
							'backend'          => $fs->backend,
							'pollerId'         => $fs->pollerId,
							'expectedInterval' => $fs->interval,
							'adminState'       => $fs->adminState->value,
							'hostId'           => $fs->hostId,
							'inMaintenance'    => $inMaint,
						];
					}

					return ['filesystems' => $items];
				},
			],
			'pfs-get-imbalance' => [
				'label'        => 'Get parallel filesystem capacity imbalance',
				'description'  => 'Return OST/MDT fill ratios, spread, imbalance index, headroom, and missing targets for permitted filesystems (read-only).',
				'category'     => 'inventory',
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'filesystemId' => ['type' => 'integer', 'minimum' => 1],
					],
					'additionalProperties' => false,
				],
				'output_schema' => self::filesystemsOutputSchema(
					[
						'id'               => ['type' => 'integer'],
						'name'             => ['type' => 'string'],
						'backend'          => ['type' => 'string'],
						'severity'         => ['type' => 'string'],
						'fillMaxPct'       => ['type' => ['number', 'null']],
						'fillMinPct'       => ['type' => ['number', 'null']],
						'fillMeanPct'      => ['type' => ['number', 'null']],
						'fillSpreadPct'    => ['type' => ['number', 'null']],
						'imbalanceIndex'   => ['type' => ['number', 'null']],
						'headroomMinPct'   => ['type' => ['number', 'null']],
						'missingTargets'   => ['type' => 'integer'],
						'topSkewedTargets' => [
							'type'     => 'array',
							'maxItems' => TopKTracker::DEFAULT_K,
							'items'    => [
								'type'       => 'object',
								'properties' => [
									'name'           => ['type' => 'string'],
									'type'           => ['type' => 'string'],
									'fill_pct'       => ['type' => 'number'],
									'skew_delta_pct' => ['type' => ['number', 'null']],
									'headroom_pct'   => ['type' => ['number', 'null']],
									'status_flags'   => ['type' => 'array', 'maxItems' => 64, 'items' => ['type' => 'string']],
								],
								'required'             => ['name', 'type', 'fill_pct', 'skew_delta_pct', 'headroom_pct', 'status_flags'],
								'additionalProperties' => false,
							],
						],
					],
					['id', 'name', 'backend', 'severity', 'fillMaxPct', 'fillMinPct', 'fillMeanPct', 'fillSpreadPct', 'imbalanceIndex', 'headroomMinPct', 'missingTargets', 'topSkewedTargets'],
				),
				'annotations' => self::READ_ONLY_ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => function (int $userId, \stdClass $args): array {
					$fsFilter = isset($args->filesystemId) && is_int($args->filesystemId) ? $args->filesystemId : null;
					$out      = [];

					foreach ($this->permittedFilesystems($userId) as $fs) {
						if ($fsFilter !== null && $fs->id !== $fsFilter) {
							continue;
						}

						$analysis = $this->imbalanceFor($fs);

						$out[] = [
							'id'             => $fs->id,
							'name'           => $fs->name,
							'backend'        => $fs->backend,
							'severity'       => $analysis['severity'],
							'fillMaxPct'     => $analysis['fill_max_pct'],
							'fillMinPct'     => $analysis['fill_min_pct'],
							'fillMeanPct'    => $analysis['fill_mean_pct'],
							'fillSpreadPct'  => $analysis['fill_spread_pct'],
							'imbalanceIndex'   => $analysis['imbalance_index'],
							'headroomMinPct'   => $analysis['headroom_min_pct'],
							'missingTargets'   => $analysis['missing_targets'],
							'topSkewedTargets' => $analysis['top_skewed_targets'],
						];
					}

					return ['filesystems' => $out];
				},
			],
			'pfs-get-health' => [
				'label'        => 'Get parallel filesystem health',
				'description'  => 'Return stored reader freshness, node status, imbalance, and maintenance summary for permitted filesystems (read-only).',
				'category'     => 'system',
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'status'                  => ['type' => 'string'],
						'filesystemsTotal'        => ['type' => 'integer'],
						'filesystemsInMaintenance' => ['type' => 'integer'],
						'filesystemsImbalanced'   => ['type' => 'integer'],
						'nodesFailed'             => ['type' => 'integer'],
					],
					'required'             => ['status', 'filesystemsTotal', 'filesystemsInMaintenance', 'filesystemsImbalanced', 'nodesFailed'],
					'additionalProperties' => false,
				],
				'annotations' => self::READ_ONLY_ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => function (int $userId): array {
					$permitted = $this->permittedFilesystems($userId);
					$allowedId = [];

					foreach ($permitted as $fs) {
						$allowedId[$fs->id] = $fs;
					}

					$nodeHealth  = $this->nodeHealth->all();
					$nodesFailed = 0;

					foreach ($this->nodes->all() as $node) {
						if (!isset($allowedId[$node->filesystemId]) || !$node->enabled()) {
							continue;
						}

						$h = $nodeHealth[$node->id] ?? null;

						if ($h !== null && $h->outcome === Collection::Failed) {
							$nodesFailed++;
						}
					}

					$inMaint    = 0;
					$imbalanced = 0;
					$critical   = $nodesFailed > 0;

					foreach ($permitted as $fs) {
						$maint = ($this->inMaintenance !== null && $fs->hostId > 0) ? ($this->inMaintenance)($fs->hostId) : null;

						if ($maint === true) {
							$inMaint++;
						}

						$analysis = $this->imbalanceFor($fs);

						if ($analysis['severity'] === 'critical' || $analysis['severity'] === 'warning') {
							$imbalanced++;
						}

						if ($fs->enabled() && $maint !== true && $analysis['severity'] === 'critical') {
							$critical = true;
						}
					}

					$status = $critical ? 'degraded' : ($imbalanced > 0 ? 'warning' : 'ok');

					return [
						'status'                   => $status,
						'filesystemsTotal'         => count($permitted),
						'filesystemsInMaintenance' => $inMaint,
						'filesystemsImbalanced'    => $imbalanced,
						'nodesFailed'              => $nodesFailed,
					];
				},
			],
		];
	}

	/**
	 * @param mixed $registrar
	 *
	 * @return mixed
	 */
	public function registerAbilities(mixed $registrar): mixed {
		$abilities = $this->abilities();

		if (is_object($registrar) && method_exists($registrar, 'register')) {
			foreach ($abilities as $id => $definition) {
				$registrar->register($id, $definition);
			}

			return $registrar;
		}

		if (is_array($registrar)) {
			foreach ($abilities as $id => $definition) {
				$registrar[$id] = $definition;
			}

			return $registrar;
		}

		return $abilities;
	}

	/**
	 * Evaluated diagnostic checks for Cacti Doctor (doctor_register_checks).
	 *
	 * @return list<array{
	 *   id: string,
	 *   plugin: string,
	 *   category: string,
	 *   title: string,
	 *   status: string,
	 *   message: string,
	 *   details: array<string, mixed>,
	 *   remediation: string
	 * }>
	 */
	public function doctorChecks(): array {
		$stored  = $this->schema->storedVersion();
		$current = Schema::VERSION;

		$now     = $this->clock->now();
		$runs    = $this->runs->all();
		$stale   = [];
		$pollers = [];

		foreach ($this->filesystems->all() as $fs) {
			if ($fs->enabled()) {
				$pollers[$fs->pollerId] = min($pollers[$fs->pollerId] ?? PHP_INT_MAX, $fs->interval);
			}
		}

		foreach ($pollers as $pollerId => $interval) {
			$completed = $runs[$pollerId]->lastCompleted ?? 0;

			if ($completed === 0 || ($now - $completed) > 3 * $interval) {
				$stale[] = $pollerId;
			}
		}

		$health          = $this->casc()->health();
		$summary         = $health['summary'];
		$imbalanceStatus = $health['status'] === 'degraded' ? 'fail' : ($health['status'] === 'warning' ? 'warn' : 'ok');

		return [
			[
				'id'          => 'pfs.schema',
				'plugin'      => 'pfs',
				'category'    => 'database',
				'title'       => 'Parallel Filesystems schema currency',
				'status'      => $stored === $current ? 'ok' : 'fail',
				'message'     => $stored === $current
					? sprintf('PFS schema is current at version %d', $current)
					: sprintf('PFS schema is at version %d; expected %d', $stored, $current),
				'details'     => ['stored_version' => $stored, 'expected_version' => $current],
				'remediation' => $stored === $current ? '' : 'Re-run plugin upgrade to bring the plugin_pfs database schema to the current version.',
			],
			[
				'id'          => 'pfs.reader_freshness',
				'plugin'      => 'pfs',
				'category'    => 'polling',
				'title'       => 'Parallel Filesystems reader freshness',
				'status'      => $stale === [] ? 'ok' : 'warn',
				'message'     => $stale === []
					? sprintf('All %d active PFS pollers completed within freshness window', count($pollers))
					: sprintf('Stale PFS readers on pollers: %s', implode(', ', $stale)),
				'details'     => ['active_pollers' => array_keys($pollers), 'stale_pollers' => $stale],
				'remediation' => $stale === [] ? '' : 'Verify poller_pfs.php execution and SSH connectivity on the stale pollers.',
			],
			[
				'id'          => 'pfs.capacity_imbalance',
				'plugin'      => 'pfs',
				'category'    => 'storage',
				'title'       => 'Parallel Filesystems OST/MDT capacity and imbalance',
				'status'      => $imbalanceStatus,
				'message'     => sprintf('%d enabled filesystems, %d imbalanced, %d failed nodes', $summary['filesystems_enabled'], $summary['filesystems_imbalanced'], $summary['nodes_failed']),
				'details'     => $summary,
				'remediation' => $imbalanceStatus === 'ok' ? '' : 'Inspect imbalanced filesystems and failed storage nodes on the Parallel Filesystems Health page.',
			],
		];
	}

	/**
	 * Redacted diagnostic snapshot for Cacti Doctor (doctor_register_debug_info).
	 *
	 * Never exposes host-local SSH paths, node endpoints, SSH usernames, or
	 * allowlisted network CIDRs.
	 *
	 * @return list<array{
	 *   id: string,
	 *   plugin: string,
	 *   label: string,
	 *   description: string,
	 *   private: bool,
	 *   fields: array<string, array{label: string, value: int|string, private: bool}>
	 * }>
	 */
	public function doctorDebugInfo(): array {
		$settings    = $this->settings->load();
		$filesystems = $this->filesystems->all();
		$nodes       = $this->nodes->all();
		$networks    = $this->networks->all();
		$backendIds  = array_keys($this->backends->all());
		sort($backendIds);

		return [
			[
				'id'          => 'pfs.overview',
				'plugin'      => 'pfs',
				'label'       => 'Parallel Filesystems',
				'description' => 'Read-only diagnostic snapshot for plugin_pfs with host-local paths and endpoints redacted.',
				'private'     => false,
				'fields'      => [
					'schema_version' => [
						'label'   => 'Schema Version',
						'value'   => $this->schema->storedVersion(),
						'private' => false,
					],
					'expected_schema_version' => [
						'label'   => 'Expected Schema Version',
						'value'   => Schema::VERSION,
						'private' => false,
					],
					'backends' => [
						'label'   => 'Registered Backends',
						'value'   => implode(', ', $backendIds),
						'private' => false,
					],
					'filesystems_total' => [
						'label'   => 'Filesystems',
						'value'   => count($filesystems),
						'private' => false,
					],
					'nodes_total' => [
						'label'   => 'Nodes',
						'value'   => count($nodes),
						'private' => false,
					],
					'networks_total' => [
						'label'   => 'Allowed Networks',
						'value'   => count($networks),
						'private' => false,
					],
					'concurrency' => [
						'label'   => 'Concurrent Reads',
						'value'   => $settings->concurrency,
						'private' => false,
					],
					'reader_budget' => [
						'label'   => 'Reader Time Budget (s)',
						'value'   => $settings->readerBudget,
						'private' => false,
					],
					'connect_timeout' => [
						'label'   => 'Connect Timeout (s)',
						'value'   => $settings->connectTimeout,
						'private' => false,
					],
					'read_timeout' => [
						'label'   => 'Read Timeout (s)',
						'value'   => $settings->readTimeout,
						'private' => false,
					],
					'max_response_kib' => [
						'label'   => 'Maximum Response (KiB)',
						'value'   => $settings->maxResponseKib,
						'private' => false,
					],
					'ssh_key_file' => [
						'label'   => 'SSH Key File',
						'value'   => '[redacted]',
						'private' => true,
					],
					'ssh_known_hosts' => [
						'label'   => 'Known Hosts File',
						'value'   => '[redacted]',
						'private' => true,
					],
					'keep_tables_on_uninstall' => [
						'label'   => 'Keep Tables on Uninstall',
						'value'   => $settings->keepTablesOnUninstall ? 'yes' : 'no',
						'private' => false,
					],
				],
			],
		];
	}

	/**
	 * Google AIP-compliant resource descriptors for api_register_resources.
	 *
	 * Plain-array registration keeps plugin_pfs 100% standalone when plugin_api
	 * is absent. Both list and get handlers enforce the View realm and Cacti
	 * per-device permissions.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function apiResources(): array {
		$serialize = function (Filesystem $fs): array {
			$inMaint = ($this->inMaintenance !== null && $fs->hostId > 0) ? ($this->inMaintenance)($fs->hostId) : null;

			return [
				'name'              => 'pfsFilesystems/' . $fs->id,
				'id'                => $fs->id,
				'display_name'      => $fs->name,
				'backend'           => $fs->backend,
				'poller_id'         => $fs->pollerId,
				'expected_interval' => $fs->interval,
				'admin_state'       => $fs->adminState->value,
				'host_id'           => $fs->hostId,
				'in_maintenance'    => $inMaint,
			];
		};

		return [
			[
				'plugin'        => 'pfs',
				'collection'    => 'pfsFilesystems',
				'singleton'     => 'pfsFilesystem',
				'description'   => 'Read-only registered parallel filesystems visible to the caller.',
				'realm_read'    => 'pfs.php',
				'read_only'     => true,
				'schema'        => 'schema/pfs.schema.yaml',
				'filter_fields' => ['id', 'name', 'display_name', 'backend', 'poller_id', 'admin_state'],
				'order_fields'  => ['id', 'name', 'display_name', 'backend', 'poller_id'],
				'handlers'      => [
					/**
					 * @param array<string, mixed> $context
					 *
					 * @return list<array<string, mixed>>
					 */
					'list' => function (array $context = []) use ($serialize): array {
						$userId = isset($context['user_id']) && is_int($context['user_id']) ? $context['user_id'] : 0;

						if ($userId < 1 || !($this->hasViewRealm)($userId)) {
							return [];
						}

						$query         = isset($context['query']) && is_array($context['query']) ? $context['query'] : [];
						$backendFilter = isset($query['backend']) && is_string($query['backend']) ? $query['backend'] : null;
						$items         = [];

						foreach ($this->permittedFilesystems($userId) as $fs) {
							if ($backendFilter !== null && $fs->backend !== $backendFilter) {
								continue;
							}

							$items[] = $serialize($fs);
						}

						return $items;
					},
					/**
					 * @param string               $resourceId numeric id or canonical pfsFilesystems/{id}
					 * @param array<string, mixed> $context
					 *
					 * @return array<string, mixed>|null
					 */
					'get' => function (string $resourceId, array $context = []) use ($serialize): ?array {
						$userId = isset($context['user_id']) && is_int($context['user_id']) ? $context['user_id'] : 0;

						if ($userId < 1 || !($this->hasViewRealm)($userId)) {
							return null;
						}

						$rawId = str_starts_with($resourceId, 'pfsFilesystems/') ? substr($resourceId, strlen('pfsFilesystems/')) : $resourceId;

						if ($rawId === '' || !ctype_digit($rawId)) {
							return null;
						}

						$targetId = (int) $rawId;

						foreach ($this->permittedFilesystems($userId) as $fs) {
							if ($fs->id === $targetId) {
								return $serialize($fs);
							}
						}

						return null;
					},
				],
			],
		];
	}

	/**
	 * CasC provider registration descriptor (casc_register_providers).
	 *
	 * @return array<string, mixed>
	 */
	public function cascDescriptor(): array {
		$casc = $this->casc();

		return [
			'slug'          => 'pfs',
			'schemaVersion' => '1.0',
			/**
			 * @param array<string, mixed> $options
			 * @return array<string, mixed>
			 */
			'export'        => static fn (array $options = []): array => [
				'config'    => $casc->snapshot(),
				'reference' => [],
			],
			/**
			 * @param array<string, mixed> $desired
			 * @return list<string>
			 */
			'validate'      => static fn (array $desired): array => $casc->validate(self::unwrapCascConfig($desired)),
			/**
			 * @param array<string, mixed> $desired
			 * @param array<string, mixed> $options
			 * @return list<array{operation: string, key: string, message: string}>
			 */
			'plan'          => static function (array $desired, array $options = []) use ($casc): array {
				$plan = $casc->plan(self::unwrapCascConfig($desired));

				if (!$plan['valid']) {
					return array_map(
						static fn (string $err): array => ['operation' => 'error', 'key' => 'pfs', 'message' => $err],
						$plan['errors'],
					);
				}

				$items = [];

				foreach ($plan['changes']['settings'] as $name => $change) {
					$items[] = [
						'operation' => 'update',
						'key'       => 'settings.' . $name,
						'message'   => sprintf('Update setting %s', $name),
					];
				}

				foreach ($plan['changes']['filesystems'] as $fsChange) {
					$items[] = [
						'operation' => $fsChange['action'] === 'create' ? 'create' : 'update',
						'key'       => 'filesystems.' . $fsChange['backend'] . '/' . $fsChange['name'],
						'message'   => sprintf('%s filesystem %s/%s', ucfirst($fsChange['action']), $fsChange['backend'], $fsChange['name']),
					];
				}

				foreach ($plan['changes']['networks'] as $netChange) {
					$items[] = [
						'operation' => 'create',
						'key'       => sprintf('networks.%d/%s', $netChange['poller_id'], $netChange['cidr']),
						'message'   => sprintf('Add allowed network %s on poller %d', $netChange['cidr'], $netChange['poller_id']),
					];
				}

				return $items;
			},
			/**
			 * @param array<string, mixed> $desired
			 * @param string               $confirm
			 * @param array<string, mixed> $options
			 * @return array<string, mixed>
			 */
			'apply'         => static function (array $desired, string $confirm = '', array $options = []) use ($casc): array {
				$result = $casc->apply(self::unwrapCascConfig($desired), $confirm);

				return ['ok' => $result['applied']] + $result;
			},
		];
	}

	/**
	 * Unwrap an optional ['config' => ...] export envelope into a string-keyed
	 * desired configuration map.
	 *
	 * @param array<array-key, mixed> $desired
	 *
	 * @return array<string, mixed>
	 */
	private static function unwrapCascConfig(array $desired): array {
		$raw = (isset($desired['config']) && is_array($desired['config']) && !isset($desired['schema_version']))
			? $desired['config']
			: $desired;

		$out = [];

		foreach ($raw as $key => $value) {
			$out[(string) $key] = $value;
		}

		return $out;
	}

	/**
	 * Handle read-only remote_agent actions (REQ-REMOTE-020, REQ-REMOTE-021).
	 *
	 * @return array<string, mixed>|null
	 */
	public function handleRemoteAgent(string $action, int $pollerId = 0): ?array {
		if ($action === 'pfs_health') {
			return $this->casc()->health();
		}

		if ($action === 'pfs_inventory') {
			$filesystems = array_values(array_filter(
				$this->filesystems->all(),
				static fn (Filesystem $fs): bool => $pollerId < 1 || $fs->pollerId === $pollerId,
			));

			return [
				'plugin'      => 'pfs',
				'filesystems' => array_map(static fn (Filesystem $fs): array => [
					'id'                => $fs->id,
					'name'              => $fs->name,
					'backend'           => $fs->backend,
					'poller_id'         => $fs->pollerId,
					'expected_interval' => $fs->interval,
					'admin_state'       => $fs->adminState->value,
				], $filesystems),
			];
		}

		return null;
	}

	/**
	 * @return list<Filesystem>
	 */
	private function permittedFilesystems(int $userId): array {
		$out = [];

		foreach ($this->filesystems->all() as $fs) {
			if ($fs->hostId > 0 && ($this->deviceAllowed)($userId, $fs->hostId)) {
				$out[] = $fs;
			}
		}

		return $out;
	}

	/**
	 * @return array{
	 *   severity: string,
	 *   targets_total: int,
	 *   targets_contributing: int,
	 *   missing_targets: int,
	 *   ost_count: int,
	 *   mdt_count: int,
	 *   fill_max_pct: ?float,
	 *   fill_min_pct: ?float,
	 *   fill_mean_pct: ?float,
	 *   fill_spread_pct: ?float,
	 *   imbalance_index: ?float,
	 *   headroom_min_pct: ?float,
	 *   headroom_min_kbytes: ?float,
	 *   headroom_kbytes_total: ?float,
	 *   mdt_fill_max_pct: ?float,
	 *   mdt_inode_max_pct: ?float,
	 *   mdt_headroom_min_pct: ?float,
	 *   top_skewed_targets: list<array{name: string, type: string, fill_pct: float, skew_delta_pct: ?float, headroom_pct: ?float, status_flags: list<string>}>,
	 *   targets: list<array{name: string, type: string, presence: string, condition: string, fill_pct: ?float, inode_pct: ?float, headroom_pct: ?float, kbytes_avail: ?float, status_flags: list<string>}>
	 * }
	 */
	private function imbalanceFor(Filesystem $fs): array {
		$targets    = $this->targets->forFilesystem($fs->id);
		$obs        = $this->observations->forFilesystem($fs->id);
		$sumTargets = [];

		foreach ($targets as $target) {
			$sumTargets[] = new SummaryTarget($target->targetId, $target->review, $target->presence, $target->condition, $obs[$target->id] ?? []);
		}

		return ImbalanceAnalyzer::analyze($sumTargets, $fs->interval);
	}

	/**
	 * Casc.
	 *
	 * @return CascProvider
	 */
	private function casc(): CascProvider {
		return new CascProvider(
			$this->settings,
			$this->filesystems,
			$this->nodes,
			$this->networks,
			$this->targets,
			$this->observations,
			$this->nodeHealth,
			$this->runs,
			$this->backends,
			$this->clock,
			$this->inMaintenance,
		);
	}

	/**
	 * @param array<string, mixed> $itemProperties
	 * @param list<string>         $required
	 *
	 * @return array<string, mixed>
	 */
	private static function filesystemsOutputSchema(array $itemProperties, array $required): array {
		return [
			'type'       => 'object',
			'properties' => [
				'filesystems' => [
					'type'     => 'array',
					'maxItems' => 1000,
					'items'    => [
						'type'                 => 'object',
						'properties'           => $itemProperties,
						'required'             => $required,
						'additionalProperties' => false,
					],
				],
			],
			'required'             => ['filesystems'],
			'additionalProperties' => false,
		];
	}
}
