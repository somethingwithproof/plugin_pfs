# Poller cost and Cacti row counts at 500 and 2,000 targets

Research for [#23](https://github.com/somethingwithproof/plugin_pfs/issues/23), run 2026-11-15. Question: what do script server and data query polling cost, and how many Cacti rows exist, for a filesystem with 500 and 2,000 targets?

**Result.** Not measured; the cycle-time and row-count measurements need a Cacti instance with fixture data and are *live*, so the exit criteria (measured cycle time and row counts, supported target count) are not met. What documentation and source settle is the structure of the cost: for a script server data query Cacti creates one `poller_item` row, and polls with one script server call, per output field per target, so nine fields on 2,000 targets is 18,000 calls per cycle; a plain script server data input that returns several `name:value` pairs needs one call per data source. The plan's row arithmetic (18,000 `data_template_rrd` rows and 2,000 RRD files at 2,000 targets with nine items each) follows from the schema, and the same count scales linearly to 4,500 calls and rows at 500 targets. The script server runs calls one at a time per script server process (`php_servers` defaults to 1 per Spine process), which is why the call count matters.

## Method

Read `update_poller_cache()`, `cmd.php`, `script_server.php`, and the settings defaults at `release/1.2.31` (`1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1`). Counted rows by reading the schema and the code that inserts them. No benchmark was run.

## Structure (confirmed from source)

| Design | Calls per cycle | `poller_item` rows | Source |
|---|---|---|---|
| Script server data query, one data source per target, N output fields (the plan's default) | targets x N | targets x N | `update_poller_cache()` adds an item per output of a query ([lib/utility.php:523-553](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/utility.php#L523-L553)); each is a `POLLER_ACTION_SCRIPT_PHP` call ([cmd.php:742-761](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cmd.php#L742-L761)) |
| Script server data input (not a query), one data source per target, N output fields in one call | targets | targets | `update_poller_cache()` adds one item with an empty field name when there are several output fields ([lib/utility.php:255-286](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/utility.php#L255-L286)); the output is validated as `name:value` pairs ([lib/functions.php:1971-1995](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/functions.php#L1971-L1995)). Loses Cacti's data query workflow (indexes, reindex, graph creation per index) |
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

## Reader cost (plan's assumption, still unmeasured)

The plan assumes 16 targets per server, 125 node reads per cycle for 2,000 targets, two seconds per read, and a concurrency of eight, giving about 30 seconds. These are assumptions, not measurements; the number of targets per server on real sites and the read latency of the chosen node source ([node-privileges.md](node-privileges.md)) are *live*.

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
