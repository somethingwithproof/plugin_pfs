<!-- Source: README.md; SHA256: e88d6875d113220a91cfc0a9d230c829a78477b17e6aa7e44dda38c9591c86ad -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_pfs

Ƙarin plugin na Cacti 1.2 wanda ke kawo yanayin lafiya da nauyin tsarin fayil na gaggawa (parallel filesystem) cikin Cacti: ƙarfin ajiya da rashin daidaiton cika a tsakanin maƙasudai, saurin watsa bayanai na kowane maƙasudi, da ƙimar ayyukan metadata. Lustre ne backend na farko. GPFS (IBM Storage Scale) da BeeGFS suna biye a bayan ƙirar haɗin backend. Bayanan suna sauka cikin na'urorin Cacti na yau da kullum, tushen bayanai, da jadawalai, don haka Thold da Maint suna ba da faɗakarwa da dakatar da su ba tare da wani sauyi ba.

> **Matsayi: mataki na 4 (milestone 4), an kammala lambar mataki na 1, ba a sake shi ba tukuna.** An kammala mataki na 2 (tushe), na 3 (lura da Lustre), da na 4 (faɗakarwa da shirye-shiryen saki) a kan `main` ko suna cikin bita. Plugin ɗin yana shigarwa, sabuntawa, da cirewa ta hanyar API na plugin ɗin Cacti kuma yana ƙin PHP da ke ƙasa da 8.2. Mai karatu na bayan fage a kan kowane poller yana karanta sabobin Lustre ta SSH ta hanyar ƙayyadadden rubutun umarni na tilas ([node/pfs-read.sh](../../../node/pfs-read.sh)) kuma yana ajiye jerin kayayyaki, yanayi, ƙarfi, da na'urorin ƙidaya tare da sabuntarsu a taska. Kowane tsarin fayil yana zama na'urar Cacti tare da tambayoyin bayanai na OST da MDT, jadawalan taƙaitawa guda biyar, jadawalan kowane maƙasudi idan an buƙata, da tushen bayanai na taƙaitawa don Thold. Thold v1.8.2 da Maint v1.3, ba tare da sauyi ba, suna ba da faɗakarwa da dakatar da ma'aunan taƙaitawa a cikin gwajin ƙarshe-zuwa-ƙarshe; ba a goyan bayan ƙayyadaddun iyakokin Thold a kan na'urorin ƙidaya tare da Thold 1.8.2 ba ([docs/alerting.md](../../alerting.md)). Kowanne gwaji ya zuwa yanzu yana gudana ne a kan fitarwar node na gwaji (fixture) a cikin Cacti na wucin-gadi: plugin ɗin bai yi aiki a kan ainihin node na Lustre ba. Abubuwan da ke saura kafin a sake shi: gwaje-gwajen farko a kan ainihin tsarin fayiloli ([shirin gwaji](../../pilot-plan.md)), bitar tsaro ta waje ([bitar kai](../../security-review.md)), da ma'aunan girma a kan kayan masarufi da aka rubuta ([ma'aunin girma](../../research/scale.md)). An shirya sigar 0.1.0 a cikin `INFO` da [CHANGELOG.md](../../../CHANGELOG.md) amma ba a sa mata alama (tag) ba.

## Matsayin shiga

| Fage | Ikon Shiga |
| --- | --- |
| Parallel Filesystem View | Shafukan sa ido da ke akwai. |
| Parallel Filesystem Operator | Sa ido tare da shafin Ayyuka (Operations) da amincewar rarrabawa ta sirri. |
| Parallel Filesystem Admin | Ikon shiga na Operator tare da saitin da ke akwai da ayyukan gudanarwa. |

Bayan kwafe fayilolin sabuntawa, buɗe shafin Plugins a matsayin mai gudanarwa (administrator) don yin rijistar sabon fagen shiga.
Sanya fagen Operator kai tsaye a ƙarƙashin **Configuration > Users** ko ƙungiyoyin masu amfani.
Sabuntawa suna riƙe IDs da izinin View da Admin da ke akwai. Ikon shiga na Admin
yana gadon ikon Operator, kuma ikon Operator yana gadon ikon View a kan kowane buƙata;
wannan ba ya ƙara izinin da aka ajiye ga masu amfani ko ƙungiyoyi. Saitunan plugin kuma suna buƙatar
ikon shiga na Parallel Filesystem Admin baya ga izinin Settings na Cacti.

