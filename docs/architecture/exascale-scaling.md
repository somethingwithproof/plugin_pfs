<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later

This file is part of plugin_pfs, a plugin for Cacti.
https://github.com/somethingwithproof/plugin_pfs
-->

# Exascale and 100+ PB Scaling Architecture

Monitoring parallel filesystems at 100+ PB to exascale capacity (thousands to tens of thousands of OSTs, MDTs, NSDs, or storage targets serving 10,000 to 1,000,000+ compute clients) requires separating server-tier operational monitoring from high-cardinality client-tier telemetry.

## Two-Tier Telemetry Architecture

`plugin_pfs` implements the **Server, Rollup, and Top-K Operational Tier** inside Cacti, designed to pair cleanly with an out-of-band **Client and Job Telemetry Tier** (LDMS, Darshan, TOKIO/UMAMI):

```
+-----------------------------------------------------------------------+
| Tier 1: Cacti + plugin_pfs (Server, Rollup, and Top-K Operational)   |
| - Filesystem -> Pool -> Storage Server (OSS/MDS/NSD) default RRDs     |
| - Opt-in / hot / skewed target RRD promotion (RollupPolicy)           |
| - Bounded O(K) Top-K skewed targets and heavy jobs (TopKTracker)      |
| - Deterministic CRC32 collector sharding (--shard=K/N, ShardAssigner) |
| - 500-row batched SQL upserts and keyset pagination (BatchUpserter)   |
| - Strict 64-bit KiB capacity hygiene (FramedReportParser, Schema v4)  |
+-----------------------------------------------------------------------+
                                   ^
                                   | Pre-aggregated Top-K summaries only
                                   | (Lustre job_stats, GPFS mmpmon, LDMS)
+-----------------------------------------------------------------------+
| Tier 2: Out-of-Band Client and Job Telemetry (LDMS / Darshan / TOKIO) |
| - Sub-second compute-node counters and job-level I/O characterization |
| - Never creates per-client Cacti devices or per-client RRD files      |
+-----------------------------------------------------------------------+
```

## Five Concrete Scaling Controls in `plugin_pfs`

### 1. Strict 64-Bit KiB Capacity Hygiene
- IEEE-754 64-bit doubles (used by PHP floats and RRDtool) preserve exact integer precision up to $2^{53} \approx 9.007 \times 10^{15}$.
- Storing capacity in raw bytes loses precision above ~9 PB.
- Storing capacity strictly in **KiB** (`kbytes_total`, `kbytes_free`, `kbytes_avail`) preserves exact integer precision up to **8 EiB (9.22 EB)** in both PHP floats and RRDtool, and `Schema` v4 adds `value_kib BIGINT UNSIGNED NULL` on `plugin_pfs_observation` and `plugin_pfs_summary`.
- `FramedReportParser::bytesToKibInt()` performs exact decimal long division by 1024 on raw byte strings (such as BeeGFS target exports) before any float conversion, ensuring 500 PB and 2 EB values suffer zero truncation or overflow.

### 2. Hierarchical Opt-In RRD Tiering (`lib/RollupPolicy.php`)
- Creating one RRD file per OST/NSD across 20,000 to 50,000 targets causes I/O amplification during 5-minute Cacti poller runs.
- `RollupPolicy` defaults to `filesystem`, `pool`, and `server` (OSS/MDS/NSD) rollup tiers (`RollupPolicy::summarizeByServer()`).
- Individual targets are promoted to per-target RRDs only when explicitly opted in (`optInTargets`) or automatically triggered by high fill, skew delta, or unhealthy status flags, subject to `maxPromotedTargets` (default 200 per filesystem).

