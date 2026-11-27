<!-- Source: README.md; SHA256: e88d6875d113220a91cfc0a9d230c829a78477b17e6aa7e44dda38c9591c86ad -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_pfs

Ngwa mgbakwunye Cacti 1.2 nke na-eweta ahụike na ibu ọrụ nke sistemụ faịlụ yiri onwe ha (parallel filesystem) n'ime Cacti: ikike nchekwa na enweghị nhata nke ndọju n'ofe ebe nchekwa (targets), ngafe data maka ebe nchekwa nke ọ bụla, na ọnụọgụgụ ọrụ metadata. Lustre bụ backend mbụ. GPFS (IBM Storage Scale) na BeeGFS na-eso n'azụ njikọ backend. Data ahụ na-abanye na ngwaọrụ Cacti nkịtị, isi mmalite data (data sources), na eserese (graphs), ka Thold na Maint wee na-ezipụ ọkwa ma na-egbochi ya n'agbanweghị ihe ọ bụla.

> **Ọnọdụ: akụkụ ọrụ (milestone) 4, koodu ezuola maka ngalaba 1, ewepụtabeghị ya.** Akụkụ ọrụ 2 (ntọala), 3 (nlele Lustre), na 4 (ịkụ ọkwa na nkwadebe mwepụta) emechala na `main` ma ọ bụ nọrọ na nnyocha. Ngwa mgbakwunye a na-etinye, na-ebuli elu, ma na-ewepụ onwe ya site na API ngwa mgbakwunye nke Cacti ma jụ PHP nọ n'okpuru 8.2. Onye na-agụ ihe n'azụ (background reader) na poller nke ọ bụla na-agụ sava Lustre site na SSH site na skripụ iwu a manyere nke a kapịrị ọnụ ([node/pfs-read.sh](../../../node/pfs-read.sh)) ma na-echekwa ndepụta ngwa, ọnọdụ, ikike nchekwa, na ndị na-agụ ọnụ (counters) tinyere ịdị ọhụrụ ha. Sistemụ faịlụ nke ọ bụla na-aghọ ngwaọrụ Cacti nwere ajụjụ data OST na MDT, eserese nchịkọta ise, eserese maka ebe nchekwa nke ọ bụla mgbe a chọrọ, na isi mmalite data nchịkọta maka Thold. Thold v1.8.2 na Maint v1.3, n'agbanweghị ha, na-akụ ọkwa ma na-egbochi ihe nrịbama nchịkọta na nnwale zuru oke; akwadoghị oke Thold na ndị na-agụ ọnụ na Thold 1.8.2 ([docs/alerting.md](../../alerting.md)). Nnwale niile ruo ugbu a na-agba na mpụta nyocha (fixture node output) n'ime Cacti a na-atụfu atụfu: ngwa mgbakwunye a agbabeghị na nọdụ Lustre n'ezie. Ihe ka ghere oghe tupu mwepụta: nnwale mbụ na sistemụ faịlụ n'ezie ([atụmatụ nnwale mbụ](../../pilot-plan.md)), nnyocha nchekwa sitere n'èzí ([nnyocha onwe](../../security-review.md)), na nha oke na ngwaike e dekọrọ ([nha oke](../../research/scale.md)). A kwadebere ụdị 0.1.0 na `INFO` na [CHANGELOG.md](../../../CHANGELOG.md) mana etinyebeghị ya akara (tag).

## Ọrụ nnweta

| Mpaghara ikike | Nnweta |
| --- | --- |
| Parallel Filesystem View | Ibe nlekọta dị adị. |
| Parallel Filesystem Operator | Nlekọta tinyere ibe Ọrụ (Operations) na nkwenye nyocha nke onwe. |
| Parallel Filesystem Admin | Nnweta Operator tinyere nhazi dị adị na omume nchịkwa. |

Mgbe i mepụtara oyiri faịlụ nkwalite, mepee ibe Plugins dị ka onye nchịkwa iji debanye aha mpaghara ikike ọhụrụ ahụ. Nye mpaghara ikike Operator n'ụzọ doro anya n'okpuru **Configuration > Users** ma ọ bụ otu ndị ọrụ. Nkwalite na-ejigide ID mpaghara ikike View na Admin dị adị na ikike e nyere ha. Nnweta Admin na-eketa nnweta Operator, na nnweta Operator na-eketa nnweta View na arịrịọ nke ọ bụla; nke a anaghị agbakwunye ikike e chekwara nye ndị ọrụ ma ọ bụ otu. Ntọala ngwa mgbakwunye chọkwara nnweta Parallel Filesystem Admin na mgbakwunye na ikike Settings nke Cacti.

