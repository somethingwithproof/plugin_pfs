# Operator guide

How to install, run, upgrade, back up, and remove the plugin, and what its graphs and values mean. Node preparation is in [node-setup.md](node-setup.md); alerting with Thold and Maint is in [alerting.md](alerting.md).

Status: phase 1 is code complete and has run only against fixture node output in a disposable Cacti. No pilot on a real Lustre filesystem has reported yet ([#57](https://github.com/somethingwithproof/plugin_pfs/issues/57)); treat every number below as a fixture result until one has.

## Requirements

| Component | Requirement |
|---|---|
| Cacti | 1.2.31 or later, no core patches |
| PHP | 8.2 or later on every Cacti host that runs the web UI or a poller. On PHP 8.0 or 8.1 the plugin refuses to enable and Cacti keeps working |
| Lustre | Servers reached over SSH with the forced-command read script; releases checked from source are 2.15.8, 2.16.1, and 2.17.0 |
| Poller host | `ssh` client, a private key for the reader, and a known hosts file |

## Install

1. Unpack the release so that the plugin directory is `<path_cacti>/plugins/pfs`. The directory must be named `pfs`; a GitHub source archive unpacks as `plugin_pfs-<version>` and has to be renamed.
2. In Cacti, open Configuration, Plugins, and install and enable Parallel Filesystems. The install creates the plugin tables, registers its hooks and two realms, imports the device, data, and graph templates, and copies the two data query files into Cacti (next section).
3. Give users the realms they need under User Management: **Parallel Filesystem View** for the filesystem page, **Parallel Filesystem Admin** for the registry and health pages. The view realm sees inventory and freshness only. Both follow Cacti's device permissions.
4. Open Console, Parallel Filesystem Health. Every warning there names what to fix. A fresh install normally shows the missing SSH key and known hosts files until the next steps are done.
5. Prepare the nodes and the poller as in [node-setup.md](node-setup.md).
6. In Parallel Filesystem Admin, allow the networks each poller may reach, add the filesystem, then its nodes (endpoint, port, login name, role). A node whose endpoint resolves outside the allowed networks is not saved.
7. Wait one poller interval. The reader runs from Cacti's `poller_bottom` hook on each poller and fills the inventory. Review the proposed targets on the filesystem page; an ignored target drops out of the data queries and the summary.
8. Create the filesystem's device from the admin page (or map the filesystem onto an existing device). The plugin lists what it will add before it adds it: the device, both data queries, and five overview graphs. Per-target graphs are created on demand from Cacti's New Graphs page.

### Data query files

Cacti reads a data query XML only from inside `<path_cacti>/resource`, so the install copies `resource/script_server/pfs_osts.xml` and `pfs_mdts.xml` from the plugin into `<path_cacti>/resource/script_server/`. It refuses a symbolic link at either destination and writes each file through a temporary file in the same directory. It records the SHA-256 only of a file it wrote: a file that is already there with the same content, such as a copy made with the steps below, is used but stays yours, and uninstall leaves it. The install never replaces a file it did not write or that was changed since, and uninstall removes only the files it wrote, while unchanged.

If the web server user cannot write that directory, the health page says so and the data queries cannot list targets. Copy the files once as a user who can, keeping them readable by the web server and poller users:

```sh
cd <path_cacti>
install -m 0644 plugins/pfs/resource/script_server/pfs_osts.xml resource/script_server/
install -m 0644 plugins/pfs/resource/script_server/pfs_mdts.xml resource/script_server/
```

Then use **Install the Templates Again** on the health page. The warning clears when both copies match the plugin's. Because these copies are yours, the plugin does not update or remove them: repeat the copy after an upgrade that changes either file (the health page warns when they differ), and remove them yourself after uninstalling.

## What the graphs and values mean

Each filesystem is one Cacti device. Its targets (OSTs and MDTs) are the indexes of two script server data queries. Every value comes from the reader's cache; no graph, page, or data source contacts a node.

| Graph | Values | Unknown when |
|---|---|---|
| Fill (overview) | Maximum, mean, and minimum OST used percentage, spread (maximum minus minimum), targets total, missing, and unhealthy | No OST has a current capacity reading |
| Capacity (overview) | Filesystem total and available kilobytes | Any OST is missing or stale, so the total would be partial |
| Throughput (overview) | Filesystem read and write bytes per second, and the read and write rate gauges for alerts (printed under the graph) | Any OST is missing, stale, unhealthy without its counter, or reset in the interval; the rate gauges also for one or two polls after a reset |
| Metadata (overview) | Filesystem metadata operations per second, all allowlisted operations together, and the operation rate gauge for alerts | Any MDT is missing, stale, or reset in the interval; the rate gauge also for one or two polls after a reset |
| Data age (overview) | Seconds since the oldest observation behind the summary, and contributor counts | Never observed |
| OST Capacity, Inodes, Throughput (per target) | Used and available, inodes, read and write bytes per second and operations per second | The target is missing, stale, or the node did not print the value |
| MDT Capacity, Operations (per target) | Used and available, inodes, operations per second by operation | Same |

Rules behind the table:

- **Used percentage** follows `lfs df`: used over used plus available, rounded up.
- **Unknown is a gap, never zero.** A missing target, a failed read, or a value the node did not print is drawn as a gap.
- **Counters** are stored raw as DERIVE with minimum 0. A reset (a decrease, a changed `start_time`, a different node answering after failover, or an unknown previous value) makes exactly one interval unknown.
- **Freshness.** A value is current while its age is at most one and a half intervals, delayed up to three, and stale after that. Gauges are served while current or delayed; counters and the summary's rate gauges only while current, so a missed read is a gap and not a flat line followed by a doubled rate.
- **Idle targets.** Lustre does not print a counter that has never counted since its start time. The filesystem sums count it as zero for a target that is present, in service, and reported its other values in that read; the per-target graph still shows a gap ([ADR 0013](decisions/0013-absent-counter-lines-count-as-zero-in-sums.md)).
- **Target set changes.** When a target joins or leaves a sum, that sum is unknown for one poll, so its whole history is not drawn as one interval.
- **Missing targets** keep their index, data sources, and history until an administrator ignores them in the registry.

## Upgrade

1. Back up first (below).
2. Replace the `plugins/pfs` directory with the new release. Keep the directory name.
3. Open Configuration, Plugins. Cacti shows the new version, and the plugin brings its schema, hooks, realms, templates, and data query files up to date when Cacti next loads its configuration (opening the Plugins page does). An interrupted upgrade finishes on the next load. The reader does not run while the schema is behind; it logs that the upgrade has to be finished.
4. Check the health page and the Cacti log. A template or query file problem is reported there, not silently skipped.

Downgrading to an older plugin version is refused when the database schema is newer than the code; restore the backup taken before the upgrade instead.

## Back up and restore

Back up these together, at the same moment:

| What | Where |
|---|---|
| Plugin tables | Every table named `plugin_pfs_*` in the Cacti database (`mysqldump cacti $(mysql -N -e "SHOW TABLES LIKE 'plugin\_pfs\_%'" cacti)`), or the whole Cacti database |
| Cacti objects | The Cacti database: the filesystem devices, their data sources and graphs, the templates, and the plugin settings in `settings` (`pfs_*`) |
| RRD files | `<path_cacti>/rra`, or your RRD directory |
| Node access | On each poller, the SSH key file and known hosts file from the plugin settings. On each node, the reader account, its `authorized_keys` line, and `/usr/local/libexec/pfs-read.sh` |
| Query file copies | `<path_cacti>/resource/script_server/pfs_osts.xml` and `pfs_mdts.xml`, if you copied them by hand |

To restore, stop the pollers, restore the database and the RRD directory from the same backup, restore the key and known hosts files, then start the pollers. The reader resumes on the next run without creating duplicate data sources: the device, its data sources, and the RRD files are matched by their stored ids and paths. The first poll after the restore draws one unknown counter interval, because the reader cannot trust a rate across the gap. If the restored schema is older than the installed code, the plugin detects it and upgrades it as in the upgrade steps. The restore is exercised by `tests/bin/restore-flow.php`.

## Uninstall

1. Optional: export the registry from Parallel Filesystem Admin.
2. Disable, then uninstall the plugin in Configuration, Plugins.
3. Uninstall removes the plugin's hooks and realms and the data query file copies it wrote and that are unchanged. Removing the `poller_bottom` hook stops the reader, so nothing collects node values after uninstall: existing graphs keep their history but stop updating, and their newest values become unknown. The imported templates stay in Cacti, like any imported template. With **Keep Plugin Tables on Uninstall** checked (the default) the tables stay; clear it to drop them.
   - **Devices.** By default every device stays, with its data sources and graphs. Check **Delete the Devices This Plugin Created on Uninstall** in Configuration, Settings, Parallel Filesystems (off by default) to have the uninstall delete the devices the plugin created from the admin page, through Cacti's own device delete: their graphs and data sources go with them, and the RRD files are removed only if Cacti's RRD cleanup setting removes them. The plugin records each device it creates, one row per device in `plugin_pfs_created_devices`; an existing device you mapped to a filesystem is never on that record and always stays, with the data queries and overview graphs the mapping added. A filesystem whose device was deleted keeps no mapping to it, so kept tables never point at a deleted device. An uninstall that drops the plugin tables keeps `plugin_pfs_created_devices` while it lists a device that is still in Cacti, so a later reinstall and uninstall can still delete it; drop that table by hand if you keep those devices for good. Devices, data sources, graphs, and RRD files you created yourself are yours to remove.
4. On each node, remove the reader's `authorized_keys` line, the read script, and the account. Storage nodes are never contacted by the uninstall.

## Troubleshooting

Start with the health page; it names the node, the poller, and the cause. Then the Cacti log, filtered on `PFS`.

| Symptom | Cause and fix |
|---|---|
| A node shows "host key is not in the known hosts file, or it changed" | Add or correct the key under the endpoint name exactly as registered. Check a changed key out of band before trusting it; the reader never accepts one on its own |
| "answered without the fixed read script framing" | The key is not limited to the forced command, or the command is not `pfs-read.sh`. Fix the `authorized_keys` line |
| "printed N parameters the plugin does not read" | The node runs something other than the shipped script. Reinstall `node/pfs-read.sh` |
| "resolves outside the networks allowed" | Allow the network for that poller in the admin page, or correct the endpoint |
| A node read is recorded as failed or partial | Unreachable, timed out, or the answer was larger than **Maximum Response**. Its targets keep their last values until those go stale, then read unknown |
| "The reader on poller N has not completed a run" | The poller is not running `poller_bottom`, PHP is below 8.2 on that poller, or **PHP Binary Path** in Cacti's settings is empty. The log says which |
| The data queries list no targets | The query file copies are missing or differ (see Data query files), or the reader has not completed a read yet |
| Counters on a per-target graph are always a gap | The reader account cannot read the OST `stats` file. On Lustre 2.17 it is in debugfs ([node-setup.md](node-setup.md)) |
| Filesystem throughput is a gap but per-target throughput draws | A target is missing, stale, unhealthy, or just reset; the targets missing and unhealthy counts on the Fill graph say which |
| The overview graphs are created but empty | The device is polled by a different poller than the one that reads its nodes; the health page warns about the mismatch |
| The poller log shows `Plugin 'thold': Uncaught TypeError` and Thold is disabled | A Thold threshold on a counter value saw an unknown interval; delete the threshold, re-enable Thold on the Plugins page, and see [alerting.md](alerting.md#counters-and-thold-182) |
