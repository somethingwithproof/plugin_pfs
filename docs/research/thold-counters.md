# Thold and counter-derived data sources

Research for [#22](https://github.com/somethingwithproof/plugin_pfs/issues/22) and decision input for [#8](https://github.com/somethingwithproof/plugin_pfs/issues/8), run 2026-11-15. Question: can Thold alert on the rate of a DERIVE data source, and what value does it see at `poller_output`?

**Result.** Yes for rate alerts on increasing counters, with a reset caveat. Thold's `poller_output` hook receives the raw polled value, not the rate RRDtool stores, and `thold_get_currentval()` computes its own rate for the data source type recorded in `data_template_rrd`: for DERIVE it is `(current - previous) / elapsed seconds` from its own saved previous sample. It does not apply the data source minimum, so on a counter reset Thold computes a negative rate (-300 per second in the fixture run, where RRDtool stores unknown), and on a reset followed by a larger value it computes a false spike (1,200 per second against a true 100), the same as RRDtool. A gap longer than the effective heartbeat gives no value for that poll and re-anchors the baseline. This holds at plugin_thold `develop` (`dc76952`, includes/functions.php) and at `v1.8.2` (`1eb406b`, thold_functions.php) with one difference: v1.8.2 also computes a rate on the first sample after install. For the COUNTER type the two versions differ on a reset: `develop` returns 0 for the fixture reset while v1.8.2 returns 71,582,488 per second. So, for the decision in #8: option 1 (Thold on DERIVE data sources directly) works for high-threshold rate alerts if the plugin accepts that a reset produces one negative sample and a larger-value reset one false spike, which a duration of at least two polls on the threshold absorbs; a plugin-computed rate gauge is needed only if low-threshold rate alerts or reset-proof rates are required. The decision stays with the maintainer. Nothing here ran inside Cacti: the function was extracted and run with stubs.

## Method

Read plugin_thold at `develop` (`dc76952ee52e8b4877b2d5b830caab1f7c43dde9`, committed 2026-11-09) and at the latest release `v1.8.2` (`1eb406bd0f81f3204358cce7bd43c8bb5adc8e4a`, 2024-10-16, `compat = 1.2.25`). The function `thold_get_currentval()` was extracted from each copy and run offline with three stubs (`read_config_option`, `thold_debug`, `cacti_sizeof`) over two counter sequences ([`tests/thold-derive/run.php`](../../tests/thold-derive/run.php)); the script prints the value Thold evaluates at each poll, with the state re-anchoring that Thold does after each poll. No Thold database, threshold, or notification path was run.

## Facts

| Fact | Status | Source |
|---|---|---|
| Thold hooks `poller_output` with `thold_poller_output()` and receives `$rrd_update_array` (raw values by `local_data_id`, before RRDtool processing) | confirmed | [setup.php:78](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/setup.php#L78), [includes/polling.php:116-160](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/polling.php#L116-L160); Cacti side [cacti-core.md](cacti-core.md) |
| For each threshold it calls `thold_get_currentval()`, then applies the threshold's data type: 0 exact, 1 CDEF, 2 percentage, 3 expression, 4 lower/upper data source | confirmed | [polling.php:196-246](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/polling.php#L196-L246); type names in `includes/arrays.php:519`. |
| Thold keeps its own previous sample (`oldvalue`, `lasttime`) in `thold_data` and re-anchors it each poll | confirmed | [functions.php:1237-1262 (`thold_sample_persistence`)](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/functions.php#L1237-L1262), [polling.php:140-160](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/polling.php#L196-L246) |
| Data source types are numbered 1 GAUGE, 2 COUNTER, 3 DERIVE, 4 ABSOLUTE, 5 COMPUTE and Thold switches on `data_source_type_id` | confirmed | [Cacti include/global_arrays.php:562-568](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/include/global_arrays.php#L562-L568); [functions.php, `thold_get_currentval()` at :1409](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/functions.php#L1462-L1530) |
| DERIVE: `(current - oldvalue) / step`; no use of the data source minimum | confirmed (read and run) | case 3 at develop `includes/functions.php`, `thold_get_currentval()` (function at :1409), case 3; v1.8.2 `thold_functions.php`, `thold_get_currentval()` at :737 (`$currentval = ($item[...] - $thold_data['oldvalue']) / $step;`). `rrd_minimum` is used only in CDEF/expression substitution (functions.php:1095, :6192). |
| A sample gap above the effective heartbeat (the larger of the RRD heartbeat and two sample intervals) gives no value (empty) for that poll and the baseline is re-anchored | confirmed in source (develop only) | `thold_get_currentval` `$previous_sample_usable` and `thold_sample_interval_eligible()` ([functions.php:1200-1220](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/functions.php#L1200-L1220)). In the v1.8.2 function body read, the DERIVE branch computes the rate unconditionally. |
| An unavailable sample is preserved as unavailable, not turned into zero | confirmed in source (develop) | [includes/polling.php, `thold_poller_output()`](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/polling.php#L196-L256): comment "Fail closed: an unavailable sample must not be persisted as a manufactured zero". The summary data source's `U` values therefore do not alert. |
| Thold declares `remote_collect:1`, so `thold_poller_output()` runs on a collector | confirmed | thold INFO; gating in [cacti-core.md](cacti-core.md) |

## Fixture runs (offline)

Counter sequence per minute, 6,000 per step = 100 per second; value shown is what `thold_get_currentval()` returned at each poll (index = poll; "(empty)" = no value).

| Version and type | Reset to 0 (0, 6000, 12000, 18000, **0**, 6000, 12000) | Reset then larger (0, 6000, 12000, 18000, **90000**, 96000) |
|---|---|---|
| develop, DERIVE | (empty), 100, 100, 100, **-300**, 100, 100 | (empty), 100, 100, 100, **1200**, 100 |
| v1.8.2, DERIVE | 0, 100, 100, 100, **-300**, 100, 100 | 0, 100, 100, 100, **1200**, 100 |
| develop, COUNTER | (empty), 100, 100, 100, **0**, 100, 100 | (empty), 100, 100, 100, **1200**, 100 |
| v1.8.2, COUNTER | 0, 0, 100, 100, **71,582,488.25**, 0, 100 | 0, 0, 100, 100, **1200**, 100 |

Reading: the first polls differ at install (v1.8.2 computes 0 where develop has no value). COUNTER treats a decrease as a 32-bit wrap; `develop` then applies a "greater than expected maximum means reset" heuristic (the COUNTER branch of `thold_get_currentval()`) and returns the sample over the step, which was 0 here; v1.8.2 returns the wrap delta. Neither gives a correct answer for a Lustre counter that restarts from zero and neither is what RRDtool stores (unknown). The plugin therefore stores cumulative counters as DERIVE (ADR 0005), not COUNTER.

## Maint

Maint suppresses Thold notifications for a device through the guarded function call `plugin_maint_check_cacti_host()`; values are still collected and written. It declares `remote_collect:0`. Evidence and the corrected statement are in [cacti-core.md](cacti-core.md). Not run.

## Decision input for #8

| Option | Evidence |
|---|---|
| Thold alerts on DERIVE data sources directly | Works for high-threshold rate alerts. A reset yields one negative sample; a reset followed by a larger value yields one false spike (a per-counter data source maximum does not stop Thold, which ignores it for DERIVE). A threshold duration of two or more polls absorbs both. Low-threshold rate alerts (for example "write rate fell") can false-fire on the negative sample unless the duration is also two polls |
| Plugin-computed rate gauges in the summary data source | Needed only for reset-proof rate alerts; the reader already detects resets ([counter-reset.md](counter-reset.md)) and could publish unknown for that interval, at the cost of state and a duplicate series |
| No rate alerts in phase 1 | Safe; alerts on capacity, imbalance, and staleness do not depend on counters |

## Limits

- Only `thold_get_currentval()` was run, with stubs; the threshold checks, breach counting, and notification path were not.
- Thold `v1.8.2` is the last release; `develop` may differ from what ships with a given Cacti 1.2.31 install. No tag between 1.8.2 and `develop` was read.
- Behavior with Spine (rather than cmd.php) was not tested; the hook point is the same.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| [#22](https://github.com/somethingwithproof/plugin_pfs/issues/22) | Install Thold and Maint on a Cacti 1.2.31 test instance, create a DERIVE fixture data source and thresholds, feed the sequences above through the poller | The values Thold stores and alerts on, end to end; Maint suppression |
