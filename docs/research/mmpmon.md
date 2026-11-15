# mmpmon behavior on Storage Scale releases

Research for [#27](https://github.com/somethingwithproof/plugin_pfs/issues/27), run 2026-11-15. Question: which `mmpmon` requests, `-p` keywords, and counter semantics hold on the Storage Scale releases in scope?

**Result.** The keyword definitions and the counter semantics are now sourced, from the IBM Spectrum Scale 5.1.0 Problem Determination Guide (PDF); the 5.2.3 command page confirms the privilege and the request list. `fs_io_s` and `io_s` values are cumulative since the file system was mounted or since the last `reset`, whichever is more recent; a file system's statistics vanish when it is unmounted; `_br_` and `_bw_` are unsigned 64-bit counters; and IBM says the counters "are maintained by GPFS at all times, even when mmpmon has not been invoked". There is one set of `fs_io_s` and `io_s` counters per node, so anything that calls `reset` changes every consumer's view. The values describe I/O "on the node running mmpmon, not the file system in total", so mmpmon is a per-node client-activity view, not a server target view; it fits the plan's phase 3 "counters of a node" but not the Lustre-style per-target values. `mmpmon` needs root on the node (5.1.0 and 5.2.3 agree). Not confirmed: whether 5.2.3 renamed or added keywords (the keyword table was read in 5.1.0 only), and whether IBM's performance monitoring (`mmperfmon`, zimon) offers a read path without root on the node. Capacity from `mmdf` is a different command and its privilege is described below. The exit criteria are partly met: the field table is sourced for 5.1.0, the recommended read path for the privilege decision is a fixed node-side wrapper (below), and nothing was run against Storage Scale.

## Method

Read IBM's 5.2.3 `mmpmon` command page and the 5.1.0 Problem Determination Guide (PDF fetched into a scratch directory outside the repository, text extracted with `pdftotext`). The 5.2.3 pages of the monitoring chapter could not be opened through the fetch tool (it returned index pages); the 5.1.0 PDF is the only source for the field table. No cluster, license, or credential was used.

## Facts

| Fact | Status | Source |
|---|---|---|
| `mmpmon` "must be run by a user with root authority, on the node for which you want statistics"; options `-p` parseable, `-r` repeat (default 1), `-d` delay ms (default 1000, 500 to 8,000,000), `-t` daemon timeout s (default 60), `-s` no prompt, `-i` command file | confirmed (5.2.3) | [mmpmon command, 5.2.3](https://www.ibm.com/docs/en/storage-scale/5.2.3?topic=reference-mmpmon-command); also "The mmpmon command must be run using root authority" in 5.1.0 PDG, "Specifying input to the mmpmon command" |
| Requests `fs_io_s` (I/O statistics per mounted file system), `io_s` (entire node), `reset` ("Resets statistics to zero"), `ver`, `nlist ...`, `rhist ...`, `rpc_s`, `qosio`, `vio_s`, `vio_s_reset`, `loc_io_s`, `once` | confirmed (5.2.3) | same page |
| `fs_io_s` values are "cumulative since the file systems were mounted or since the last reset request, whichever is most recent. When a file system is unmounted, its statistics are lost." They are for activity "on the node running mmpmon, not the file system in total (across the cluster)" | confirmed (5.1.0) | [5.1.0 Problem Determination Guide](https://aix.software.ibm.com/storage/EOS/Scale/scale_pdg_510.pdf), "Display I/O statistics per mounted file system" (printed pages 8 to 9 of the extracted text) |
| `io_s`: cumulative since mount or last reset, per node, statistics of an unmounted file system vanish from the node total | confirmed (5.1.0) | same, "Display I/O statistics for the entire node" |
| Counters are "maintained by GPFS at all times, even when mmpmon has not been invoked"; `_br_` and `_bw_` are unsigned 64-bit integers; counters "may wrap"; IBM suggests `reset` before a sequence of requests | confirmed (5.1.0) | same, "Counter sizes and counter wrapping". The plugin must not call `reset` (it would change what every other consumer sees). Wrap at 64 bits is not a practical concern. |
| One set of `fs_io_s` and `io_s` counters per node, shared by up to five concurrent `mmpmon` instances; consumers "must coordinate any activities that could reset the counters" | confirmed (5.1.0) | same, "Running mmpmon concurrently from multiple users on the same node" |
| `reset` does not reset `rhist` histogram data | confirmed (5.1.0) | same |

## `fs_io_s` keyword table (`-p` output)

Table 8 of the 5.1.0 guide, in output order.

| Keyword | Meaning (IBM's wording) | Counter or label |
|---|---|---|
| `_n_` | IP address of the node responding | label |
| `_nn_` | Hostname that corresponds to `_n_` | label |
| `_rc_` | Status of the operation | status; a non-zero value is a failed read |
| `_t_`, `_tu_` | Current time of day: seconds since the Epoch, and the microseconds part | timestamp |
| `_cl_` | Name of the cluster that owns the file system | label |
| `_fs_` | Name of the file system | label |
| `_d_` | Number of disks in the file system | gauge |
| `_br_` | "Total number of bytes read, from both disk and cache" | cumulative counter |
| `_bw_` | "Total number of bytes written, to both disk and cache" | cumulative counter |
| `_oc_` | Count of `open()` requests serviced by GPFS, including `creat()` | cumulative counter |
| `_cc_` | Number of `close()` requests serviced by GPFS | cumulative counter |
| `_rdc_` | Number of application read requests serviced by GPFS | cumulative counter |
| `_wc_` | Number of application write requests serviced by GPFS | cumulative counter |
| `_dir_` | Number of `readdir()` requests serviced by GPFS | cumulative counter |
| `_iu_` | Number of inode updates to disk | cumulative counter |

The `io_s` table (Table 9) has the same node and time keywords followed by the node-wide counters; its field names were not copied here. The classification "cumulative counter" follows IBM's text above, and the same 5.1.0 text applies to both requests.

## Capacity and pool usage

| Fact | Status | Source |
|---|---|---|
| `mmdf <fs>` reports storage usage per file system and per pool ("To query the storage usage for an individual storage pool, the user must issue the mmdf command") | confirmed (5.1.0) | 5.1.0 PDG, the file system usage check that runs `df` and then `mmdf fs1`; output columns not read |
| `mmdf` is among the commands that carry the setuid bit, because removing the bit "can produce errors for non-root users" | confirmed (5.1.0), indirect | 5.1.0 PDG, problem determination for quorum loss. So non-root `mmdf` is the default. This was not tested |
| IBM's own capacity sensor `GPFSDiskCap` runs `mmdf` "in the background", "typically runs once per day as it is resource intensive", "should be restricted to run on a single node only" | confirmed (5.1.0) | 5.1.0 PDG, performance monitoring sensors; and `GPFSPool` (pool and file system capacity, "typically runs every 5 minutes") |
| `mmperfmon` and the performance monitoring bridge for Grafana exist | confirmed (5.1.0) | 5.1.0 PDG table of contents ("Using IBM Spectrum Scale performance monitoring bridge with Grafana"); whether a non-root read path to zimon data exists is unverified |

## Recommended read path (for the privilege decision)

A fixed wrapper on each node that runs `mmpmon -p -s` with an input file containing only `fs_io_s` and `io_s` (never `reset`), under a narrow `sudo` rule or a service unit; the Cacti host never holds root on a node (plan, Security). Capacity from `mmdf` once per hour or slower, on one node, not per node. This is a recommendation from the documents above; no wrapper was written or run. Another monitoring tool (ClusterCockpit's GPFS collector, found while searching) uses the same pattern, `mmpmon` through password-less `sudo` for a monitoring user, and says only that "`mmpmon` typically require root"; it does not say anything about resets ([gpfsMetric.md](https://git.clustercockpit.org/ClusterCockpit/cc-metric-collector/raw/branch/main/collectors/gpfsMetric.md)).

## Limits

- Only the 5.1.0 Problem Determination Guide was read for keywords and semantics; 5.2.3's equivalent page was not reachable. Field names may differ.
- No `mmpmon` or `mmdf` output was seen. `_rc_` values and error formats were not read.
- The 5.1.0 PDG page references (printed pages 8 to 11 for `fs_io_s` and `io_s`) are from the extracted text.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| [#27](https://github.com/somethingwithproof/plugin_pfs/issues/27) | On a Storage Scale test cluster (5.2.x), run `mmpmon -p -s` with `fs_io_s` and `io_s`; compare keywords with Table 8 | Keyword set on the release in scope |
| [#27](https://github.com/somethingwithproof/plugin_pfs/issues/27) | Run it as a non-root user, and through a restricted `sudo` rule | Privilege path |
| [#27](https://github.com/somethingwithproof/plugin_pfs/issues/27) | Unmount and remount a file system; read before and after | Counters vanish and restart; reset evidence |
| [#27](https://github.com/somethingwithproof/plugin_pfs/issues/27) | `mmdf <fs>` as a non-root user; time and output shape | Capacity read path and cost |
| [#27](https://github.com/somethingwithproof/plugin_pfs/issues/27) | `mmperfmon query` as a non-root user | Whether a non-root monitoring path exists |
