#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# This file is part of plugin_pfs, a plugin for Cacti.
# https://github.com/somethingwithproof/plugin_pfs
#
# This program is free software; you can redistribute it and/or modify it
# under the terms of the GNU General Public License as published by the Free
# Software Foundation; either version 2 of the License, or (at your option)
# any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
# FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
# more details.

# Scale measurements (tests/bin/scale-flow.php). Run from the root of a
# disposable Cacti install with the plugin enabled:
#
#   PFS_TEST_DATABASE=disposable sh plugins/pfs/tests/bin/scale-replay.sh [N ...]
#
# For each target count (default 500 and 2000) it builds the filesystem,
# times two forced poller runs with cmd.php, prints METRIC lines, and removes
# the filesystem and its device again, so each count is measured alone.
# Exits non-zero when a step or a poller run fails.
set -u

[ "${PFS_TEST_DATABASE:-}" = disposable ] || { echo "Set PFS_TEST_DATABASE=disposable" >&2; exit 2; }
[ "$#" -gt 0 ] || set -- 500 2000

echo "METRIC host_cpus $(nproc 2>/dev/null || echo unknown)"
echo "METRIC host_memory_kib $(awk '/MemTotal/ {print $2}' /proc/meminfo 2>/dev/null || echo unknown)"
echo "METRIC php $(php -r 'print PHP_VERSION;')"

for n in "$@"; do
	php plugins/pfs/tests/bin/scale-flow.php build "$n" || exit 1
	for run in 1 2; do
		start=$(date +%s)
		out=$(mktemp)
		if ! php poller.php --poller=1 --force > "$out" 2>&1; then
			echo "FAIL poller run $run for $n targets:" >&2
			tail -20 "$out" >&2
			rm -f "$out"
			exit 1
		fi
		rm -f "$out"
		echo "METRIC $n poller_run_${run}_s $(( $(date +%s) - start ))"
	done
	php plugins/pfs/tests/bin/scale-flow.php after "$n" || exit 1
	php plugins/pfs/tests/bin/scale-flow.php remove "$n" || exit 1
done
