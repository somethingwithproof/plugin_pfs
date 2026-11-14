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
