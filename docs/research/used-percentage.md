# Used percentage and lfs df

Research for [#17](https://github.com/somethingwithproof/plugin_pfs/issues/17), run 2026-11-15. Question: which formula for target used percentage agrees with `lfs df`, and does `lfs df` report the same capacity as the server-side kbytes parameters?

**Result.** From source at `v2_15_8` and `v2_17_0`, `lfs df` computes `Use%` as `used / (used + avail) * 100`, rounded up, with `used = blocks - bfree` and `avail = bavail` from the target's statfs; it is not `used / total`. The server-side `kbytestotal`, `kbytesfree`, and `kbytesavail` are the same three statfs values scaled to KiB, so the same formula applied to them gives the same percentage. A property check of the two code paths (re-implemented from the source, 20,004 cases) found no disagreement. That is a consistency check of formulas, not a measurement: agreement on real targets, the difference between a client's view (`lfs df`) and a server's own statfs, and behavior with an offline target are *live*. The `lfs df` option lists per release are confirmed from the man pages and are in the table below. The exit criterion "measured agreement on every fixture target" is not met.

## Formula

Define for each target, from the three server parameters (all in KiB):

```
used_kib = kbytestotal - kbytesfree
used_pct = ceil( used_kib / (used_kib + kbytesavail) * 100 )      (0 when the denominator is 0)
```

Inodes use the same shape with `used = filestotal - filesfree` and `avail = filesfree`. The plan's `fill_max_pct` and `fill_spread_pct` use this value for OSTs. It differs from `used / total` whenever reserved blocks exist (`bavail < bfree`), which is the normal case on ldiskfs.

## Facts

| Fact | Status | Source |
|---|---|---|
| `lfs df` `Use%` is `used / (used + avail) * 100` rounded up "to match df(1)" | confirmed in source (2.15.8 and 2.17.0 identical) | `obd_statfs_ratio()`: [2.15.8 lfs.c:6319-6335](https://github.com/lustre/lustre-release/blob/ee228420b7fa12f183bceb9864ff083cb07e8964/lustre/utils/lfs.c#L6319-L6335); same function at 2.17.0 (`lfs.c`, "obd_statfs_ratio"). Space: `avail = os_bavail`, `used = os_blocks - os_bfree`. Inodes: `avail = os_ffree`, `used = os_files - os_ffree`. |
| `lfs df` columns: `1K-blocks` (total = `os_blocks * os_bsize`), `Used` (`(os_blocks - os_bfree) * os_bsize`), `Available` (`os_bavail * os_bsize`) | confirmed in source | `showdf()`: [2.15.8 lfs.c:6359-6385](https://github.com/lustre/lustre-release/blob/ee228420b7fa12f183bceb9864ff083cb07e8964/lustre/utils/lfs.c#L6359-L6385). |
| Server `kbytestotal` = `os_blocks` scaled by `os_bsize / 1024` (power-of-two rounded); `kbytesfree`, `kbytesavail` = `os_bfree`, `os_bavail` shifted by the same factor | confirmed in source | [2.17.0 lprocfs_status.c:265-336](https://github.com/lustre/lustre-release/blob/4b0407a48e9e5b7f17877e8868080e9f40c238c6/lustre/obdclass/lprocfs_status.c#L265-L336). With a power-of-two `bsize / 1024` (ldiskfs 4 KiB, ZFS 128 KiB) the scaling cancels in the ratio. |
| Formula from kbytes equals `lfs df` | confirmed for the formulas, *live* for targets | `php tests/used-percent/run.php`; see [tests/used-percent](../../tests/used-percent/run.php): 20,004 cases (block sizes 4096, 8192, 131072, 1048576 and edge cases) agree. The check models the C code; it does not read a Lustre target. |
| ZFS-backed targets report estimated inode totals | confirmed in man page | `lfs df -i`: for ZFS "the `IUsed` count accurately reflects the number of in-use objects ... but the `Inodes` total and `Free` inode counts are ESTIMATED" ([2.17.0 lfs-df.1](https://github.com/lustre/lustre-release/blob/4b0407a48e9e5b7f17877e8868080e9f40c238c6/Documentation/man1/lfs-df.1)). Inode fill on ZFS targets is an estimate and must be labeled so. |
| `lfs df` status flags `D` degraded, `R` read-only, `N`, `S` out of space, `I` out of inodes, `f` flash (shown with `-v`) | confirmed | `N` is "no-precreate" in the 2.15.8 and 2.16.1 man pages and "no-create" in 2.17.0. Flag table in code: [2.15.8 lfs.c:6340-6351](https://github.com/lustre/lustre-release/blob/ee228420b7fa12f183bceb9864ff083cb07e8964/lustre/utils/lfs.c#L6340-L6351). |

## `lfs df` options per release

From the man page SYNOPSIS and OPTIONS at each tag ([2.15.8](https://github.com/lustre/lustre-release/blob/ee228420b7fa12f183bceb9864ff083cb07e8964/lustre/doc/lfs-df.1), [2.16.1](https://github.com/lustre/lustre-release/blob/718a3fd9c78507a589a657ec3745b4c553f66633/lustre/doc/lfs-df.1), [2.17.0](https://github.com/lustre/lustre-release/blob/4b0407a48e9e5b7f17877e8868080e9f40c238c6/Documentation/man1/lfs-df.1)).

| Option | 2.15.8 | 2.16.1 | 2.17.0 |
|---|---|---|---|
| `-h`, `-H`, `-i`, `-l`/`--lazy`, `-p`/`--pool`, `-v` | yes | yes | yes |
| `-m`/`--mdt`, `-o`/`--ost` | no | yes | yes (with optional index) |
| `-N`/`--no-header`, `-s`/`--only-summary`, `-u`/`--output=FIELD_LIST` | no | no | yes |
| JSON or YAML output | no | no | no |

A client that works on all three lines can rely only on the first row and must parse text. Capacity from the server-side parameters needs no `lfs`, and is the preferred source ([node-privileges.md](node-privileges.md)). `lfs df` remains the reconciliation reference.

## Limits

- No `lfs df` was run. Cached statfs (1 s on the server parameters; the client path has its own caching) means two reads can differ by activity in between; the size of that difference is *live*.
- `lfs df --lazy` and offline targets: the man page says `--lazy` does not contact targets not currently connected; what a client prints for an offline target, with and without `--lazy`, was not observed.
- The 2.16.1 `lfs.c` was not read; only the man page was.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| [#17](https://github.com/somethingwithproof/plugin_pfs/issues/17) | On a test filesystem, `lfs df`, `lfs df -i`, and server `kbytes*`, `files*` for every OST and MDT, ldiskfs and ZFS | Measured agreement of the formula; ZFS inode estimate |
| [#17](https://github.com/somethingwithproof/plugin_pfs/issues/17) | Stop one OST; run `lfs df` and `lfs df --lazy` on a client | Offline target output and blocking behavior |
