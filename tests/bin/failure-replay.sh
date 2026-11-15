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

# Failure qualification (tests/bin/failure-flow.php). Run from the root of a
# disposable Cacti install with the plugin enabled, as root for the RRD
# permission scenario:
#
#   PFS_TEST_DATABASE=disposable PFS_TEST_MYSQL_DEFAULTS=<client defaults file> \
#     sh plugins/pfs/tests/bin/failure-replay.sh
#
# Each scenario runs the production reader (poller_pfs.php) against a
# stand-in for ssh and prints how long the reader and the Cacti poller took.
# Exits non-zero on a failure.
set -u

[ "${PFS_TEST_DATABASE:-}" = disposable ] || { echo "Set PFS_TEST_DATABASE=disposable" >&2; exit 2; }
[ -r "${PFS_TEST_MYSQL_DEFAULTS:-}" ] || { echo "Set PFS_TEST_MYSQL_DEFAULTS to a readable client defaults file" >&2; exit 2; }

CLIENT=$(command -v mariadb || command -v mysql)
DB=$(php -r 'chdir("."); include("./include/cli_check.php"); print $database_default;')
case "$DB" in
	'' | *[!A-Za-z0-9_]*) echo "Cannot read a plain database name from include/config.php" >&2; exit 2 ;;
esac
PFS_FAULT_DIR=$(mktemp -d)
export PFS_FAULT_DIR
fail=0

# The last update time of an RRD file, or nothing when rrdtool fails or does
# not print a numeric time.
rrd_last() {
	last=$("$RRDTOOL" lastupdate "$1" 2> /dev/null) || return 0
	last=$(printf '%s\n' "$last" | tail -1 | cut -d: -f1)
	case "$last" in
		'' | *[!0-9]*) ;;
		*) printf '%s\n' "$last" ;;
	esac
}

flow() {
	php plugins/pfs/tests/bin/failure-flow.php "$@" || fail=1
}

reader() {
	start=$(date +%s)
	php plugins/pfs/poller_pfs.php --poller=1 --force || fail=1
	echo "TIMING $1 reader $(( $(date +%s) - start )) s"
}

flow setup

for mode in ok unreachable hostkey slow oversized unframed ok; do
	echo "== $mode"
	flow arm "$mode"
	reader "$mode"
	flow check "$mode"
done

# The Cacti poller with a node that hangs: poller_bottom starts the reader
# in the background, so the poller is not held up by the node.
echo "== slow node under the Cacti poller"
flow arm slow
start=$(date +%s)
php poller.php --poller=1 --force > "$PFS_FAULT_DIR/poll.txt" 2>&1 || fail=1
took=$(( $(date +%s) - start ))
echo "TIMING slow poller $took s"
[ "$took" -lt 25 ] && echo "PASS the Cacti poller finished in $took s while the node hung for 30" || { echo "FAIL the Cacti poller took $took s"; fail=1; }
for _ in $(seq 1 30); do
	[ "$(php -r 'chdir("."); include("./include/cli_check.php"); print db_fetch_cell("SELECT COUNT(*) FROM processes WHERE tasktype = \"pfs\"");')" = 0 ] && break
	sleep 1
done
flow check slow

# The database connection drops while the reader waits for a node.
echo "== database connection lost mid-read"
flow arm dbloss
php plugins/pfs/poller_pfs.php --poller=1 --force > "$PFS_FAULT_DIR/dbloss.txt" 2>&1 &
pid=$!
sleep 2
# Nothing else uses the disposable database now, so every other connection
# to it is the reader's, which is waiting on the node.
killed=0
if kill -0 "$pid" 2> /dev/null; then
	for id in $("$CLIENT" --defaults-file="$PFS_TEST_MYSQL_DEFAULTS" -N -e "SELECT ID FROM information_schema.PROCESSLIST WHERE DB = '$DB' AND ID <> CONNECTION_ID()"); do
		"$CLIENT" --defaults-file="$PFS_TEST_MYSQL_DEFAULTS" -e "KILL $id" && killed=$((killed + 1))
	done
