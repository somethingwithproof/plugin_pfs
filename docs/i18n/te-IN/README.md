<!-- Source: README.md; SHA256: e88d6875d113220a91cfc0a9d230c829a78477b17e6aa7e44dda38c9591c86ad -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_pfs

Lustre, IBM Storage Scale (GPFS), మరియు BeeGFS లలో ఒకే `Backend` ఇంటర్‌ఫేస్ వెనుక పారలల్ ఫైల్‌సిస్టమ్ ఆరోగ్యం, సామర్థ్యం, ఫిల్ అసమతుల్యత, ప్రతి-టార్గెట్ త్రూపుట్, మరియు మెటాడేటా ఆపరేషన్ రేట్లను Cacti లోకి తీసుకువచ్చే Cacti 1.2 ప్లగిన్. ప్రతి ఫైల్‌సిస్టమ్ డేటా క్వెరీలు, అవలోకన మరియు అవసరానుగుణ టార్గెట్ గ్రాఫ్‌లు, మరియు సారాంశ గేజ్‌లతో కూడిన సాధారణ Cacti పరికరంగా మ్యాప్ అవుతుంది, కాబట్టి Thold మరియు Maint ఎటువంటి మార్పు లేకుండా అలర్ట్‌లు పంపిస్తాయి మరియు సప్రెస్ చేస్తాయి.

> **స్థితి: దశలు 1 నుండి 3 మరియు exascale స్కేలింగ్ ప్రిమిటివ్‌లు కోడ్‌లో అమలు చేయబడ్డాయి, ఇంకా విడుదల కాలేదు.** పునాది, Lustre పరిశీలన, అలర్టింగ్ మరియు Maint సప్రెషన్, GPFS మరియు BeeGFS రీడ్-ఓన్లీ డ్రైవర్లు, Maint ఇంటిగ్రేషన్‌తో OST/MDT అసమతుల్యత విశ్లేషణ, రిమోట్ డేటా కలెక్టర్ స్కోపింగ్ (`replicate_out`, `remote_agent`), 100+ PB / exascale స్కేలింగ్ ప్రిమిటివ్‌లు (`FramedReportParser`, Schema v4 `value_kib`, `RollupPolicy`, `TopKTracker`, `ShardAssigner`, `BatchUpserter`), CasC ఆపరేటర్ CLI (`cli/pfs.php`), మరియు ఎకోసిస్టమ్ హుక్స్ (`abilities_register`, `doctor_register_checks`, `casc_register_providers`) అమలు చేయబడ్డాయి మరియు యూనిట్, భద్రతా, మరియు డిస్పోజబుల్-Cacti ఇంటిగ్రేషన్ పరీక్షలతో ధృవీకరించబడ్డాయి. ఇప్పటివరకు జరిగిన అన్ని రన్‌లు లైవ్ Lustre, GPFS, లేదా BeeGFS హార్డ్‌వేర్‌కు బదులుగా ఫిక్చర్ నోడ్ అవుట్‌పుట్‌ను ఉపయోగిస్తున్నాయి. విడుదలను ట్యాగ్ చేయడానికి ముందు ఇంకా తెరిచి ఉన్నవి: నిజమైన ఫైల్‌సిస్టమ్‌లపై పైలట్ పరీక్షలు ([పైలట్ ప్రణాళిక](../../pilot-plan.md)), బాహ్య భద్రతా సమీక్ష ([స్వీయ-సమీక్ష](../../security-review.md)), మరియు డాక్యుమెంట్ చేయబడిన హార్డ్‌వేర్‌పై స్కేల్ కొలతలు ([స్కేల్](../../research/scale.md)). వెర్షన్ 0.1.0 `INFO` మరియు [CHANGELOG.md](../../../CHANGELOG.md) లో సిద్ధం చేయబడింది కానీ ఇంకా ట్యాగ్ చేయబడలేదు.

## యాక్సెస్ పాత్రలు

