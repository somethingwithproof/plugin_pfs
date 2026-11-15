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
# Prototype of the fixed, argument-free read described in ADR 0008. It prints
# an allowlist of Lustre parameter files from a node, each framed by a header
# line, with per-file and total size limits (the total counts the bytes actually printed, framing included). It takes no arguments, ignores its
# environment for paths, writes nothing, skips symbolic links and anything that
# is not a regular file, and never opens a path that is not in the allowlist.
# It is a prototype for review; it has not run against a real Lustre node.

SYS_ROOT=/sys/fs/lustre
PROC_ROOT=/proc/fs/lustre
DEBUG_ROOT=/sys/kernel/debug/lustre
MAX_FILE=65536
MAX_TOTAL=4194304

[ "$#" -eq 0 ] || { echo "pfs-read: no arguments accepted" >&2; exit 64; }

PATH=/usr/bin:/bin
export PATH
LC_ALL=C
export LC_ALL
umask 077

# Allowlist: <root> <glob relative to root>. Globs are expanded by the shell
# and never contain operator input.
list() {
	cat <<LIST
$SYS_ROOT osd-*/*-OST*/kbytestotal
$SYS_ROOT osd-*/*-OST*/kbytesfree
$SYS_ROOT osd-*/*-OST*/kbytesavail
$SYS_ROOT osd-*/*-OST*/filestotal
$SYS_ROOT osd-*/*-OST*/filesfree
$SYS_ROOT osd-*/*-MDT*/kbytestotal
$SYS_ROOT osd-*/*-MDT*/kbytesfree
$SYS_ROOT osd-*/*-MDT*/kbytesavail
$SYS_ROOT osd-*/*-MDT*/filestotal
$SYS_ROOT osd-*/*-MDT*/filesfree
$SYS_ROOT obdfilter/*-OST*/degraded
$SYS_ROOT obdfilter/*-OST*/no_precreate
$SYS_ROOT obdfilter/*-OST*/no_create
$SYS_ROOT obdfilter/*-OST*/readonly
$PROC_ROOT obdfilter/*-OST*/stats
$PROC_ROOT obdfilter/*-OST*/recovery_status
$PROC_ROOT mdt/*-MDT*/md_stats
$DEBUG_ROOT obdfilter/*-OST*/stats
$DEBUG_ROOT obdfilter/*-OST*/recovery_status
LIST
}

total=0
list | while read -r root glob; do
	for f in "$root"/$glob; do
		[ -f "$f" ] || continue
		[ -L "$f" ] && continue
		[ "$total" -lt "$MAX_TOTAL" ] || exit 0
		rel=${f#"$root"/}
		# Bounded read. The sentinel "x<exit status>" keeps trailing newlines
		# and carries head's status out of the command substitution.
		out=$(head -c "$MAX_FILE" "$f" 2>/dev/null; printf 'x%s' "$?")
		rc=${out##*x}
		data=${out%x*}
		printf '== %s\n' "$rel"
		printf '%s' "$data"
		if [ "$rc" -ne 0 ]; then
			# A failed read is reported, never turned into zeros.
			printf '!! read-failed\n'
		fi
		printf '\n'
		# Charge the bytes actually emitted: payload plus framing.
		total=$((total + ${#data} + ${#rel} + 8))
	done
done