fi
wait "$pid"
status=$?
echo "EVIDENCE dbloss killed $killed reader connection(s) on $DB; reader exit $status output: $(tr '\n' ' ' < "$PFS_FAULT_DIR/dbloss.txt" | cut -c1-400)"
[ "$killed" -gt 0 ] && echo "PASS the reader's database connection was killed mid-read" || { echo "FAIL no reader connection to $DB was killed, so the scenario proves nothing"; fail=1; }
flow check dbloss
echo "== after the database came back"
flow arm ok
reader after-dbloss
flow check ok

# An RRD file the poller cannot write. Root ignores file modes, so the
# poller runs as www-data with that one file left owned by root, read-only.
# Changing owners needs root; elsewhere (a CI runner) the scenario is skipped.
echo "== RRD file the poller cannot write"
if [ "$(id -u)" -eq 0 ] && id www-data > /dev/null 2>&1; then
	# shellcheck disable=SC2046 # the id and the path, split on purpose
	set -- $(php -r 'chdir("."); include("./include/cli_check.php"); include_once("./lib/rrd.php"); $id = db_fetch_cell("SELECT dl.id FROM data_local dl INNER JOIN host h ON h.id = dl.host_id WHERE h.hostname LIKE \"fault.%\" AND dl.snmp_query_id = 0 ORDER BY dl.id LIMIT 1"); print $id . " " . get_data_source_path($id, true);')
	rrd_id=${1:-0}
	rrd=${2:-}
	# The executable Cacti itself runs, which need not be on PATH.
	RRDTOOL=$(php -r 'chdir("."); include("./include/cli_check.php"); print read_config_option("path_rrdtool");')
	php poller.php --poller=1 --force > /dev/null 2>&1 || fail=1
	if [ "$rrd_id" -gt 0 ] && [ -f "$rrd" ] && [ -x "$RRDTOOL" ]; then
		chown -R www-data rra log
		chown root "$rrd" && chmod 0444 "$rrd"
		su -s /bin/sh www-data -c "test -w '$rrd'" && denied=no || denied=yes
		before=$(rrd_last "$rrd")
		sleep 2
		# The poller writes its output until it exits, so a separate marker
		# made before the poll tells which RRD files it updated.
		touch "$PFS_FAULT_DIR/rrd-mark"
		su -s /bin/sh www-data -c 'php poller.php --poller=1 --force' > "$PFS_FAULT_DIR/rrd.txt" 2>&1
		status=$?
		after=$(rrd_last "$rrd")
		others=$(find rra -name '*.rrd' -newer "$PFS_FAULT_DIR/rrd-mark" ! -path "$rrd" | wc -l)
		chown -R root rra log
		chmod 0644 "$rrd"
		echo "EVIDENCE rrd poller exit $status, other files updated in that poll: $others"
		[ "$status" -eq 0 ] || { echo "FAIL the Cacti poller failed:"; tail -5 "$PFS_FAULT_DIR/rrd.txt"; fail=1; }
		[ "$others" -gt 0 ] && echo "PASS the poll updated the other RRD files" || { echo "FAIL the poll updated no other RRD file"; fail=1; }
		if [ -z "$before" ] || [ -z "$after" ]; then
			echo "FAIL could not read the RRD file's last update before and after the poll ($before, $after)"
			fail=1
		elif [ "$before" = "$after" ]; then
			echo "PASS the unwritable RRD file was not updated ($before)"
		else
			echo "FAIL the unwritable RRD file changed ($before, $after)"
			fail=1
		fi
		# Cacti does not always log the refused update, so the permission is
		# checked directly and the log line is evidence only.
		grep "RRDtool did not acknowledge the update for local_data_id: $rrd_id\$" log/cacti.log | tail -1 | sed 's/^/EVIDENCE rrd log: /'
		if [ "$denied" = yes ]; then
			echo "PASS the poller user could not write the RRD file, so the unchanged file is the refused update"
		else
			echo "FAIL the poller user could write the RRD file, so the scenario proves nothing"
			fail=1
		fi
	else
		echo "FAIL no summary RRD file of the fault device to make unwritable, or no rrdtool ($rrd_id, $rrd, $RRDTOOL)"
		fail=1
	fi
	flow arm ok
	reader after-rrd
	flow check ok
else
	echo "SKIP not root, or no unprivileged www-data user to run the poller as"
fi

flow cleanup
[ -n "$PFS_FAULT_DIR" ] && rm -rf "$PFS_FAULT_DIR"
echo "failure replay fail=$fail"
exit $fail