| రెల్మ్ | యాక్సెస్ |
| --- | --- |
| Parallel Filesystem View | ప్రస్తుత పర్యవేక్షణ పేజీలు. |
| Parallel Filesystem Operator | పర్యవేక్షణతో పాటు ఆపరేషన్స్ పేజీ మరియు వ్యక్తిగత ట్రయాజ్ నిర్ధారణలు. |
| Parallel Filesystem Admin | ఆపరేటర్ యాక్సెస్‌తో పాటు ప్రస్తుత కాన్ఫిగరేషన్ మరియు పరిపాలనా చర్యలు. |

అప్‌గ్రేడ్ ఫైళ్లను కాపీ చేసిన తర్వాత, కొత్త రెల్మ్‌ను నమోదు చేయడానికి నిర్వాహకుడిగా Plugins పేజీని తెరవండి. **Configuration > Users** లేదా వినియోగదారు సమూహాల క్రింద Operator రెల్మ్‌ను స్పష్టంగా కేటాయించండి.
అప్‌గ్రేడ్‌లు ప్రస్తుత View మరియు Admin రెల్మ్ ID లు మరియు అనుమతులను అలాగే ఉంచుతాయి. ప్రతి అభ్యర్థనపై Admin యాక్సెస్ Operator యాక్సెస్‌ను వారసత్వంగా పొందుతుంది, మరియు Operator యాక్సెస్ View యాక్సెస్‌ను వారసత్వంగా పొందుతుంది; ఇది వినియోగదారులు లేదా సమూహాలకు నిల్వ చేసిన అనుమతులను జోడించదు. ప్లగిన్ సెట్టింగ్‌లకు Cacti యొక్క Settings అనుమతితో పాటు Parallel Filesystem Admin యాక్సెస్ కూడా అవసరం.

Cacti పరికర అనుమతులు ఇప్పటికీ ప్రతి వినియోగదారుకు అందుబాటులో ఉన్న వరుసలు మరియు చర్యలను పరిమితం చేస్తాయి; మ్యాప్ చేయని ఆబ్జెక్ట్‌లకు నిర్వాహకుడు అవసరం.
క్రెడెన్షియల్స్, కాన్ఫిగరేషన్, మ్యాపింగ్, తొలగింపు మరియు ఇతర పరిపాలనా మార్పులు పరిపాలనా పేజీలలోనే ఉంటాయి. నిర్ధారణలు ప్రస్తుత వినియోగదారు సెషన్‌లో మాత్రమే, గరిష్టంగా ఒక గంట పాటు మరియు అదే పరిశీలన తాజాగా ఉన్నంత వరకు మాత్రమే ఉంటాయి. కొత్త లేదా మారిన పరిశీలనలు వాటిని చెల్లుబాటు కాకుండా చేస్తాయి. అవి ఎప్పుడూ విఫలమైన కొలతను ఆరోగ్యకరమైనదిగా గుర్తించవు లేదా అలర్ట్‌లను సప్రెస్ చేయవు.

## ఎందుకు

షేర్డ్ ఫైల్‌సిస్టమ్ నెమ్మదిగా ఉన్నప్పుడు, ఆధారాలు సర్వర్‌లన్నింటిలో విస్తరించి ఉంటాయి. Lustre తన కౌంటర్‌లను ప్రతి నోడ్‌కు విడిగా ఉంచుతుంది: `lctl get_param` "స్థానిక నోడ్‌పై" ఉన్న విలువలను నివేదిస్తుంది, కాబట్టి ప్రతి ఆబ్జెక్ట్ స్టోరేజ్ సర్వర్ (OSS) మరియు మెటాడేటా సర్వర్ (MDS) తన స్వంత టార్గెట్‌ల సంఖ్యలను మాత్రమే కలిగి ఉంటుంది. నెమ్మదిగా ఉన్న ఫైల్‌సిస్టమ్‌ను పరిశోధించే ఆపరేటర్‌కు వేర్వేరు ప్రదేశాల నుండి మూడు సమాధానాలు అవసరం:

