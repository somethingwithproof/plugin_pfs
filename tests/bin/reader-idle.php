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
 * Bounded waits on the production reader for the end-to-end flows. A forced
 * Cacti poll starts poller_pfs.php in the background (poller_bottom), so a
 * flow that reads or forces a run next has to wait for that reader to end.
 * Reader::run() releases the lease before poller_pfs.php unregisters its
 * process, so idle means both: no live lease and no registration.
 *
 * Must parse on PHP 8.0, like the other tests/bin scripts.
 */

/** Whether no reader holds the poller's lease or is registered as running. */
function pfs_reader_idle($poller = 1) {
	$lease      = db_fetch_row_prepared('SELECT expires_at FROM plugin_pfs_reader WHERE poller_id = ?', array($poller));
	$leaseFree  = is_array($lease) && (!isset($lease['expires_at']) || (int) $lease['expires_at'] < time());
	$registered = db_fetch_cell_prepared("SELECT COUNT(*) FROM processes WHERE tasktype = 'pfs' AND taskname = 'reader' AND taskid = ?", array($poller));

	// A background PHP process can pause before registering with Cacti.
	// Include that startup window, and fail closed if process lookup fails.
	$readers = array();
	$status = 0;
	exec("pgrep -f '[p]oller_pfs[.]php'", $readers, $status);

	return $leaseFree && $registered !== false && (int) $registered === 0 && $status === 1;
}

/**
 * Poll $done every 0.2 seconds until it is true or $seconds have passed.
 * Returns whether it became true.
 */
function pfs_wait_until($done, $seconds) {
	$until = microtime(true) + $seconds;

	while (!$done()) {
		if (microtime(true) >= $until) {
			return false;
		}

		usleep(200000);
	}

	return true;
}