Ikike ngwaọrụ Cacti ka na-egbochi ahịrị na omume dịịrị onye ọrụ nke ọ bụla; ihe ndị na-enweghị njikọ (unmapped objects) chọrọ onye nchịkwa. Asambodo nzuzo, nhazi, njikọ, nhichapụ na mgbanwe nchịkwa ndị ọzọ ka nọ na ibe nchịkwa. Nkwenye (Acknowledgments) na-adịgide naanị n'oge nnọkọ onye ọrụ ugbu a, maka otu awa kachasị na naanị mgbe otu nlele ahụ ka dị ọhụrụ. Nlele ọhụrụ ma ọ bụ nke gbanwere agbanwe na-emebi ikike ha. Ha anaghị akara nha dara ada dị ka nke dị mma ma ọ bụ gbochie ọkwa.

## Ihe kpatara ya

Mgbe sistemụ faịlụ a na-ekerịta na-adị nwayọọ, ihe akaebe na-agbasa n'ofe sava dị iche iche. Lustre na-echekwa ndị na-agụ ọnụ ya maka nọdụ nke ọ bụla: `lctl get_param` na-akọ ọnụọgụgụ "na nọdụ mpaghara", ya mere sava nchekwa ihe nke ọ bụla (OSS) na sava metadata nke ọ bụla (MDS) na-ejide naanị ọnụọgụgụ nke ebe nchekwa nke ya. Onye ọrụ na-enyocha sistemụ faịlụ na-adị nwayọọ chọrọ azịza atọ sitere n'ebe dị iche iche:

- **Otu ebe nchekwa ọ na-eju ebe ndị ọzọ tọgbọrọ chakoo?** Enweghị nhata nke ndọju na-agbanwe ebe faịlụ ọhụrụ na-ada na mgbe otu ebe nchekwa ga-agwụcha ohere.
- **Ebe nchekwa ma ọ bụ ọrụ ndị dị aṅaa na-arụsi ọrụ ike?** Ndị na-agụ ọnụ byte maka ebe nchekwa nke ọ bụla na ndị na-agụ ọnụ ọrụ maka MDT nke ọ bụla na-egosi ma ibu ọrụ ọ bụ nke data ma ọ bụ nke metadata.
- **Nlekọta ahụ n'onwe ya ọ bụ nke ugbu a?** Eserese dị larịị na-enye nkasi obi naanị ma ọ bụrụ na ngwa mgbakwunye ahụ nwere ike ịhụ sava ndị ahụ.

Cacti na-ese eserese ndị ọbịa (hosts) ma na-edozi oke ọnụọgụgụ na Thold, na nzukọ GitHub nke Cacti enweghị ebe nchekwa koodu maka nlekọta Lustre, GPFS, ma ọ bụ BeeGFS (a chọrọ na 2026-11-14). Ngwa mgbakwunye a na-emechi oghere ahụ site n'ịgụ ọnụọgụgụ a kwadoro maka ọgụgụ naanị site na nọdụ nchekwa ma na-anọchi anya sistemụ faịlụ nke ọ bụla dị ka ngwaọrụ Cacti nke ebe nchekwa ya bụ ndị ntụaka nke ajụjụ data. Ọ na-eji usoro eserese, ikike, ndị na-anakọta data dịpụrụ adịpụ, Thold, na Maint nke Cacti eme ihe kama iwulite ụdị yiri ha dị iche.

## Oke ọrụ a tụrụ anya

