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
 * Why a node read failed (REQ-SRC-003). Logs and the health page show these
 * classes, never the node's output or the transport's own messages.
 */
enum ReadError: string {
	/** ReadError config case. */
	case Config      = 'config';
	/** ReadError network case. */
	case Network     = 'network';
	/** ReadError resolve case. */
	case Resolve     = 'resolve';
	/** ReadError unreachable case. */
	case Unreachable = 'unreachable';
	/** ReadError host key case. */
	case HostKey     = 'host_key';
	/** ReadError auth case. */
	case Auth        = 'auth';
	/** ReadError timeout case. */
	case Timeout     = 'timeout';
	/** ReadError too large case. */
	case TooLarge    = 'too_large';
	/** ReadError exit status case. */
	case ExitStatus  = 'exit_status';
	/** ReadError unframed case. */
	case Unframed    = 'unframed';
	/** ReadError incomplete case. */
	case Incomplete  = 'incomplete';

	/**
	 * Message.
	 *
	 * @return string
	 */
	public function message(): string {
		return match ($this) {
			self::Config      => __('The plugin settings for node access are incomplete or unusable', 'pfs'),
			self::Network     => __('The endpoint resolves outside the networks allowed for its poller', 'pfs'),
			self::Resolve     => __('The endpoint name does not resolve', 'pfs'),
			self::Unreachable => __('The node could not be reached', 'pfs'),
			self::HostKey     => __('The node host key is unknown or changed', 'pfs'),
			self::Auth        => __('The node refused the key', 'pfs'),
			self::Timeout     => __('The read exceeded its time limit', 'pfs'),
			self::TooLarge    => __('The response exceeded the size limit', 'pfs'),
			self::ExitStatus  => __('The read command failed', 'pfs'),
			self::Unframed    => __('The node did not answer with the fixed read script', 'pfs'),
			self::Incomplete  => __('The response ended before the read script finished', 'pfs'),
		};
	}
}
