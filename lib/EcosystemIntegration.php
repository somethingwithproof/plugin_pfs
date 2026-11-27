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
									'status_flags'   => ['type' => 'array', 'items' => ['type' => 'string']],
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
	 * Diagnostic checks for Cacti Doctor (doctor_register_checks).
	 *
	 * @return list<array{id: string, plugin: string, label: string, category: string, run: \Closure(): array{status: string, message: string, details: array<string, mixed>}}>
	 */
	public function doctorChecks(): array {
		return [
			[
				'id'       => 'pfs_schema',
				'plugin'   => 'pfs',
				'label'    => 'Parallel Filesystems schema currency',
				'category' => 'database',
				'run'      => function (): array {
					$stored  = $this->schema->storedVersion();
					$current = Schema::VERSION;

					return [
						'status'  => $stored === $current ? 'ok' : 'critical',
						'message' => $stored === $current
							? sprintf('PFS schema is current at version %d', $current)
							: sprintf('PFS schema is at version %d; expected %d', $stored, $current),
						'details' => ['stored_version' => $stored, 'expected_version' => $current],
					];
				},
			],
			[
				'id'       => 'pfs_reader_freshness',
				'plugin'   => 'pfs',
				'label'    => 'Parallel Filesystems reader freshness',
				'category' => 'polling',
				'run'      => function (): array {
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

					return [
						'status'  => $stale === [] ? 'ok' : 'warning',
						'message' => $stale === []
							? sprintf('All %d active PFS pollers completed within freshness window', count($pollers))
							: sprintf('Stale PFS readers on pollers: %s', implode(', ', $stale)),
						'details' => ['active_pollers' => array_keys($pollers), 'stale_pollers' => $stale],
					];
				},
			],
			[
				'id'       => 'pfs_capacity_imbalance',
				'plugin'   => 'pfs',
				'label'    => 'Parallel Filesystems OST/MDT capacity and imbalance',
				'category' => 'storage',
				'run'      => function (): array {
					$health  = $this->casc()->health();
					$summary = $health['summary'];
					$status  = $health['status'] === 'degraded' ? 'critical' : ($health['status'] === 'warning' ? 'warning' : 'ok');

					return [
						'status'  => $status,
						'message' => sprintf('%d enabled filesystems, %d imbalanced, %d failed nodes', $summary['filesystems_enabled'], $summary['filesystems_imbalanced'], $summary['nodes_failed']),
						'details' => $summary,
					];
				},
			],
		];
	}

	/**
	 * Redacted diagnostic snapshot for Cacti Doctor (doctor_register_debug_info).
	 *
	 * Never exposes host-local SSH paths, node endpoints, SSH usernames, or
	 * allowlisted network CIDRs.
	 *
	 * @return array<string, array{
	 *   label: string,
	 *   description: string,
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
			'pfs' => [
				'label'       => 'Parallel Filesystems',
				'description' => 'Read-only diagnostic snapshot for plugin_pfs with host-local paths and endpoints redacted.',
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
	 * @return array<string, array<string, mixed>>
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
			'pfsFilesystems' => [
				'plugin'     => 'pfs',
				'type'       => 'cacti.pfs/Filesystem',
				'collection' => 'pfsFilesystems',
				'singular'   => 'pfsFilesystem',
				'plural'     => 'pfsFilesystems',
				'pattern'    => 'pfsFilesystems/{pfs_filesystem}',
				'read_only'  => true,
				'methods'    => ['list', 'get'],
				'realm'      => 'pfs.php',
				'schema'     => 'schema/pfs.schema.yaml',
				'fields'     => [
					'name'              => ['type' => 'string', 'behavior' => ['IDENTIFIER']],
					'id'                => ['type' => 'integer', 'behavior' => ['OUTPUT_ONLY']],
					'display_name'      => ['type' => 'string', 'behavior' => ['OUTPUT_ONLY']],
					'backend'           => ['type' => 'string', 'enum' => ['beegfs', 'gpfs', 'lustre'], 'behavior' => ['OUTPUT_ONLY']],
					'poller_id'         => ['type' => 'integer', 'behavior' => ['OUTPUT_ONLY']],
					'expected_interval' => ['type' => 'integer', 'behavior' => ['OUTPUT_ONLY']],
					'admin_state'       => ['type' => 'string', 'enum' => ['enabled', 'disabled'], 'behavior' => ['OUTPUT_ONLY']],
					'host_id'           => ['type' => 'integer', 'behavior' => ['OUTPUT_ONLY']],
					'in_maintenance'    => ['type' => ['boolean', 'null'], 'behavior' => ['OUTPUT_ONLY']],
				],
				'handlers' => [
					/**
					 * @param int                  $userId caller Cacti user id
					 * @param array<string, mixed> $params optional backend, page_size, page_token
					 *
					 * @return array{pfsFilesystems: list<array<string, mixed>>, next_page_token: string}
					 */
					'list' => function (int $userId, array $params = []) use ($serialize): array {
						if (!($this->hasViewRealm)($userId)) {
							return ['pfsFilesystems' => [], 'next_page_token' => ''];
						}

						$backendFilter = isset($params['backend']) && is_string($params['backend']) ? $params['backend'] : null;
						$pageSize      = isset($params['page_size']) && is_int($params['page_size']) ? max(1, min(1000, $params['page_size'])) : 100;
						$offset        = isset($params['page_token']) && is_string($params['page_token']) && ctype_digit($params['page_token']) ? (int) $params['page_token'] : 0;

						$items = [];

						foreach ($this->permittedFilesystems($userId) as $fs) {
							if ($backendFilter !== null && $fs->backend !== $backendFilter) {
								continue;
							}

							$items[] = $serialize($fs);
						}

						$slice = array_slice($items, $offset, $pageSize);
						$next  = ($offset + count($slice)) < count($items) ? (string) ($offset + count($slice)) : '';

						return [
							'pfsFilesystems'  => $slice,
							'next_page_token' => $next,
						];
					},
					/**
					 * @param int    $userId     caller Cacti user id
					 * @param string $resourceId numeric id or canonical pfsFilesystems/{id}
					 *
					 * @return array<string, mixed>|null
					 */
					'get' => function (int $userId, string $resourceId) use ($serialize): ?array {
						if (!($this->hasViewRealm)($userId)) {
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
			'id'             => 'pfs',
			'plugin'         => 'pfs',
			'schema_version' => CascProvider::SCHEMA_VERSION,
			'formats'        => CascProvider::FORMATS,
			'export'         => static fn (string $format = 'yaml'): string => $casc->export($format),
			'validate'       => static fn (array $desired): array => $casc->validate($desired),
			'plan'           => static fn (array $desired): array => $casc->plan($desired),
			'apply'          => static fn (array $desired, string $confirmHash): array => $casc->apply($desired, $confirmHash),
		];
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