Izinin na'urorin Cacti har yanzu suna iyakance layuka da ayyukan da ke akwai ga kowane mai amfani; abubuwan da ba a taswira ba suna buƙatar mai gudanarwa.
Takardun shaida, saiti, taswira, gogewa da sauran canje-canjen gudanarwa
suna ci gaba da kasancewa a cikin shafukan gudanarwa. Amincewa (acknowledgments) tana aiki ne kawai a cikin zaman
mai amfani na yanzu, na tsawon awanni ɗaya a mafi yawa kuma kawai yayin da wannan lura ta kasance
sabuwa. Sabbin lura ko waɗanda suka canza suna soke su. Ba sa taɓa nuna ma'aunin da ya gaza
a matsayin mai lafiya ko dakatar da faɗakarwa.

## Dalili

Lokacin da tsarin fayil na haɗin gwiwa ya yi jinkiri, shaidun suna bazu a cikin sabobi. Lustre yana adana na'urorin ƙidayarsa a kan kowane node: `lctl get_param` yana ba da rahoton ƙimomi "a kan node na gida", don haka kowane object storage server (OSS) da metadata server (MDS) yana riƙe da lambobin maƙasudansa kawai. Mai gudanarwa da ke binciken jinkirin tsarin fayil yana buƙatar amsoshi uku daga wurare daban-daban:

- **Shin maƙasudi ɗaya yana cika yayin da sauran suke fanko?** Rashin daidaiton cika yana canza inda sabbin fayiloli ke sauka da kuma lokacin da maƙasudi ɗaya zai cika.
- **Waɗanne maƙasudai ko ayyuka ne suke da cunkoso?** Na'urorin ƙidayar byte na kowane maƙasudi da na'urorin ƙidayar ayyuka na kowane MDT suna nuna ko nauyin yana da alaƙa da bayanai ne ko da metadata.
- **Shin sa idon da kansa na yanzu ne?** Jadawali mai lebur yana da kwanciyar hankali ne kawai idan plugin ɗin zai iya ganin sabobin.

Cacti yana zana jadawalin na'urori kuma yana sanya iyakokin ƙimomi da Thold, kuma ƙungiyar Cacti a GitHub ba ta da wani ma'ajiyar lamba don sa ido kan Lustre, GPFS, ko BeeGFS (an bincika 2026-11-14). Plugin ɗin yana cike wannan giɓi ta hanyar karanta ƙimomi masu izini na karatu-kawai daga nodes na ajiya da wakiltar kowane tsarin fayil a matsayin na'urar Cacti wadda maƙasudanta su ne fihirisar tambayar bayanai (data query). Yana sake amfani da zana jadawali na Cacti, izini, masu tattara bayanai na nesa, Thold, da Maint maimakon gina irinsu na daban.

## Tsarin aiki da aka shirya

