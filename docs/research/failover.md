# Target identity across failover and server moves

Research for [#24](https://github.com/somethingwithproof/plugin_pfs/issues/24), run 2026-11-15. Question: how does a target's identity, counters, and capacity behave when an OST or MDT fails over to another server, and what does each server report meanwhile?

**Result.** From Lustre source, parameters are per node and per mounted target: a target's stats, capacity attributes, and `recovery_status` exist only on the server that currently has the target mounted, and a new mount starts new counters. So a target's identity is its name (`<fsname>-OST<nnnn>` or `-MDT<nnnn>`, the device name used in every parameter path), and the same name should appear on exactly one node at a time. The proposed rule is below: identify the target by name within a filesystem, take the observation from the node whose `recovery_status` is not `INACTIVE` and whose observation is newest, and record the source node with each value; a name reported by two nodes in the same cycle is a conflict state (unknown), not a sum. None of this was observed on a failover pair, so what each server prints during the move, whether a stale directory remains on the old node, and the time in `RECOVERING` are *live*; the exit criterion "a failover fixture" is not met.

## What source shows

| Fact | Status | Source |
|---|---|---|
| Parameters are read on the local node (`lctl get_param`), so a target appears only on the node that has it set up | confirmed in man page | [lctl-get_param.8](https://github.com/lustre/lustre-release/blob/4b0407a48e9e5b7f17877e8868080e9f40c238c6/Documentation/man8/lctl-get_param.8) ("on the local node"; names such as `testfs-OST0000`) |
| Target stats and parameters are created at setup and removed at cleanup (`ofd_procfs_fini` frees the stats and cleans up the proc entries) | confirmed in source | [2.17.0 ofd_dev.c:700-707](https://github.com/lustre/lustre-release/blob/4b0407a48e9e5b7f17877e8868080e9f40c238c6/lustre/ofd/ofd_dev.c#L700-L707), [2.16.1 :717-724](https://github.com/lustre/lustre-release/blob/718a3fd9c78507a589a657ec3745b4c553f66633/lustre/ofd/ofd_dev.c#L717-L724) |
| A new mount allocates new counters with a new start time | inference from the above and [lustre-parameters.md](lustre-parameters.md) | *live* |
| `recovery_status` reports `INACTIVE` (no recoverable clients), `COMPLETE`, `WAITING` (MDT waiting for other MDTs), `WAITING_FOR_CLIENTS`, or `RECOVERING` with `recovery_start`, `time_remaining`, `connected_clients: n/m`, and request and client counts | confirmed in source | [lprocfs_status_server.c:925-1015 at 2.17.0](https://github.com/lustre/lustre-release/blob/4b0407a48e9e5b7f17877e8868080e9f40c238c6/lustre/obdclass/lprocfs_status_server.c#L925-L1015). On 2.17.0 the OFD file is in debugfs ([lustre-parameters.md](lustre-parameters.md)); on 2.15.8 and 2.16.1 in procfs. |
| Capacity of a target is the same device on either server (same backing storage) | inference | The capacity attributes read the backing device's statfs ([lustre-parameters.md](lustre-parameters.md)); a stale value on the old server would only exist if its device still existed, which cleanup contradicts. *live* |
| `lfs df` on a client reports each target once, by its uuid, whichever server serves it | not read | man page text only; client behavior during failover not observed |

## Rule (proposed, for the state model)

1. Identity is `(filesystem, target type, index)` taken from the target name; node names are attributes of an observation, never part of the key. A filesystem device in Cacti ([plan: device model](../PLAN.md)) therefore keeps one history across a move.
2. Each observation records source node, observation time, ingestion time, and for counters `start_time` when printed.
3. Per cycle, for each target: use the observation from the node that reported the target fresh; if two nodes report the same name, state is "conflict" and capacity and counters are unknown for that cycle (never summed, never the larger).
4. A target missing from every node is "not observed" (unknown), not zero, and not "failed over"; the plugin cannot tell a failover in progress from an outage without `recovery_status`.
5. A change of source node resets counter continuity: a new mount starts new counters, so the first interval after a move is unknown ([counter-reset.md](counter-reset.md)).
6. Show `recovery_status` where readable: `RECOVERING` is a visible transitional state; `COMPLETE` is the normal state after a move.

## Limits

- No failover pair was available; nothing here was observed. All rows marked inference need confirmation.
- HA software (Pacemaker and similar) behavior and how a site names its nodes were not examined.
- MDT failover and DNE (several MDTs) were not separated from the OST case; the MDT `recovery_status` is described by the same function.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| [#24](https://github.com/somethingwithproof/plugin_pfs/issues/24) | On a failover pair, move one OST and one MDT; capture both servers' allowlisted parameters before, during, and after (including `recovery_status`) | What each server reports meanwhile; whether a stale directory remains; the fixture for the state model tests |
| [#24](https://github.com/somethingwithproof/plugin_pfs/issues/24) | Move a target while the reader polls both nodes every 60 seconds | Conflict and missing-target frequency; adequacy of rule 3 |
