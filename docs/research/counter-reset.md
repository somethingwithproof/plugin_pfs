# Counter reset, clear, and gap behavior

Research for [#21](https://github.com/somethingwithproof/plugin_pfs/issues/21), run 2026-11-15. Question: what do the counters and the RRD do across target remount, module reload, server reboot, failover, a cleared stats file, and a long poll gap?

**Result.** The RRDtool half is measured offline; the Lustre half is read from source and is *live* for the observed raw values. With a DERIVE data source (minimum 0), a reset to a smaller value yields one unknown interval, a gap longer than the heartbeat yields unknown intervals, and a counter that jumps up in one step (runs B and C: 18,000 to 90,000 in 60 seconds) yields the endpoint difference, 1,200 per second, whatever happened in between. If that jump hides a reset, at least 90,000 new increments happened after it, so the true rate was at least 1,500 per second and the stored 1,200 undercounts; if no reset happened, 1,200 is the average. The RRD cannot tell the two apart, and the minimum cannot see either. Setting the data source maximum (run C, 500 per second) turns such an interval into unknown, which also drops genuinely high traffic above the ceiling, so the maximum is a plausibility bound and not a reset filter. A reader rule of "counter decreased, or `start_time` changed" detects a reset at the reader whenever the value decreased, and a hidden reset only where `start_time` is printed. So DERIVE with minimum 0 is not enough alone; the proposed rule is DERIVE, minimum 0, a per-counter maximum set generously from a documented ceiling, plus the reader's reset detection that publishes unknown. Where `start_time` is switched off, a reset hidden by a later larger value is undetectable and the interval's rate is a lower bound. Thold sees a different value from RRDtool for the same sequence ([thold-counters.md](thold-counters.md)). The event table for real Lustre events (remount, reload, reboot, failover) has no observed values yet.

## RRDtool, measured

Script: [`tests/counter-reset/run.php`](../../tests/counter-reset/run.php), run with `rrdtool` 1.11.0 (Homebrew build on the research machine), step 60 s, heartbeat 120 s, DERIVE with minimum 0. The script uses `rrdtool create`, `update`, and `fetch` in a new directory under the system temp directory; it needs only the binary. Each index is the slot ending at that step. The final null in each row is the slot after the last update.

| Scenario | Counter values per step (6,000 = 100 per second) | Stored rate per second by slot | Reading |
|---|---|---|---|
| A: reset to 0, then normal | 0, 6000, 12000, 18000, **0**, 6000, 12000 | 100, 100, 100, **null**, 100, 100, null | Decrease gives one unknown interval |
| B: synthetic upward jump (value rises 72,000 in one step; could hide a reset), maximum U | 0, 6000, 12000, 18000, **90000**, 96000 | 100, 100, 100, **1200**, 100, null | Endpoint difference only. If a reset hid in the step, the true rate was at least 1,500 per second and 1,200 undercounts; the RRD cannot tell |
| C: same as B, data source maximum 500 per second | same | 100, 100, 100, **null**, 100, null | The maximum makes the interval unknown, including if the traffic was real |
| D: samples missing for 4 steps (240 s, above the 120 s heartbeat) | 0, 6000, then 30000 at step 5 | 100, **null, null, null, null**, 100, null | A long gap is unknown, not interpolated |

The reader rule (decrease, or a changed `start_time` string) passes four unit checks in the same script, including that a rise with no `start_time` evidence is not detected.

## Lustre side, from source

| Event | What the source shows | Observed raw values | Status |
|---|---|---|---|
| Write to a stats file | Counts, sums, minimums, and sumsquares are zeroed and `start_time` is set to now ([lustre-parameters.md](lustre-parameters.md)) | none | confirmed in source; *live* for the values |
| Target remount or module reload | Stats are allocated at target setup and freed at `ofd_procfs_fini`, so a new mount starts a new set of counters | none | inference from source; *live* |
| Server reboot | Same as remount: counters are in memory | none | inference; *live* |
| Failover to another server | The target's stats exist only on the server that has it mounted; the new server starts its own counters ([failover.md](failover.md)) | none | inference; *live* |
| Counter wrap | Counts and sums are 64-bit signed or unsigned values in the printed format (`%lld`, `%llu`); a wrap is not a practical concern within a mount | none | confirmed for the format; practical effect not measured |
| `start_time` and `elapsed_time` | Printed on 2.15.8 always; on 2.16.1 and 2.17.0 only while `enable_stats_header` is non-zero (default 1) | none | confirmed in source; *live* |
| Cleared by other consumers | Anything that writes a stats file (an operator, another monitoring tool) resets every consumer's view | none | confirmed in source |

## Proposed rule (for the state model)

1. Store cumulative counters as DERIVE with minimum 0 and a maximum per counter. The maximum is an upper bound on a believable rate for that counter (bytes per second a target can sustain); it is hardware-dependent and set per template, and it must be generous: a value above it becomes unknown even when the traffic was real. An unset maximum leaves a hidden-reset interval (case B) as an undetected lower-bound rate.
2. In the reader, publish unknown for a counter's interval when the new value is below the previous value, or `start_time` changed, or the observation is stale. Record "reset detected" in the counter continuity state.
3. A filesystem aggregate over targets is unknown for any interval in which a contributing target reset or is missing (plan, Counter semantics).
4. Do not infer a reset from absence: a counter line absent from a stats file that was read successfully means zero samples (confirmed in source), and a failed read is unknown.

## Limits

- `rrdtool` 1.11.0 is the version on the research machine; Cacti installations commonly run 1.7 or 1.8, and the unknown-interval handling was not compared across versions.
- Cacti, not the reader, writes the RRD in production, and Cacti applies its own heartbeat and update path (poller output, boost); only the RRD semantics were tested here.
- No Lustre event was run.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| [#21](https://github.com/somethingwithproof/plugin_pfs/issues/21) | On a test filesystem, capture raw stats before and after: stats write, target remount, module reload, server reboot, and a failover; with `enable_stats_header` on and off | The event table; whether `start_time` marks every reset |
| [#21](https://github.com/somethingwithproof/plugin_pfs/issues/21) | Feed the captured sequences through a Cacti data source | Agreement with the offline results above |
