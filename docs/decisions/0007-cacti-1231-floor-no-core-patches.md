# 0007. Target Cacti 1.2.31 or later with no core patches

Status: Accepted (2026-11-14)

## Context

Plugins that need core patches block upgrades and cannot be adopted by sites that track upstream. Core 1.2.31 provides the plugin lifecycle and hook APIs, `api_device_save()`, `import_xml_data()`, `exec_background()`, `register_process_start()`, `call_remote_data_collector()`, and the `remote_agent` hook, as checked in milestone 1 research.

## Decision

The minimum Cacti release is 1.2.31, declared as `compat = 1.2.31` in `INFO`. The plugin uses only APIs present in that release and requires no change to Cacti core, Thold, or Maint. If milestone 1 finds a needed API missing at 1.2.31, the decision is revisited with the evidence rather than patched around.

## Consequences

- Sites on 1.2.31 or later can install the plugin without modifying core.
- The plugin cannot rely on features added after 1.2.31, even when `1.2.x` has them.
- CI covers 1.2.31 and the current `1.2.x` branch.

## Alternatives rejected

- **Requiring the current 1.2.x head.** Excludes sites that have not upgraded.
- **Carrying a core patch for a missing hook.** Breaks the no-patch constraint and every site's upgrade path.

## Research update (2026-11-15)

Every API named here has the same signature at `release/1.2.31` and the current `1.2.x` ([cacti-core](../research/cacti-core.md)). Behavior differs in three places the plugin must respect: `api_plugin_db_table_create()` skips an existing table on 1.2.31 and refreshes its schema on `1.2.x`; `exec_background()` shell-quotes its first argument only on `1.2.x`; and hook and realm registration is refused unless the calling function's name contains `install`, `upgrade`, or `setup`. No API was missing at 1.2.31, so the decision stands.
