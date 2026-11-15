# 0008. Read nodes with fixed, allowlisted requests and no operator input

Status: Accepted (2026-11-14)

## Context

Reading storage nodes crosses the plugin's largest trust boundary. Some Lustre parameters are readable by an ordinary user and some only by root. IBM documents that `mmpmon` requires root authority on the node. Operators supply node names, endpoints, and filesystem names, and node output (target names, job IDs taken from user-controlled environment variables) is itself untrusted.

## Decision

- Every node read is a fixed request from a per-backend allowlist. No operator, request, or node-supplied value becomes part of a command line, shell string, or request path.
- Node access is read-only by construction: the backend interface has no write operation, and the plugin never clears or resets counters.
- Least privilege: the plugin documents the privilege each parameter needs and never asks for root on the Cacti host or broad privilege on a node. Where a read needs root, a fixed node-side wrapper prints only the allowlisted output.
- Credentials are not stored in plain text, not logged, and not passed as process arguments.
- Node output is parsed with strict limits and escaped for every output context. It is never part of an RRD path or SQL text.

## Consequences

- The access method decision (exporter, restricted SSH, or other) is constrained, not open-ended.
- Some useful parameters may be excluded if they need privilege the sites will not grant.
- A compromised node can still return hostile values; parsing and escaping, not trust, handle that.

## Alternatives rejected

- **Arbitrary operator-defined commands.** Turns the plugin into remote command execution.
- **Running node reads as root from Cacti.** Widens privilege on both ends.

## Research update (2026-11-15)

The context says some Lustre parameters are readable by an ordinary user and some only by root. From source ([lustre-parameters](../research/lustre-parameters.md), [node-privileges](../research/node-privileges.md)): capacity, inode, and condition parameters are sysfs attributes readable by any user, and OST `stats` is a procfs file on Lustre 2.15.8 and 2.16.1 but a debugfs file on 2.17.0, which the kernel documents as root-only by default. Which reads need root on 2.17 is a live check. A fixed, argument-free read script was prototyped and tested on a fixture ([tests/restricted-read](../../tests/restricted-read/pfs-read.sh)).
