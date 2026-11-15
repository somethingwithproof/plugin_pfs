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
 * The graph_buttons link from a filesystem device's graphs to the plugin's
 * view of that filesystem (REQ-GRAPH-004). The view links back to the
 * device's graphs. Cacti's graph and device permissions still decide what
 * either page shows.
 */
final class GraphLinks {
	public function __construct(
		private readonly FilesystemRepository $filesystems,
		private readonly CactiObjectRepository $objects,
	) {
	}

	/**
	 * The filesystem whose view the graph links to, or null for no link.
	 */
	public function filesystemFor(int $localGraphId): ?Filesystem {
		return $localGraphId > 0 ? $this->filesystems->findByHost($this->objects->hostOfGraph($localGraphId)) : null;
	}

	public static function html(string $urlPath, Filesystem $filesystem): string {
		$url   = $urlPath . 'plugins/pfs/pfs.php#pfs_graphs_' . $filesystem->id;
		$title = __('Parallel Filesystem view of %s', $filesystem->name, 'pfs');

		return '<a class="iconLink" role="link" href="' . html_escape($url) . '" title="' . html_escape($title) . '" aria-label="' . html_escape($title) . '"><img class="drillDown" src="' . html_escape($urlPath . 'images/server_chart_curve.png') . '" alt=""></a><br/>';
	}
}
