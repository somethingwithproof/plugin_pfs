# BeeGFS statistics read paths

Research for [#28](https://github.com/somethingwithproof/plugin_pfs/issues/28), run 2026-11-15. Question: which BeeGFS command or export gives read-only server and target statistics on the releases in scope, and what are their counter semantics?

**Result.** The one documented, official read path for server and target statistics is the BeeGFS monitoring service (`beegfs-mon`), which stores them in InfluxDB; its database reference documents the measurements and fields. Most are gauges or per-interval values, not cumulative counters, so the plan's DERIVE-for-counters assumption does not apply to BeeGFS: the one cumulative field the page marks is `numInvalidations`. The `beegfs-ctl --serverstats` flags and output, and the 8.x `beegfs` tool's server statistics commands, are not documented on any official page found: the BeeGFS 8.4 documentation index lists no CTL reference page, and the release notes name only `beegfs stats client` (and the removal of `beegfs-ctl`). So the command and field table the issue asked for cannot be produced from documentation; it needs a BeeGFS test system. The 8.4 note about exporting metrics over OpenTelemetry to Prometheus or Telegraf/InfluxDB sits under Remote Storage Targets (as summarized by the fetch tool), so it should not be read as a server statistics export; confirm by reading the page. Privileges per command were not found (*live*).

## Method

Read the official BeeGFS documentation pages (release notes 8.0 and 8.4, the monitoring service page, the Mon database reference) through the fetch tool, and searched the official site for `beegfs-ctl --serverstats`. Community sources were used only to name flags and are marked unverified. Nothing was run; no BeeGFS system was available.

## Facts

| Fact | Status | Source |
|---|---|---|
| BeeGFS 8.0.0 "Replaces the `beegfs-ctl` command-line interface with a new `beegfs` tool written in Go"; `stats` and `benchmark` modes "now default to executing the most commonly used functionality"; `beegfs-check-servers`, `beegfs-df`, and `beegfs-net` are integrated into the new tool | confirmed | [8.0 release notes](https://doc.beegfs.io/8.0/release_notes.html) (summarized by the fetch tool; headings "BeeGFS Command Line Interface", "Stats and Check modes", "Supportability modes") |
| The 8.0 release notes do not mention `beegfs-mon`, metrics export, or server statistics collection | confirmed | same page |
| `beegfs stats client` exists in 8.0 and 8.1; the 8.0 and 8.1 CTL and Mon are not forward compatible with 8.2 servers (upgrade CTL and Mon to 8.2 first); 8.2 CTL and Mon work with 8.0 and 8.1 | confirmed | [8.4 release notes](https://doc.beegfs.io/8.4/release_notes.html), "Known Issues and Limitations" |
| 8.4 Mon adds "Metadata target capacity and health metrics" | confirmed | same page, "Monitoring (Mon)" |
| 8.4: "exporting metrics and structured logs via the OpenTelemetry Protocol to backends such as Prometheus and Telegraf/InfluxDB", listed under Remote Storage Targets | confirmed (heading per summary) | same page; about RST monitoring |
| The official 8.4 documentation index has a "Command Line Interface (CTL)" entry that links only to the release notes, with no stats reference page | confirmed | [documentation index 8.4.0](https://doc.beegfs.io/latest/index.html) |
| `beegfs-ctl --serverstats --nodetype=storage --history=N` as used in community scripts | unverified (community, not official) | [fhgfs-user mailing list](https://groups.google.com/g/fhgfs-user/c/BHyD0-Da1w0); `--interval` appears in a third-party collector comment without a description. Do not use these as facts. |
| `beegfs-mon` collects statistics into InfluxDB (or Cassandra); needs HTTP access to InfluxDB and the management host (`sysMgmtdHost`); authentication through `connAuthFile` or `connDisableAuthentication`; Telegraf on management, metadata, and storage servers feeds host metrics | confirmed | [Monitoring service, 8.4.0](https://doc.beegfs.io/latest/advanced_topics/mon.html). How Mon collects from servers, its polling interval, and privileges are not documented on that page |

## Mon database (what a read path would return)

From the [Mon database reference, 8.4.0](https://doc.beegfs.io/latest/reference/mon_database_reference.html). The page labels only `numInvalidations` as cumulative; the other classifications are inferred from the descriptions.

| Measurement | Fields | Semantics |
|---|---|---|
| `meta`, `storage` | `isResponding`, `indirectWorklistSize`, `directWorklistSize` (queued tasks); on `storage` also `diskSpaceTotal`, `diskSpaceFree` (bytes, all targets) | gauges |
| `highResMeta`, `highResStorage` | `workRequests`, `queuedRequests` ("in period"), `netSendBytes`, `netRecvBytes`; storage also `diskWriteBytes`, `diskReadBytes` | per-interval (inferred) |
| `metaTargets` | `diskSpaceTotal`, `diskSpaceFree`, `inodesTotal`, `inodesFree`, `targetConsistencyState` (`GOOD`, `NEEDS_RESYNC`, `BAD`) | gauges and a state |
| `storageTargets` | `storageTargetID`, `workRequests`, `queuedRequests`, `diskSpaceTotal`, `diskSpaceFree`, `inodesTotal`, `inodesFree`, `targetConsistencyState` | gauges, per-interval, and a state |
| `invalWatchPerClient` | `numInvalidations` | cumulative since registration (page says use a non-negative derivative) |
| client operation measurements (by node and by user) | per-operation call counts, `B-rd`, `B-wr`, `ops-rd`, `ops-wr` | per-interval counts, keyed by client IP or user ID: cardinality is unbounded, like Lustre job IDs |

Capacity, inode, and consistency values are gauges, matching the plan's GAUGE storage. Per-interval operation counts need a different treatment than cumulative counters: stored as GAUGE, they depend on Mon's interval matching Cacti's, and a missed Mon interval is a missing sample, not a catch-up.

## Recommended read path

For phase 3, for sites that run `beegfs-mon`, read from its InfluxDB over HTTP with a read-only account and a fixed query allowlist, on the BeeGFS releases whose Mon database reference lists the needed fields (7.4 and 8.x; the 7.4.2 reference was reached through the site search and not read here). Sites without Mon would need the `beegfs` CLI, whose server statistics commands are not documented and are *live*. This path makes the plugin an InfluxDB client, which is a different trust boundary from the Lustre node read and needs its own review.

## Limits

- No official page documents `beegfs-ctl --serverstats` flags or output, or the 8.x CLI's server statistics. The command and field table per release the issue asks for is not produced.
- The privileges each command needs were not found.
- The 8.0 and 8.4 release notes were read through a summarizing fetch tool, not as raw pages; headings and quotes should be re-checked at the URL before they are relied on.
- The 7.4 documentation of Mon and `beegfs-ctl` was not read in full.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| [#28](https://github.com/somethingwithproof/plugin_pfs/issues/28) | On a BeeGFS 7.4 and an 8.x test system: `beegfs-ctl --serverstats --help` and `beegfs stats --help`; capture output as fixtures | Flags, fields, per-interval versus cumulative, per release |
| [#28](https://github.com/somethingwithproof/plugin_pfs/issues/28) | Run the same as an unprivileged user and as root | Privileges per command |
| [#28](https://github.com/somethingwithproof/plugin_pfs/issues/28) | Query InfluxDB measurements from a running `beegfs-mon` with a read-only account | Field names, intervals, and the read-only path |
