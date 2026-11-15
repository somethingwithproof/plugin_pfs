# Poller cost and Cacti row counts at 500 and 2,000 targets

Research for [#23](https://github.com/somethingwithproof/plugin_pfs/issues/23), run 2026-11-15. Question: what do script server and data query polling cost, and how many Cacti rows exist, for a filesystem with 500 and 2,000 targets?

**Result.** Not measured; the cycle-time and row-count measurements need a Cacti instance with fixture data and are *live*, so the exit criteria (measured cycle time and row counts, supported target count) are not met. What documentation and source settle is the structure of the cost: for a script server data query Cacti creates one `poller_item` row, and polls with one script server call, per output field per target, so nine fields on 2,000 targets is 18,000 calls per cycle; a plain script server data input that returns several `name:value` pairs needs one call per data source. The plan's row arithmetic (18,000 `data_template_rrd` rows and 2,000 RRD files at 2,000 targets with nine items each) follows from the schema, and the same count scales linearly to 4,500 calls and rows at 500 targets. The script server runs calls one at a time per script server process (`php_servers` defaults to 1 per Spine process), which is why the call count matters.

## Method

Read `update_poller_cache()`, `cmd.php`, `script_server.php`, and the settings defaults at `release/1.2.31` (`1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1`). Counted rows by reading the schema and the code that inserts them. No benchmark was run.

## Structure (confirmed from source)

| Design | Calls per cycle | `poller_item` rows | Source |
|---|---|---|---|
| Script server data query, one data source per target, N output fields (the plan's default) | targets x N | targets x N | `update_poller_cache()` adds an item per output of a query ([lib/utility.php:523-553](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/utility.php#L523-L553)); each is a `POLLER_ACTION_SCRIPT_PHP` call ([cmd.php:742-761](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cmd.php#L742-L761)) |
| Script server data input (not a query), one data source per target, N output fields in one call | targets | targets | `update_poller_cache()` adds one item with an empty field name when there are several output fields ([lib/utility.php:255-286](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/utility.php#L255-L286)); the output is validated as `name:value` pairs ([lib/functions.php:1971-2020](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/functions.php#L1971-L2020)). Loses Cacti's data query workflow (indexes, reindex, graph creation per index) |
| Summary data source only (the plan's alerting source) | 5 values on one data source | 1 item with 5 fields, or 5 items | same code; the plan's summary has five values |

The script server answers one request line at a time on one process (`script_server.php` main loop); a Spine process starts `php_servers` of them (default 1, valid 1 to 15, "only valid for the spine Data Collector") and `script_timeout` defaults to 25 seconds ([global_settings.php:1331-1343](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/include/global_settings.php#L1331-L1343)). The per-call latency of the plugin's script server function against a cache is the quantity that decides whether 18,000 calls fit in a 300-second cycle; it is not known.

## Row counts for the plan's default (data query, nine items per target)

Counts follow from the schema and code; none was observed in a database.

| Table or object | 500 targets | 2,000 targets | Basis |
|---|---|---|---|
| `data_local` | 500 | 2,000 | one per data source (one data source per target) |
| `data_template_data` | 500 | 2,000 | one per data source |
| `data_template_rrd` | 4,500 | 18,000 | one per item per data source (9 items) |
| RRD files | 500 | 2,000 | one RRD per data source, nine data sources inside |
| `poller_item` | 4,500 | 18,000 | one per output field per target (above) |
| `host_snmp_cache` | 500 x F | 2,000 x F | one row per (index, query input field); F is the number of input fields in the query XML, not fixed yet |
| Graphs | 0 to 500 | 0 to 2,000 | depends on the default graph set; the plan proposes on-demand per-target graphs, about 3 graph items each if created |
| `data_input_data` | not counted | not counted | rows per data source depend on the data input fields; not read |

Poll values per cycle equal the `poller_item` count: 4,500 and 18,000. At a 300-second interval that is 15 and 60 values per second on average, matching the plan.

## Reader cost (plan's assumption; parsing and caching measured below, SSH still unmeasured)

The plan assumes 16 targets per server, 125 node reads per cycle for 2,000 targets, two seconds per read, and a concurrency of eight, giving about 30 seconds. These are assumptions, not measurements; the number of targets per server on real sites and the read latency of the chosen node source ([node-privileges.md](node-privileges.md)) are *live*.

## Container measurement (milestone 4)

Run 2026-11-15 with `tests/bin/scale-replay.sh` (driver `tests/bin/scale-flow.php`) for [#52](https://github.com/somethingwithproof/plugin_pfs/issues/52).

**Machine caveat.** One container on a developer laptop: Apple M3 Max, 14 CPUs and 16 GiB given to the OrbStack Linux VM, PHP 8.2.35 CLI, MariaDB in a second container on the same host, Cacti 1.2.31 with `cmd.php`, one process, no boost, no Spine. Node answers are generated fixtures served in process, so no SSH, network, or Lustre time is included. These numbers show how the plugin's own work grows with the target count on this machine; they are not a capacity claim and do not replace the live check in #23.

Each filesystem has N OSTs on N/16 OSS nodes (16 OSTs per OSS) and one MDS with one MDT. One per-target capacity graph is created for every OST, which is one of the three per-OST graphs; graphing throughput and inodes as well would roughly triple the data source, `poller_item`, and RRD counts.

Each target count is measured alone: after its counts are taken, the replay removes that filesystem with its device, data sources, and graphs before building the next one, so the 2,000 OST poller runs do not also poll the 500 OST filesystem. The figures below are from that isolated run; an earlier run that left the 500 OST filesystem in place gave the same row counts and readings within a few tenths of a second, except the first 2,000 OST poller run (6 s then, 7 s now).

| Measurement | 500 OSTs | 2,000 OSTs |
|---|---|---|
| Nodes read | 33 | 126 |
| Reader run, steady state (s) | 0.40 | 0.96 |
| Reader run, first (s) | 0.70 | 1.12 |
| Reader peak memory (MiB) | 18 | 42 |
| Create the device and index both queries (s) | 0.91 | 1.15 |
| Indexes in `host_snmp_cache` | 501 | 2,001 |
| Data query `index`, first call, empty cache (ms) | 8.2 | 30.4 |
| Data query `query` for one field, cached (ms) | 2.5 | 10.1 |
| Data query `get`, per call, cached (ms) | 0.090 | 0.342 |
| Summary script, per call, cached (ms) | 0.076 | 0.067 |
| Summary script, new instance with an empty cache, same process (ms) | 0.101 | 0.097 |
| Create one capacity graph per OST (s) | 25.7 | 156.5 |
| Forced poller run, first, creating RRD files (s, whole seconds) | 2 | 7 |
| Forced poller run, second (s, whole seconds) | 1 | 3 |
| `data_local` | 505 | 2,005 |
| `data_template_rrd` | 1,580 | 6,080 |
| `graph_local` | 505 | 2,005 |
| `poller_item` | 1,505 | 6,005 |
| `host_snmp_cache` | 1,002 | 4,002 |
| RRD files on disk after two polls | 505 | 2,004 |
| Plugin observation rows | 4,525 | 18,025 |

Readings:

- The 5 extra data sources and graphs are the overview graphs' summary data sources; each per-target capacity data source has 3 items, so `poller_item` is 3 per OST plus 5.
- The summary script answers in under 0.1 ms per call at 2,000 targets, against the plan's 50 ms budget for it. The data query `get` stays under 0.4 ms per call once its 30 second cache is filled; the first `index` call, which loads every target, takes about 30 ms.
- Creating graphs is the largest cost and is Cacti's own: about 0.08 s per graph at 2,000 targets, against 0.05 s at 500. Operators who graph every OST should expect minutes, once.
- One RRD file of the 2,005 was missing after two polls at 2,000 targets, in both runs, including the isolated one; at 500 targets every file existed. Which data source lacks its file was not recorded, and the cause is not known. Rerun with the file list before relying on the count.
- Reader time excludes SSH. With real nodes the reader's time is dominated by the slowest node and the parallelism setting, not by parsing.

## Limits

- The script server cost per call, the poll cycle time, Cacti page response times at these row counts, and database sizes are not known.
- `cmd.php` versus Spine, boost, and database server settings change all of these; none was varied.
- The `host_snmp_cache` row count depends on the query XML, which does not exist yet.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| [#23](https://github.com/somethingwithproof/plugin_pfs/issues/23) | Cacti 1.2.31 on documented hardware (CPU, memory, database, Spine or `cmd.php`, `php_servers`), fixture cache with 500 and 2,000 targets, nine items each; run one poller cycle and record time, `poller_item` and `data_template_rrd` counts, RRD file count | Measured cycle time and row counts; supported target count |
| [#23](https://github.com/somethingwithproof/plugin_pfs/issues/23) | Same fixture as a script server data input returning all fields in one call | Gain of one call per target against one call per field |
| [#23](https://github.com/somethingwithproof/plugin_pfs/issues/23) | Overview page, device page, and data source page response times with the fixture loaded | Whether the plan's two-second target holds |
