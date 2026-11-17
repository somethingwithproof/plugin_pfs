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

# Thold and Maint end to end (tests/bin/alert-flow.php). Run from the root
# of a disposable Cacti install with the plugin installed and enabled, and
# unchanged Thold and Maint releases at plugins/thold and plugins/maint:
#
#   PFS_TEST_DATABASE=disposable sh plugins/pfs/tests/bin/alert-replay.sh
#
# Installs and enables Thold and Maint, then runs each step, a forced
# poll, and the Thold check. Exits non-zero when any check failed.
set -u

[ "${PFS_TEST_DATABASE:-}" = disposable ] || { echo "Set PFS_TEST_DATABASE=disposable" >&2; exit 2; }
[ -f plugins/thold/setup.php ] && [ -f plugins/maint/setup.php ] || { echo "plugins/thold and plugins/maint are required" >&2; exit 2; }

php cli/plugin_manage.php --plugin=thold --plugin=maint --install --enable || exit 1

fail=0

steps=$(php plugins/pfs/tests/bin/alert-flow.php list) || { echo "alert-flow.php list failed" >&2; exit 1; }
[ -n "$steps" ] || { echo "alert-flow.php list printed no steps" >&2; exit 1; }

for step in $steps; do
	echo "== step $step"
	php plugins/pfs/tests/bin/alert-flow.php "$step" || fail=1
	[ "$step" = final ] && break

	# The OSS stays silent long enough for the summary age to pass the
	# 30 second threshold.
	[ "$step" = returned ] && sleep 40

	# RRDtool refuses a second update in the same second, and the steps
	# run faster than that.
	sleep 1
	php poller.php --poller=1 --force > /tmp/pfs-alert-poll.txt 2>&1 || { fail=1; tail -5 /tmp/pfs-alert-poll.txt; }

	# poller_bottom starts poller_thold.php in the background. Wait for it,
	# then run it in the foreground: a run that finds nothing new to check
	# changes nothing, because Thold clears each threshold's check flag.
	# The background job may not have registered in processes yet when
	# poller.php returns, and a foreground run that finds it registered
	# exits without checking, so wait for the process itself to end too.
	settled=0
	for _ in $(seq 1 60); do
		busy=0
		for f in /proc/[0-9]*/cmdline; do
			case $(tr '\0' ' ' < "$f" 2>/dev/null) in
				*poller_thold.php* | *thold_notify.php*) busy=1 ;;
			esac
		done
		[ "$busy" -eq 0 ] && [ "$(php -r 'chdir("."); include("./include/cli_check.php"); print db_fetch_cell("SELECT COUNT(*) FROM processes WHERE tasktype = \"thold\"");')" = 0 ] && { settled=1; break; }
		sleep 1
	done
	[ "$settled" -eq 1 ] || { echo "the background Thold check did not finish"; fail=1; }

	php plugins/thold/poller_thold.php > /tmp/pfs-alert-thold.txt 2>&1 || { fail=1; cat /tmp/pfs-alert-thold.txt; }
done

echo "alert replay fail=$fail"
exit $fail
