# 0005. Store cumulative counters raw as DERIVE with minimum 0

Status: Accepted (2026-11-14)

## Context

Lustre statistics are cumulative: a `stats` file lists, per operation, a sample count and a sum since the file was initialized or last cleared. In the source at tag `v2_17_0`, any write to a stats file clears it, so counters can drop to zero outside the plugin's control, and so can a module reload or remount. GPFS `mmpmon` documents that `reset` "Resets statistics to zero". RRDtool's COUNTER type assumes overflow at 32 or 64 bits and corrects for it; DERIVE "works exactly like COUNTER but without overflow checks", and RRDtool's guidance is to combine DERIVE with a minimum of 0 when a counter does not wrap at those borders.

## Decision

Cumulative counters are stored as raw values in DERIVE data sources with minimum 0, so RRDtool computes rates and a decrease becomes an unknown interval. The reader also detects a decrease itself and publishes unknown for that interval, because a reset followed by a value larger than the last pre-reset value cannot be seen by a minimum of 0. Capacity values are GAUGE. Aggregates across targets are unknown for an interval in which any contributor is missing or reset, so a partial sum is never shown as a throughput drop.

## Consequences

- A reset costs one unknown interval, not a spike.
- Rates come from RRDtool, so the plugin keeps no previous-sample state for graphs.
- Alerting on rates depends on how Thold evaluates these data sources; that is an open decision.

## Alternatives rejected

- **COUNTER type.** Its overflow correction turns a reset into a large false value.
- **Plugin-computed rates for graphs.** Duplicates RRDtool and adds state.

## Research update (2026-11-15)

Measured offline with RRDtool ([counter-reset](../research/counter-reset.md)) and by running Thold's rate function ([thold-counters](../research/thold-counters.md)). Two statements here are corrected. "A reset costs one unknown interval, not a spike" is true only when the counter restarts below the last value; a reset hidden by a later, larger value gives a rate that is only the endpoint difference (1,200 per second for a 72,000 jump in 60 seconds in the fixture; if a reset hid in the step the true rate was higher, so it is a lower bound, not an overcount), and the minimum cannot see it. A per-counter data source maximum turns such an interval into unknown, at the price of also dropping genuine traffic above the maximum, so it is a plausibility bound and not a reset filter. "Rates come from RRDtool" holds for graphs only: Thold computes its own rate from the polled value and does not apply the minimum, so on a reset it sees a negative rate. The decision stands, with a generous per-counter maximum added to the DERIVE template and the reader's reset rule (decrease or changed `start_time`) as proposed in the research. The statement that a write to a stats file clears it holds at Lustre 2.15.8, 2.16.1, and 2.17.0 ([lustre-parameters](../research/lustre-parameters.md)).
