# 0011. Create overview graphs by default and per-target graphs on demand

Status: Accepted (2026-11-15). Decides #12.

## Context

A large Lustre filesystem has hundreds to thousands of targets. In Cacti a data query graph creates a data source per index, and each data source adds `poller_item` rows and script server calls ([scale](../research/scale.md), from source at `release/1.2.31`):

| Per-target design, all targets graphed | 500 targets | 2,000 targets |
|---|---|---|
| Data sources (`data_local`) and RRD files | 500 | 2,000 |
| `data_template_rrd` rows and `poller_item` rows, nine values per OST | 4,500 | 18,000 |
| Script server calls per poll cycle | 4,500 | 18,000 |
| Graphs per per-target graph template, about three items each | 500 graphs, about 1,500 items | 2,000 graphs, about 6,000 items |

The script server answers one call at a time per process. Its per-call cost against the cache has not been measured, so 18,000 calls in a 300-second cycle is unproven. An overview graph with one series per target is unreadable at that size.

## Decision

Creating a filesystem device creates five overview graphs, each from a "Parallel Filesystem Summary" data source of its own:

- fill (maximum, mean, and minimum OST used percentage, with the counts of targets, targets left out, and unhealthy targets);
- imbalance (spread);
- capacity;
- aggregate OST throughput;
- aggregate MDT operations.

Cacti gives each graph that does not come from a data query its own data source, and stores in an RRD file only the values a graph draws. Each summary data source is one script server call per poll cycle that returns every summary value, whatever the target count, and Cacti keeps the values its graph draws. Every summary value is drawn on one of the five graphs, so each can carry a Thold threshold. The OST and MDT data queries list every target as an index. No per-target data source or graph exists until an administrator creates it on Cacti's New Graphs page, or enables a Cacti automation rule for these queries.

## Consequences

- A new filesystem costs five data sources, five RRD files, five script server calls per cycle, and five graphs, independent of its size. Sharing one data source among graphs that are not from a data query would mean editing Cacti's graph items behind its API, which this plugin does not do.
- Per-target cost is paid only for the targets an administrator chooses. The scale measurement in #23 decides how many per-target data sources one poller can carry.
- The summary is computed by the reader from its cache, so the overview does not depend on any per-target data source existing.
- Aggregates that would be partial are unknown, as ADR 0005 requires, and the number of contributors is published beside them.

## Alternatives rejected

- **Per-target graphs for every target by default.** At 2,000 targets that is 18,000 polled values and 2,000 graphs per per-target template before anyone asks for one.
- **A plugin view instead of Cacti graphs.** It would bypass graph permissions, trees, and Thold, which ADR 0002 keeps in Cacti.
