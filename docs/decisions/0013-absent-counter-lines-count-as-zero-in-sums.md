# 0013. Count an absent counter line as zero in filesystem sums, for healthy targets only

Status: Accepted (2026-11-15). Owner decision for #50.

## Context

Lustre leaves a counter out of a target's `stats` or `md_stats` file until the counter first counts. The source shows this at `v2_15_8`, `v2_16_1`, and `v2_17_0` ([counter semantics](../PLAN.md#counter-semantics)): an OST that has never been written prints `read_bytes` but no `write_bytes` line. In milestone 3 an absent line was unknown, and because a filesystem sum needs every target, one idle OST made `write_bytes` for the whole filesystem `U` for as long as it stayed idle. The same held for `md_ops` when an MDT had never seen one of the allowlisted operations, which is common for rare operations and certain on a release that lacks one.

An absent line is not the only way a value goes missing. A section can fail to read, be cut off, or not be printed at all because the node is down or the target moved. Those must stay unknown, or an outage would draw as a throughput drop.

## Decision

- **Sums.** In the filesystem-wide counter sums (`read_bytes`, `write_bytes`, `md_ops`), a counter whose line is absent counts as 0 only when all of these hold for that target in the same read:
  - the node printed the target's stats section completely (its `snapshot_time` line is present and the section was not marked failed or truncated);
  - the target is present and its condition is in service;
  - the target reported at least one other known value in that read;
  - the observation is current.
- **Everything else stays unknown.** A target that is missing, stale, unhealthy, of unknown condition, or whose read failed keeps the sum `U`, as before. A line that is present but malformed is unknown, never zero.
- **Per-target values stay unknown.** The target's own data source still serves `U` for the absent counter. Only the filesystem sum treats it as zero, so the per-target graph shows that the counter was never printed.
- **Continuity.** The reader records the absent line as its own continuity state (`absent`), not as a value. When the counter first appears, its interval continues from zero only if the node printed the same `start_time` as before and the same node answered; otherwise the interval is a reset and is unknown once.

## Consequences

- An idle OST or a rarely used metadata operation no longer hides the filesystem throughput or metadata rate.
- The member set of a sum includes an absent-but-healthy target, so the first write to an idle OST does not change the set and does not cost an unknown interval when its `start_time` is printed.
- On a release that lacks an allowlisted metadata operation, the operation counts as zero in `md_ops`, which is what that release did.
- If a node ever leaves out a counter line that had counted, the sum would undercount by that target for the read. The source does not do this; a pilot that sees it reopens this record.

## Alternatives rejected

- **Absent means unknown everywhere.** Correct but useless on a real filesystem, where some OST is always idle.
- **Absent means zero everywhere, per target too.** A per-target graph would draw a never-written OST as measured zero traffic, which a broken read can mimic.
- **Zero for any present target.** A degraded or unknown-condition target may be mid-failover or mid-recovery, where a missing line is not evidence of no traffic.
