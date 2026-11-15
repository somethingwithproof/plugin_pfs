# Node privileges and a restricted read path

Research for [#20](https://github.com/somethingwithproof/plugin_pfs/issues/20) and decision input for [#7](https://github.com/somethingwithproof/plugin_pfs/issues/7), run 2026-11-15. Question: what is the least privilege that lets a fixed read-only command print the plugin's parameters on each node type, and can a forced SSH command or unit enforce it?

**Result.** From Lustre source, the capacity, inode, and condition parameters are sysfs attributes with mode 0444 (read-only) or 0644 (read-write, root-owned), so an ordinary user can read them; the `stats`, `md_stats`, and `job_stats` files are procfs files with mode 0644 on 2.15.8 and 2.16.1, and on 2.17.0 the OST `stats` and `recovery_status` files are created in debugfs, which the kernel documents as root-only by default. So the least privilege is an unprivileged service user on 2.15 and 2.16, and probably root, or a debugfs mount with a relaxed mode, for OST counters on 2.17. That last point and every per-node-type result are *live*: no node was available, so the per-parameter table below is derived from source modes and registration code, not from `lctl get_param` as an unprivileged user. A fixed, argument-free read script was written and tested against a fixture tree ([tests/restricted-read](../../tests/restricted-read/pfs-read.sh)); it has not run on a Lustre node or as a forced SSH command. Recommendation for the access decision: a fixed node-side script run as an unprivileged user, reached by a forced SSH command or a local unit that the site chooses, with the exporter as an optional alternative; the decision stays with the maintainer ([lustre-exporter.md](lustre-exporter.md)).

## Per-parameter privilege (derived from source)

"Unprivileged" means the file's mode and location allow a non-root read; it was not tried. `sysfs` attributes under `/sys/fs/lustre`, `procfs` under `/proc/fs/lustre`, `debugfs` under `/sys/kernel/debug/lustre`.

| Parameter | Location and mode | 2.15.8 | 2.16.1 | 2.17.0 | Basis |
|---|---|---|---|---|---|
| `osd-*.<target>.kbytestotal`, `kbytesfree`, `kbytesavail`, `filestotal`, `filesfree` (OST and MDT) | sysfs, 0444 | unprivileged | unprivileged | unprivileged | `dt_def_attrs` with `LUSTRE_RO_ATTR` ([lustre-parameters.md](lustre-parameters.md)) |
| `obdfilter.<ost>.kbytes*`, `files*` | sysfs, 0444 | unprivileged | unprivileged | unprivileged | `obd_def_attrs` |
| `obdfilter.<ost>.degraded`, `no_precreate`, `no_create`, `readonly` | sysfs, 0644 | unprivileged read | unprivileged read | unprivileged read | `LUSTRE_RW_ATTR` (write needs root; the plugin never writes) |
| `obdfilter.<ost>.stats` | procfs 0644 (2.15.8, 2.16.1); debugfs 0644 (2.17.0) | unprivileged | unprivileged | root by default (debugfs root dir "accessible only to the root user by default"; mount options `uid`, `gid`, `mode` can change it) | registration code and [kernel debugfs documentation](https://docs.kernel.org/filesystems/debugfs.html) |
| `obdfilter.<ost>.recovery_status` | procfs (2.15.8, 2.16.1); debugfs (2.17.0) | unprivileged | unprivileged | root by default | `LPROC_SEQ_FOPS_RO_TYPE` versus `LDEBUGFS_SEQ_FOPS_RO_TYPE` |
| `mdt.<mdt>.md_stats` | procfs | unprivileged | unprivileged | unprivileged | `lprocfs_register_stats` / `lprocfs_stats_register` on the procfs entry |
| `obdfilter.<ost>.job_stats`, `mdt.<mdt>.job_stats` | procfs, `default_mode(fops)` | read mode not read from source | same | same | `lprocfs_add_simple` mode comes from `default_mode()`; its value was not read. Job stats also carry user-chosen job IDs, so they are excluded from the default read |
| `jobid_var`, `job_cleanup_interval` | sysfs | readable | readable | readable | `LUSTRE_RW_ATTR` |

Procfs entries under `/proc/fs/lustre` are created with the modes in the registration calls (0644 for stats files); the directory modes and any distribution hardening (`hidepid`, restrictive `/proc` mounts, SELinux or AppArmor profiles for the service user) were not examined and are *live*. Node types: the OST attributes above exist only on an OSS (OFD devices), `mdt.*` and the MDT `osd-*` only on an MDS; a combined node has both. Client-side values (`llite`, phase 2) were not read.

## Restricted read prototype

[`tests/restricted-read/pfs-read.sh`](../../tests/restricted-read/pfs-read.sh) is a POSIX shell script with these properties, each exercised by [`tests/restricted-read/run.sh`](../../tests/restricted-read/run.sh) on a fixture tree (6 checks pass; the script copy used in the test has only its three root constants rewritten):

| Property | How | Tested |
|---|---|---|
| Takes no arguments | exits 64 if `$# != 0` | yes |
| Reads only an allowlist | fixed list of (root, glob) pairs in the script; no input in any path | yes: a non-listed file is not printed |
| Does not follow links or read non-files | `[ -f ]` and `[ -L ]` checks | yes: a symlink to another file is skipped |
| Bounded output | `head -c 65536` per file; total capped at 4 MiB | yes for the per-file cap |
| Writes nothing | only `head` and `printf` | by inspection |
| Fixed `PATH`, locale, `umask` | set at the top | by inspection |
| A failed read is not a zero | prints `!! read-failed` after the file header | not exercised on a real error |
| Output framing | `== <path relative to its root>` then the file, then a blank line | yes |

It does not set or clear anything and cannot be asked to read another path. It does not protect against a compromised server, which can print any content: the parser on the Cacti side stays strict (plan, Security). The script lists the 2.17 debugfs paths; reading them needs root or a relaxed debugfs mount, and which to ask for is a site decision.

## Forced command and unit

| Mechanism | Source | Notes |
|---|---|---|
| SSH `command=` in `authorized_keys` runs one command whenever the key authenticates, ignoring the client's request; `restrict` disables port, agent, X11 forwarding and PTY; `from=` limits source hosts; the original request is available as `SSH_ORIGINAL_COMMAND` | [sshd(8)](https://man.openbsd.org/sshd.8), AUTHORIZED_KEYS FILE FORMAT | Cacti would hold a private key, which the plan's Security section requires to be stored and never logged or passed as an argument. Not tested against a real sshd. |
| A systemd unit or socket on the node serving the script's output locally | not researched | No source read; listed so the decision includes it. |
| Site-written file drop | n/a | Freshness and ownership are the site's problem (issue #7 option). |

## Decision input for #7

| Option | Privilege on server | Exposure | Covers |
|---|---|---|---|
| Exporter scrape | unprivileged service user for sysfs; procfs `stats`; 2.17 OST stats unclear | an open TCP port with no TLS or authentication in the flags | capacity, bytes, samples, `degraded`; not reset evidence or condition flags ([lustre-exporter.md](lustre-exporter.md)) |
| Forced SSH command with a fixed script | unprivileged user on 2.15 and 2.16; root or relaxed debugfs for 2.17 OST counters | SSH on the existing management network; one key | everything in the plan |
| Client mount for capacity only | none on servers | none | capacity from `lfs df`; no counters |
| File drop | site's choice | none from the plugin | whatever the site writes |

Open points: a threat model for a Cacti-held SSH key, and the 2.17 debugfs question. The plan and ADR 0003 and 0008 are consistent with a fixed script behind any of the transports.

## Limits

- No `lctl get_param` was run as any user. Modes and locations come from source; distribution packaging, SELinux, and mount options can change them.
- The prototype ran only against a fixture, in `sh` on macOS; its behavior under other `sh` implementations and on a node with thousands of devices was not tested.
- Client nodes and GPFS/BeeGFS privileges are covered in [mmpmon.md](mmpmon.md) and [beegfs.md](beegfs.md).

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| [#20](https://github.com/somethingwithproof/plugin_pfs/issues/20) | As an unprivileged user on OSS, MDS, and combined nodes of each matrix release, run the prototype and `lctl get_param -r` on each allowlisted path | The per-parameter table, per node type |
| [#20](https://github.com/somethingwithproof/plugin_pfs/issues/20) | Same on 2.17.x with debugfs mounted default and with a relaxed mode | The 2.17 privilege cost |
| [#20](https://github.com/somethingwithproof/plugin_pfs/issues/20) | Install the script as a forced command for a test key; try a different command, a long output, and a hung node | Forced-command enforcement and failure behavior (feeds #7) |
