# Lustre release and distribution matrix

Research for [#19](https://github.com/somethingwithproof/plugin_pfs/issues/19), run 2026-11-15. Question: which Lustre releases, server operating systems, and backend filesystems must the plugin support?

**Result.** Support Lustre 2.15.x (the long-term support line and the line most respondents run), 2.16.x, and 2.17.x. The OpenSFS community survey presented at LUG 2026 (47 respondents) found 2.15.x the clear leader in production, 2.16.x and 2.17.x used mainly by sites on newer server distributions, RHEL 8.x the majority server distribution, and ldiskfs on 64 percent of installations against ZFS on 30 percent (6 percent both). Drop rule proposed: support a line while it is the long-term support line or has had a release in the last 12 months; the plugin reads only the parameters in [lustre-parameters.md](lustre-parameters.md), so a line not in the matrix is "unsupported, may work", not blocked. The test filesystem recipes and a survey of what sites run beyond this one survey are not done: building a filesystem is *live*, and only one survey source was read in full. The mirror shows no `v2_15_9` or `v2_18_0` tag at the check, although the April 2026 roadmap expected 2.15.9 in Q2 2026 and 2.18.0 in Q4 2026.

## Releases

| Line | Latest tag in the mirror | Commit date | Status | Source |
|---|---|---|---|---|
| 2.15.x | `v2_15_8` | 2025-12-02 | Long-term support; "2.15.9 up next" with RHEL 9.8 client support; roadmap "2.15.x LTS releases continue" | [LUG 2026 community release update](https://www.opensfs.org/wp-content/uploads/01-Community-Release-Update-LUG-2026-FINAL.pdf), slides "Lustre 2.15.x LTS" and "Lustre Community Roadmap" (read in full) |
| 2.16.x | `v2_16_1` | 2024-11-16 | Feature release, no later tag in the mirror; the survey says it is used on "more current distros for servers" | same, survey analysis slide; tag from [mirror tags](https://github.com/lustre/lustre-release/tags) |
| 2.17.x | `v2_17_0` | 2025-12-30 | Feature release, GA "late last year"; candidate next LTS (47 percent of respondents want it, 53 percent want 2.18) | LUG slides "Lustre 2.17" and "Community Survey - LTS Transition"; the LWG planned an announcement ahead of ISC26 (not found) |
| 2.18 | none at the check | n/a | Targeted Q4 2026: RHEL 9.8 servers, RHEL 10.2 clients, client data compression, erasure coding | LUG slide "Lustre 2.18" |

Checked 2026-11-15 with `gh api repos/lustre/lustre-release/tags`; the mirror may lag releases. Tag commits: `v2_15_8` ee22842, `v2_16_1` 718a3fd, `v2_17_0` 4b0407a (full SHAs in [lustre-parameters.md](lustre-parameters.md)).

## Server and client platforms

From `lustre/ChangeLog` in each tag ("Server primary kernels built and tested during release cycle"; the file points to the Whamcloud Lustre Support Matrix for the current list). Other kernels listed in the file are "known to build and work at some point".

| Release | Server primary kernels | Client primary kernels (selection) | Source |
|---|---|---|---|
| 2.15.8 | RHEL 8.10 only | RHEL 9.5, 9.6, 9.7, RHEL 8.10, Ubuntu 22.04, SLES 15 SP5 and SP6 | [ChangeLog](https://github.com/lustre/lustre-release/blob/ee228420b7fa12f183bceb9864ff083cb07e8964/lustre/ChangeLog) |
| 2.16.1 | RHEL 9.4, 9.3, 8.10, 8.9, 8.8 | RHEL 9.4 first (list continues) | [ChangeLog](https://github.com/lustre/lustre-release/blob/718a3fd9c78507a589a657ec3745b4c553f66633/lustre/ChangeLog) |
| 2.17.0 | RHEL 9.7, 9.6, 9.5, 9.4, 9.3, 8.10, 8.9, 8.8 | RHEL 10.1, 10.0, 9.7 (list continues) | [ChangeLog](https://github.com/lustre/lustre-release/blob/4b0407a48e9e5b7f17877e8868080e9f40c238c6/lustre/ChangeLog) |

Survey distributions, servers: RHEL 7.x, 8.x, 9.x, Ubuntu 22.04 and 24.04, with "RHEL 8.x servers the clear majority" and "still significant numbers of RHEL7 despite limited support". Clients: "RHEL 9.x clients have overtaken RHEL 8.x as most common". The plugin never runs on a Lustre server or client as a module; what matters is that the parameter files in [lustre-parameters.md](lustre-parameters.md) behave per release, not the distribution. Distribution only matters for a node-side wrapper script (shell, no extra runtime) and for the exporter route ([lustre-exporter.md](lustre-exporter.md)).

## Backends and topology

| Item | Finding | Source |
|---|---|---|
| ldiskfs and ZFS | Both in use: ldiskfs 64 percent, ZFS 30 percent, both 6 percent of respondents. Both OSDs register the same device attributes (`dt_def_attrs`), so the capacity parameters are common | LUG slide "ZFS vs ldiskfs"; [dt_object.c at v2_17_0](https://github.com/lustre/lustre-release/blob/4b0407a48e9e5b7f17877e8868080e9f40c238c6/lustre/obdclass/dt_object.c#L1258-L1270) |
| ZFS inode counts | Estimated by Lustre | [used-percentage.md](used-percentage.md) |
| ZFS versions supported per release | Not found in the ChangeLog files read | unverified |
| Combined MGS/MDS and DNE (several MDTs) | Parameters are per target (`osd-*.<fsname>-MDT<nnnn>`); a combined node serves several target types. Not tested | inference from [lustre-parameters.md](lustre-parameters.md); *live* |

## What sites run

| Source | What it says | Limit |
|---|---|---|
| LUG 2026 community survey, 47 respondents, annual since 2012 | 2.15.x clear leader; 2.16.x and 2.17.x on newer distributions; 72 percent of respondents have used Lustre over 10 years; 52 percent self-supported, 39 percent formal support | One survey of volunteers; the respondents skew to experienced, open-source users, which the slides say themselves |
| Vendor documentation | Not read | Not done |
| Other conference updates | Not read | Not done |

## Test filesystem recipe

Lustre ships a single-node loopback test setup: `lustre/tests/llmount.sh` and `lustre/tests/cfg/local.sh` exist at all three tags ([2.17.0 cfg/local.sh](https://github.com/lustre/lustre-release/blob/4b0407a48e9e5b7f17877e8868080e9f40c238c6/lustre/tests/cfg/local.sh)). Whether that setup runs on a small VM per line, with ldiskfs and ZFS, was not tried; the recipe per line is *live* and the exit criterion is not met.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| [#19](https://github.com/somethingwithproof/plugin_pfs/issues/19) | Build a one-node or three-node test filesystem per supported line (ldiskfs and ZFS), record steps and sizes | Recipes, and the fixture source for [#16](https://github.com/somethingwithproof/plugin_pfs/issues/16) |
| [#19](https://github.com/somethingwithproof/plugin_pfs/issues/19) | Check the Whamcloud Lustre Support Matrix and the release notes at the time of M2 | Current server kernels and ZFS versions; whether 2.15.9, 2.17 as LTS, or 2.18 changed the lines |
