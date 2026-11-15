# Node setup

How a Lustre server is prepared so that the plugin's reader can read it ([ADR 0009](decisions/0009-read-nodes-through-a-forced-ssh-command.md)). Nothing here has run on a real Lustre node yet; the live checks are in [#20](https://github.com/somethingwithproof/plugin_pfs/issues/20) and the pilot plan ([pilot-plan.md](pilot-plan.md)). Installing the plugin in Cacti is in [operator-guide.md](operator-guide.md).

## On each Lustre server

1. Create a dedicated unprivileged account for the reader, for example `pfsread`, with no password and no other use.
2. Install [node/pfs-read.sh](../node/pfs-read.sh) as `/usr/local/libexec/pfs-read.sh`, owned by root and mode 0755. It takes no arguments, reads only its allowlist, and prints framed output.
3. Add the reader's public key to that account's `authorized_keys` with the script as its forced command, and nothing else allowed:

   ```
   restrict,command="/usr/local/libexec/pfs-read.sh" ssh-ed25519 AAAA... cacti-pfs
   ```

   `restrict` turns off forwarding, agent forwarding, X11, and the pseudo-terminal. sshd runs the forced command in place of whatever the client asks for, so the client cannot run anything else.
4. On Lustre 2.17 the OST `stats` and `recovery_status` files are in debugfs, which only root can read by default. Without root, or a debugfs mount whose mode lets the account read them, those targets report capacity and condition but their counters stay unknown. Which to allow is a site decision ([node-privileges](research/node-privileges.md)).

## What the reader account needs, by node type

The reader account needs read access to the files below and nothing else: no sudo rule, no group membership beyond its own, no write access anywhere on Lustre, and no login shell use other than the forced command. Derived from Lustre source ([node-privileges](research/node-privileges.md)); not yet confirmed on a real node.

| Node type | Files the script reads | Readable by an unprivileged account |
|---|---|---|
| OSS (2.15, 2.16) | `osd-*/<fs>-OST*/kbytes*`, `files*` and `obdfilter/<fs>-OST*/degraded`, `no_precreate`, `no_create`, `readonly` under `/sys/fs/lustre`; `obdfilter/<fs>-OST*/stats` and `recovery_status` under `/proc/fs/lustre` | Yes |
| OSS (2.17) | The same sysfs files; `stats` and `recovery_status` under `/sys/kernel/debug/lustre` | Sysfs yes; debugfs only for root by default |
| MDS (all three) | `osd-*/<fs>-MDT*/kbytes*`, `files*` under `/sys/fs/lustre`; `mdt/<fs>-MDT*/md_stats` under `/proc/fs/lustre` | Yes |
| Any | `version` under `/sys/fs/lustre` | Yes |

If the site grants debugfs access on 2.17 OSSs, prefer a debugfs mount whose `gid` and `mode` let a dedicated group read it over running the script as root, and note that this opens all of debugfs to that group.

## Confirm nothing more is granted

On each server, as root:

```sh
id pfsread                                   # only its own group, plus any debugfs group you chose
sudo -l -U pfsread                           # "not allowed to run sudo"
grep -n pfsread /etc/sudoers /etc/sudoers.d/* 2>/dev/null   # nothing
cat ~pfsread/.ssh/authorized_keys            # exactly one line, starting with restrict,command="/usr/local/libexec/pfs-read.sh"
ls -l /usr/local/libexec/pfs-read.sh         # root-owned, 0755, not writable by pfsread
sshd -T -C user=pfsread,host=cacti,addr=<poller address> | grep -Ei 'permituserenvironment|authorizedkeyscommand|forcecommand'
```

From the poller, as the poller user, using the configured key:

```sh
ssh -F /dev/null -i /etc/cacti/pfs/id_ed25519 -o UserKnownHostsFile=/etc/cacti/pfs/known_hosts -o HostKeyAlias=<endpoint> pfsread@<address> 'id; cat /etc/shadow'
```

The answer must be the framed output of the read script (`#pfs-read 1` ... `#pfs-read end`) and nothing from the requested command. Anything else means the forced command is not in effect; fix it before adding the node to the registry. Check that `PermitUserEnvironment` is `no` and that no `Match` block or `AuthorizedKeysCommand` gives the account another key or command.

## On each Cacti poller that reads nodes

1. Put the private key at the path in **SSH Key File** (default `/etc/cacti/pfs/id_ed25519`), readable only by the poller user. The plugin never creates, copies, or prints it. The health page warns when other users can read it.
2. Put each node's host key in the file at **Known Hosts File** (default `/etc/cacti/pfs/known_hosts`), under the endpoint exactly as it is registered. The reader connects to the resolved address with `HostKeyAlias` set to the endpoint and refuses a missing or changed key. It never accepts a new key on its own.
3. In the registry page, allow the networks this poller may reach. A node whose endpoint resolves to any address outside them is neither saved nor read.

## SSH options

The reader runs `ssh` with `-F /dev/null` before every other option, so neither the poller user's `~/.ssh/config` nor the system `ssh_config` is read, and nothing in them can add or change an option. Configure per-site values through the plugin settings (Settings, Parallel Filesystems): the SSH client path, key file, known hosts file, connect timeout, read timeout, maximum response size, reader budget, and concurrency. The node's port and login name are set per node in the registry. Options outside these settings, such as a jump host or a proxy command, are not supported.
