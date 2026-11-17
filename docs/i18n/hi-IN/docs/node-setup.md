<!-- Source: docs/node-setup.md; SHA256: c8550f0416a418ce66d9fdcaf77fcd251f23daa25dcc969b8b05ad76a4793b11 -->

<a id="node-setup"></a>
# नोड सेटअप

Lustre सर्वर तैयार करने का तरीका ताकि प्लगइन का रीडर उसे पढ़ सके ([ADR 0009](../../../decisions/0009-read-nodes-through-a-forced-ssh-command.md))। यहाँ की कोई प्रक्रिया अभी असली Lustre नोड पर नहीं चली; वास्तविक जाँच [#20](https://github.com/somethingwithproof/plugin_pfs/issues/20) और [पायलट योजना](../../../pilot-plan.md) में हैं। Cacti में इंस्टॉल के लिए [operator-guide.md](operator-guide.md) देखें।

<a id="on-each-lustre-server"></a>
## हर Lustre सर्वर पर

1. रीडर के लिए अलग, बिना विशेषाधिकार वाला खाता बनाएँ, जैसे `pfsread`, बिना पासवर्ड और किसी अन्य उपयोग के।
2. [node/pfs-read.sh](../../../../node/pfs-read.sh) को `/usr/local/libexec/pfs-read.sh` पर root स्वामित्व और 0755 मोड में इंस्टॉल करें। यह आर्ग्युमेंट नहीं लेता, केवल अनुमति सूची पढ़ता और सीमाचिह्न वाला आउटपुट देता है।
3. रीडर की सार्वजनिक कुंजी खाते के `authorized_keys` में जोड़ें, स्क्रिप्ट को अनिवार्य कमांड बनाएँ और कुछ और अनुमति न दें:

   ```
   restrict,command="/usr/local/libexec/pfs-read.sh" ssh-ed25519 AAAA... cacti-pfs
   ```

   `restrict` फ़ॉरवर्डिंग, एजेंट फ़ॉरवर्डिंग, X11 और छद्म टर्मिनल बंद करता है। क्लाइंट जो भी माँगे, sshd उसके स्थान पर अनिवार्य कमांड चलाता है, इसलिए क्लाइंट कुछ और नहीं चला सकता।
4. Lustre 2.17 में OST `stats` और `recovery_status` फ़ाइलें debugfs में हैं, जिन्हें डिफ़ॉल्ट रूप से केवल root पढ़ सकता है। root या खाते को पढ़ने देने वाले debugfs माउंट मोड के बिना वे टारगेट क्षमता और स्थिति देते हैं लेकिन काउंटर अज्ञात रहते हैं। क्या अनुमति देना है, यह साइट का निर्णय है ([नोड विशेषाधिकार](../../../research/node-privileges.md))।

<a id="what-the-reader-account-needs-by-node-type"></a>
## नोड प्रकार के अनुसार रीडर खाते की आवश्यकताएँ

खाते को केवल नीचे की फ़ाइलें पढ़नी हैं: sudo नियम नहीं, अपने समूह के अलावा सदस्यता नहीं, Lustre में कहीं लेखन नहीं और अनिवार्य कमांड के अलावा लॉगिन शेल उपयोग नहीं। Lustre स्रोत से निकाला गया ([नोड विशेषाधिकार](../../../research/node-privileges.md)); असली नोड पर अभी पुष्टि नहीं हुई।

| नोड प्रकार | स्क्रिप्ट की पढ़ी फ़ाइलें | बिना विशेषाधिकार के पठनीय |
|---|---|---|
| OSS (2.15, 2.16) | `/sys/fs/lustre` के अंतर्गत `osd-*/<fs>-OST*/kbytes*`, `files*` और `obdfilter/<fs>-OST*/degraded`, `no_precreate`, `no_create`, `readonly`; `/proc/fs/lustre` में `obdfilter/<fs>-OST*/stats` तथा `recovery_status` | हाँ |
| OSS (2.17) | वही sysfs फ़ाइलें; `/sys/kernel/debug/lustre` में `stats` और `recovery_status` | Sysfs हाँ; debugfs डिफ़ॉल्ट में केवल root |
| MDS (तीनों) | `/sys/fs/lustre` में `osd-*/<fs>-MDT*/kbytes*`, `files*`; `/proc/fs/lustre` में `mdt/<fs>-MDT*/md_stats` | हाँ |
| सभी | `/sys/fs/lustre` में `version` | हाँ |

साइट 2.17 OSS पर debugfs पहुँच दे तो स्क्रिप्ट root से चलाने के बजाय ऐसा debugfs माउंट चुनें जिसके `gid` और `mode` अलग समूह को पढ़ने दें; ध्यान रखें इससे पूरा debugfs उस समूह के लिए खुलता है।

<a id="confirm-nothing-more-is-granted"></a>
## पुष्टि करें कि अतिरिक्त अनुमति नहीं मिली

हर सर्वर पर root के रूप में:

```sh
id pfsread                                   # only its own group, plus any debugfs group you chose
sudo -l -U pfsread                           # "not allowed to run sudo"
grep -n pfsread /etc/sudoers /etc/sudoers.d/* 2>/dev/null   # nothing
cat ~pfsread/.ssh/authorized_keys            # exactly one line, starting with restrict,command="/usr/local/libexec/pfs-read.sh"
ls -l /usr/local/libexec/pfs-read.sh         # root-owned, 0755, not writable by pfsread
sshd -T -C user=pfsread,host=cacti,addr=<poller address> | grep -Ei 'permituserenvironment|authorizedkeyscommand|forcecommand'
```

पोलर से, पोलर उपयोगकर्ता के रूप में, कॉन्फ़िगर की गई कुंजी से:

```sh
ssh -F /dev/null -i /etc/cacti/pfs/id_ed25519 -o UserKnownHostsFile=/etc/cacti/pfs/known_hosts -o HostKeyAlias=<endpoint> pfsread@<address> 'id; cat /etc/shadow'
```

उत्तर केवल पढ़ने वाली स्क्रिप्ट का सीमाचिह्न वाला आउटपुट (`#pfs-read 1` … `#pfs-read end`) हो, माँगे गए कमांड का कुछ नहीं। अन्य आउटपुट का अर्थ अनिवार्य कमांड लागू नहीं है; नोड रजिस्ट्री में जोड़ने से पहले ठीक करें। जाँचें कि `PermitUserEnvironment`, `no` हो और कोई `Match` ब्लॉक या `AuthorizedKeysCommand` खाते को दूसरी कुंजी या कमांड न देता हो।

<a id="on-each-cacti-poller-that-reads-nodes"></a>
## नोड पढ़ने वाले हर Cacti पोलर पर

1. निजी कुंजी **SSH कुंजी फ़ाइल** के पथ पर रखें (डिफ़ॉल्ट `/etc/cacti/pfs/id_ed25519`), केवल पोलर उपयोगकर्ता पढ़ सके। प्लगइन इसे कभी बनाता, कॉपी या छापता नहीं। दूसरे उपयोगकर्ता पढ़ सकें तो स्वास्थ्य पृष्ठ चेतावनी देता है।
2. हर नोड की होस्ट कुंजी **ज्ञात होस्ट फ़ाइल** में रखें (डिफ़ॉल्ट `/etc/cacti/pfs/known_hosts`), ठीक उसी एंडपॉइंट नाम से जो दर्ज है। रीडर हल किए पते पर जुड़ता है, `HostKeyAlias` एंडपॉइंट पर रखता है और गायब या बदली कुंजी अस्वीकार करता है। खुद नई कुंजी कभी स्वीकार नहीं करता।
3. रजिस्ट्री में वे नेटवर्क अनुमति दें जहाँ पोलर पहुँच सकता है। नोड एंडपॉइंट का कोई भी पता उनसे बाहर हल हो तो नोड न सहेजा जाता है न पढ़ा जाता है।

<a id="ssh-options"></a>
## SSH विकल्प

रीडर `ssh` में सभी विकल्पों से पहले `-F /dev/null` देता है, इसलिए पोलर उपयोगकर्ता की `~/.ssh/config` और सिस्टम `ssh_config` नहीं पढ़ी जातीं और उनमें कुछ विकल्प जोड़ या बदल नहीं सकता। साइट मान प्लगइन सेटिंग (सेटिंग, समानांतर फ़ाइल सिस्टम) में दें: SSH क्लाइंट पथ, कुंजी फ़ाइल, ज्ञात होस्ट फ़ाइल, कनेक्शन टाइमआउट, पढ़ने का टाइमआउट, अधिकतम प्रतिक्रिया आकार, रीडर बजट और समवर्ती संख्या। पोर्ट और लॉगिन नाम रजिस्ट्री में प्रति नोड सेट होते हैं। इनके बाहर जंप होस्ट या प्रॉक्सी कमांड जैसे विकल्प समर्थित नहीं हैं।
