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

# Backup and restore qualification (tests/bin/restore-flow.php). Run from
# the root of a disposable Cacti install with the plugin enabled:
#
#   PFS_TEST_DATABASE=disposable PFS_TEST_MYSQL_DEFAULTS=<client defaults file> \
#     sh plugins/pfs/tests/bin/restore-replay.sh
#
# The defaults file names the server and user; a password comes from
# MYSQL_PWD, never the command line. Dumps the whole Cacti database and
# copies the RRD directory, changes both, restores them, and checks that the
# plugin resumes without duplicating anything. Exits non-zero on a failure.
set -u

[ "${PFS_TEST_DATABASE:-}" = disposable ] || { echo "Set PFS_TEST_DATABASE=disposable" >&2; exit 2; }
[ -r "${PFS_TEST_MYSQL_DEFAULTS:-}" ] || { echo "Set PFS_TEST_MYSQL_DEFAULTS to a readable client defaults file" >&2; exit 2; }

DB=${PFS_TEST_MYSQL_DB:-cacti}
DUMP=$(command -v mariadb-dump || command -v mysqldump)
CLIENT=$(command -v mariadb || command -v mysql)
SCRATCH=$(mktemp -d)
export PFS_RESTORE_STATE="$SCRATCH/state.json"
fail=0

flow() {
	php plugins/pfs/tests/bin/restore-flow.php "$1" || fail=1
}

# The poller starts background jobs (automation, maintenance, boost) that
# keep using the database after it returns. Wait for them, so the backup is
# consistent and the restore does not drop tables under them.
settle() {
	for _ in $(seq 1 120); do
		busy=0
		for f in /proc/[0-9]*/cmdline; do
			case $(tr '\0' ' ' < "$f" 2>/dev/null) in
				*poller_*.php* | *cmd.php* | *script_server.php*) busy=1 ;;
			esac
		done
		[ "$busy" -eq 0 ] && return 0
		sleep 1
	done
	echo "background poller jobs did not finish"
	fail=1
}

poll() {
	php poller.php --poller=1 --force > "$SCRATCH/poll.txt" 2>&1 || { fail=1; tail -5 "$SCRATCH/poll.txt"; }
	settle
}

flow setup
poll
flow snapshot

echo "== backup"
"$DUMP" --defaults-file="$PFS_TEST_MYSQL_DEFAULTS" --single-transaction --routines "$DB" > "$SCRATCH/cacti.sql" || fail=1
tar -C rra -cf "$SCRATCH/rra.tar" . || fail=1

flow drift
poll

echo "== restore"
"$CLIENT" --defaults-file="$PFS_TEST_MYSQL_DEFAULTS" -e "DROP DATABASE \`$DB\`; CREATE DATABASE \`$DB\`" || fail=1
"$CLIENT" --defaults-file="$PFS_TEST_MYSQL_DEFAULTS" "$DB" < "$SCRATCH/cacti.sql" || fail=1
find rra -mindepth 1 -delete
tar -C rra -xf "$SCRATCH/rra.tar" || fail=1

flow verify
poll
flow resumed

[ -n "$SCRATCH" ] && rm -rf "$SCRATCH"
echo "restore replay fail=$fail"
exit $fail
