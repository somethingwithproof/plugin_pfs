# 0012. Allow Thold rate alerts on counters for high thresholds only

Status: Accepted (2026-11-15). Decides #8.

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
