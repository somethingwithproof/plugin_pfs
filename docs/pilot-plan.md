# Phase 1 pilot plan

Plan for [#57](https://github.com/somethingwithproof/plugin_pfs/issues/57) (REQ-PILOT-001, REQ-PILOT-002). Fixtures and the container runs cannot show what a real filesystem does; pilots can. Nothing below has run yet.

## Pilots

| Pilot | Filesystem | Purpose |
|---|---|---|
| A | A Lustre test filesystem, at least 2 OSS and 1 MDS, one of the matrix releases (2.15, 2.16, or 2.17) | First contact: node setup, read script, values against `lfs df` and server counters, failure drills |
| B | A multi-server, production-like Lustre filesystem, ideally another release than pilot A, with failover pairs | Scale, failover, long-running counters, alert noise |

Each pilot needs a site contact with root on the servers for setup, a Cacti 1.2.31 or later install on PHP 8.2 or later, and agreement to share a redacted report.

## Before the pilot

1. Install the plugin from the release candidate, following [operator-guide.md](operator-guide.md) and [node-setup.md](node-setup.md) as written. Record every step that was unclear or wrong; that record closes the acceptance criterion of #53.
2. Record the site profile: Lustre release and build, OSS and MDS counts, OSTs per OSS, failover pairs, how debugfs access was granted (or not), Cacti version, poller type (`cmd.php` or Spine), poller interval, and hardware of the Cacti host and database.
3. Confirm the reader account can read only what [node-privileges](research/node-privileges.md) lists; record `sudo -l -U pfsread` (or equivalent) and the `authorized_keys` line.

## Checks

Run each check at least once; repeat the comparisons at three times of day.

| # | Check | Method | Pass when |
|---|---|---|---|
| 1 | Fill agrees with `lfs df` | On a client, `lfs df` and `lfs df -i` at the same minute as a reader run; compare each OST's used percentage, the summary maximum, minimum, and spread | Each OST within 1 percentage point; summary values match the same rounding |
| 2 | Throughput agrees with the servers | `lctl get_param obdfilter.*.stats` twice, 300 s apart, on each OSS; compute read and write bytes per second; compare with the per-OST and filesystem graphs for the same interval | Within 2 percent per OST and for the sum |
| 3 | Metadata rate agrees | `lctl get_param mdt.*.md_stats` twice, 300 s apart; compare with the MDT and filesystem metadata graphs | Within 2 percent |
| 4 | Idle targets | Find an OST with no `write_bytes` line; confirm the filesystem write rate still draws and the per-OST graph shows a gap ([ADR 0013](decisions/0013-absent-counter-lines-count-as-zero-in-sums.md)) | As described |
| 5 | Absent counter rule | For each release in the pilot, confirm from real output that a counter that has never counted is left out of `stats` and `md_stats` (the source says so) | Confirmed, or ADR 0013 is reopened |
| 6 | Node down | Stop sshd or block the poller on one OSS for two intervals | Health page names the node; targets go delayed then stale; sums read unknown; `age` and `targets_missing` alert in Thold; recovery is automatic |
| 7 | Failover | Fail an OST over to its partner | One unknown interval on its counters; capacity continues; no duplicate data source |
| 8 | Counter clear | With the site's agreement, clear one OST's stats (`lctl set_param obdfilter.<ost>.stats=clear`) | One unknown interval; no spike |
| 9 | Thold and Maint | The thresholds in [alerting.md](alerting.md) on gauges only; a Maint schedule over a planned change | Alerts and restorals as tested; nothing notified during the schedule |
| 10 | Scale | Reader run time, poll cycle time, and page times from Cacti's own statistics over a week | Reader within its budget; poll cycle within the interval; pages under two seconds |
| 11 | Privilege | Confirm the reader account could not read or run anything beyond the allowlist | No extra access found |

## Report

Each pilot report, attached to #57, lists:

- the site profile above, with names and addresses redacted;
- the result of each check, with the commands and outputs used to compare;
- every defect found, its severity, and its disposition (fixed, with the commit; deferred, with the issue; or not a defect, with the reason);
- install guide problems, for #53;
- measurements, for [research/scale.md](research/scale.md).

## Exit

#57 closes when both pilot reports are attached, checks 1 to 3 pass on both, and every defect has a disposition. A failed check reopens the design question it touches before the phase 1 release.
