# 0010. Model each filesystem as one Cacti device with targets as data query indexes

Status: Accepted (2026-11-15). Decides #11.

## Context

A Lustre filesystem is served by many nodes, and a target (OST or MDT) is mounted on one node at a time but can fail over to its partner. The failover research ([failover](../research/failover.md)) found that a target's identity is its name, `<fsname>-OST<nnnn>` or `-MDT<nnnn>`. It is the same name whichever node serves it, and a new mount starts new counters. The plan left open whether a Cacti device stands for a filesystem, a server host, or a target. Cacti attaches graphs, data sources, permissions, and maintenance to devices. A data query attaches one data source per index to a device ([scale](../research/scale.md), [cacti-core](../research/cacti-core.md)).

## Decision

Each registered filesystem maps to one Cacti device. Its targets are the indexes of two script server data queries on that device, one for OSTs and one for MDTs. The index is the validated target name. The device is created from the "Parallel Filesystem" device template through `api_device_save()`, with availability None and the poller recorded for the filesystem, and only after an administrator confirms. An existing device can be mapped instead. The storage nodes are plugin records and not Cacti devices.

## Consequences

- A target that fails over keeps its index, data source, and history. Only the source node recorded with each value changes, and the reader counts that change as a counter reset.
- Cacti device permissions apply per filesystem. A user allowed to see the device sees all of its targets' graphs. There is no per-target permission and no plugin permission layer.
- Maint schedules and Thold thresholds attach to the filesystem device.
- The device has no network address of its own. Its hostname is a reserved `.invalid` name, so availability checks and any lookup fail instead of reaching a host.
- A remote collector polls the device of a filesystem whose nodes it reads (phase 2).

## Alternatives rejected

- **One device per server host.** A failover would move a target's values to another device, which either splits its history or makes the plugin move data sources between devices.
- **One device per target.** Thousands of devices per filesystem. It gives up the data query workflow (indexes, reindex, New Graphs), and every target would need its own permission and maintenance entry.