- **ఇతర టార్గెట్‌లు ఖాళీగా ఉండగా ఒక టార్గెట్ నిండిపోతోందా?** ఫిల్ అసమతుల్యత కొత్త ఫైళ్లు ఎక్కడ చేరతాయో మరియు ఒకే టార్గెట్‌లో స్థలం ఎప్పుడు అయిపోతుందో మారుస్తుంది.
- **ఏ టార్గెట్‌లు లేదా ఆపరేషన్‌లు బిజీగా ఉన్నాయి?** ప్రతి-టార్గెట్ బైట్ కౌంటర్లు మరియు ప్రతి-MDT ఆపరేషన్ కౌంటర్లు లోడ్ డేటా-బౌండ్‌గా ఉందా లేదా మెటాడేటా-బౌండ్‌గా ఉందా అనేది చూపుతాయి.
- **పర్యవేక్షణే తాజాగా ఉందా?** ప్లగిన్ సర్వర్‌లను చూడగలిగితేనే సమతల గ్రాఫ్ భరోసానిస్తుంది.

Cacti హోస్ట్‌లను గ్రాఫ్ చేస్తుంది మరియు Thold తో విలువలకు థ్రెషోల్డ్‌లను పెడుతుంది, మరియు Cacti GitHub సంస్థలో Lustre, GPFS, లేదా BeeGFS పర్యవేక్షణ కోసం ఎటువంటి రిపోజిటరీ లేదు (2026-11-14 న శోధించబడింది). స్టోరేజ్ నోడ్‌ల నుండి అనుమతించబడిన, రీడ్-ఓన్లీ విలువలను చదవడం ద్వారా మరియు ప్రతి ఫైల్‌సిస్టమ్‌ను దాని టార్గెట్‌లు డేటా క్వెరీ యొక్క ఇండెక్స్‌లుగా ఉండే Cacti పరికరంగా చూపడం ద్వారా ఈ ప్లగిన్ ఆ లోటును పూడ్చుతుంది. ఇది సమాంతర సంస్కరణలను నిర్మించడానికి బదులుగా Cacti యొక్క గ్రాఫింగ్, అనుమతులు, రిమోట్ డేటా కలెక్టర్లు, Thold మరియు Maint లను తిరిగి ఉపయోగిస్తుంది.

## ప్రణాళికాబద్ధమైన పరిధి

