# Milestone 1 research

Documentation and source research for the milestone 1 issues, run 2026-11-15. Each document names its issue, states a result, lists its method, and ends with a table of live checks (work that needs a real Lustre, Storage Scale, or BeeGFS system, a Cacti remote collector, or accounts). None of those live checks has run and no real system was contacted.

| Document | Issue | Status |
|---|---|---|
| [cacti-core](cacti-core.md) | [#25](https://github.com/somethingwithproof/plugin_pfs/issues/25) | Done from source; real collector live |
| [lustre-parameters](lustre-parameters.md) | [#16](https://github.com/somethingwithproof/plugin_pfs/issues/16) | Source at three tags; fixtures and unprivileged reads live |
| [used-percentage](used-percentage.md) | [#17](https://github.com/somethingwithproof/plugin_pfs/issues/17) | Formula from source; measured agreement live |
| [lustre-exporter](lustre-exporter.md) | [#18](https://github.com/somethingwithproof/plugin_pfs/issues/18) | Source read; release matrix live |
| [lustre-matrix](lustre-matrix.md) | [#19](https://github.com/somethingwithproof/plugin_pfs/issues/19) | Matrix sourced; recipes live |
| [node-privileges](node-privileges.md) | [#20](https://github.com/somethingwithproof/plugin_pfs/issues/20), input to [#7](https://github.com/somethingwithproof/plugin_pfs/issues/7) | Prototype tested on a fixture; real nodes live |
| [counter-reset](counter-reset.md) | [#21](https://github.com/somethingwithproof/plugin_pfs/issues/21) | RRDtool measured offline; Lustre events live |
| [thold-counters](thold-counters.md) | [#22](https://github.com/somethingwithproof/plugin_pfs/issues/22), input to [#8](https://github.com/somethingwithproof/plugin_pfs/issues/8) | Thold function run with stubs; end to end live |
| [scale](scale.md) | [#23](https://github.com/somethingwithproof/plugin_pfs/issues/23) | Structure and row counts from source; measurement live |
| [failover](failover.md) | [#24](https://github.com/somethingwithproof/plugin_pfs/issues/24) | Rule proposed from source; observation live |
| [estimates](estimates.md) | [#26](https://github.com/somethingwithproof/plugin_pfs/issues/26) | Ranges from measured lines |
| [mmpmon](mmpmon.md) | [#27](https://github.com/somethingwithproof/plugin_pfs/issues/27) | Documentation read; cluster live |
| [beegfs](beegfs.md) | [#28](https://github.com/somethingwithproof/plugin_pfs/issues/28) | Official docs read; commands not documented; system live |

Offline checks live under [tests](../../tests): `capability-gating`, `counter-reset`, `thold-derive`, `used-percent`, and `restricted-read`. Each needs only PHP or `sh`, plus the `rrdtool` binary for `counter-reset`; the Cacti and Thold checks take a source file fetched outside the repository as an argument.

The research issues for quota, LNet, and the triage view (#67 to #69) are outside milestone 1 and were not worked.
