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
# The fixed, argument-free node read of ADR 0008, installed on each Lustre
# server and run as the forced command of the reader's key:
#
#   restrict,command="/usr/local/libexec/pfs-read.sh" ssh-ed25519 AAAA... cacti-pfs
#
# It prints an allowlist of Lustre parameter files, each framed by a header
# line, and nothing else. It takes no arguments and ignores
# SSH_ORIGINAL_COMMAND, so the client cannot ask for anything else. It writes
# nothing, skips symbolic links and anything that is not a regular file,
# and never opens a path that is not in the allowlist. Run it as an
# unprivileged user; on Lustre 2.17 the OST stats and recovery_status files
# are in debugfs, which only root can read by default
# (docs/research/node-privileges.md).
#
# Output, protocol 1, which lib/LustreBackend.php parses:
#
#   #pfs-read 1
#   == <path relative to its sysfs, procfs, or debugfs root>
#   <at most 64 KiB of the file>
#   !! read-failed        (the read returned an error)
#   !! truncated          (the file was longer than 64 KiB)
#   <blank line>
#   ...
#   !! total-limit        (4 MiB printed; the rest was not read)
#   #pfs-read end
#
# Evolved from the milestone 1 prototype in tests/restricted-read. It has not
# run on a real Lustre node or as a real forced command yet (live checks in
# issue #20).

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
# and never contain client input.
list() {
	cat <<LIST
$SYS_ROOT version
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

printf '#pfs-read 1\n'

list | {
	total=0
	while read -r root glob; do
		for f in "$root"/$glob; do
			[ -f "$f" ] || continue
			[ -L "$f" ] && continue
			if [ "$total" -ge "$MAX_TOTAL" ]; then
				printf '!! total-limit\n'
				break 2
			fi
			rel=${f#"$root"/}
			# Bounded read of one byte past the limit, so a longer file is
			# seen as truncated. The sentinel "x<exit status>" keeps
			# trailing newlines and carries head's status out of the
			# command substitution.
			out=$(head -c "$((MAX_FILE + 1))" "$f" 2>/dev/null; printf 'x%s' "$?")
			rc=${out##*x}
			data=${out%x*}
			cut=0
			if [ "${#data}" -gt "$MAX_FILE" ]; then
				data=$(printf '%s' "$data" | head -c "$MAX_FILE"; printf x)
				data=${data%x}
				cut=1
			fi
			printf '== %s\n' "$rel"
			printf '%s' "$data"
			case $data in
				'' | *'
') ;;
				*) printf '\n' ;;
			esac
			# A failed or cut-off read is reported, never turned into zeros.
			[ "$rc" -eq 0 ] || printf '!! read-failed\n'
			[ "$cut" -eq 0 ] || printf '!! truncated\n'
			printf '\n'
			# Charge the bytes actually emitted: payload plus framing.
			total=$((total + ${#data} + ${#rel} + 8))
		done
	done
}

printf '#pfs-read end\n'
