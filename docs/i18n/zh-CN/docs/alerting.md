<!-- Source: docs/alerting.md; SHA256: 9a7a13377db55a29e79a612b78203e8912f7d62de1a540b3f79f9fb3e5e08792 -->

<a id="alerting-with-thold-and-maint"></a>
# 使用 Thold 和 Maint 告警

插件没有告警引擎。Thold 阈值和 Maint 计划无需修改即可用于插件的数据源（[ADR 0002](../../../decisions/0002-alert-through-cacti-data-sources.md)）。本页介绍应对哪些值设阈值、已测试内容及限制。

<a id="what-to-threshold"></a>
## 应设置阈值的内容

使用 **并行文件系统汇总** 值。每个概览图表有自己的汇总数据源，因此应从绘制所需值的图表创建阈值：

| 值 | 图表 | 阈值示例 | 说明 |
|---|---|---|---|
| `fill_spread_pct` | 不均衡 | 高阈值 20（百分点） | 最满 OST 减最空 OST |
| `fill_max_pct` | 填充 | 高阈值 90 | 最满的 OST |
| `targets_missing` | 填充或不均衡 | 高阈值 0.5 | 未计入汇总的目标：缺失或没有当前容量读取 |
| `targets_unhealthy` | 填充 | 高阈值 0.5 | 状态标志不在服务中的目标；没有任何已知目标状态时为未知 |
| `age` | 填充 | 高阈值为轮询间隔的 3 倍 | 汇总所依据的最旧观测以来的秒数；只要文件系统曾被观测就为数值，因此节点无响应时持续增长 |
| `kbytes_avail` | 容量 | 低阈值，单位千字节 | 任意 OST 缺失时为未知，因此不会对中断告警 |
| `read_bytes`、`write_bytes`、`md_ops` | 吞吐量、元数据操作 | Thold 1.8.2 不可使用；见下文 | 计数器（DERIVE） |

逐目标 gauge（容量、inode）可同样从逐目标图表设置阈值。

<a id="durations"></a>
## 持续时间

- 将阈值的故障触发次数至少设为一次轮询。读取器和 Cacti 轮询器按各自计划运行，因此 Cacti 轮询时值可能已有一个间隔的年龄。
- 未知值（`U`）绝不触发高或低阈值。在 Thold 1.8.2 源码中，未知值走未超限分支，因此已经超限的阈值在图表缺口时会记录恢复（通过阅读 `thold_check_threshold()` 确认，未做端到端验证）。汇总仍当前时，缺失目标反映在 `targets_missing` 中。当读取停止、汇总过期时，除 `age` 外所有值均返回 `U`，包括 `targets_missing`，所以应由 `age` 对读取中断告警；应对这些指示值告警，而不是对缺口告警。
- 计数器重置会在该计数器图表上产生一个未知间隔。

<a id="maintenance"></a>
## 维护

将文件系统设备加入 Maint 计划。Thold 使用 Maint 自身函数检查设备，因此计划期间既不计数也不通知超限，也不记录相关日志。采集继续：读取器读取、轮询器轮询，RRD 文件记录值。计划结束时，仍存在的超限从零开始计数，在达到故障触发次数后通知。

<a id="counters-and-thold-182"></a>
## 计数器与 Thold 1.8.2

使用 Thold 1.8.2 时，不要对计数器值（`read_bytes`、`write_bytes`、`md_ops` 或逐目标计数器）创建 Thold 阈值。

对于无法信任的计数器间隔，插件返回 `U`：重置、目标加入或离开求和集合，或读取并非当前。Thold 1.8.2 将这个 `U` 存为 DERIVE 数据源的上一原始值，下一次数值出现时，其速率计算在 Cacti 轮询器输出处理中抛出 PHP `TypeError`。Cacti 随即禁用 Thold，且该次轮询输出处理停止。端到端运行观察到了这一问题（日志见 [research/thold-counters.md](../../../research/thold-counters.md#milestone-4-end-to-end-run)）。在没有 `U` 到达时，故障触发为两次轮询的高速率阈值行为符合 [ADR 0012](../../../decisions/0012-thold-rate-alerts-high-thresholds-only.md)：第一次超过阈值仅超限而不通知，第二次通知。

Thold 开发分支的 `dc76952`（未发布）改变了此路径：未知速率建立新基线，并保留告警状态，而非记录恢复。待包含该变更的 Thold 版本发布后，应重新考虑速率阈值；是否修订 ADR 0012 由所有者决定。

<a id="what-was-tested"></a>
## 已测试内容

`tests/bin/alert-replay.sh` 在一次性 Cacti 1.2.31 和 1.2.x 分支中，使用未经修改的 Thold v1.8.2（`1eb406bd0f81f3204358cce7bd43c8bb5adc8e4a`）和 Maint v1.3（`cd62de9cc323a4f5a8ac80a8d9dda3c7fa16c470`），以节点测试样本输出和真实轮询器运行进行测试。阈值通过 Thold 自己的 `save_thold()` 保存；Maint 计划为其计划页面保存的两行记录。

| 场景 | 阈值 | 结果 |
|---|---|---|
| 填充不均衡，随后平衡 | `fill_spread_pct` 大于 20，1 次轮询 | 通知一次，随后记录恢复 |
| 一个 OST 消失，随后返回 | `targets_missing` 大于 0.5，1 次轮询 | 通知，随后恢复 |
| OSS 停止响应 40 秒 | `age` 大于 30，1 次轮询 | `age` 保持数值时通知，节点响应后恢复 |
| 写入速率远高于阈值，持续两次轮询 | `write_bytes` 大于 1 GB/s，2 次轮询 | 第一次超限不通知，第二次通知，随后恢复 |
| Maint 计划期间不均衡，持续两次轮询 | `fill_spread_pct` | RRD 记录值，但不计数也不记日志；计划结束后的轮询通知 |

未测试：通知投递（没有邮件服务器）、Thold 守护进程模式，以及远程数据采集器上的 Maint（*实机*）。
