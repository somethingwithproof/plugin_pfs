# 0004. Reach nodes through Cacti pollers and remote data collectors, not a custom bridge

Status: Accepted (2026-11-14)

## Context

Storage nodes sit on management networks the Cacti main server may not reach. Cacti already has remote data collectors, and plugins such as servcheck run work on them through the `remote_collect` capability, the `replicate_out` hook, and `poller_bottom` jobs filtered by `poller_id`. Core 1.2.31 provides `call_remote_data_collector()` and a `remote_agent` hook for plugin actions.

## Decision

The reader runs on the Cacti poller that can reach a filesystem's nodes: the main server or a remote data collector. That poller runs the reader and the script server input. The main server never contacts nodes. Plugin configuration replicates to collectors through `replicate_out`. The main server fetches inventory and health on demand through `remote_agent` actions, which are read-only. Remote support ships in phase 2, after the single-poller design is proven.

## Consequences

- No new daemon, socket, credential store, or protocol.
- A node network needs a full Cacti remote collector (PHP, a web server, a local database).
- Remote CLI jobs use the collector's local database by default and must handle offline and recovery modes.
- `remote_agent` authorizes callers by address, not cryptographically. Documentation requires https and a restricted network between pollers.
- Which INFO capabilities make each hook run on a collector is a milestone 1 research item, because core 1.2.31 maps `poller_bottom` to `remote_poller`.

## Alternatives rejected

- **Custom bridge service.** The largest security surface, duplicating what remote collectors provide.
- **Main server reaching every node.** Fails on segmented networks and widens the credential footprint.
