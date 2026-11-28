<!-- Source: README.md; SHA256: e88d6875d113220a91cfc0a9d230c829a78477b17e6aa7e44dda38c9591c86ad -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [Български](../bg-BG/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [Ελληνικά](../el-GR/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [ქართული](../ka-GE/README.md) | [한국어](../ko-KR/README.md) | [Latviešu](../lv-LV/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Português (Portugal)](../pt-PT/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

<a id="plugin_pfs"></a>
# plugin_pfs

一个 Cacti 1.2 插件，在统一的 `Backend` 接口之后，将 Lustre、IBM Storage Scale (GPFS) 和 BeeGFS 的并行文件系统健康状态、容量、填充不均衡、逐目标吞吐量以及元数据操作速率引入 Cacti。每个文件系统映射为一个普通 Cacti 设备，附带数据查询、概览及按需目标图表和汇总仪表，因此 Thold 和 Maint 无需修改即可告警和抑制。

> **状态：阶段 1 至 3 及百 PB 级（exascale）扩展原语已在代码中实现，尚未发布。** 基础框架、Lustre 观测、告警与 Maint 抑制、GPFS 和 BeeGFS 只读驱动、带 Maint 集成的 OST/MDT 不均衡分析、远程数据采集器作用域（`replicate_out`、`remote_agent`）、100+ PB / 百 PB 级扩展原语（`FramedReportParser`、Schema v4 `value_kib`、`RollupPolicy`、`TopKTracker`、`ShardAssigner`、`BatchUpserter`）、CasC 运维 CLI（`cli/pfs.php`）以及生态钩子（`abilities_register`、`doctor_register_checks`、`casc_register_providers`）均已实现，并通过单元测试、安全测试及一次性 Cacti 集成测试验证。目前所有运行均使用样本节点输出，而非真实 Lustre、GPFS 或 BeeGFS 硬件。打标签发布前仍需完成：真实文件系统试点（[试点计划](../../pilot-plan.md)）、外部安全审查（[自审](../../security-review.md)）及在有文档记录的硬件上进行规模测量（[规模](../../research/scale.md)）。`INFO` 和 [CHANGELOG.md](../../../CHANGELOG.md) 已准备版本 0.1.0，但尚未打标签。

<a id="access-roles"></a>
## 访问角色

| 权限域 | 访问范围 |
| --- | --- |
| Parallel Filesystem View | 现有监控页面。 |
| Parallel Filesystem Operator | 监控，加上操作页面和个人分诊确认。 |
| Parallel Filesystem Admin | Operator 访问，加上现有配置和管理操作。 |

复制升级文件后，以管理员身份打开插件页面以注册新权限域。在 **配置 > 用户** 或用户组中显式分配 Operator 权限域。升级保留现有 View 和 Admin 权限域 ID 及授权。每个请求中，Admin 继承 Operator，Operator 继承 View；这不会为用户或组添加持久授权。插件设置除要求 Cacti 的 Settings 权限外，还要求 Parallel Filesystem Admin。

Cacti 设备权限继续限制每位用户可见的行和可执行操作；未映射对象需要管理员。凭据、配置、映射、删除及其他管理变更仍位于管理页面。确认仅持续于当前用户会话，最多一小时，且仅在同一观测仍新鲜时有效。新的或变化的观测会使其失效。确认绝不将失败测量标为健康，也不抑制告警。

<a id="why"></a>
## 为什么需要它

共享文件系统缓慢时，证据分散在各服务器。Lustre 按节点保存计数器：`lctl get_param` 报告“本地节点”的值，所以每个对象存储服务器（OSS）和元数据服务器（MDS）只持有自身目标的数值。调查缓慢文件系统的操作员需要从不同位置获得三个答案：

- **是否某个目标正在填满，而其他目标为空？** 填充不均衡影响新文件落点以及单个目标何时耗尽空间。
- **哪些目标或操作繁忙？** 逐目标字节计数器和逐 MDT 操作计数器揭示负载受数据还是元数据限制。
- **监控本身是否当前？** 只有插件能看到服务器时，平直图线才令人放心。

Cacti 为主机绘图并通过 Thold 设置值阈值，但 Cacti GitHub 组织没有 Lustre、GPFS 或 BeeGFS 监控仓库（搜索于 2026-11-14）。插件通过从存储节点读取允许列表中的只读值，并将每个文件系统表示为 Cacti 设备、目标表示为数据查询索引，填补此缺口。它复用 Cacti 的图表、权限、远程数据采集器、Thold 和 Maint，而不建立平行版本。

<a id="planned-scope"></a>
## 计划范围

| 阶段 | 范围 | 状态 | 里程碑 |
|---|---|---|---|
| 0，研究 | 版本矩阵、节点访问决策、上游行为确认、计数器和故障转移行为、规模测量 | 已完成 | [M1](https://github.com/somethingwithproof/plugin_pfs/milestone/1) |
| 1，观测 | Lustre 清单、容量和填充、OST/MDT 不均衡（`lib/ImbalanceAnalyzer.php`）、Maint 集成（`lib/HealthPage.php`）、OST 吞吐量、MDT 操作速率、图表、Thold 告警、健康页面 | 已实现（样本测试） | [M2](https://github.com/somethingwithproof/plugin_pfs/milestone/2)、[M3](https://github.com/somethingwithproof/plugin_pfs/milestone/3)、[M4](https://github.com/somethingwithproof/plugin_pfs/milestone/4) |
| 2，远程与规模 | 按轮询器划分作用域的远程采集（`lib/Reader.php`、`lib/ReplicationRepository.php`、`replicate_out`、`remote_agent`）、100+ PB 扩展原语（64 位 KiB 规范化与 Schema v4 `value_kib`、`lib/RollupPolicy.php`、`lib/TopKTracker.php`、`lib/ShardAssigner.php` + `poller_pfs.php --shard=K/N`、`lib/BatchUpserter.php`）、CasC CLI（`cli/pfs.php`）、MCP/Doctor/CasC 钩子 | 已实现（单元测试与 CI 集成测试） | [M5](https://github.com/somethingwithproof/plugin_pfs/milestone/5) |
| 3，后端 | 在 `BackendRegistry::production()` 中注册的只读 IBM Storage Scale (GPFS)（`lib/GpfsBackend.php`）和 BeeGFS（`lib/BeegfsBackend.php`）驱动 | 已实现（单元测试） | [M6](https://github.com/somethingwithproof/plugin_pfs/milestone/6) |

已实现的关键运维与扩展原语：

- **多文件系统只读驱动：** 共用 `lib/FramedReportParser.php` 的 `LustreBackend`、`GpfsBackend`（仅限帧格式 `mmpmon` `fs_io_s`/`io_s`，禁止 `reset`；`mmdf` 存储池容量/状态及等待者摘要）与 `BeegfsBackend`（BeeGFS 7.4 和 8.x 存储/元数据目标，具备精确字节到 KiB 转换、可达性/一致性状态映射及按间隔仪表语义）。
- **不均衡、余量与 Maint 感知：** `lib/ImbalanceAnalyzer.php` 计算 OST/MDT 填充率、极差、总体标准差不均衡指数、剩余空间余量、1 间隔新鲜度强制检查（`missing_targets`）、逐目标状态标志以及有界 Top-K 倾斜目标。`HealthPage`（`pfs_health.php`）和 `CascProvider::health()` 呈现 Cacti `Maint` 维护窗口，并在计划维护期间抑制活动故障警告。
- **100+ PB 与百 PB 级扩展**（[docs/architecture/exascale-scaling.md](../../architecture/exascale-scaling.md)）：经 500 PB 和 2 EB 验证的精确十进制字节到 KiB 转换（`FramedReportParser::bytesToKibInt()`）配合 `BIGINT UNSIGNED value_kib` 列（Schema v4）；层次化 `Filesystem -> Pool -> Storage Server` RRD 汇总，带有限制的选择加入/热点/倾斜目标提升（`lib/RollupPolicy.php`）；有界 $O(K)$ Top-K 倾斜目标与预聚合高负载作业/客户端摘要（`lib/TopKTracker.php`，绝不创建逐客户端设备或 RRD）；确定性 CRC32 采集器分片（`lib/ShardAssigner.php`、`php plugins/pfs/poller_pfs.php [--poller=N] [--shard=K/N] [--force] [--debug]`）；以及 500 行批量 SQL 更新插入与键集分页（`lib/BatchUpserter.php`、`CacheRepository::upsertObservationsBatch()`、`TargetRepository::forFilesystemPage()`）。
- **运维 CasC CLI 与生态钩子：** `cli/pfs.php` 支持 `export [--format=yaml|xml]`、`validate <file> [--format=yaml|xml]`、`plan <file> [--format=yaml|xml] [--output=text|json]`、`apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]` 以及 `health [--format=text|json]`，零外部 Composer 依赖。`setup.php` 和 `lib/EcosystemIntegration.php` 注册 `abilities_register`（`pfs-list-filesystems`、`pfs-get-imbalance`、`pfs-get-health`；只读，受 `Parallel Filesystem View` 和 Cacti 设备权限门控）、`doctor_register_checks`（`pfs_schema`、`pfs_reader_freshness`、`pfs_capacity_imbalance`）、`casc_register_providers`、`replicate_out` 和 `remote_agent`。

范围外：对节点或文件系统的任何写入、清除、重置或调优操作；插件自有告警引擎；自定义守护进程或网络协议；逐作业或逐客户端 Cacti 设备/RRD；修改 Cacti 核心、Thold 或 Maint。

<a id="compatibility-targets"></a>
## 兼容性目标

| 组件 | 目标 |
|---|---|
| Cacti | 1.2.31 或更高，无需核心补丁 |
| PHP | 8.2 或更高；`setup.php`、`poller_pfs.php` 和 `cli/pfs.php` 保持可在 8.0 解析，使不支持的运行时得到拒绝而非损坏 Cacti 界面 |
| Lustre | 源码和手册已检查至 2.17.0；已完成样本测试 |
| GPFS、BeeGFS | 已实现 IBM Storage Scale (GPFS) 5.x `mmpmon`/`mmdf` 和 BeeGFS 7.4 / 8.x 只读驱动并完成样本测试 |
| Thold、Maint | Thold 1.8.2 和 Maint 1.3，无需修改 |

<a id="design"></a>
## 设计

- [docs/architecture/exascale-scaling.md](../../architecture/exascale-scaling.md) 记录了双层 100+ PB / 百 PB 级遥测架构（Cacti/`plugin_pfs` 服务器+汇总+Top-K 层与带外 LDMS/Darshan/TOKIO 客户端遥测相结合）及其学术文献背景。
- [docs/PLAN.md](../../PLAN.md) 为完整计划：架构、所依赖上游事实（含来源及仍未验证的内容）、计数器语义、状态模型、安全边界、性能计算、测试和风险。
- [docs/operator-guide.md](docs/operator-guide.md) 包括安装、图表和值、升级、备份恢复、卸载及故障排除；[docs/alerting.md](docs/alerting.md) 介绍 Thold 和 Maint。
- [docs/node-setup.md](docs/node-setup.md) 说明如何为读取器准备 Lustre 服务器及轮询器。
- [docs/decisions/](../../decisions) 保存已做选择的架构决策记录。
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) 将每个需求 ID 映射到其议题。

```mermaid
flowchart LR
    N[存储服务器: Lustre OSS/MDS, GPFS NSD, BeeGFS] -->|固定只读请求| S[按轮询器划分作用域且分片的读取器]
    S --> B[BatchUpserter + 64 位 KiB 缓存]
    B --> R[RollupPolicy + TopKTracker + ImbalanceAnalyzer]
    B --> SS[脚本服务器输入]
    R --> ECO[CasC CLI, MCP 能力, Doctor 检查]
    SS -->|poller_output| T[Thold 和 Maint]
    SS --> RRD[(Cacti RRD: FS/Pool/Server + 可选目标)]
    RRD --> G[Cacti 图表与权限]
```

<a id="how-work-is-tracked"></a>
## 工作跟踪方式

- **里程碑** 遵循计划中的里程碑。每个有一项 epic 议题，列出子议题和完成依据。
- **议题** 用标签分类：`type: requirement`、`type: research`、`type: decision`、`type: epic`。需求议题给出带编号、可测试的需求（`REQ-AREA-NNN`）及验收标准。
- **标签** 还包括 `area:`、`phase:`、`priority:` 和 `status:`。见 [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels)。
- [项目看板](https://github.com/users/somethingwithproof/projects/11) 显示各里程碑的全部议题。

<a id="contributing"></a>
## 贡献

阅读 [CONTRIBUTING.md](../../../CONTRIBUTING.md)。提交需要 DCO 签署。设计讨论在议题中进行；未决决策带 `type: decision` 标签。

<a id="security"></a>
## 安全

请私下报告漏洞；见 [SECURITY.md](../../../SECURITY.md)。

<a id="license"></a>
## 许可证

GNU 通用公共许可证 v2.0，与 Cacti 及其插件相同。见 [LICENSE](../../../LICENSE)。