| Mataki | Fage | Matakan Aiki |
|---|---|---|
| 0, bincike | Jadawalin saki, shawarar shiga node, tabbatar da halayen upstream, halayen ƙidaya da failover, ma'aunan girma | [M1](https://github.com/somethingwithproof/plugin_pfs/milestone/1) |
| 1, lura | Jerin kayayyakin Lustre, ƙarfi da cika, saurin watsawar OST, ƙimar ayyukan MDT, jadawalai, faɗakarwar Thold, dakatarwar Maint, shafin lafiya | [M2](https://github.com/somethingwithproof/plugin_pfs/milestone/2), [M3](https://github.com/somethingwithproof/plugin_pfs/milestone/3), [M4](https://github.com/somethingwithproof/plugin_pfs/milestone/4) |
| 2, zurfi | Masu tattara bayanai na nesa na Cacti, ra'ayoyin matakin aiki (job-level) da matakin abokin ciniki (client-level) cikin ƙayyadadden adadin abubuwa | [M5](https://github.com/somethingwithproof/plugin_pfs/milestone/5) |
| 3, backends | Backends na GPFS (IBM Storage Scale) da BeeGFS | [M6](https://github.com/somethingwithproof/plugin_pfs/milestone/6) |

Abin da ke wajen tsari: kowane aiki na rubutu, sharewa, sake saitawa, ko daidaitawa a kan node ko tsarin fayil; injin faɗakarwa mallakar plugin; daemon na musamman ko ƙa'idar hanyar sadarwa; tushen bayanai na kowane aiki (per-job); sauye-sauye ga ainihin Cacti, Thold, ko Maint.

## Maƙasudan jituwa

| Bangare | Maƙasudi |
|---|---|
| Cacti | 1.2.31 ko na baya, babu facin ainihin tsari |
| PHP | 8.2 ko na baya; `setup.php` yana kasancewa mai karantuwa a kan 8.0 don haka yanayin aiki da ba a goyan baya ya sami ƙin yarda maimakon ɓarnatar da ƙirar Cacti |
| Lustre | Layukan saki da za a gyara ta mataki na 1; lamba da litattafan jagora da aka duba ya zuwa yanzu suna a 2.17.0 |
| GPFS, BeeGFS | Mataki na 3; layukan saki da za a gyara ta bincike |
| Thold, Maint | Babu sauye-sauyen da ake buƙata |

## Tsari

- [docs/PLAN.md](../../PLAN.md) shi ne cikakken shiri: tsarin gini, bayanan upstream da ya dogara a kansu (tare da tushe da abin da har yanzu ba a tabbatar ba), ma'anar na'urorin ƙidaya, samfurin yanayi, iyakokin tsaro, lissafin ingancin aiki, gwaji, da haɗari.
- [docs/operator-guide.md](../../operator-guide.md) yana ɗauke da shigarwa, jadawalai da ƙimomi, sabuntawa, ajiyar baya da maidowa, cirewa, da magance matsaloli; [docs/alerting.md](../../alerting.md) yana ɗauke da Thold da Maint.
- [docs/node-setup.md](../../node-setup.md) yana bayyana yadda za a shirya sabar Lustre da poller don mai karatu.
- [docs/decisions/](../../decisions) yana riƙe da bayanan shawarar tsarin gini don zaɓuɓɓukan da aka riga aka yanke.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) yana taswira kowane ID na buƙata zuwa batunsa.

```mermaid
flowchart LR
    N[Sabobin Lustre: OSS, MDS] -->|ƙayyadadden buƙatar karatu-kawai| S[Tushen node a kan mai tattara bayanai na Cacti]
    S --> R[Mai karatu]
    R --> C[(Taskar lura)]
    C --> SS[Shigarwar uwar garken rubutu]
    SS -->|poller_output| T[Thold da Maint]
    SS --> RRD[(Cacti RRDs)]
    RRD --> G[Jadawali da izinin Cacti]
```

## Yadda ake bin diddigin aiki

- **Matakan aiki (Milestones)** suna bin matakan shirin. Kowanne yana da babban batu (epic issue) da ke jero ƙananan batutuwa da shaidar fita.
- **Batutuwa (Issues)** an rarraba su ta alama (label): `type: requirement`, `type: research`, `type: decision`, `type: epic`. Batutuwan buƙatu suna bayyana buƙatu masu lamba da za a iya gwadawa (`REQ-AREA-NNN`) da ma'aunin karɓuwa.
- **Alamomi (Labels)** suna kuma ɗauke da `area:`, `phase:`, `priority:`, da `status:`. Duba [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Allon aiki](https://github.com/users/somethingwithproof/projects/11) yana nuna dukkan batutuwa a tsakanin matakan aiki.

## Gudummawa

Karanta [CONTRIBUTING.md](../../../CONTRIBUTING.md). Commits suna buƙatar sa hannun DCO (DCO sign-off). Tattaunawar tsari tana faruwa a kan batutuwa; buɗaɗɗun shawarwari suna ɗauke da alamar `type: decision`.

## Tsaro

Ka ba da rahoton raunin tsaro a asirce; duba [SECURITY.md](../../../SECURITY.md).

## Lasisi

GNU General Public License v2.0, lasisi guda da Cacti da plugins ɗinsa. Duba [LICENSE](../../../LICENSE).
