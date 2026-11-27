<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later

This file is part of plugin_pfs, a plugin for Cacti.
https://github.com/somethingwithproof/plugin_pfs
-->

# Exascale Algorithmic Audit and PHP Performance Profile

This document records the algorithmic audit and PHP runtime benchmarks for `plugin_pfs` at 100,000-target and 1,000,000-key scale, along with the bottlenecks identified and fixed on PR #87.

## Measurement Environment and Methodology

- Runtime: PHP 8.4.25 CLI (`memory_limit=1G`, OPcache JIT disabled, matching the CI coverage runtime).
- Timing: monotonic wall-clock nanoseconds via `hrtime(true)` over the hot path only (fixture construction excluded from elapsed time).
- Memory: each benchmark ran in an isolated PHP subprocess to measure clean process-wide `memory_get_peak_usage(false)` (allocated PHP heap), `memory_get_peak_usage(true)` (OS allocator peak), and per-call working-memory delta (`peak_after - usage_before`).
- Baseline revision: commit `f160e46` (`feat/gpfs-beegfs-maint-remote-casc-mcp-doctor`).

## Before and After Benchmark Results

| Workload | Baseline Time | Optimized Time | Speedup | Baseline Delta / Peak Real | Optimized Delta / Peak Real |
| --- | ---: | ---: | ---: | ---: | ---: |
| `RollupPolicy::selectPromotedTargets` (100k targets, cap 200) | 118.10 ms | 29.44 ms | 4.01x | +19.57 MiB / 120.52 MiB | +0.13 MiB / 102.02 MiB |
| `BeegfsBackend::parse` (10k targets at 500 PiB) | 124.35 ms | 67.19 ms | 1.85x | +27.53 MiB / 36.48 MiB | +25.39 MiB / 34.48 MiB |
| `SummaryCalculator::calculate` (99k OST + 1k MDT) | 539.58 ms | 325.64 ms | 1.66x | +28.26 MiB / 249.12 MiB | +12.91 MiB / 235.05 MiB |
| `LustreBackend::parse` (5k OSTs, 25k+ sections) | 101.86 ms | 63.32 ms | 1.61x | +27.09 MiB / 32.16 MiB | +26.02 MiB / 30.16 MiB |
| `TopKTracker::topHeavyHitters` (100k streamed jobs, K=20) | 100.43 ms | 67.18 ms | 1.49x | 2.00 MiB peak real | 2.00 MiB peak real |
| `ImbalanceAnalyzer::analyze` (100k targets, K=20) | 325.47 ms | 232.92 ms | 1.40x | +105.80 MiB / 284.08 MiB | +72.33 MiB / 250.03 MiB |
| `ShardAssigner::owns` (1,000,000 client/node keys) | 185.06 ms | 135.66 ms | 1.36x | 2.00 MiB peak real | 2.00 MiB peak real |
| `TopKTracker::topSkewedTargets` (100k targets, K=20) | 43.54 ms | 35.74 ms | 1.22x | +0.07 MiB / 102.02 MiB | +0.09 MiB / 102.02 MiB |
| `RollupPolicy::summarizeByServer` (100k targets, 1k servers) | 80.63 ms | 73.91 ms | 1.09x | 142.03 MiB peak real | 142.03 MiB peak real |
| `BatchUpserter::buildUpsertBatches` (100k rows, 500/batch) | 24.05 ms | 21.75 ms | 1.11x | +34.53 MiB / 74.02 MiB | +25.92 MiB / 66.02 MiB |
| `BatchUpserter::streamUpsertBatches` (100k rows, 500/batch) | n/a (materialized) | 23.49 ms | n/a | +34.53 MiB / 74.02 MiB | +0.30 MiB / 2.00 MiB |
| `LatencyHistogram` (1,000,000 individual RPC samples) | new | 281.21 ms | n/a | n/a | +0.07 MiB / 2.00 MiB |
| `RpcLatencyMetrics` (100k targets, 350M RPCs folded) | new | 171.34 ms | n/a | n/a | +0.07 MiB / 2.00 MiB |

## Algorithmic Bottlenecks Found and Fixed

### 1. Unbounded Candidate Buffering and Tuple Sort in `RollupPolicy`
- **Root cause:** `RollupPolicy::selectPromotedTargets()` appended every qualifying target to `$candidates[]` and sorted all $M$ candidates via `usort()` with temporary 2-element array comparisons (`[$b['priority'], $a['name']] <=> [...]`). At 100,000 targets with ~40,000 hot or skewed candidates, this required $O(N)$ working memory (+19.57 MiB) and $O(N \log N)$ comparisons. In `summarizeByServer()`, copying `$bucket = $buckets[$nodeId]` before mutating triggered copy-on-write HashTable duplication on every target.
- **Fix:** Replaced full buffering with an inline $O(K)$ binary min-heap (`$K = maxPromotedTargets`, default 200) and a fast-path threshold check against the heap root before heap mutation, reducing working memory by 150x (+19.57 MiB to +0.13 MiB) and runtime by 4.01x. Updated `summarizeByServer()` to mutate `$buckets[$nodeId]` in place.

