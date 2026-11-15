# lustre_exporter as a node source

Research for [#18](https://github.com/somethingwithproof/plugin_pfs/issues/18), run 2026-11-15. Question: can the plugin rely on GSI-HPC/lustre_exporter as the node source, and what does that require of the sites and the plugin?

**Result.** Not as the only node source. From the source at `master` (commit `8991c864d1ba82f4b0e81bef087f15c0bd923f2c`, 2026-08-05) the exporter exposes the capacity, inode, byte, sample, and job values the plugin needs, but (1) it reads OST `stats` from procfs, where Lustre 2.17.0 no longer creates the file (see [lustre-parameters.md](lustre-parameters.md)), so coverage of 2.17 is doubtful; (2) it does not expose `start_time` or `elapsed_time`, `recovery_status`, `no_create`, `readonly`, or `statfs_state`, so the plugin could detect resets only from counter decreases and could not report target condition beyond `degraded`; (3) its listener is plain `http.ListenAndServe` with no TLS, authentication, or allowlist option in the flags, so an open port exposes every metric to the network; (4) the README's version table still lists only Lustre 2.12 with tag v2.1.6, and its own issue tracker has an open question about deprecating it in favor of Whamcloud's `lustrefs-exporter`. It is a reasonable optional source for sites that already run it behind their own network controls. A matrix of exporter release against Lustre release needs real nodes and is *live*, so the exit criterion for it is not met. The recommendation for the access decision is in [node-privileges.md](node-privileges.md).

## Method

Read the repository through the GitHub API at the default branch head (files saved outside the repository), plus the release list, changelog, and open issues. Nothing was run; the exporter was not built.

| Item | Value |
|---|---|
| Repository | [GSI-HPC/lustre_exporter](https://github.com/GSI-HPC/lustre_exporter), GPL-3.0 |
| Head read | `8991c864d1ba82f4b0e81bef087f15c0bd923f2c`, committed 2026-08-05 (README, `lustre_exporter.go`, `sources/*.go`) |
| Latest release | `v2.1.9`, 2026-03-31 (releases `v2.1.7` 2026-02-18, `v2.1.8` 2026-02-25); `VERSION` at head reads `2.1.10_dirty` |
| Open issues | 9 open at the check (includes dependency bumps) |

## Facts

| Fact | Status | Source |
|---|---|---|
| Listener `:9169`, path `/metrics`, served by `http.ListenAndServe`; flags are `collector.*`, `web.listen-address`, `web.telemetry-path`, `log.level`, `log.file`, `version`; no TLS or auth flags | confirmed in source | [lustre_exporter.go:116-128, :210](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/lustre_exporter.go#L116-L128). Listener security is therefore left to the host firewall or a reverse proxy; `web.listen-address` can bind to a management interface. |
| Collectors `ost`, `mdt`, `mgs`, `mds`, `client`, `generic`, `lnet`, `health`, each `disabled`, `core`, or `extended`; all default to `extended` | confirmed | same lines; [README](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/README.md#L69-L96). `core` is a subset marked per metric in the source tables. |
| OST capacity and inodes: `capacity_kibibytes`, `free_kibibytes`, `available_kibibytes`, `inodes_maximum`, `inodes_free`, `blocksize_bytes` | confirmed in source | read from sysfs `osd-*/*-OST*` files `kbytestotal`, `kbytesfree`, `kbytesavail`, `filestotal`, `filesfree`: [sources/sysfs.go:81-92](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/sources/sysfs.go#L81-L92); same for `osd-*/*-MDT*` (:128-134). All are `core`. |
| OST bytes and samples: `read_samples_total`, `read_bytes_total`, `write_samples_total`, `write_bytes_total`, plus `stats_total` with per-operation values | confirmed in source | read from procfs `obdfilter/*-OST*/stats`: [sources/procfs.go:117-141](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/sources/procfs.go#L117-L141). Counters are passed through as Prometheus counters; the source does not compute rates. |
| MDT operation counters | confirmed in source | `stats_total` (counter, multi-valued) from the MDT stats file, [sources/sysfs.go:137](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/sources/sysfs.go#L137), and `job_stats_total` (:139). The exact file path read for MDT stats was not traced. |
| Labels | confirmed in source | `component` and `target` on every metric; `operation` and `size` on brw metrics; `jobid` on job metrics; `operation` or similar as the extra label for multi-valued stats ([procfs.go:351-364](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/sources/procfs.go#L351-L364), [sysfs.go:186-196](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/sources/sysfs.go#L186-L196)). The plan's "label names unconfirmed" is resolved for these. |
| Condition values the exporter exposes | confirmed (absence) | `degraded` (OST, sysfs) and `health_check` only. A search for `recovery_status`, `no_precreate`, `no_create`, `readonly`, `statfs_state`, `start_time`, `elapsed_time` in the source finds nothing in the metric tables. |
| Missing or unreadable files | partly confirmed | A source `Update` that returns an error is logged and the scrape result for that source is labeled `error` ([lustre_exporter.go:73-84](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/lustre_exporter.go#L73-L84)); the parse loops skip some bad blocks with `continue` (procfs.go :419, :423, :637). Which of these produce no series versus a partial scrape was not traced to the HTTP response; the plugin must treat an absent series as unknown, never zero. |
| LNET metrics read DebugFS and need root or a DebugFS mount other users can read | confirmed | [README:104-108](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/README.md#L104-L108); `sys.go:95` sets the base path to `<sys>/kernel/debug`. The systemd unit's default user is `prometheus`. |
| Changelog users use `sudo lctl get_param` | confirmed in source | [sources/lctl.go:120-130](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/sources/lctl.go#L120-L130) runs `sudo lctl get_param` (only for `mdd.*-*.changelog_users`). The v2.1.4 changelog says "Remove user check for root and sudo". Not needed by the plugin. |
| README version table lists only Lustre 2.12 with tag v2.1.6 | confirmed | [README:10-14](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/README.md#L10-L14). Releases through v2.1.9 exist; v2.1.9 notes: "Get more MGS, MDT and OST metrics via sysfs" ([CHANGELOG](https://github.com/GSI-HPC/lustre_exporter/blob/8991c864d1ba82f4b0e81bef087f15c0bd923f2c/CHANGELOG.md)). |
| Known problems | confirmed (issue text) | [#30](https://github.com/GSI-HPC/lustre_exporter/issues/30), open: job_stats queries on large clusters take "4-5 seconds", file re-read repeatedly; [#38](https://github.com/GSI-HPC/lustre_exporter/issues/38), open: on 2.15.4 `read_bytes` and `exports_total` missing while `write_bytes` is present (no resolution seen); [#44](https://github.com/GSI-HPC/lustre_exporter/issues/44), open: evaluate [whamcloud/lustrefs-exporter](https://github.com/whamcloud/lustrefs-exporter) and "probably deprecate this exporter" if it fits. |
| Response size and scrape time with many targets | *live* | Not measurable without a server. Issue #30 reports multi-second scrapes with many jobs; the plugin would exclude job metrics from the default. |
| Needs on the plugin | inference | An HTTP GET with timeout and response limit, a parser for the Prometheus text format restricted to an allowlist of series names, and per-release behavior for missing series. No credentials, but an open port per server. |

## Decision input

| Option | Fit |
|---|---|
| Exporter scrape | Covers capacity, inodes, bytes, samples, and `degraded`. Does not cover 2.17 OST counters as read from procfs (not confirmed live), reset evidence, or condition flags. No transport security. Treat as an optional source |
| Whamcloud `lustrefs-exporter` | Named by the exporter's own tracker; a Rust exporter, last push 2026-08-20. Not evaluated here (repository metadata only; license field empty in the API response) |
| Fixed node-side read | Covers everything the plan lists, at the cost of a node-side script and a transport ([node-privileges.md](node-privileges.md)) |

## Limits

- Exporter versus Lustre release matrix: not produced. The exit criterion "pass or fail per metric" needs a node per release.
- The metric label set was read from source for the plan's values only; the whole series set at `core` and `extended` was not enumerated.
- Whether the exporter's `stats` path fails on 2.17 depends on the debugfs question in [lustre-parameters.md](lustre-parameters.md), which is *live*.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| [#18](https://github.com/somethingwithproof/plugin_pfs/issues/18) | Run v2.1.9 on one OSS and MDS per release (2.15.8, 2.16.1, 2.17.0) as an unprivileged user; list the series names and labels | Matrix of exporter against Lustre release; whether `read_bytes_total` and friends appear on 2.17 |
| [#18](https://github.com/somethingwithproof/plugin_pfs/issues/18) | Scrape a server with several hundred targets or jobs; record time and bytes | Response size and scrape time |
| [#18](https://github.com/somethingwithproof/plugin_pfs/issues/18) | Same scrape with a stats file made unreadable | Missing-series behavior |
