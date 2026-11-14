# 0003. Put filesystem products behind a backend interface, Lustre first

Status: Accepted (2026-11-14)

## Context

The target products (Lustre, GPFS or IBM Storage Scale, BeeGFS) expose different counters, privileges, and tools. Lustre reads per-node parameters; IBM documents that `mmpmon` runs as root on the node it reports; BeeGFS replaced `beegfs-ctl` with a new `beegfs` tool in 8.0. A design shaped around one product's file layout would need rewriting for the next. Transport (how a node's output reaches the poller) is a separate question from what the output means.

## Decision

Two interfaces:

- A **backend** knows one product. It declares the values it produces (kind, unit, reporting node roles), parses node output into observations, and reports unknown for anything it cannot parse. It has no write operation.
- A **node source** carries one allowlisted read from a node to the reader, with timeouts and a size limit. It knows nothing about the product.

Lustre is the first and only backend in phase 1. Shared code does not branch on a backend identifier. A shared contract test suite runs against every backend's fixtures.

## Consequences

- Adding GPFS or BeeGFS needs a backend and fixtures, not changes to shared objects or to the Lustre backend.
- The access method decision (exporter, restricted SSH, or other) can change without touching parsers.
- Two interfaces add indirection. They exist only where there is a real boundary: the product and the node.

## Alternatives rejected

- **Lustre-specific code with later extraction.** The privilege and counter differences would be discovered late, after Cacti objects are shaped around Lustre.
- **One interface covering product and transport.** Forces every product to reimplement every transport.