### 2. Per-Target `ValueSpec` Re-Allocation and Full Line-Array Slicing in Backends
- **Root cause:** `LustreBackend::parse()` invoked `$this->values()` inside the `foreach ($targets)` loop, instantiating 34 `ValueSpec` objects per target (170,000 allocations for 5,000 OSTs). Both `LustreBackend::parse()` and `FramedReportParser::parse()` copied the entire `$lines` array with `array_slice($lines, 1)`. `LustreBackend::classify()` ran up to five regexes and called `implode('|', self::FLAGS)` per section. `FramedReportParser::bytesToKibInt()` ran character-by-character decimal long division even for byte counts within signed 64-bit integer range (<= 8 EiB - 1 B).
- **Fix:** Pre-indexed `ValueSpec` defaults by `TargetType` once outside the target loop, replaced `array_slice($lines, 1)` with indexed `for ($idx = 1; ...)` loops, added prefix dispatch (`osd-`, `obdfilter/`, `mdt/`) and a precompiled flag regex in `LustreBackend::classify()`, replaced regex loops in `parseKeyValues()` and `uint()` with `strspn()`/`ctype_digit()`, and added a native 64-bit right-shift (`>> 10`) fast path in `bytesToKibInt()` while preserving exact decimal long division above `PHP_INT_MAX`.

### 3. Multi-Pass Filtering, Duplicate Sorting, and String Joining in `SummaryCalculator` and `ImbalanceAnalyzer`
- **Root cause:** `SummaryCalculator::calculate()` allocated temporary arrays via three `array_filter()` calls, stored 100,000-element `$fill[]` and `$inodes[]` arrays only to compute min/max/sum across four scans, scanned `$osts` twice per counter inside `sum()`, called `TargetId::name()` (`sprintf`) twice per OST, sorted 99,000 member strings twice, and allocated multi-megabyte strings via `implode("\n", $members)` for SHA-1 hashing. `ImbalanceAnalyzer::analyze()` buffered `$ostFills[]` and `$ostAvails[]` arrays for four-pass summary statistics and variance calculation.
- **Fix:** Folded capacity, condition, and inode statistics into single-pass scalar accumulators (`O(1)` memory), replaced `ImbalanceAnalyzer` variance array buffering with single-pass Welford online variance, combined `read_bytes` and `write_bytes` OST summation into one pass that skips `sort()` when targets arrive in index order and reuses one sorted list when member sets match, switched SHA-1 member hashing to streaming `hash_init()`/`hash_update()`, cached per-target known observation timestamps for `absentAsZero()`, and replaced `sprintf()` in `TargetId::name()` with direct concatenation and `dechex()`.

### 4. Per-Record Allocations and Regex/Linear Scans in `TopKTracker` and `ShardAssigner`
- **Root cause:** `TopKTracker` allocated full output entry arrays and 4-element tuples for every rejected record before comparing against the min-heap root, used linear `in_array()` and PCRE per record in `topHeavyHitters()`, and relied on `SplMinHeap` lexicographic array comparisons that broke ties inconsistently with descending name order. `ShardAssigner::shardFor()` called `(int) sprintf('%u', crc32($key))` on every key, allocating and re-parsing a string 1,000,000 times.
- **Fix:** Replaced `SplMinHeap` with an inline binary min-heap that checks the root threshold before allocating output entries and enforces consistent tie-breaking, switched `ALLOWED_SOURCES` to an $O(1)$ hash map and ID validation to `strspn()`, and replaced `sprintf('%u', crc32($key))` with `crc32($key) & 0xFFFFFFFF` on 64-bit PHP.

### 5. Duplicate Batch Materialization and Per-Row Writes in `BatchUpserter` and `CacheRepository`
- **Root cause:** `BatchUpserter::buildUpsertBatches()` duplicated `$rows` via `array_chunk()` and rebuilt the identical 500-row SQL placeholder string on every full batch. `CacheRepository::apply()` executed one SQL `INSERT` and one `SELECT @@in_transaction` per observation row rather than batching observations.
- **Fix:** Added `BatchUpserter::streamUpsertBatches()` to yield 500-row batches in $O(\text{batchSize})$ working memory (+0.30 MiB for 100,000 rows vs +34.53 MiB materialized) while caching the full-batch SQL string once. Updated `CacheRepository::upsertObservationsBatch()` and `CacheRepository::apply()` to stream observations in 500-row prepared batches.

### 6. Secondary Quadratic Scans in `InventoryReconciler` and `Reader`
- **Root cause:** `InventoryReconciler::reconcile()` evaluated `in_array(null, $reads, true)` inside the `foreach ($existing)` loop ($O(\text{targets} \times \text{nodes})$ for conflict targets). `Reader::observationsFor()` ran `in_array($name, $update->report->absent, true)` inside the metric loop ($O(M^2)$ per target).
- **Fix:** Hoisted `$allNodesAnswered` outside the target loop in `InventoryReconciler::reconcile()` and converted `$update->report->absent` into an $O(1)$ hash map once per target in `Reader::observationsFor()`.

## Bounded-Memory Enterprise Telemetry Overhead

- `LatencyHistogram` uses 201 fixed integer buckets (8 exact 1-microsecond base buckets, 24 power-of-2 octaves with 8 linear sub-buckets each up to ~134.2 s, plus 1 overflow bucket) with integer bit-shift bucket indexing (zero `log()` calls). Recording 1,000,000 individual RPC samples completes in 281.21 ms with +0.07 MiB working memory; folding 100,000 target summaries (350,000,000 RPCs across read, write, and metadata channels) completes in 171.34 ms with +0.07 MiB working memory.
- `RecoveryMetrics` and `StallMetrics` aggregate across nodes in a single $O(N)$ pass with $O(1)$ working memory.
