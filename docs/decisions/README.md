# Decision records

Each record captures one architecture decision: its context, the decision, its consequences, and the alternatives rejected. Records are numbered and never renumbered. A reversed decision gets a new record that supersedes the old one.

| Record | Decision | Status |
|---|---|---|
| [0001](0001-read-only-observe-first.md) | Ship read-only observation first, in phases that each release | Accepted |
| [0002](0002-alert-through-cacti-data-sources.md) | Alert through polled Cacti data sources, not a plugin alert engine | Accepted |
| [0003](0003-backend-interface-lustre-first.md) | Put filesystem products behind a backend interface, Lustre first | Accepted |
| [0004](0004-use-cacti-remote-collectors.md) | Reach nodes through Cacti pollers and remote data collectors, not a custom bridge | Accepted |
| [0005](0005-store-cumulative-counters-as-derive.md) | Store cumulative counters raw as DERIVE with minimum 0 | Accepted |
| [0006](0006-php-82-floor-with-80-parse-guard.md) | Require PHP 8.2, keep entry points parseable on PHP 8.0 | Accepted |
| [0007](0007-cacti-1231-floor-no-core-patches.md) | Target Cacti 1.2.31 or later with no core patches | Accepted |
| [0008](0008-fixed-allowlisted-node-reads.md) | Read nodes with fixed, allowlisted requests and no operator input | Accepted |
| [0009](0009-read-nodes-through-a-forced-ssh-command.md) | Read nodes through a forced SSH command running a fixed script | Accepted |

Open decisions are tracked as issues labelled `type: decision`.
