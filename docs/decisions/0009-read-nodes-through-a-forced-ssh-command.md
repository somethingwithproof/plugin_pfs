# 0009. Read nodes through a forced SSH command running a fixed script

Status: Accepted (2026-11-15). Decides #7.

## Context

ADR 0008 requires every node read to be a fixed, allowlisted request with no operator input, but left the transport open. Milestone 1 compared the options ([node-privileges](../research/node-privileges.md), [lustre-exporter](../research/lustre-exporter.md)):

- The Prometheus exporter (GSI-HPC `lustre_exporter`) serves `/metrics` with plain `http.ListenAndServe`, with no TLS, authentication, or allowlist option, so an open port exposes every metric to the network. It reads OST `stats` from procfs, where Lustre 2.17.0 no longer creates the file, and it exposes no `start_time`, `recovery_status`, `no_create`, or `readonly`, so the plugin could not detect resets from start times or report target condition beyond `degraded`.
- Capacity, inode, and condition parameters are sysfs attributes any user can read. OST `stats` and `md_stats` are procfs files any user can read on 2.15 and 2.16. On 2.17 the OST `stats` and `recovery_status` files are in debugfs, which the kernel makes root-only by default, so 2.17 OST counters may need root or a relaxed debugfs mount.
- A fixed, argument-free read script was prototyped and passed its fixture tests. It covers every parameter the plan needs.

## Decision

- Each Lustre server runs [node/pfs-read.sh](../../node/pfs-read.sh) as the forced command of the reader's key in `authorized_keys` (`restrict,command="..."`). The script takes no arguments, ignores the command the client sent, reads only its allowlist, skips symbolic links and anything that is not a regular file, and frames its output with a protocol header and end marker.
- The key belongs to a dedicated unprivileged account used for nothing else. The plugin documents which parameters need more ([node setup](../node-setup.md)) and never asks for root.
- The client side is fixed. The reader runs `ssh` through `proc_open()` with an argument array, so no shell parses anything, and with stdin from `/dev/null` and a fixed environment. The arguments are `-F /dev/null` before every option, so no `ssh_config` is read. It sends no remote command and allows no agent, password, keyboard-interactive login, forwarding, local command, or proxy command. It connects to the resolved address with `HostKeyAlias` set to the registered endpoint, and only when every address the endpoint resolves to is inside a network allowed for the node's poller.
- A host key is required. `StrictHostKeyChecking=yes` runs against the configured known hosts file only, and a missing or changed key is a failure, never accepted on its own.
- Output and time are bounded. The script caps each file at 64 KiB and the whole answer at 4 MiB and says when it cut something. The reader kills a read at its timeout or response size limit and keeps none of that output.
- Every failure is an error, never zeros: timeout, size, host key, authentication, unreachable, configuration, a non-zero exit, an answer without the framing, or one without the end marker. A failed read leaves the node's previous values and their times as they were.
- The exporter remains a possible later, optional source behind the same node source interface, for sites that already run it behind their own network controls.

## Consequences

- Whether the account can read the 2.17 debugfs files is a live check ([#20](https://github.com/somethingwithproof/plugin_pfs/issues/20)). Until a site allows it, 2.17 OST counters read as unknown while capacity and condition still work.
- The private key lives on each poller that reads nodes, readable by the poller user. Anyone who obtains it can run the read script and nothing else. The health page warns when the key file is readable by other users, when a node answers without the script's framing (a key without the forced command), and when the script prints parameters it should not.
- Each site maintains the script on its servers and the host keys on its pollers.
- Per-site SSH behavior is limited to the plugin settings. Jump hosts and proxy commands are not supported.

## Alternatives rejected

- **The Prometheus exporter as the primary path.** It needs an open port with no transport security, it lacks reset evidence and condition flags, and its 2.17 counter coverage is doubtful.
- **A Cacti remote data collector on each storage node.** That would put a web server, PHP, a database connection, and Cacti's own update path on every server, which is far more than one read script.
- **sudo for the read.** A sudoers rule running the script as root widens privilege on the node, and the reads that need root on 2.17 can be granted more narrowly through the debugfs mount mode.
