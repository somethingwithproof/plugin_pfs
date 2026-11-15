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
 * Answers the OST and MDT script server data queries (REQ-DQ-001 to
 * REQ-DQ-004) from the reader's cache, never from a node.
 *
 * Cacti runs the index and query steps as a PHP CLI process and the get
 * step through the script server, one call per value per target. Every
 * argument arrives from Cacti's command line or the script server, so each
 * is checked here: the type and field against fixed lists, and the index
 * against the target name pattern. Anything else is answered with U or an
 * empty list, never echoed back.
 *
 * Indexes are every target of the filesystem and type that an administrator
 * has not ignored, including missing ones, so a target that disappears keeps
 * its data source and history, with unknown values, until it is ignored in
 * the registry (REQ-DQ-002).
 */
final class TargetQuery {
	/** Input fields, by query_name, beside the value names. */
	public const NAME_FIELD  = 'name';
	public const INDEX_FIELD = 'number';

	private const CACHE_SECONDS = 30;

	/** @var array<int, array{at: int, filesystem: ?Filesystem, targets: array<string, Target>, values: array<int, array<string, Observation>>}> */
	private array $cache = [];

	public function __construct(
		private readonly FilesystemRepository $filesystems,
		private readonly TargetRepository $targets,
		private readonly ObservationRepository $observations,
		private readonly BackendRegistry $backends,
		private readonly Clock $clock,
	) {
	}

	public function answer(int $hostId, string $type, string $command, string $field = '', string $index = ''): string {
		$targetType = TargetType::tryFrom($type);

		if ($targetType === null || $hostId <= 0) {
			return $command === 'get' ? 'U' : '';
		}

		$data = $this->load($hostId);

		if ($data['filesystem'] === null) {
			return match ($command) {
				'get'         => 'U',
				'num_indexes' => '0',
				default       => '',
			};
		}

		$targets = array_values(array_filter($data['targets'], static fn (Target $t): bool => $t->targetId->type === $targetType && $t->review !== Review::Ignored));

		return match ($command) {
			'index'       => self::lines(array_map(static fn (Target $t): string => $t->name(), $targets)),
			'num_indexes' => (string) count($targets),
			'query'       => $this->query($field, $targets, $data['filesystem'], $data['values']),
			'get'         => $this->get($field, $index, $targets, $data['filesystem'], $data['values']),
			default       => '',
		};
	}

	/**
	 * @param list<Target>                           $targets
	 * @param array<int, array<string, Observation>> $values
	 */
	private function query(string $field, array $targets, Filesystem $filesystem, array $values): string {
		$lines = [];

		foreach ($targets as $target) {
			$value = match ($field) {
				self::NAME_FIELD  => $target->name(),
				self::INDEX_FIELD => (string) $target->targetId->index,
				default           => $this->value($field, $target, $filesystem, $values),
			};

			if ($value === null) {
				return '';
			}

			$lines[] = $target->name() . '!' . $value;
		}

		return self::lines($lines);
	}

	/**
	 * @param list<Target>                           $targets
	 * @param array<int, array<string, Observation>> $values
	 */
	private function get(string $field, string $index, array $targets, Filesystem $filesystem, array $values): string {
		$id = TargetId::parse($index);

		if ($id === null) {
			return 'U';
		}

		foreach ($targets as $target) {
			if ($target->name() === $id->name()) {
				return $this->value($field, $target, $filesystem, $values) ?? 'U';
			}
		}

		return 'U';
	}

	/**
	 * A value as the data source should store it, U when unknown, or null
	 * when the field is not a value of this type.
	 *
	 * A gauge is served while it is current or delayed. A counter is served
	 * only while current: a counter left over from a missed read would draw
	 * a flat zero and then a doubled rate (ADR 0012).
	 *
	 * @param array<int, array<string, Observation>> $values
	 */
	private function value(string $field, Target $target, Filesystem $filesystem, array $values): ?string {
		$spec = $this->spec($filesystem, $target->targetId->type, $field);

		if ($spec === null) {
			return null;
		}

		$observation = $values[$target->id][$field] ?? null;
		$value       = $observation?->published();

		if ($observation === null || $value === null) {
			return 'U';
		}

		$freshness = (new FreshnessEvaluator($this->clock))->evaluate($observation->observedAt, $observation->expectedInterval);
		$served    = $spec->kind === ValueKind::Counter ? $freshness === Freshness::Current : ($freshness === Freshness::Current || $freshness === Freshness::Delayed);

		return $served ? Number::format($value) : 'U';
	}

	private function spec(Filesystem $filesystem, TargetType $type, string $field): ?ValueSpec {
		if (!$this->backends->has($filesystem->backend)) {
			return null;
		}

		foreach ($this->backends->get($filesystem->backend)->values() as $spec) {
			if ($spec->targetType === $type && $spec->name === $field) {
				return $spec;
			}
		}

		return null;
	}

	/**
	 * The script server serves every get of a poll cycle from one process,
	 * so the filesystem is read once and reused for a short time.
	 *
	 * @return array{at: int, filesystem: ?Filesystem, targets: array<string, Target>, values: array<int, array<string, Observation>>}
	 */
	private function load(int $hostId): array {
		$now = $this->clock->now();
		$key = $hostId;

		if (isset($this->cache[$key]) && $now - $this->cache[$key]['at'] < self::CACHE_SECONDS && $now >= $this->cache[$key]['at']) {
			return $this->cache[$key];
		}

		$filesystem = $this->filesystems->findByHost($hostId);

		$this->cache[$key] = [
			'at'         => $now,
			'filesystem' => $filesystem,
			'targets'    => $filesystem === null ? [] : $this->targets->forFilesystem($filesystem->id),
			'values'     => $filesystem === null ? [] : $this->observations->forFilesystem($filesystem->id),
		];

		return $this->cache[$key];
	}

	/**
	 * @param list<string> $lines
	 */
	private static function lines(array $lines): string {
		return $lines === [] ? '' : implode("\n", $lines) . "\n";
	}
}
