# Alerting with Thold and Maint

The plugin has no alert engine. Thold thresholds and Maint schedules work on the plugin's data sources unchanged ([ADR 0002](decisions/0002-alert-through-cacti-data-sources.md)). This page says what to threshold, what was tested, and the limits.

## What to threshold

Use the **Parallel Filesystem Summary** values. Each overview graph has its own summary data source, so create the threshold from the graph that draws the value:

| Value | Graph | Example threshold | Notes |
|---|---|---|---|
| `fill_spread_pct` | Imbalance | High 20 (percentage points) | Fullest minus emptiest OST |
| `fill_max_pct` | Fill | High 90 | Fullest OST |
| `targets_missing` | Fill or Imbalance | High 0.5 | Targets left out of the summary: missing, or no current capacity reading |
| `targets_unhealthy` | Fill | High 0.5 | Targets whose condition flags are not in service; unknown when no target's condition is known |
| `age` | Fill | High 3 x the poll interval | Seconds since the oldest observation behind the summary; numeric whenever the filesystem was ever observed, so it keeps rising while a node is silent |
| `kbytes_avail` | Capacity | Low, in kilobytes | Unknown while any OST is missing, so it does not alert on an outage |
| `read_bytes_rate`, `write_bytes_rate` | Throughput | High, in bytes per second, 2 polls | Filesystem read and write rates the reader computes; unknown for two or three polls after a reset |
| `md_ops_rate` | Metadata Operations | High, in operations per second, 2 polls | Filesystem metadata operation rate, computed the same way |
| `read_bytes`, `write_bytes`, `md_ops` | Throughput, Metadata Operations | None with Thold 1.8.2; use the rates above | Counters (DERIVE), for graphs only |

Per-target gauges (capacity, inodes) can be thresholded the same way from per-target graphs.

## Durations

- Set the threshold's fail trigger to at least one poll. The reader and the Cacti poller run on their own schedules, so a value can be one interval old when Cacti polls it.
- A value that is unknown (`U`) never breaches a high or low threshold. In Thold 1.8.2's source an unknown value takes the not-breached path, so a breached threshold logs a restoral on a gap (read in `thold_check_threshold()`, not exercised end to end). A missing target shows up in `targets_missing` while the summary is current. When reads stop and the summary goes stale, every value but `age` is served as `U`, `targets_missing` included, so `age` is the one that alerts on a read outage; alert on these rather than on a gap.
- A counter reset produces one unknown interval on the counter's graph, and two or three unknown polls on the matching rate gauge.
- Rate thresholds should be high thresholds with a fail trigger of at least two polls. Low rate thresholds ("write rate fell below") are not supported in phase 1, because an unknown poll after a reset restores them ([ADR 0012](decisions/0012-thold-rate-alerts-high-thresholds-only.md#amendment-2026-11-15)).

## Maintenance

Put the filesystem's device in a Maint schedule. Thold checks the device with Maint's own function, so during the schedule a breach is neither counted nor notified, and Thold logs nothing for it. Collection continues: the reader reads, the poller polls, and the RRD files record the values. When the schedule ends, a breach that is still there counts from zero and notifies after the threshold's fail trigger.

## Counters and Thold 1.8.2

Do not create Thold thresholds on the counter values (`read_bytes`, `write_bytes`, `md_ops`, or per-target counters) with Thold 1.8.2. Threshold the rate gauges instead.

The plugin serves `U` for a counter interval it cannot trust: a reset, a target joining or leaving the sum, or a read that is not current. Thold 1.8.2 stores that `U` as the previous raw value of a DERIVE data source, and on the next numeric value its rate calculation throws a PHP `TypeError` inside Cacti's poller output processing. Cacti then disables the Thold plugin, and that poll's output processing stops. This was observed in the end-to-end run (log line in [research/thold-counters.md](research/thold-counters.md#milestone-4-end-to-end-run)).

The rate gauges avoid this: the reader computes the rate, Cacti stores it as a GAUGE, and Thold compares the level without computing anything. A reset makes the gauge `U` for two or three polls, which Thold reads as not breached and which the end-to-end run passes through with Thold still enabled. Thold's development branch at `dc76952` (not released) guards the DERIVE path; counter thresholds can be reconsidered once a release includes that change.

If Thold was disabled by this error: delete the counter threshold, re-enable Thold on the Plugins page, and create the threshold on the rate gauge.

## What was tested

`tests/bin/alert-replay.sh` runs against Thold v1.8.2 (`1eb406bd0f81f3204358cce7bd43c8bb5adc8e4a`) and Maint v1.3 (`cd62de9cc323a4f5a8ac80a8d9dda3c7fa16c470`), unchanged, on a disposable Cacti 1.2.31 and the 1.2.x branch, with fixture node output and real poller runs. Thresholds are saved through Thold's own `save_thold()`; the Maint schedule is the two rows Maint's schedule page saves.

| Scenario | Threshold | Result |
|---|---|---|
| Fill imbalance, then balance | `fill_spread_pct` above 20, 1 poll | Notifies once, then logs a restoral |
| An OST disappears, then returns | `targets_missing` above 0.5, 1 poll | Notifies, then restores |
| The OSS stops answering for 40 seconds | `age` above 30, 1 poll | Notifies while `age` stays numeric, restores when the node answers |
| Write rate far above the threshold for two polls | `write_bytes_rate` above 1 GB/s, 2 polls | First poll breaches without notifying, second notifies, then restores |
| Every OST's counters reset, then the write rate bursts again | `write_bytes_rate` | Two polls read unknown with no breach, no log entry, and Thold still enabled; the rate is a number again on the third, and the next burst breaches, notifies, and restores |
| Imbalance inside a Maint schedule, for two polls | `fill_spread_pct` | No count and no log entry while the RRD records the value; notifies on the poll after the schedule ends |

Not tested: notification delivery (no mail server), Thold's daemon mode, and Maint on a remote data collector (*live*).
