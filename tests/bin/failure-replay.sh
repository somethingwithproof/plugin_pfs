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

# Failure qualification (tests/bin/failure-flow.php). Run as root from the
# root of a disposable Cacti install with the plugin enabled:
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
PFS_FAULT_DIR=$(mktemp -d)
export PFS_FAULT_DIR
fail=0

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
"$CLIENT" --defaults-file="$PFS_TEST_MYSQL_DEFAULTS" -N -e "SELECT ID FROM information_schema.PROCESSLIST WHERE DB = 'cacti' AND ID <> CONNECTION_ID()" | while read -r id; do
	"$CLIENT" --defaults-file="$PFS_TEST_MYSQL_DEFAULTS" -e "KILL $id" || true
done
wait "$pid"
echo "EVIDENCE dbloss reader exit $? output: $(tr '\n' ' ' < "$PFS_FAULT_DIR/dbloss.txt" | cut -c1-400)"
flow check dbloss
echo "== after the database came back"
flow arm ok
reader after-dbloss
flow check ok

# An RRD file the poller cannot write. Root ignores file modes, so the
# poller runs as www-data with that one file left owned by root, read-only.
echo "== RRD file the poller cannot write"
if id www-data > /dev/null 2>&1; then
	rrd=$(php -r 'chdir("."); include("./include/cli_check.php"); include_once("./lib/rrd.php"); $id = db_fetch_cell("SELECT dl.id FROM data_local dl INNER JOIN host h ON h.id = dl.host_id WHERE h.hostname LIKE \"fault.%\" AND dl.snmp_query_id = 0 ORDER BY dl.id LIMIT 1"); print get_data_source_path($id, true);')
	php poller.php --poller=1 --force > /dev/null 2>&1
	chown -R www-data rra log
	chown root "$rrd" && chmod 0444 "$rrd"
	before=$(rrdtool lastupdate "$rrd" | tail -1 | cut -d: -f1)
	sleep 2
	su -s /bin/sh www-data -c 'php poller.php --poller=1 --force' > "$PFS_FAULT_DIR/rrd.txt" 2>&1
	after=$(rrdtool lastupdate "$rrd" | tail -1 | cut -d: -f1)
	others=$(find rra -name '*.rrd' -newer "$PFS_FAULT_DIR/rrd.txt" ! -path "$rrd" | wc -l)
	grep -i "rrd" log/cacti.log | grep -i "permission denied\|ERROR" | tail -2 | sed 's/^/EVIDENCE rrd log: /'
	[ "$before" = "$after" ] && echo "PASS the unwritable RRD file was not updated ($before)" || { echo "FAIL the unwritable RRD file changed ($before, $after)"; fail=1; }
	chown -R root rra log
	chmod 0644 "$rrd"
	echo "EVIDENCE rrd other files updated in that poll: $others"
	flow arm ok
	reader after-rrd
	flow check ok
else
	echo "SKIP no unprivileged www-data user to run the poller as"
fi

flow cleanup
[ -n "$PFS_FAULT_DIR" ] && rm -rf "$PFS_FAULT_DIR"
echo "failure replay fail=$fail"
exit $fail
