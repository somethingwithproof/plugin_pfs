# Phase 1 security self-review

Review for [#56](https://github.com/somethingwithproof/plugin_pfs/issues/56) (REQ-SEC-020, REQ-SEC-021), done 2026-11-15 by the author against the code at milestone 4. It is a self-review: it does not replace the external review the issue asks for, and it does not claim the plugin has no other weaknesses. Report vulnerabilities privately as described in [SECURITY.md](../SECURITY.md).

## Scope and trust boundaries

| Boundary | Who is on each side | What crosses it |
|---|---|---|
| Storage node to poller | A Lustre server (root on it is trusted for its own data) and the Cacti poller user | The output of the fixed read script, over SSH |
| Poller to Cacti database | The poller user and the database | Parsed values, inventory, node health |
| Cacti user to plugin pages | Any logged-in Cacti user and the web server | Page requests and form posts |
| Plugin to Cacti install | The web server or CLI user that installs the plugin and Cacti's `resource/` tree | Two copied data query files, imported templates |
| Script server to cache | Cacti's poller and script server, with arguments from Cacti's command line | Host ids, target names, field names; numbers back |

The plugin never writes to a node, never runs a command a user supplies, and has no network listener of its own.

## Findings by area

### Node access and the forced command

- The poller connects with a dedicated key whose `authorized_keys` line forces `pfs-read.sh` and sets `restrict` ([node-setup.md](node-setup.md)). The client sends no command; sshd runs the forced command whatever is asked.
- `pfs-read.sh` takes no arguments (exits 64 if given any), ignores `SSH_ORIGINAL_COMMAND`, sets `PATH`, `LC_ALL`, and `umask`, reads only an allowlist of globs under the Lustre sysfs, procfs, and debugfs roots, skips symbolic links and anything that is not a regular file, caps each file at 64 KiB and the whole answer at 4 MiB, and writes nothing.
- The reader runs `ssh` from an argument array through `proc_open()` with no shell, a two-variable environment, stdin from `/dev/null`, `-F /dev/null` before every option, `BatchMode`, `StrictHostKeyChecking=yes`, a plugin-owned known hosts file with `HostKeyAlias` set to the registered endpoint, no agent, no forwarding, no proxy or local command, and `--` before the address. A missing or changed host key is a failed read; the reader never accepts a key.
- Endpoints, login names, and ports are validated against fixed patterns before they are saved and again when a node is loaded; the endpoint must resolve only to addresses inside the networks allowed for that poller.
- The private key path, known hosts path, and ssh path are settings restricted to absolute paths of a fixed character set. The plugin never creates, copies, or prints the key; the health page warns when the key file is readable by others.

No issue found. Residual: framing is in-band. A line inside a parameter file that starts with `== `, `!! `, or `#pfs-read` would be read as framing. The files are produced by the Lustre kernel modules and are not writable by unprivileged users on the node, so this needs root on the node, which already controls its data. An external reviewer should confirm that no allowlisted file can carry text from an unprivileged source, for example a client-supplied name in `recovery_status`.

### Input parsing

- The parser accepts only the protocol header, section headers, and the end marker; an answer without the end marker, or with anything after it, is rejected as incomplete. Unframed output is rejected and reported on the health page.
- Each value is matched by an anchored, bounded regular expression; a malformed number, a counter printed twice, or a section printed twice is unknown, never zero. At most 50,000 sections are read per answer; the response size is capped by **Maximum Response** before parsing, and a larger answer is discarded unread.
- Target names must match the Lustre `<fsname>-OST|MDT<hex>` pattern and the registered filesystem; anything else is counted and ignored. Unknown parameter paths are counted and ignored.
- The failure run in `tests/bin/failure-replay.sh` exercises unreachable, changed host key, a hung node, an oversized answer, and unframed output through the production reader; each is recorded as a failed read and leaves the cached values unchanged.

No issue found.

### Output escaping

- Every page cell goes through `html_escape()` (`Format::row`, `Format::header`); node versions, names, and errors are shown only after pattern checks. The hostile-string fixture (`tests/fixtures/lustre/hostile.txt`) runs through the page suite.
- The registry export is served as `application/json` with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.
- The script server functions answer only numbers, `U`, target names that matched the pattern, or nothing; arguments are checked against fixed lists and never echoed.
- Log lines name nodes by id and registered name; ssh's error text is classified and dropped, not logged.

No issue found.

### Permissions

- Three realms: **Parallel Filesystem View** (`pfs.php`), **Parallel Filesystem Operator** (`pfs_operator.php`), and **Parallel Filesystem Admin** (`pfs_admin.php`, `pfs_health.php`). The Operator realm is never granted automatically.
- Inheritance is resolved per request and adds no stored grant: `pfs_auth_alternate_realms()` (setup.php) maps `pfs.php` and `pfs_operator.php` onto the Admin realm id for a user who holds Admin, and `pfs.php` onto the Operator realm id for a user who holds Operator, by rewriting Cacti's `$user_auth_realm_filenames` for that request only.
- Before a session exists, Cacti runs this hook ahead of cookie and Basic authentication. For `pfs.php` and `pfs_operator.php` only, the hook then sets the page's core realm id to -1, so core skips its realm check, and restores the real id on the next call. The page's own guard does the check instead: `pfs_page_start()` calls `pfs_page_allowed()`, which resolves inheritance again against the now authenticated user and requires `api_plugin_user_realm_auth()` for the page. Every deferred page must call `pfs_page_start()` before it reads data or acts; `tests/Security/SourceRulesTest.php` checks that each page the hook defers does so first.
- Every page checks its realm, and every page and admin action on a record tied to a device checks `is_device_allowed()` before reading or writing it; lists and the export are filtered the same way. This was tightened in milestone 3 after a coordinator audit, and a restricted-admin case is in the permission suite (`tests/bin/device-permissions.php`).
- State-changing actions are POST only, behind Cacti's CSRF protection; the page suite checks that GET does not change state.
- Realms are granted only from the Plugins page (install and `check_config`); a repair triggered by any page load never grants a realm.

No issue found.

### File writes into Cacti

- Uninstall deletes devices only when the full-admin setting **Delete the Devices This Plugin Created on Uninstall** is checked (off by default), and only the host ids `DeviceProvisioner::create()` recorded in the `plugin_pfs_created_devices` table that still exist (one row per device, so the record has no size limit; schema migration 3 moves the milestone 4 setting into it); a mapped existing device is never recorded. Deletion goes through Cacti's `api_device_remove_multi()`. Uninstall is a Plugins page action, which needs Cacti's plugin management permission rather than a device permission, so it is not limited by `is_device_allowed()`.

- Install copies two fixed file names into `<path_cacti>/resource/script_server/` and records the SHA-256 of each file it wrote. A file already there with the right content is used but not recorded, so uninstall never removes a copy an administrator made. It never replaces a file it did not write or that changed since, and uninstall removes only recorded files whose hash still matches. No path comes from user input.
- A symbolic link at either destination, dangling or not, is refused before anything is written. Each file is copied to a temporary file in the same directory, its hash is checked, and it is renamed into place; `rename()` replaces the path itself and does not follow a link. Uninstall does not remove a link. `tests/Unit/QueryFilesTest.php` covers both kinds of link, an administrator's copy, and replacement of the plugin's own copy.
- Templates are imported through Cacti's own import code from the shipped XML; the export test checks it carries no data source profile and no third-party attribution.
- The reader writes only to the plugin's own tables; Cacti writes the RRD files.

Residual: an account that can write `resource/script_server/` can still replace a file between the install's checks and Cacti reading it; that account can already change any data query Cacti loads from there, so this gives it nothing new.

### Database access

- SQL is in `Schema` and the `*Repository` classes, plus the statements in `include/unbooted-uninstall.php`, and every statement goes through Cacti's `*_prepared` functions with values bound (checked by search at this review; no test enforces it). Table and column names are constants.

No issue found.

## Confirmed issues fixed in this review

None in this pass. The device-permission gap found in milestone 3 was fixed then.

## For the external reviewer

1. The forced-command setup on a real Lustre server: that `restrict` and the forced command hold under the site's sshd configuration (`Match` blocks, `PermitUserEnvironment`, `AuthorizedKeysCommand`), and that the reader account can read nothing else of value.
2. The allowlisted files on 2.15, 2.16, and 2.17: who can influence their content, and the in-band framing residual above.
3. Debugfs access: whatever the site does to let the reader read OST `stats` (a debugfs mount option, group, or a privileged helper) and whether it widens access.
4. `SshNodeSource::argv()` and `ProcRunner`: option injection through any setting or node field, and the child's environment and file descriptors.
5. `LustreBackend::parse()` with crafted input: memory and time at the size limits.
6. The permission suite results (`tests/bin/page-permissions.php`, `tests/bin/device-permissions.php`) and the realm and `is_device_allowed()` checks in `pfs.php`, `pfs_operator.php`, `pfs_admin.php`, and `pfs_health.php`, and the realm deferral in `pfs_auth_alternate_realms()`.
7. The query file copy and uninstall removal, including the symbolic link refusal and the temporary file and rename.
8. Remote data collectors are phase 2 and out of scope here; `remote_agent` actions do not exist yet.

## Owners

| Item | Owner | State |
|---|---|---|
| External review | Project owner to arrange | Open (#56) |
| Framing residual | External reviewer | Recorded |
| Symbolic link residual | External reviewer | Recorded |
