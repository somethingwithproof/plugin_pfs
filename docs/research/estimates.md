# Estimates for phases 1 to 3

Research for [#26](https://github.com/somethingwithproof/plugin_pfs/issues/26), run 2026-11-15, after the other milestone 1 research documents. Question: how large is each later milestone, and what gates it?

**Result.** About 9,300 to 14,300 lines of production PHP across milestones 2, 3, 5, and 6 (the sum of the per-milestone ranges below), with milestones 2 and 3 (most of phase 1) 7,500 to 11,000 of it. Milestone 4 is mostly tests, documentation, and packaging; its 500 to 1,000 lines are listed separately and are not part of these production totals, and its small production share (the summary data source and release glue) is not estimated separately. Size is the better-grounded figure; it comes from measured lines of two comparable plugins. This document does not give calendar durations: the commit spans of the comparable plugins are not effort, both were built with AI assistance, and the largest gates here (live Lustre nodes, a Storage Scale cluster, a BeeGFS system, security reviews, maintainer decisions on repository name and test harness) are outside the code. Several of those gates are listed as *live* in the other documents, and none of those live checks has run, so the numbers below may move when they do. Estimates must be re-made after each release, as the issue requires.

## Basis

| Plugin | Production lines | Test lines | Commit | Scope |
|---|---|---|---|---|
| [plugin_cloudflare](https://github.com/somethingwithproof/plugin_cloudflare) | 10,479 | 20,347 | `c08c99bbdfd0cea5fbd0da22dbde43bdb91ec4f0` (2026-11-14), history from 2026-10-21 | One provider: accounts, vault, curl transport, discovery, metrics, script server, templates |
| [plugin_smokeping](https://github.com/Cacti/plugin_smokeping) | 14,932 | 11,328 | `481547c54050d97f413d3f23408cf4e88e7ef39f` (2026-11-14), `main` | Observe mode, devices, data sources, smoke graphs, release qualification |

Lines are non-vendor `.php`, `.sh`, `.pl`, and `.py` files in each repository's `main`, counted with `git cat-file blob | wc -l`; production excludes `tests/` and `features/`. The plugin_cloud research measured 10,324 and 13,249 production lines on 2026-11-14 for the same two plugins; both repositories moved since (plugin_smokeping's `main` has 1,683 more production lines than in that count). For plugin_cloudflare's areas the plugin_cloud estimate gave: pages and lifecycle about 3,300 lines, accounts 1,050, HTTP 610, storage and migrations 800, secrets 410, jobs 380, discovery 980, metrics 850 ([estimates.md](https://github.com/somethingwithproof/plugin_cloud/blob/main/docs/research/estimates.md)); those numbers were not re-derived here.

## Per milestone

| Milestone | Production lines | Grounding | Gates outside the code |
|---|---|---|---|
| 2. Foundation (phase 1) | 4,500 to 6,500 | plugin_cloudflare's lifecycle, pages, storage and migrations, jobs, and settings areas (3,300 + 800 + 380 = about 4,500); plus the class layout, the backend and node-source interfaces, the reader, and the state model; credentials and a vault only if the SSH node source is chosen (plugin_cloudflare's secrets 410 and accounts 1,050 areas, about 1,500) | Decision on node access ([#7](https://github.com/somethingwithproof/plugin_pfs/issues/7)); test harness and repository name with Cacti maintainers |
| 3. Lustre observation (phase 1) | 3,000 to 4,500, plus template XML and data query XML | plugin_cloudflare's discovery, metrics, script server, and template areas (discovery 980 + metrics 850 = about 1,800, plus script server and templates); plus the Lustre parsers for `stats`, `md_stats`, `job_stats`, `lfs df`, with per-release name tables ([lustre-parameters.md](lustre-parameters.md)); fixtures per release | Fixtures from real nodes for every matrix release ([#16](https://github.com/somethingwithproof/plugin_pfs/issues/16), [#19](https://github.com/somethingwithproof/plugin_pfs/issues/19)); device model and default graph set decisions; scale measurement ([#23](https://github.com/somethingwithproof/plugin_pfs/issues/23)) |
| 4. Alerting and phase 1 release | Not estimated separately (small); 500 to 1,000 lines of tests, docs, and packaging, not counted in the production totals | plugin_smokeping's release qualification, failure tests, and packaging milestone, as used in the plugin_cloud estimate | Security review; Lustre test filesystem pilot; Thold and Maint check on a live Cacti ([#22](https://github.com/somethingwithproof/plugin_pfs/issues/22)) |
| 5. Remote collectors and Lustre depth (phase 2) | 1,000 to 1,800 | plugin_cloud's remote milestone estimate (1,000 to 1,500) for collector replication and `remote_agent` actions; plus bounded job and client tables | A Cacti remote collector test bed ([#25](https://github.com/somethingwithproof/plugin_pfs/issues/25) live check); phase 2 security review |
| 6. Other backends (phase 3) | 800 to 1,500 | Two parsers behind the backend interface: `mmpmon -p` and `mmdf` ([mmpmon.md](mmpmon.md)); BeeGFS through the Mon database or the CLI ([beegfs.md](beegfs.md)). Smaller than plugin_cloud's per-provider estimates (1,000 to 1,500 each), which include HTTP transport and credentials that these local-node reads do not | A Storage Scale cluster and a BeeGFS system; privilege decisions; phase 3 security review |

Totals: milestones 2 and 3 sum to 7,500 to 11,000 production lines, and milestones 2, 3, 5, and 6 to 9,300 to 14,300 by addition of the production ranges above. The 500 to 1,000 non-production lines of milestone 4 are in addition. Tests were 0.8 times (plugin_smokeping) and 1.9 times (plugin_cloudflare) production lines in the two plugins; no multiplier is assumed here.

## Uncertainty

- Milestone 2 depends on the node source: a Prometheus-format scrape needs a text parser and an HTTP client (about plugin_cloudflare's HTTP area, 610 lines); an SSH forced command needs credential storage and a process runner. The decision is open ([node-privileges.md](node-privileges.md)).
- Milestone 3 depends on the 2.17 debugfs question ([lustre-parameters.md](lustre-parameters.md)): if OST counters need root there, the node-side wrapper grows, and the per-release parsing table is larger than assumed.
- The Cacti object model (data query per target versus fewer data sources) changes provisioning code; call counts are in [scale.md](scale.md).
- BeeGFS has the least documentation; milestone 6's upper bound could rise if the read path needs the CLI parsing that no official page describes.
- No live check has run. A fact that proves wrong in a live check changes the milestone that depends on it, most likely 3 and 6.
