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
 * A backend's reading of one node's output.
 *
 * Framed means the output starts with the fixed read script's header;
 * complete means its end marker arrived. Only a framed and complete report
 * is used: anything else is a failed read, and none of its values reach the
 * cache. Partial marks a usable report in which some sections failed or were
 * cut short; their values are unknown.
 */
final readonly class NodeReport {
	/**
	 * Initialize a parsed node report.
	 *
	 * @param bool                       $framed             whether output began with the backend header
	 * @param bool                       $complete           whether output ended with the backend footer without trailing data
	 * @param bool                       $partial            whether any section failed, was truncated, or duplicated
	 * @param ?string                    $version            reported backend software version
	 * @param array<string, TargetReport> $targets           target name => report
	 * @param int                        $rejectedNames      count of malformed target identifiers dropped
	 * @param int                        $unexpectedSections count of unrecognized sections skipped
	 * @param ?RpcLatencyMetrics         $rpcLatency         optional bounded RPC latency histograms (read/write/metadata)
	 * @param ?StallMetrics              $stalls             optional backend-specific stall counters
	 * @param ?RecoveryMetrics           $recovery           optional aggregated recovery and client eviction counters
	 */
	public function __construct(
		public bool $framed,
		public bool $complete,
		public bool $partial,
		public ?string $version,
		public array $targets,
		public int $rejectedNames,
		public int $unexpectedSections,
		public ?RpcLatencyMetrics $rpcLatency = null,
		public ?StallMetrics $stalls = null,
		public ?RecoveryMetrics $recovery = null,
	) {
	}

	/**
	 * Unframed.
	 *
	 * @return self
	 */
	public static function unframed(): self {
		return new self(false, false, false, null, [], 0, 0);
	}

	/**
	 * Usable.
	 *
	 * @return bool
	 */
	public function usable(): bool {
		return $this->framed && $this->complete;
	}

	/**
	 * Error.
	 *
	 * @return ?ReadError
	 */
	public function error(): ?ReadError {
		return match (true) {
			!$this->framed   => ReadError::Unframed,
			!$this->complete => ReadError::Incomplete,
			default          => null,
		};
	}
}
