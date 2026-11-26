<!-- Source: README.md; SHA256: 40f2c5a5e9be97e4bd2b05a9ebab2a77de51de3af1b1c21dbeb5ee7f936cdd3b -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_pfs

Umugereka wa Cacti 1.2 uzana muri Cacti amakuru y'ubuzima n'umutwaro by'ububiko bw'amadosiye bukora mu buryo buteganye (parallel filesystem): ubushobozi bwo kubika n'ubusumbane bw'ikuzura hagati y'intego (targets), umuvuduko w'amakuru kuri buri ntego, n'igipimo cy'ibikorwa kuri metadata. Lustre ni yo backend ya mbere. GPFS (IBM Storage Scale) na BeeGFS bikurikiraho binyuze ku murongo uhuza backend (backend interface). Amakuru yinjira mu bikoresho bya Cacti bisanzwe, amasoko y'amakuru (data sources), n'ibishushanyo mbonera (graphs), ku buryo Thold na Maint bitanga impuruza kandi bikazihagarika by'igihe gito nta gihindutse.

> **Imiterere: icyiciro cya 4 (milestone 4), kode y'icyiciro cya 1 yarangiye, ntabwo irashyirwa hanze.** Ibyiciro bya 2 (urufatiro), 3 (ugukurikirana Lustre), na 4 (gutanga impuruza no kwitegura gushyira hanze) byararangiye kuri `main` cyangwa birimo gusuzumwa. Uyu mugereka urashyirwamo (install), uvugururwa (upgrade), kandi ukurwamo (uninstall) binyuze muri API y'imigereka ya Cacti kandi wanga PHP iri munsi ya 8.2. Igikoresho gisoma inyuma (background reader) kuri buri poller gisoma seriveri za Lustre binyuze kuri SSH hifashishijwe script yategetswe idahinduka ([node/pfs-read.sh](../../../node/pfs-read.sh)) kandi kikabika by'igihe gito (cache) urutonde rw'ibikoresho, imiterere, ubushobozi, n'ibipimo bibara (counters) hamwe n'uburyo bikiri bishya. Buri bubiko bw'amadosiye buhinduka igikoresho cya Cacti gifite ibibazo by'amakuru (data queries) bya OST na MDT, ibishushanyo bitanu by'incamake, ibishushanyo bya buri ntego bisabwe, n'isoko y'amakuru y'incamake ya Thold. Thold v1.8.2 na Maint v1.3, nta gihindutse, bitanga impuruza kandi bigahagarika impuruza ku bipimo by'incamake mu isuzuma ryuzuye kuva ku ntangiriro kugeza ku musozo; imipaka ya Thold ku bipimo bibara (counters) ntiyemewe muri Thold 1.8.2 ([docs/alerting.md](../../alerting.md)). Isuzuma ryose kugeza ubu rikorwa ku bisohoka by'imfashanyigisho (fixture) bya node muri Cacti ishobora guhanagurwa: uyu mugereka nturakoreshwa kuri node nyayo ya Lustre. Ibikirindiriwe mbere yo gushyira hanze: igerageza ku bubiko bw'amadosiye nyabwo ([gahunda y'igerageza](../../pilot-plan.md)), isuzuma ry'umutekano rikorwa n'abo hanze ([isuzuma bwite](../../security-review.md)), n'ipima ry'ubushobozi ku bikoresho byanditswe ([ubushobozi n'ingano](../../research/scale.md)). Verisiyo 0.1.0 yateguwe muri `INFO` na [CHANGELOG.md](../../../CHANGELOG.md) ariko ntabwo irashyirwaho tag.

## Inshingano z'uburenganzira

| Urwego (Realm) | Uburenganzira |
| --- | --- |
| Parallel Filesystem View | Amapaji yo gukurikirana asanzwe ariho. |
| Parallel Filesystem Operator | Gukurikirana hamwe n'ipaji y'ibikorwa (Operations) no kwemeza ko umuntu ku giti cye yiboneye ikibazo (triage acknowledgments). |
| Parallel Filesystem Admin | Uburenganzira bwa Operator hamwe n'ibikorwa bisanzwe byo gutunganya igenamiterere n'ubuyobozi. |

Nyuma yo gukoporora amadosiye y'ivugurura, fungura ipaji ya Plugins nk'umuyobozi (administrator) kugira ngo wandikishe urwego rushya. Ha umuntu urwego rwa Operator ku buryo bweruye munsi ya **Configuration > Users** cyangwa amatsinda y'abakoresha. Amavugurura agumana nimero ziranga (IDs) n'uburenganzira bisanzwe bya View na Admin. Uburenganzira bwa Admin bukomora ubwa Operator, kandi uburenganzira bwa Operator bukomora ubwa View kuri buri busabe; ibi ntibyiyongeza mu burenganzira bubitswe ku bakoresha cyangwa amatsinda. Igenamiterere ry'umugereka na ryo risaba uburenganzira bwa Parallel Filesystem Admin hiyongereyeho uruhushya rwa Settings rwa Cacti.

Uburenganzira bw'ibikoresho bya Cacti bukomeza kugabanya imirongo n'ibikorwa biboneka kuri buri mukoresha; ibintu bitarahuzwa bisaba umuyobozi. Ibimenyetso by'ibanga (credentials), igenamiterere, guhuza (mapping), gusiba n'izindi mpinduka z'ubuyobozi biguma mu mapaji y'ubuyobozi. Kwemeza ko wabonye ikibazo (acknowledgments) bimara gusa mu gihe cy'ifungura ririho ry'umukoresha, igihe kitarenze isaha imwe kandi gusa mu gihe rya genzura rimwe rikiri rishya. Igenzura rishya cyangwa ryahindutse rirabitesha agaciro. Ntibishobora na rimwe kwerekana ko igipimo cyapfuye kimeze neza cyangwa guhagarika impuruza.

## Impamvu

Iyo ububiko bw'amadosiye buhuriweho bugenda buhoro, ibimenyetso biba bitatanye kuri seriveri zitandukanye. Lustre ibika ibipimo byayo bibara kuri buri node: `lctl get_param` itanga imibare "kuri node y'imbere", bityo buri seriveri ibika ibintu (object storage server - OSS) na seriveri ya metadata (metadata server - MDS) ibika gusa imibare y'intego zayo bwite. Umukoresha ukora isuzuma ku bubiko bw'amadosiye bugenda buhoro aba akeneye ibisubizo bitatu biva ahantu hatandukanye:

- **Ese hari intego imwe irimo kwuzura mu gihe izindi zirimo ubusa?** Ubusumbane bw'ikuzura buhindura aho amadosiye mashya ajya n'igihe intego imwe ishiriramo umwanya.
- **Ni izihe ntego cyangwa ibikorwa bifite akazi kenshi?** Ibipimo bya byte kuri buri ntego n'ibipimo by'ibikorwa kuri buri MDT byerekana niba umutwaro uterwa n'amakuru nyirizina cyangwa metadata.
- **Ese ubwabyo igukurikirana rigezweho?** Igishushanyo kidahinduka gihumuriza gusa iyo umugereka wari ushoboye kubona seriveri.

Cacti ishushanya seriveri kandi igashyiraho imipaka y'imibare ikoresheje Thold, kandi umuryango wa Cacti kuri GitHub nta bubiko (repository) ufite bwo gukurikirana Lustre, GPFS, cyangwa BeeGFS (byashakishijwe ku ya 2026-11-14). Uyu mugereka uziba icyo cyuho usoma imibare yemewe ku rutonde (allowlisted) kandi yo gusoma gusa iturutse kuri za node z'ububiko kandi ukagaragaza buri bubiko bw'amadosiye nk'igikoresho cya Cacti gifite intego ziba ari imibare ndangamimerere (indexes) y'ikibazo cy'amakuru (data query). Ukoresha bundi bushya uburyo bwa Cacti bwo gushushanya, uburenganzira, abakusanya amakuru ba kure (remote data collectors), Thold, na Maint aho kubaka ibindi bisa na byo.

## Ibikorwa biteganyijwe

| Icyiciro | Ibikubiyemo | Intambwe (Milestones) |
|---|---|---|
| 0, ubushakashatsi | Urutonde rwa verisiyo zisohoka, icyemezo ku buryo bwo kugera kuri node, kwemeza imikorere y'isoko nkuru (upstream), imikorere y'ibipimo bibara no gusimburana igihe habaye ikibazo (failover), ipima ry'ubushobozi | [M1](https://github.com/somethingwithproof/plugin_pfs/milestone/1) |
| 1, gukurikirana | Urutonde rw'ibikoresho bya Lustre, ubushobozi n'ikuzura, umuvuduko wa OST, igipimo cy'ibikorwa bya MDT, ibishushanyo, impuruza za Thold, guhagarika impuruza kwa Maint, ipaji y'ubuzima | [M2](https://github.com/somethingwithproof/plugin_pfs/milestone/2), [M3](https://github.com/somethingwithproof/plugin_pfs/milestone/3), [M4](https://github.com/somethingwithproof/plugin_pfs/milestone/4) |
| 2, ubujyakuzimu | Abakusanya amakuru ba kure ba Cacti, kureba ku rwego rw'akazi (job) no ku rwego rw'umukoresha (client) mu mubare w'ibintu ufite umupaka | [M5](https://github.com/somethingwithproof/plugin_pfs/milestone/5) |
| 3, za backend | Za backend za GPFS (IBM Storage Scale) na BeeGFS | [M6](https://github.com/somethingwithproof/plugin_pfs/milestone/6) |

Ibitari mu nshingano: igikorwa icyo ari cyo cyose cyo kwandika, gusiba, gusubiza inyuma (reset), cyangwa gutunganya imikorere kuri node cyangwa ku bubiko bw'amadosiye; moteri y'impuruza yihariwe n'umugereka; porogaramu ikora mu ibanga (daemon) cyangwa porotokole y'urusobemiyoboro yihariye; amasoko y'amakuru kuri buri kazi; impinduka kuri Cacti ubwayo, Thold, cyangwa Maint.

## Intego z'ubwuzuzanye

| Igice | Intego |
|---|---|
| Cacti | 1.2.31 cyangwa verisiyo zikurikiraho, nta mpinduka kuri kode fatizo |
| PHP | 8.2 cyangwa verisiyo zikurikiraho; `setup.php` iguma isomeka kuri 8.0 kugira ngo igihe ikoreshejweho uburyo butemewe ihakane neza aho kwangiza imigaragarire ya Cacti |
| Lustre | Verisiyo zizashyirwaho mu cyiciro cya 1 (milestone 1); kode n'ibitabo byasuzumwe kugeza ubu biri kuri 2.17.0 |
| GPFS, BeeGFS | Icyiciro cya 3; verisiyo zizashyirwaho nyuma y'ubushakashatsi |
| Thold, Maint | Nta mpinduka zisabwa |

## Imiterere n'ishusho mbonera

- [docs/PLAN.md](../../PLAN.md) ni gahunda yuzuye: imiterere y'ubwubatsi, amakuru y'isoko nkuru (upstream) ishingiyeho (hamwe n'amasoko n'ibitaragenzurwa), ibisobanuro by'ibipimo bibara, imiterere y'ibihe (state model), imipaka y'umutekano, imibare y'imikorere, isuzuma, n'ibyago.
- [docs/operator-guide.md](../../operator-guide.md) ikubiyemo gushyiramo (install), ibishushanyo n'imibare, kuvugurura, kubika no gusubiranya (backup and restore), gukuramo (uninstall), no gukemura ibibazo; [docs/alerting.md](../../alerting.md) ikubiyemo Thold na Maint.
- [docs/node-setup.md](../../node-setup.md) isobanura uko wategura seriveri ya Lustre na poller ku bw'igikoresho gisoma (reader).
- [docs/decisions/](../../decisions) ibika inyandiko z'ibyemezo by'imiterere y'ubwubatsi ku mahitamo yamaze gukorwa.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) ihuza buri nomero iranga ibisabwa n'ikibazo (issue) cyayo.

```mermaid
flowchart LR
    N[Seriveri za Lustre: OSS, MDS] -->|ubusabe budahinduka bwo gusoma gusa| S[Isoko ya node kuri poller ya Cacti]
    S --> R[Igikoresho gisoma]
    R --> C[(Ububiko bw'igihe gito bw'igenzura)]
    C --> SS[Ibyinjira muri script server]
    SS -->|poller_output| T[Thold na Maint]
    SS --> RRD[(Za RRD za Cacti)]
    RRD --> G[Ibishushanyo n'uburenganzira bya Cacti]
```

## Uko akazi gakurikiranwa

- **Ibyiciro (Milestones)** bikurikira ibyiciro bya gahunda. Buri kimwe gifite ikibazo kigenga (epic issue) kigaragaza ibibazo bito bikigize n'ibimenyetso byo gusohoka.
- **Ibibazo (Issues)** bishyirwa mu byiciro hakoreshejwe uturango (labels): `type: requirement`, `type: research`, `type: decision`, `type: epic`. Ibibazo by'ibisabwa bigaragaza ibisabwa bifite inomero kandi bishobora gusuzumwa (`REQ-AREA-NNN`) hamwe n'ibipimo byo kwemerwa.
- **Uturango (Labels)** tunatwara `area:`, `phase:`, `priority:`, na `status:`. Reba [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Ikibaho cy'umushinga](https://github.com/users/somethingwithproof/projects/11) kigaragaza ibibazo byose mu byiciro bitandukanye.

## Gutanga umusanzu

Soma [CONTRIBUTING.md](../../../CONTRIBUTING.md). Buri commit isaba gusinywa na DCO (DCO sign-off). Ibiganiro ku miterere n'ishusho mbonera bibera ku bibazo (issues); ibyemezo bitarafatwa bitwara akamenyetso ka `type: decision`.

## Umutekano

Tanga raporo y'intege nke mu ibanga; reba [SECURITY.md](../../../SECURITY.md).

## Uruhushya

GNU General Public License v2.0, uruhushya rumwe n'urwa Cacti n'imigereka yayo. Reba [LICENSE](../../../LICENSE).
