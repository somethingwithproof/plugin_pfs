# 0002. Alert through polled Cacti data sources, not a plugin alert engine

Status: Accepted (2026-11-14)

## Context

Operators want alerts on fill, imbalance, missing targets, and stale collection through the same path as their other Cacti alerts, with Maint suppression. Thold receives values through Cacti's `poller_output` hook, so it only sees data sources the Cacti poller polls. It cannot read the plugin's cache or RRD files directly.

## Decision

Each filesystem gets a "Parallel Filesystem Summary" data source using a script server input that returns, from the reader's cache, the maximum and spread of OST used percentage, the number of targets missing, the number of targets in a non-healthy condition, and `age`. Values are `U` when data is missing or stale; `age` stays numeric so staleness itself can alert. Per-target gauges are ordinary data sources that Thold can use directly. Thold thresholds and Maint schedules apply with no change to either plugin.

## Consequences

- One notification path for SNMP and filesystem data; no Thold or Maint change.
- Alerts see summary and per-target values, not the full counter history.
- Cacti's poll interval and the node read interval are independent, so a value can be reported twice or skipped; Thold durations should be at least one poll interval.
- Whether Thold can alert on rates from counter data sources is a separate open decision.

## Alternatives rejected

- **Plugin-owned incident engine.** Duplicates Thold and Maint and adds a third notification path.
- **Thold reading the cache directly.** Would be a Thold redesign.
- **Writing values into Cacti RRDs outside the poller.** Bypasses the poller and conflicts with boost.

## Research update (2026-11-15)

Milestone 1 research ([cacti-core](../research/cacti-core.md), [thold-counters](../research/thold-counters.md)) checked this record. The statements about `poller_output` and that Thold sees only polled data sources hold. Two details are corrected. Maint does not act through a hook: Maint registers an `is_device_in_maintenance` hook that no caller uses, and Thold (and Cacti's SNMP agent code) instead call `plugin_maint_check_cacti_host()` directly after loading Maint's functions file, so the Maint suppression applies to Thold notifications for the device and values are still collected. Maint declares `remote_collect:0`, so on a remote collector Maint's hooks do not run. The open question about rates from counter data sources is answered in [thold-counters](../research/thold-counters.md); the decision itself stays open (issue #8).
