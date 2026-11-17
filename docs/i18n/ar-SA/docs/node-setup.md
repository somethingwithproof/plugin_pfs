<!-- Source: docs/node-setup.md; SHA256: c09178060de5f4911ee3eb7cdd732eb414dce31c5acb1d2c70b1f15222f1cccc -->

<a id="node-setup"></a>
# إعداد العقد

كيفية إعداد خادم Lustre كي يستطيع قارئ الملحق قراءته ([ADR 0009](../../../decisions/0009-read-nodes-through-a-forced-ssh-command.md)). لم يُنفذ شيء هنا على عقدة Lustre حقيقية بعد؛ الفحوص الحية في [#20](https://github.com/somethingwithproof/plugin_pfs/issues/20) و[خطة التجربة](../../../pilot-plan.md). تثبيت الملحق في Cacti موثّق في [operator-guide.md](operator-guide.md).

<a id="on-each-lustre-server"></a>
## على كل خادم Lustre

1. أنشئ حسابًا مخصصًا غير ذي امتيازات للقارئ، مثل `pfsread`، دون كلمة مرور أو استخدام آخر.
2. ثبّت [node/pfs-read.sh](../../../../node/pfs-read.sh) في `/usr/local/libexec/pfs-read.sh` بملكية root ووضع 0755. لا يقبل معاملات، ولا يقرأ إلا قائمة السماح، ويطبع خرجًا ذا علامات حدود.
3. أضف مفتاح القارئ العام إلى `authorized_keys` للحساب مع السكربت كأمر مفروض، ودون السماح بشيء آخر:

   ```
   restrict,command="/usr/local/libexec/pfs-read.sh" ssh-ed25519 AAAA... cacti-pfs
   ```

   يعطّل `restrict` إعادة التوجيه وإعادة توجيه الوكيل وX11 والطرفية الوهمية. يشغّل sshd الأمر المفروض بدل أي طلب للعميل، فلا يستطيع العميل تشغيل شيء آخر.
4. على Lustre 2.17، ملفا OST `stats` و`recovery_status` في debugfs، ولا يقرأهما افتراضيًا إلا root. دون root أو تركيب debugfs بوضع يسمح للحساب بقراءتهما، تبلّغ تلك الأهداف السعة والحالة لكن تبقى عداداتها مجهولة. ما يُسمح به قرار للموقع ([امتيازات العقد](../../../research/node-privileges.md)).

<a id="what-the-reader-account-needs-by-node-type"></a>
## احتياجات حساب القارئ حسب نوع العقدة

يحتاج الحساب قراءة الملفات أدناه فقط: دون قاعدة sudo، أو عضوية مجموعة سوى مجموعته، أو كتابة في أي موضع من Lustre، أو استخدام صدفة تسجيل الدخول إلا للأمر المفروض. مستمد من مصدر Lustre ([امتيازات العقد](../../../research/node-privileges.md))؛ ولم يُؤكد على عقدة حقيقية بعد.

| نوع العقدة | الملفات التي يقرأها السكربت | مقروءة لحساب غير ذي امتيازات |
|---|---|---|
| OSS (2.15، 2.16) | `osd-*/<fs>-OST*/kbytes*` و`files*` و`obdfilter/<fs>-OST*/degraded` و`no_precreate` و`no_create` و`readonly` تحت `/sys/fs/lustre`؛ و`obdfilter/<fs>-OST*/stats` و`recovery_status` تحت `/proc/fs/lustre` | نعم |
| OSS (2.17) | ملفات sysfs نفسها؛ و`stats` و`recovery_status` تحت `/sys/kernel/debug/lustre` | sysfs نعم؛ debugfs لـroot فقط افتراضيًا |
| MDS (الإصدارات الثلاثة) | `osd-*/<fs>-MDT*/kbytes*` و`files*` تحت `/sys/fs/lustre`؛ و`mdt/<fs>-MDT*/md_stats` تحت `/proc/fs/lustre` | نعم |
| أي نوع | `version` تحت `/sys/fs/lustre` | نعم |

إذا منح الموقع وصول debugfs على OSS 2.17، ففضّل تركيبًا تسمح فيه `gid` و`mode` بالقراءة لمجموعة مخصصة بدل تشغيل السكربت كـroot، وانتبه أن ذلك يفتح debugfs كله لتلك المجموعة.

<a id="confirm-nothing-more-is-granted"></a>
## التأكد من عدم منح صلاحيات إضافية

على كل خادم، بصفتك root:

```sh
id pfsread                                   # only its own group, plus any debugfs group you chose
sudo -l -U pfsread                           # "not allowed to run sudo"
grep -n pfsread /etc/sudoers /etc/sudoers.d/* 2>/dev/null   # nothing
cat ~pfsread/.ssh/authorized_keys            # exactly one line, starting with restrict,command="/usr/local/libexec/pfs-read.sh"
ls -l /usr/local/libexec/pfs-read.sh         # root-owned, 0755, not writable by pfsread
sshd -T -C user=pfsread,host=cacti,addr=<poller address> | grep -Ei 'permituserenvironment|authorizedkeyscommand|forcecommand'
```

من المستطلع، بصفتك مستخدمه، وبالمفتاح ومنفذ SSH للعقدة المضبوطين (استبدل `<port>` بذلك المنفذ):

```sh
ssh -F /dev/null -p <port> -i /etc/cacti/pfs/id_ed25519 -o UserKnownHostsFile=/etc/cacti/pfs/known_hosts -o HostKeyAlias=<endpoint> pfsread@<address> 'id; cat /etc/shadow'
```

يجب ألا تكون الإجابة إلا خرج سكربت القراءة ذي العلامات (`#pfs-read 1` … `#pfs-read end`)، دون أي خرج للأمر المطلوب. أي شيء آخر يعني أن الأمر المفروض غير فعال؛ أصلحه قبل إضافة العقدة إلى السجل. تحقق من أن `PermitUserEnvironment` يساوي `no` وأن لا كتلة `Match` ولا `AuthorizedKeysCommand` تمنح الحساب مفتاحًا أو أمرًا آخر.

<a id="on-each-cacti-poller-that-reads-nodes"></a>
## على كل مستطلع Cacti يقرأ العقد

1. ضع المفتاح الخاص في مسار **ملف مفتاح SSH** (افتراضيًا `/etc/cacti/pfs/id_ed25519`)، مقروءًا لمستخدم المستطلع فقط. لا ينشئه الملحق ولا ينسخه ولا يطبعه مطلقًا. تحذر صفحة الصحة إذا أمكن لمستخدمين آخرين قراءته.
2. ضع مفتاح مضيف كل عقدة في **ملف المضيفين المعروفين** (افتراضيًا `/etc/cacti/pfs/known_hosts`)، تحت اسم نقطة النهاية المسجّل حرفيًا. يتصل القارئ بالعنوان المحلول مع ضبط `HostKeyAlias` إلى نقطة النهاية، ويرفض المفتاح المفقود أو المتغير. لا يقبل مفتاحًا جديدًا بنفسه مطلقًا.
3. اسمح في صفحة السجل بالشبكات التي يجوز لهذا المستطلع الوصول إليها. العقدة التي تُحل نقطة نهايتها إلى أي عنوان خارجها لا تُحفظ ولا تُقرأ.

<a id="ssh-options"></a>
## خيارات SSH

يشغّل القارئ `ssh` مع `-F /dev/null` قبل كل الخيارات الأخرى، فلا يُقرأ `~/.ssh/config` لمستخدم المستطلع ولا `ssh_config` للنظام، ولا يستطيع محتواهما إضافة خيار أو تغييره. اضبط قيم الموقع في إعدادات الملحق (الإعدادات، أنظمة الملفات المتوازية): مسار عميل SSH وملف المفتاح وملف المضيفين المعروفين ومهلة الاتصال ومهلة القراءة وأقصى حجم استجابة وميزانية القارئ والتزامن. يُضبط منفذ العقدة واسم الدخول لكل عقدة في السجل. لا تُدعم الخيارات خارج هذه الإعدادات، مثل مضيف وسيط أو أمر وكيل.
