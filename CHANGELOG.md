# Changelog

All notable changes are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html). The version in `INFO` is the version of the next release; a release is a signed tag of that version (see [docs/release.md](docs/release.md)).

## [0.1.0] - Unreleased

Phase 1, Lustre observation. Not released: pilots on real filesystems, the external security review, and live scale measurements are still open.

### Added

- Plugin install, upgrade, and uninstall through Cacti's plugin API, with a refusal on PHP below 8.2 that leaves Cacti working (milestone 2).
- Registry of filesystems, nodes, and the networks each poller may reach; a background reader per poller that reads Lustre servers over SSH through a fixed forced-command script, `node/pfs-read.sh` (milestone 2).
- A cache of target inventory, condition, capacity, and counters with their freshness, and a health page (milestone 2).
- One Cacti device per filesystem with OST and MDT script server data queries, device, data, and graph templates, five overview graphs, and per-target graphs on demand; counters as DERIVE data sources that read unknown across a reset (milestone 3).
- A per-filesystem summary data source for Thold: fill and imbalance, target counts, capacity totals, filesystem throughput and metadata counters, and data age (milestones 3 and 4).
- Summary rate gauges, `read_bytes_rate`, `write_bytes_rate`, and `md_ops_rate`, computed by the reader so Thold can alert on rates without a counter data source ([ADR 0012 amendment](docs/decisions/0012-thold-rate-alerts-high-thresholds-only.md#amendment-2026-11-15)).
- An uninstall setting, off by default, that deletes the devices the plugin created, with their graphs and data sources, through Cacti's device delete; mapped existing devices always stay (REQ-LIFE-005, [#31](https://github.com/somethingwithproof/plugin_pfs/issues/31)).
- Device permissions on every page and admin action, and a `graph_buttons` link from a device's graphs to its filesystem view (milestone 3).
- End-to-end tests with unchanged Thold and Maint releases, target churn and reindex, backup and restore, failure scenarios, and a scale harness (milestone 4).
- Operator guide, alerting guide, release process, security self-review, and pilot plan (milestone 4).

### Changed

- An absent counter line counts as zero in the filesystem sums for a target that is present, in service, and reported its other values in the same read; the per-target value stays unknown ([ADR 0013](docs/decisions/0013-absent-counter-lines-count-as-zero-in-sums.md)).

### Fixed

- A target that a complete read left out serves unknown on its per-target data source at once, instead of its last value until that value went stale.
- The Fill graph did not draw `age`, so Cacti stored no value for it and Thold could not threshold it.

### Known issues

- Thold 1.8.2 thresholds on counter data sources (`read_bytes`, `write_bytes`, `md_ops`) are not supported: an unknown counter interval makes Thold's poller hook fail and Cacti disables Thold. Threshold the rate gauges instead ([docs/alerting.md](docs/alerting.md#counters-and-thold-182)).