### 3. Bounded Top-K Heavy-Hitter and Skew Aggregator (`lib/TopKTracker.php`)
- `TopKTracker` uses an `SplMinHeap` capped at `K` (default 20, maximum 500) so memory stays $O(K)$ regardless of target or job cardinality.
- `topSkewedTargets()` surfaces the most imbalanced and fullest targets in `ImbalanceAnalyzer`, `CascProvider::health()`, and the read-only `pfs-get-imbalance` MCP ability.
- `topHeavyHitters()` ingests pre-aggregated heavy job/client feeds (`job_stats`, `mmpmon`, `ldms`, `beegfs`) without ever provisioning per-client Cacti hosts or RRD files.

### 4. Deterministic Collector Sharding (`lib/ShardAssigner.php` and `poller_pfs.php`)
- `poller_pfs.php` accepts `--shard=K/N` (`1 <= K <= N <= 1024`).
- `ShardAssigner` partitions storage servers deterministically via unsigned CRC32 modulo `N` so concurrent workers and remote Cacti pollers divide large server fleets without overlap and finish inside a 5-minute poll window.

### 5. Batched SQL Upserts and Keyset Pagination (`lib/BatchUpserter.php`)
- `BatchUpserter::buildUpsertBatches()` chunks multi-row `INSERT ... ON DUPLICATE KEY UPDATE` writes into 500-row prepared batches (`CacheRepository::upsertObservationsBatch()`), reducing database round-trips by 500x.
- `TargetRepository::forFilesystemPage()` uses indexed keyset pagination (`WHERE filesystem_id = ? AND id > ? ORDER BY id ASC LIMIT ?`) instead of `OFFSET` scans.

## Research Context and References

This architecture aligns with empirical findings from production leadership-class HPC storage monitoring systems:

1. Agelastos, A., Allan, B., Brandt, J., Cassella, P., Enos, J., Fullop, J., Gentile, A., Monk, S., Naksinehaboon, N., Ogden, J., Rajan, M., Showerman, M., and Tucker, J., "The Lightweight Distributed Metric Service: A Scalable Infrastructure for Continuous Monitoring of Large Scale Computing Systems and Applications", *SC '14: Proceedings of the International Conference for High Performance Computing, Networking, Storage and Analysis*, 2014, DOI: `10.1109/SC.2014.18`.
2. Yang, B., Ji, X., Ma, X., Wang, X., Zhang, T., Zhu, X., El-Sayed, N., Lan, H., Li, Y., Zhai, J., Liu, W., and Xue, W., "End-to-end I/O Monitoring on a Leading Supercomputer" (Beacon), *16th USENIX Symposium on Networked Systems Design and Implementation (NSDI '19)*, 2019, `https://www.usenix.org/conference/nsdi19/presentation/yang`.
3. Lockwood, G. K., Snyder, S., Wang, T., Byna, S., Carns, P., and Wright, N. J., "A Year in the Life of a Parallel File System", *SC '18: Proceedings of the International Conference for High Performance Computing, Networking, Storage and Analysis*, 2018, DOI: `10.1109/SC.2018.00077`.
4. Lockwood, G. K., Yoo, W., Byna, S., Wright, N. J., Snyder, S., Harms, K., Nault, Z., and Carns, P., "UMAMI: A Recipe for Generating Meaningful Metrics through Holistic I/O Performance Analysis", *Proceedings of the 2nd Joint International Workshop on Parallel Data Storage and Data Intensive Scalable Computing Systems (PDSW-DISCS '17)*, 2017, DOI: `10.1145/3149393.3149395`.
5. Lockwood, G. K., Wright, N. J., Snyder, S., Carns, P., Brown, G., and Harms, K., "TOKIO on ClusterStor: Connecting Standard Tools to Enable Holistic I/O Performance Analysis", *Cray User Group (CUG '18)*, 2018, OSTI: `1632125`.
6. Carns, P., Harms, K., Allcock, W., Bacon, C., Lang, S., Latham, R., and Ross, R., "Understanding and Improving Computational Science Storage Access through Continuous Characterization" (Darshan), *ACM Transactions on Storage*, Vol. 7, No. 3, Article 8, 2011, DOI: `10.1145/2027066.2027068`.
