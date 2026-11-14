# 0001. Ship read-only observation first, in phases that each release

Status: Accepted (2026-11-14)

## Context

A parallel filesystem plugin could grow into target management, quota enforcement, striping advice, and cross-product orchestration. Writing to a production filesystem or its servers from a monitoring system carries a security and safety cost, and most of the value operators ask for (fill, imbalance, throughput, metadata rates) needs only reads.

## Decision

Release in phases that each ship on their own:

1. Observation of Lustre: inventory, devices, capacity and fill, OST throughput, MDT operation rates, graphs, Thold alerting, Maint suppression, health.
2. Remote data collectors, job-level and client-level views.
3. GPFS (IBM Storage Scale) and BeeGFS backends.

The plugin never writes, clears, resets, or tunes anything on a node or filesystem. Quota views, network metrics, and a triage view are research.

## Consequences

- Phase 1 is useful to a site with Lustre and Cacti without granting Cacti any write path.
- No phase depends on a later one, so a stalled later phase does not strand earlier work.
- Operators who want Cacti to change filesystem settings will not get it from this plugin.

## Alternatives rejected

- **Management features in phase 1.** Requires a write path and its security review before any read-only value ships.
- **All backends at once.** Delays value until the slowest backend is done, and the other backends' counter and privilege semantics are not yet confirmed.
