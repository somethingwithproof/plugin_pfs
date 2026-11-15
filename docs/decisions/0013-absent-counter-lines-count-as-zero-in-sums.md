# 0013. Count an absent counter line as zero in filesystem sums, for healthy targets only

Status: Accepted (2026-11-15). Owner decision for #50.

## Context

Lustre leaves a counter out of a target's `stats` or `md_stats` file until the counter first counts. The source shows this at `v2_15_8`, `v2_16_1`, and `v2_17_0` ([counter semantics](../PLAN.md#counter-semantics)): an OST that has never been written prints `read_bytes` but no `write_bytes` line. In milestone 3 an absent line was unknown, and because a filesystem sum needs every target, one idle OST made `write_bytes` for the whole filesystem `U` for as long as it stayed idle. The same held for `md_ops` when an MDT had never seen one of the allowlisted operations, which is common for rare operations and certain on a release that lacks one.

An absent line is not the only way a value goes missing. A section can fail to read, be cut off, or not be printed at all because the node is down or the target moved. Those must stay unknown, or an outage would draw as a throughput drop.

## Decision

- **OST sums.** In the filesystem-wide OST counter sums (`read_bytes`, `write_bytes`), a counter whose line is absent counts as 0 only when all of these hold for that target in the same read:
  - the node printed the target's stats section completely (its `snapshot_time` line is present and the section was not marked failed or truncated);
  - the target is present and its condition is in service;
  - the target reported at least one other known value in that read;
  - the observation is current;
  - the counter did not have a value before under a different or the same `start_time`, and its node did not change: a counter that had counted and is now absent was cleared, so that read is a reset and unknown, and only later reads under the new `start_time` count as 0.
- **`md_ops`.** The zero rule does not apply to MDTs: the read script reads no condition flags for an MDT, so its condition is never in service. `md_ops` sums the operations each MDT printed; an operation that is absent is left out of the sum's member set. While the set of printed operations stays the same the sum is unchanged by this, and when an operation first appears the member set changes and that interval is unknown once.
- **Everything else stays unknown.** A target that is missing, stale, unhealthy, of unknown condition, or whose read failed keeps the sum `U`, as before. A line that is present but malformed is unknown, never zero.
- **Per-target values stay unknown.** The target's own data source still serves `U` for the absent counter. Only the filesystem sum treats it as zero, so the per-target graph shows that the counter was never printed.
- **Continuity.** The reader records the absent line as its own continuity state (`absent`), not as a value. When the counter first appears, its interval continues from zero only if the node printed the same `start_time` as before and the same node answered; otherwise the interval is a reset and is unknown once.

## Consequences

- An idle OST no longer hides the filesystem throughput, and a rarely used metadata operation does not hide the metadata rate.
- The member set of a sum includes an absent-but-healthy target, so the first write to an idle OST does not change the set and does not cost an unknown interval when its `start_time` is printed.
- On a release that lacks an allowlisted metadata operation, the operation is left out of `md_ops`, which gives the same sum as counting it as zero; the first time a rare operation is printed costs one unknown interval.
- A stats clear that removes a counter line which had counted is a reset, not a zero, so the sum is unknown for that read rather than undercounting the target.

## Alternatives rejected

- **Absent means unknown everywhere.** Correct but useless on a real filesystem, where some OST is always idle.
- **Absent means zero everywhere, per target too.** A per-target graph would draw a never-written OST as measured zero traffic, which a broken read can mimic.
- **Zero for any present target.** A degraded or unknown-condition target may be mid-failover or mid-recovery, where a missing line is not evidence of no traffic.
