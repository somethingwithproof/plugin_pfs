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
#
# Runs pfs-read.sh against a fixture tree (no Lustre needed):
#   sh run.sh
# The fixture and a path-rewritten copy of the script are created in a new
# directory under $TMPDIR and left in place.

set -eu
here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d "${TMPDIR:-/tmp}/pfs-restricted-read.XXXXXX")
sysr=$work/sys; procr=$work/proc; dbgr=$work/debug
mkdir -p "$sysr/osd-ldiskfs/fs-OST0000" "$sysr/obdfilter/fs-OST0000" "$procr/obdfilter/fs-OST0000" "$procr/mdt/fs-MDT0000" "$dbgr"
echo 1000000 > "$sysr/osd-ldiskfs/fs-OST0000/kbytestotal"
echo 400000 > "$sysr/osd-ldiskfs/fs-OST0000/kbytesfree"
echo 0 > "$sysr/obdfilter/fs-OST0000/degraded"
printf 'snapshot_time 1.0 secs.nsecs\nread_bytes 3 samples [bytes] 1 4096 9000\n' > "$procr/obdfilter/fs-OST0000/stats"
# Many small targets: with a per-file reservation of the cap, 70 targets would
# exhaust the 4 MiB budget before the later groups (stats) were read.
n=1
while [ "$n" -le 70 ]; do
	d="$sysr/osd-ldiskfs/fs-OST$(printf '%04d' "$n")"; mkdir -p "$d"; echo 1 > "$d/kbytestotal"; n=$((n + 1))
done
echo secret > "$work/outside"
ln -s "$work/outside" "$sysr/osd-ldiskfs/fs-OST0000/kbytesavail"      # symlink: must be skipped
echo do-not-read > "$sysr/obdfilter/fs-OST0000/evict_client"            # not in the allowlist
head -c 200000 /dev/zero | tr '\0' 'x' > "$procr/mdt/fs-MDT0000/md_stats" # oversized: truncated
sed -e "s|^SYS_ROOT=.*|SYS_ROOT=$sysr|" -e "s|^PROC_ROOT=.*|PROC_ROOT=$procr|" \
    -e "s|^DEBUG_ROOT=.*|DEBUG_ROOT=$dbgr|" "$here/pfs-read.sh" > "$work/pfs-read.sh"
out=$work/out.txt
sh "$work/pfs-read.sh" > "$out"
fail=0
ck() { if "$@"; then echo "ok   $DESC"; else echo "FAIL $DESC"; fail=1; fi; }
DESC="allowlisted capacity value present"; ck grep -q '^400000$' "$out"
DESC="allowlisted stats file present"; ck grep -q 'read_bytes 3 samples' "$out"
DESC="symlink skipped"; ck sh -c "! grep -q secret '$out'"
DESC="non-allowlisted file skipped"; ck sh -c "! grep -q do-not-read '$out'"
DESC="oversized file read as exactly one header and 65536 payload bytes"
ck sh -c "[ \"\$(grep -c '^== mdt/' '$out')\" = 1 ] && [ \"\$(awk '/^== mdt\\//{getline; print length(\$0), (\$0 ~ /^x+\$/ ? \"allx\" : \"mixed\")}' '$out')\" = '65536 allx' ]"
DESC="stats group still read after 70 small targets"; ck grep -q 'read_bytes 3 samples' "$out"
DESC="arguments rejected"; ck sh -c "! sh '$work/pfs-read.sh' x 2>/dev/null"
echo "output: $(wc -c < "$out") bytes; work dir $work"
exit $fail
