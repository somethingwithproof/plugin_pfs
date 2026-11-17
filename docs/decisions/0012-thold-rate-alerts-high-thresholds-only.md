# 0012. Allow Thold rate alerts on counters for high thresholds only

Status: Accepted (2026-11-15), amended 2026-11-15 after the milestone 4 Thold run (see [Amendment](#amendment-2026-11-15)). Decides #8.

## Context

Cumulative counters are stored as DERIVE with minimum 0 (ADR 0005). Offline RRDtool runs ([counter-reset](../research/counter-reset.md)) showed what a minimum of 0 catches: a reset to a smaller value gives one unknown interval. A reset hidden by a later, larger value gives a rate that is only a lower bound. A data source maximum is a plausibility bound, not a reset filter. Thold does not use the RRD's rate ([thold-counters](../research/thold-counters.md)). It computes its own rate from the raw polled value, ignores the data source minimum and maximum, and gave -300 per second on the fixture reset at both `develop` and v1.8.2. A threshold duration of two polls absorbs one isolated artifact. It is not reset-proof when a genuine breach comes just before the artifact. Whether Thold re-anchors its baseline after an unavailable sample was not read.

## Decision

- **Graphs.** Counters are DERIVE, minimum 0, with a generous per-counter maximum taken from a documented ceiling. The reader also detects a reset (a decrease, a changed `start_time`, a changed source node, or an unknown previous value) and publishes `U` for the interval containing it. A counter is published only while its observation is current, so a missed read is unknown and not a flat zero followed by a doubled rate.
- **Thold, rates.** Thold may alert on a DERIVE counter data source, per target or on the summary aggregates, with high thresholds only and a duration of at least two polls. Low-threshold rate alerts ("write rate fell below") are unsupported in phase 1. A reset can reach Thold as a negative rate, which a low threshold reads as a breach.
- **Thold, gauges.** Capacity, fill, imbalance, target counts, and age are gauges and may use any threshold type.
- **No plugin-computed rate gauges in phase 1.** If pilots need reset-proof or low-threshold rate alerts, a later record adds them.

## Consequences

- The documentation states the unsupported case. The Thold and Maint validation in milestone 4 tests the supported ones.
- A detected reset reaches Thold as `U`. An undetected reset, possible only where `start_time` is not printed, still reaches Thold as one negative or lower-bound sample, which the two-poll duration absorbs for high thresholds.
- Nothing in the plugin depends on a Thold version.

## Alternatives rejected

- **Plugin-computed rate gauges now.** Reset-proof, but it adds per-value state and a second series beside every counter before any pilot has asked for low-threshold rate alerts.
- **No rate alerts in phase 1.** It throws away high-threshold alerts, which the research shows work with a two-poll duration.

## Amendment (2026-11-15)

### Context

The milestone 4 end-to-end run with Thold v1.8.2 ([thold-counters](../research/thold-counters.md#milestone-4-end-to-end-run)) showed that the decision above cannot hold with that release. Thold stores a DERIVE item's raw polled value as its previous value without checking that it is a number. After the `U` the reader publishes for a reset or a change of contributing targets, the next numeric value makes Thold's rate calculation throw a PHP `TypeError` in the poller output hook, and Cacti disables Thold for the whole install. The reader publishes that `U` by design, so a supported rate threshold would disable Thold at the first reset.

### Decision

- **Thold, rates.** Rate alerts use the summary's rate gauges, `read_bytes_rate`, `write_bytes_rate`, and `md_ops_rate`. The reader computes each from two consecutive sums of the same members read in one run each, at most two intervals apart, and publishes `U` for a reset, a member change, a stale or first read, or a sum that went down, the same cases the DERIVE value is unknown for. They are GAUGE data sources, so Thold reads them as levels and never computes a rate.
- **Thold, counters.** Thresholds on DERIVE counter data sources, per target or summary, are unsupported with Thold 1.8.2.
- **Low thresholds on rates.** Still unsupported in phase 1: in Thold 1.8.2's source an unknown value takes the not-breached path, so a low rate threshold would restore on every reset (read, not run).
- **Graphs.** Unchanged: per-target and summary counters stay DERIVE, and the overview graphs draw them. The Throughput and Metadata Operations graphs also print the rate gauges, because Cacti stores only the data source items a graph uses.
- The "no plugin-computed rate gauges in phase 1" item above is withdrawn.

### Consequences

- Rate alerts are per filesystem only; there are no per-target rate gauges.
- A reset makes the gauge unknown for two or three polls (all targets resetting together, or some), which a two-poll duration does not count as a breach. `tests/bin/alert-replay.sh` checks that a high rate threshold on `write_bytes_rate` fires, restores, stays quiet and enabled through a reset, and fires again after it.
- Overview graphs created before this change have RRD files without the rate gauges, since Cacti 1.2 does not add a data source item to an existing file. No release had them; a pre-release install must recreate its Throughput and Metadata Operations graphs.
- If a Thold release guards DERIVE values (its development branch at `dc76952` does), counter thresholds can be reconsidered in a later record.