| Ngalaba | Oke ọrụ | Akụkụ ọrụ (Milestones) |
|---|---|---|
| 0, nnyocha | Tebụl mwepụta, mkpebi nnweta nọdụ, nkwenye omume upstream, omume ndị na-agụ ọnụ na failover, nha oke | [M1](https://github.com/somethingwithproof/plugin_pfs/milestone/1) |
| 1, nlele | Ndepụta ngwa Lustre, ikike nchekwa na ndọju, ngafe data OST, ọnụọgụgụ ọrụ MDT, eserese, ịkụ ọkwa Thold, mgbochi Maint, ibe ahụike | [M2](https://github.com/somethingwithproof/plugin_pfs/milestone/2), [M3](https://github.com/somethingwithproof/plugin_pfs/milestone/3), [M4](https://github.com/somethingwithproof/plugin_pfs/milestone/4) |
| 2, omimi | Ndị na-anakọta data dịpụrụ adịpụ nke Cacti, nlele ọkwa ọrụ (job) na ọkwa onye ahịa (client) n'ime ọnụọgụgụ ihe nwere oke | [M5](https://github.com/somethingwithproof/plugin_pfs/milestone/5) |
| 3, backends | Backend nke GPFS (IBM Storage Scale) na BeeGFS | [M6](https://github.com/somethingwithproof/plugin_pfs/milestone/6) |

Ihe na-abụghị n'oke ọrụ: ọrụ ide ihe, ihichapụ, ịtọgharị, ma ọ bụ idozi ihe ọ bụla na nọdụ ma ọ bụ sistemụ faịlụ; injin ịkụ ọkwa nke ngwa mgbakwunye nwe; daemon pụrụ iche ma ọ bụ usoro netwọkụ; isi mmalite data maka ọrụ nke ọ bụla; mgbanwe na isi Cacti, Thold, ma ọ bụ Maint.

## Ebe ndakọrịta ezubere

| Akụrụngwa | Ebumnuche |
|---|---|
| Cacti | 1.2.31 ma ọ bụ karịa, enweghị mgbanwe isi koodu (core patches) |
| PHP | 8.2 ma ọ bụ karịa; `setup.php` na-anọgide na-enwe ike ịgụ na 8.0 ka usoro runtime na-akwadoghị wee nata ajụ kama imebi ihu njikọ Cacti |
| Lustre | Usoro mwepụta ga-abụ nke akụkụ ọrụ 1 ga-ekpebi; koodu isi mmalite na akwụkwọ ntuziaka e nyochara ruo ugbu a nọ na 2.17.0 |
| GPFS, BeeGFS | Ngalaba 3; mwepụta ga-abụ nke nnyocha ga-ekpebi |
| Thold, Maint | Enweghị mgbanwe achọrọ |

## Nhazi usoro

- [docs/PLAN.md](../../PLAN.md) bụ atụmatụ zuru oke: nhazi usoro, eziokwu upstream ọ dabere na ya (ya na isi mmalite na ihe ndị a na-enyochabeghị), nkọwa ndị na-agụ ọnụ, ụdị ọnọdụ, oke nchekwa, mgbakọ na mwepụ arụmọrụ, nnwale, na ihe ize ndụ.
- [docs/operator-guide.md](../../operator-guide.md) na-ekpuchi ntinye, eserese na ọnụọgụgụ, nkwalite, ndabere (backup) na iweghachi, mwepụ, na idozi nsogbu; [docs/alerting.md](../../alerting.md) na-ekpuchi Thold na Maint.
- [docs/node-setup.md](../../node-setup.md) na-akọwa otu esi akwado sava Lustre na poller maka onye na-agụ ihe.
- [docs/decisions/](../../decisions) nwere ndekọ mkpebi nhazi usoro maka nhọrọ ndị e merela.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) na-ejikọ ID ihe achọrọ nke ọ bụla na okwu (issue) ya.

```mermaid
flowchart LR
    N["Sava Lustre: OSS, MDS"] -->|arịrịọ ọgụgụ naanị nke a kapịrị ọnụ| S["Isi mmalite nọdụ na Cacti poller"]
    S --> R["Onye na-agụ ihe"]
    R --> C[("Ebe nchekwa nlele")]
    C --> SS["Ntinye sava skripụ"]
    SS -->|poller_output| T["Thold na Maint"]
    SS --> RRD[("Cacti RRDs")]
    RRD --> G["Eserese na ikike Cacti"]
```

## Otu esi eso ọrụ

- **Akụkụ ọrụ (Milestones)** na-eso akụkụ ọrụ nke atụmatụ ahụ. Nke ọ bụla nwere okwu epic nke depụtara okwu nta ya na ihe akaebe mmecha.
- **Okwu (Issues)** ka e ji akara hazie: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Okwu ihe achọrọ na-ekwu ihe achọrọ nwere nọmba nke a pụrụ ịnwale (`REQ-AREA-NNN`) na ụkpụrụ nnabata.
- **Akara (Labels)** na-ebukwa `area:`, `phase:`, `priority:`, na `status:`. Hụ [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Bọọdụ ọrụ](https://github.com/users/somethingwithproof/projects/11) na-egosi okwu niile gafee akụkụ ọrụ dị iche iche.

## Ntinye aka

Gụọ [CONTRIBUTING.md](../../../CONTRIBUTING.md). Ntinye koodu (commits) chọrọ nkwenye mbinye aka DCO. Mkparịta ụka nhazi usoro na-eme na okwu (issues); mkpebi ghere oghe na-ebu akara `type: decision`.

## Nchekwa

Kọọ adịghị ike nchekwa na nzuzo; hụ [SECURITY.md](../../../SECURITY.md).

## Ikikere

GNU General Public License v2.0, otu ikikere ahụ dị ka Cacti na ngwa mgbakwunye ya. Hụ [LICENSE](../../../LICENSE).
