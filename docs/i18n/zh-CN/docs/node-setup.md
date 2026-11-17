<!-- Source: docs/node-setup.md; SHA256: c8550f0416a418ce66d9fdcaf77fcd251f23daa25dcc969b8b05ad76a4793b11 -->

<a id="node-setup"></a>
# 节点设置

本页说明如何准备 Lustre 服务器，让插件的读取器可以读取（[ADR 0009](../../../decisions/0009-read-nodes-through-a-forced-ssh-command.md)）。这里的内容尚未在真实 Lustre 节点上运行；实测见 [#20](https://github.com/somethingwithproof/plugin_pfs/issues/20) 和[试点计划](../../../pilot-plan.md)。在 Cacti 中安装插件见 [operator-guide.md](operator-guide.md)。

<a id="on-each-lustre-server"></a>
## 在每台 Lustre 服务器上

1. 为读取器创建专用的非特权账户，例如 `pfsread`，不设密码且不作其他用途。
2. 将 [node/pfs-read.sh](../../../../node/pfs-read.sh) 安装为 `/usr/local/libexec/pfs-read.sh`，所有者为 root，权限为 0755。它不接受参数，只读取允许列表，并输出带边界标记的内容。
3. 将读取器公钥加入该账户的 `authorized_keys`，将脚本设为强制命令，不允许其他功能：

   ```
   restrict,command="/usr/local/libexec/pfs-read.sh" ssh-ed25519 AAAA... cacti-pfs
   ```

   `restrict` 关闭转发、代理转发、X11 和伪终端。sshd 用强制命令替换客户端要求的任何命令，因此客户端不能运行其他内容。
4. Lustre 2.17 的 OST `stats` 和 `recovery_status` 文件位于 debugfs，默认仅 root 可读。如果不是 root，也没有使账户可读的 debugfs 挂载权限，这些目标会报告容量和状态，但计数器仍未知。允许哪种方式由站点决定（[节点权限](../../../research/node-privileges.md)）。

<a id="what-the-reader-account-needs-by-node-type"></a>
## 各节点类型所需的读取器账户权限

读取器账户仅需读取下表文件，无其他权限：无需 sudo 规则，无需自身组以外的组成员资格，不得在 Lustre 任何位置写入，除强制命令外不得使用登录 shell。此结论源于 Lustre 源码（[节点权限](../../../research/node-privileges.md)），尚未在真实节点确认。

| 节点类型 | 脚本读取的文件 | 非特权账户是否可读 |
|---|---|---|
| OSS（2.15、2.16） | `/sys/fs/lustre` 下的 `osd-*/<fs>-OST*/kbytes*`、`files*` 及 `obdfilter/<fs>-OST*/degraded`、`no_precreate`、`no_create`、`readonly`；`/proc/fs/lustre` 下的 `obdfilter/<fs>-OST*/stats` 和 `recovery_status` | 是 |
| OSS（2.17） | 相同 sysfs 文件；`/sys/kernel/debug/lustre` 下的 `stats` 和 `recovery_status` | sysfs 可以；debugfs 默认仅 root |
| MDS（三个版本） | `/sys/fs/lustre` 下的 `osd-*/<fs>-MDT*/kbytes*`、`files*`；`/proc/fs/lustre` 下的 `mdt/<fs>-MDT*/md_stats` | 是 |
| 任意 | `/sys/fs/lustre` 下的 `version` | 是 |

如果站点授予 2.17 OSS 的 debugfs 访问权限，优先采用通过 `gid` 和 `mode` 允许专用组读取的 debugfs 挂载，而非以 root 运行脚本；注意这会向该组开放整个 debugfs。

<a id="confirm-nothing-more-is-granted"></a>
## 确认未授予额外权限

在每台服务器上，以 root 执行：

```sh
id pfsread                                   # only its own group, plus any debugfs group you chose
sudo -l -U pfsread                           # "not allowed to run sudo"
grep -n pfsread /etc/sudoers /etc/sudoers.d/* 2>/dev/null   # nothing
cat ~pfsread/.ssh/authorized_keys            # exactly one line, starting with restrict,command="/usr/local/libexec/pfs-read.sh"
ls -l /usr/local/libexec/pfs-read.sh         # root-owned, 0755, not writable by pfsread
sshd -T -C user=pfsread,host=cacti,addr=<poller address> | grep -Ei 'permituserenvironment|authorizedkeyscommand|forcecommand'
```

从轮询器以轮询器用户身份，使用配置的密钥执行：

```sh
ssh -F /dev/null -i /etc/cacti/pfs/id_ed25519 -o UserKnownHostsFile=/etc/cacti/pfs/known_hosts -o HostKeyAlias=<endpoint> pfsread@<address> 'id; cat /etc/shadow'
```

响应必须仅为读取脚本带边界标记的输出（`#pfs-read 1` … `#pfs-read end`），不能含所请求命令的任何输出。其他内容表示强制命令未生效；在将节点添加到注册表前修复。检查 `PermitUserEnvironment` 为 `no`，并且没有 `Match` 块或 `AuthorizedKeysCommand` 给账户其他密钥或命令。

<a id="on-each-cacti-poller-that-reads-nodes"></a>
## 在每个读取节点的 Cacti 轮询器上

1. 将私钥放在 **SSH 密钥文件** 指定路径（默认 `/etc/cacti/pfs/id_ed25519`），仅轮询器用户可读。插件绝不创建、复制或打印它。其他用户可读时，健康页面会警告。
2. 将每个节点主机密钥放在 **已知主机文件**（默认 `/etc/cacti/pfs/known_hosts`），按注册时完全相同的端点名称记录。读取器连接解析后的地址，并将 `HostKeyAlias` 设为端点；拒绝缺失或变更的密钥。它绝不自行接受新密钥。
3. 在注册表页面允许该轮询器可访问的网络。节点端点只要解析到任何范围外地址，就既不保存也不读取。

<a id="ssh-options"></a>
## SSH 选项

读取器运行 `ssh` 时，将 `-F /dev/null` 放在其他所有选项之前，因此不会读取轮询器用户的 `~/.ssh/config` 或系统 `ssh_config`，其中内容无法添加或更改选项。通过插件设置（设置、并行文件系统）配置站点值：SSH 客户端路径、密钥文件、已知主机文件、连接超时、读取超时、最大响应大小、读取器预算和并发数。节点端口及登录名在注册表中逐节点设置。不支持这些设置之外的选项，例如跳板主机或代理命令。
