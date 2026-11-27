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
 * A node's role in its filesystem (REQ-SRC-001). Lustre names them; other
 * backends map their own server kinds onto the same four.
 */
enum NodeRole: string {
	/** NodeRole mgs case. */
	case Mgs    = 'mgs';
	/** NodeRole mds case. */
	case Mds    = 'mds';
	/** NodeRole oss case. */
	case Oss    = 'oss';
	/** NodeRole client case. */
	case Client = 'client';

	/**
	 * Return the human-readable display label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ($this) {
			self::Mgs    => __('Management server (MGS)', 'pfs'),
			self::Mds    => __('Metadata server (MDS)', 'pfs'),
			self::Oss    => __('Object storage server (OSS)', 'pfs'),
			self::Client => __('Client', 'pfs'),
		};
	}
}