| దశ | పరిధి | స్థితి | మైలురాళ్ళు |
|---|---|---|---|
| 0, పరిశోధన | విడుదల మ్యాట్రిక్స్, నోడ్ యాక్సెస్ నిర్ణయం, అప్‌స్ట్రీమ్ ప్రవర్తన నిర్ధారణ, కౌంటర్ మరియు ఫెయిల్‌ఓవర్ ప్రవర్తన, స్కేల్ కొలతలు | పూర్తయింది | [M1](https://github.com/somethingwithproof/plugin_pfs/milestone/1) |
| 1, పరిశీలన | Lustre ఇన్వెంటరీ, సామర్థ్యం మరియు ఫిల్, OST/MDT అసమతుల్యత (`lib/ImbalanceAnalyzer.php`), Maint ఇంటిగ్రేషన్ (`lib/HealthPage.php`), OST త్రూపుట్, MDT ఆపరేషన్ రేట్లు, గ్రాఫ్‌లు, Thold అలర్టింగ్, ఆరోగ్య పేజీ | అమలు చేయబడింది (ఫిక్చర్-పరీక్షించబడింది) | [M2](https://github.com/somethingwithproof/plugin_pfs/milestone/2), [M3](https://github.com/somethingwithproof/plugin_pfs/milestone/3), [M4](https://github.com/somethingwithproof/plugin_pfs/milestone/4) |
| 2, రిమోట్ మరియు స్కేల్ | పోలర్-స్కోప్డ్ రిమోట్ సేకరణ (`lib/Reader.php`, `lib/ReplicationRepository.php`, `replicate_out`, `remote_agent`), 100+ PB స్కేలింగ్ ప్రిమిటివ్‌లు (64-బిట్ KiB హైజీన్ + Schema v4 `value_kib`, `lib/RollupPolicy.php`, `lib/TopKTracker.php`, `lib/ShardAssigner.php` + `poller_pfs.php --shard=K/N`, `lib/BatchUpserter.php`), CasC CLI (`cli/pfs.php`), MCP/Doctor/CasC హుక్స్ | అమలు చేయబడింది (యూనిట్ మరియు CI ఇంటిగ్రేషన్ పరీక్షించబడింది) | [M5](https://github.com/somethingwithproof/plugin_pfs/milestone/5) |
| 3, బ్యాకెండ్‌లు | `BackendRegistry::production()` లో నమోదు చేయబడిన రీడ్-ఓన్లీ IBM Storage Scale (GPFS) (`lib/GpfsBackend.php`) మరియు BeeGFS (`lib/BeegfsBackend.php`) డ్రైవర్లు | అమలు చేయబడింది (యూనిట్-పరీక్షించబడింది) | [M6](https://github.com/somethingwithproof/plugin_pfs/milestone/6) |

అమలు చేయబడిన ముఖ్య ఆపరేటర్ మరియు స్కేలింగ్ ప్రిమిటివ్‌లు:

- **బహుళ-ఫైల్‌సిస్టమ్ రీడ్-ఓన్లీ డ్రైవర్లు:** `lib/FramedReportParser.php` ను పంచుకునే `LustreBackend`, `GpfsBackend` (`reset` నిషేధించబడిన ఫ్రేమ్డ్ `mmpmon` `fs_io_s`/`io_s` మాత్రమే, `mmdf` పూల్ సామర్థ్యం/స్థితులు, మరియు వెయిటర్ సారాంశాలు), మరియు `BeegfsBackend` (ఖచ్చితమైన బైట్-నుండి-KiB మార్పిడి, రీచబిలిటీ/కన్సిస్టెన్సీ కండిషన్ మ్యాపింగ్, మరియు ప్రతి-విరామం గేజ్ సెమాంటిక్స్‌తో BeeGFS 7.4 మరియు 8.x స్టోరేజ్/మెటాడేటా టార్గెట్‌లు).
- **అసమతుల్యత, హెడ్‌రూమ్, మరియు Maint అవగాహన:** `lib/ImbalanceAnalyzer.php` OST/MDT ఫిల్ నిష్పత్తులు, వ్యాప్తి, పాపులేషన్ స్టాండర్డ్ డీవియేషన్ అసమతుల్యత సూచిక, హెడ్‌రూమ్, 1-విరామం తాజాదనం అమలు (`missing_targets`), ప్రతి-టార్గెట్ స్థితి ఫ్లాగ్‌లు, మరియు పరిమిత Top-K వక్ర టార్గెట్‌లను గణిస్తుంది. `HealthPage` (`pfs_health.php`) మరియు `CascProvider::health()` Cacti `Maint` నిర్వహణ విండోలను చూపిస్తాయి మరియు షెడ్యూల్ చేసిన నిర్వహణ సమయంలో క్రియాశీల వైఫల్య హెచ్చరికలను సప్రెస్ చేస్తాయి.
- **100+ PB మరియు exascale స్కేలింగ్** ([docs/architecture/exascale-scaling.md](../../architecture/exascale-scaling.md)): `BIGINT UNSIGNED value_kib` నిలువు వరుసలతో (Schema v4) పాటు 500 PB మరియు 2 EB వద్ద ధృవీకరించబడిన ఖచ్చితమైన దశాంశ బైట్-నుండి-KiB మార్పిడి (`FramedReportParser::bytesToKibInt()`); పరిమిత ఆప్ట్-ఇన్/హాట్/వక్ర టార్గెట్ ప్రమోషన్‌తో శ్రేణిబద్ధమైన `Filesystem -> Pool -> Storage Server` RRD రోలప్‌లు (`lib/RollupPolicy.php`); పరిమిత $O(K)$ Top-K వక్ర టార్గెట్‌లు మరియు ముందుగా సమీకరించబడిన భారీ జాబ్/క్లయింట్ సారాంశాలు (`lib/TopKTracker.php`, ప్రతి-క్లయింట్ పరికరాలు లేదా RRD లను ఎప్పుడూ సృష్టించదు); నిర్దిష్ట CRC32 కలెక్టర్ షార్డింగ్ (`lib/ShardAssigner.php`, `php plugins/pfs/poller_pfs.php [--poller=N] [--shard=K/N] [--force] [--debug]`); మరియు 500-వరుసల బ్యాచ్డ్ SQL అప్‌సర్ట్‌లు అలాగే కీసెట్ పేజినేషన్ (`lib/BatchUpserter.php`, `CacheRepository::upsertObservationsBatch()`, `TargetRepository::forFilesystemPage()`).
- **ఆపరేటర్ CasC CLI మరియు ఎకోసిస్టమ్ హుక్స్:** `cli/pfs.php` ఎటువంటి బాహ్య Composer డిపెండెన్సీలు లేకుండా `export [--format=yaml|xml]`, `validate <file> [--format=yaml|xml]`, `plan <file> [--format=yaml|xml] [--output=text|json]`, `apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]`, మరియు `health [--format=text|json]` లకు మద్దతు ఇస్తుంది. `setup.php` మరియు `lib/EcosystemIntegration.php` `abilities_register` (`pfs-list-filesystems`, `pfs-get-imbalance`, `pfs-get-health`; రీడ్-ఓన్లీ, `Parallel Filesystem View` మరియు Cacti పరికర అనుమతులపై నియంత్రించబడినవి), `doctor_register_checks` (`pfs_schema`, `pfs_reader_freshness`, `pfs_capacity_imbalance`), `casc_register_providers`, `replicate_out`, మరియు `remote_agent` లను నమోదు చేస్తాయి.

పరిధి వెలుపల ఉన్నవి: నోడ్ లేదా ఫైల్‌సిస్టమ్‌పై ఏదైనా వ్రాయడం, క్లియర్ చేయడం, రీసెట్ చేయడం లేదా ట్యూనింగ్ ఆపరేషన్; ప్లగిన్-యాజమాన్యంలోని అలర్టింగ్ ఇంజిన్; కస్టమ్ డీమన్ లేదా నెట్‌వర్క్ ప్రోటోకాల్; ప్రతి-జాబ్ లేదా ప్రతి-క్లయింట్ Cacti పరికరాలు/RRD లు; Cacti కోర్, Thold, లేదా Maint లకు మార్పులు.

## అనుకూలత లక్ష్యాలు

| భాగం | లక్ష్యం |
|---|---|
| Cacti | 1.2.31 లేదా ఆ తర్వాతిది, కోర్ ప్యాచ్‌లు లేవు |
| PHP | 8.2 లేదా ఆ తర్వాతిది; మద్దతు లేని రన్‌టైమ్‌కు విరిగిన Cacti UI బదులు తిరస్కరణ అందేలా `setup.php`, `poller_pfs.php`, మరియు `cli/pfs.php` 8.0 పై కూడా పార్స్ చేయదగినవిగా ఉంటాయి |
| Lustre | 2.17.0 వద్ద తనిఖీ చేసిన సోర్స్ మరియు మాన్యువల్‌లు; ఫిక్చర్-పరీక్షించబడింది |
| GPFS, BeeGFS | IBM Storage Scale (GPFS) 5.x `mmpmon`/`mmdf` మరియు BeeGFS 7.4 / 8.x రీడ్-ఓన్లీ డ్రైవర్లు అమలు చేయబడి ఫిక్చర్-పరీక్షించబడ్డాయి |
| Thold, Maint | Thold 1.8.2 మరియు Maint 1.3, మార్పు లేదు |

## రూపకల్పన

- [docs/architecture/exascale-scaling.md](../../architecture/exascale-scaling.md) రెండు-అంచెల 100+ PB / exascale టెలిమెట్రీ ఆర్కిటెక్చర్ (అవుట్-ఆఫ్-బ్యాండ్ LDMS/Darshan/TOKIO క్లయింట్ టెలిమెట్రీతో జతచేయబడిన Cacti/`plugin_pfs` సర్వర్ + రోలప్ + Top-K అంచె) మరియు దాని అకడమిక్ సాహిత్య సందర్భాన్ని డాక్యుమెంట్ చేస్తుంది.
- [docs/PLAN.md](../../PLAN.md) పూర్తి ప్రణాళిక: ఆర్కిటెక్చర్, అది ఆధారపడే అప్‌స్ట్రీమ్ వాస్తవాలు (సోర్స్‌లు మరియు ఇంకా ధృవీకరించని వాటితో), కౌంటర్ సెమాంటిక్స్, స్టేట్ మోడల్, భద్రతా సరిహద్దులు, పనితీరు గణనలు, పరీక్షలు మరియు ప్రమాదాలు.
- [docs/operator-guide.md](../../operator-guide.md) ఇన్‌స్టాల్, గ్రాఫ్‌లు మరియు విలువలు, అప్‌గ్రేడ్, బ్యాకప్ మరియు పునరుద్ధరణ, అన్‌ఇన్‌స్టాల్ మరియు ట్రబుల్‌షూటింగ్‌లను వివరిస్తుంది; [docs/alerting.md](../../alerting.md) Thold మరియు Maint లను వివరిస్తుంది.
- [docs/node-setup.md](../../node-setup.md) రీడర్ కోసం Lustre సర్వర్ మరియు పోలర్‌ను ఎలా సిద్ధం చేయాలో వివరిస్తుంది.
- [docs/decisions/](../../decisions) ఇప్పటికే తీసుకున్న ఎంపికల కోసం ఆర్కిటెక్చర్ నిర్ణయ రికార్డులను కలిగి ఉంటుంది.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) ప్రతి అవసర ID ని దాని ఇష్యూకు మ్యాప్ చేస్తుంది.

```mermaid
flowchart LR
    N["స్టోరేజ్ సర్వర్లు: Lustre OSS/MDS, GPFS NSD, BeeGFS"] -->|స్థిరమైన రీడ్-ఓన్లీ అభ్యర్థన| S["పోలర్-స్కోప్డ్ మరియు షార్డెడ్ రీడర్"]
    S --> B["BatchUpserter + 64-బిట్ KiB క్యాష్"]
    B --> R["RollupPolicy + TopKTracker + ImbalanceAnalyzer"]
    B --> SS["స్క్రిప్ట్ సర్వర్ ఇన్‌పుట్"]
    R --> ECO["CasC CLI, MCP సామర్థ్యాలు, Doctor తనిఖీలు"]
    SS -->|poller_output| T["Thold మరియు Maint"]
    SS --> RRD[("Cacti RRD లు: FS/Pool/సర్వర్ + ఆప్ట్-ఇన్ టార్గెట్‌లు")]
    RRD --> G["Cacti గ్రాఫ్‌లు మరియు అనుమతులు"]
```

## పనిని ఎలా ట్రాక్ చేస్తారు

- **మైలురాళ్ళు** ప్రణాళిక యొక్క మైలురాళ్ళను అనుసరిస్తాయి. ప్రతి ఒక్కటి దాని ఉప-ఇష్యూలు మరియు నిష్క్రమణ ఆధారాలను జాబితా చేసే ఎపిక్ ఇష్యూను కలిగి ఉంటుంది.
- **ఇష్యూలు** లేబుల్ ద్వారా వర్గీకరించబడ్డాయి: `type: requirement`, `type: research`, `type: decision`, `type: epic`. అవసర ఇష్యూలు సంఖ్యలతో కూడిన, పరీక్షించదగిన అవసరాలు (`REQ-AREA-NNN`) మరియు ఆమోద ప్రమాణాలను పేర్కొంటాయి.
- **లేబుళ్లు** `area:`, `phase:`, `priority:`, మరియు `status:` లను కూడా కలిగి ఉంటాయి. [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels) చూడండి.
- [ప్రాజెక్ట్ బోర్డు](https://github.com/users/somethingwithproof/projects/11) మైలురాళ్ళన్నింటిలోని ఇష్యూలను చూపుతుంది.

## సహకారం

[CONTRIBUTING.md](../../../CONTRIBUTING.md) చదవండి. కమిట్‌లకు DCO సైన్-ఆఫ్ అవసరం. డిజైన్ చర్చ ఇష్యూలపై జరుగుతుంది; ఓపెన్ నిర్ణయాలకు `type: decision` లేబుల్ ఉంటుంది.

## భద్రత

లోపాలను ప్రైవేట్‌గా నివేదించండి; [SECURITY.md](../../../SECURITY.md) చూడండి.

## లైసెన్స్

GNU General Public License v2.0, Cacti మరియు దాని ప్లగిన్‌ల మాదిరిగానే అదే లైసెన్స్. [LICENSE](../../../LICENSE) చూడండి.
