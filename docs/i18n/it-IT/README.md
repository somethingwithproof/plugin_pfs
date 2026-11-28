<!-- Source: README.md; SHA256: e88d6875d113220a91cfc0a9d230c829a78477b17e6aa7e44dda38c9591c86ad -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [Български](../bg-BG/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [Ελληνικά](../el-GR/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](README.md) | [日本語](../ja-JP/README.md) | [ქართული](../ka-GE/README.md) | [한국어](../ko-KR/README.md) | [Latviešu](../lv-LV/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Português (Portugal)](../pt-PT/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_pfs

Un plugin per Cacti 1.2 che porta in Cacti lo stato di salute dei filesystem paralleli, la capacità, lo squilibrio di riempimento, il throughput per singolo target e la frequenza delle operazioni sui metadati su Lustre, IBM Storage Scale (GPFS) e BeeGFS dietro un'interfaccia `Backend` unificata. Ciascun filesystem corrisponde a un normale dispositivo Cacti con query dati, grafici panoramici e per singolo target su richiesta e indicatori riepilogativi, così Thold e Maint generano avvisi e li sopprimono senza alcuna modifica.

> **Stato: fasi dalla 1 alla 3 e primitive di scalabilità exascale implementate nel codice, non ancora rilasciato.** Fondamenta, osservazione di Lustre, alerting e soppressione Maint, driver di sola lettura per GPFS e BeeGFS, analisi dello squilibrio OST/MDT con integrazione Maint, definizione dell'ambito dei collettori di dati remoti (`replicate_out`, `remote_agent`), primitive di scalabilità a 100+ PB / exascale (`FramedReportParser`, Schema v4 `value_kib`, `RollupPolicy`, `TopKTracker`, `ShardAssigner`, `BatchUpserter`), CLI operatore CasC (`cli/pfs.php`) e hook di ecosistema (`abilities_register`, `doctor_register_checks`, `casc_register_providers`) sono implementati e verificati con test unitari, di sicurezza e di integrazione su Cacti usa e getta. Tutte le esecuzioni finora utilizzano output simulati di nodi anziché hardware reale Lustre, GPFS o BeeGFS. Restano aperti prima di taggare una release: i progetti pilota su filesystem reali ([piano pilota](../../pilot-plan.md)), una revisione di sicurezza esterna ([autovalutazione](../../security-review.md)) e le misurazioni di scalabilità su hardware documentato ([scalabilità](../../research/scale.md)). La versione 0.1.0 è predisposta in `INFO` e [CHANGELOG.md](../../../CHANGELOG.md), ma non ancora taggata.

## Ruoli di accesso

| Ambito | Accesso |
| --- | --- |
| Parallel Filesystem View | Pagine di monitoraggio esistenti. |
| Parallel Filesystem Operator | Monitoraggio più la pagina Operations e le prese in carico personali per il triage. |
| Parallel Filesystem Admin | Accesso Operator più le pagine di configurazione esistenti e le azioni amministrative. |

Dopo aver copiato i file di aggiornamento, aprire la pagina Plugins come amministratore per registrare
il nuovo realm. Assegnare esplicitamente il realm Operator in **Configuration > Users** o nei gruppi di utenti.
Gli aggiornamenti mantengono gli ID e le autorizzazioni esistenti dei realm View e Admin. L'accesso Admin
eredita l'accesso Operator e l'accesso Operator eredita l'accesso View a ogni richiesta;
ciò non aggiunge autorizzazioni salvate a utenti o gruppi. Le impostazioni del plugin richiedono inoltre
l'accesso Parallel Filesystem Admin in aggiunta al permesso Settings di Cacti.

I permessi sui dispositivi di Cacti continuano a limitare le righe e le azioni disponibili per ciascun utente; gli oggetti non mappati richiedono un amministratore.
Credenziali, configurazione, mappatura, eliminazione e altre modifiche amministrative
restano nelle pagine di amministrazione. Le prese in carico (acknowledgment) durano solo nella sessione corrente
dell'utente, per al massimo un'ora e solo finché la stessa osservazione rimane
aggiornata. Osservazioni nuove o modificate le invalidano. Non contrassegnano mai come sana una misurazione
fallita né sopprimono gli avvisi.

## Motivazioni

Quando un filesystem condiviso è lento, le evidenze sono distribuite su più server. Lustre mantiene i propri contatori per singolo nodo: `lctl get_param` riporta i valori "sul nodo locale", quindi ciascun object storage server (OSS) e metadata server (MDS) conserva soltanto i dati dei propri target. Un operatore che indaga su un filesystem lento ha bisogno di tre risposte provenienti da punti diversi:

- **Un target si sta riempiendo mentre gli altri sono vuoti?** Lo squilibrio di riempimento influisce su dove vengono scritti i nuovi file e su quando un singolo target esaurisce lo spazio.
- **Quali target o operazioni sono più carichi?** I contatori di byte per singolo target e i contatori di operazioni per singolo MDT mostrano se il carico è dovuto ai dati o ai metadati.
- **Il monitoraggio stesso è aggiornato?** Un grafico piatto è rassicurante solo se il plugin è riuscito a contattare i server.

Cacti genera grafici per gli host e applica soglie ai valori con Thold, e l'organizzazione GitHub di Cacti non dispone di alcun repository per il monitoraggio di Lustre, GPFS o BeeGFS (verifica del 2026-11-14). Il plugin colma questa lacuna leggendo valori in sola lettura inclusi in una lista consentita dai nodi di storage e rappresentando ciascun filesystem come un dispositivo Cacti i cui target sono gli indici di una query dati. Riutilizza la generazione dei grafici, i permessi, i collettori di dati remoti, Thold e Maint di Cacti anziché costruirne versioni parallele.

## Ambito pianificato

| Fase | Ambito | Stato | Milestone |
|---|---|---|---|
| 0, ricerca | Matrice di rilascio, decisione sull'accesso ai nodi, conferma del comportamento upstream, comportamento di contatori e failover, misurazioni di scalabilità | Completato | [M1](https://github.com/somethingwithproof/plugin_pfs/milestone/1) |
| 1, osservazione | Inventario Lustre, capacità e riempimento, squilibrio OST/MDT (`lib/ImbalanceAnalyzer.php`), integrazione Maint (`lib/HealthPage.php`), throughput OST, frequenza delle operazioni MDT, grafici, alerting con Thold, pagina sullo stato di salute | Implementato (testato con fixture) | [M2](https://github.com/somethingwithproof/plugin_pfs/milestone/2), [M3](https://github.com/somethingwithproof/plugin_pfs/milestone/3), [M4](https://github.com/somethingwithproof/plugin_pfs/milestone/4) |
| 2, raccolta remota e scala | Raccolta remota delimitata per poller (`lib/Reader.php`, `lib/ReplicationRepository.php`, `replicate_out`, `remote_agent`), primitive di scalabilità a 100+ PB (igiene KiB a 64 bit + Schema v4 `value_kib`, `lib/RollupPolicy.php`, `lib/TopKTracker.php`, `lib/ShardAssigner.php` + `poller_pfs.php --shard=K/N`, `lib/BatchUpserter.php`), CLI CasC (`cli/pfs.php`), hook MCP/Doctor/CasC | Implementato (testato con test unitari e di integrazione CI) | [M5](https://github.com/somethingwithproof/plugin_pfs/milestone/5) |
| 3, backend | Driver di sola lettura IBM Storage Scale (GPFS) (`lib/GpfsBackend.php`) e BeeGFS (`lib/BeegfsBackend.php`) registrati in `BackendRegistry::production()` | Implementato (testato con test unitari) | [M6](https://github.com/somethingwithproof/plugin_pfs/milestone/6) |

Principali primitive per operatori e scalabilità implementate:

- **Driver di sola lettura multi-filesystem:** `LustreBackend`, `GpfsBackend` (solo `mmpmon` in formato framed `fs_io_s`/`io_s` con `reset` vietato, capacità e stati dei pool `mmdf` e riepiloghi dei waiter) e `BeegfsBackend` (target di storage e metadati BeeGFS 7.4 e 8.x con conversione esatta da byte a KiB, mappatura delle condizioni di raggiungibilità e coerenza e semantica degli indicatori per intervallo) che condividono `lib/FramedReportParser.php`.
- **Rilevamento dello squilibrio, margine disponibile e supporto a Maint:** `lib/ImbalanceAnalyzer.php` calcola rapporti di riempimento OST/MDT, scarto, indice di squilibrio tramite deviazione standard della popolazione, margine disponibile, verifica di aggiornamento a 1 intervallo (`missing_targets`), flag di stato per singolo target e Top-K limitato dei target sbilanciati. `HealthPage` (`pfs_health.php`) e `CascProvider::health()` espongono le finestre di manutenzione `Maint` di Cacti e sopprimono gli avvisi di errore attivi durante la manutenzione pianificata.
- **Scalabilità a 100+ PB ed exascale** ([docs/architecture/exascale-scaling.md](../../architecture/exascale-scaling.md)): conversione decimale esatta da byte a KiB (`FramedReportParser::bytesToKibInt()`) verificata a 500 PB e 2 EB insieme a colonne `BIGINT UNSIGNED value_kib` (Schema v4); rollup RRD gerarchici `Filesystem -> Pool -> Storage Server` con promozione limitata dei target opt-in/caldi/sbilanciati (`lib/RollupPolicy.php`); Top-K limitato $O(K)$ dei target sbilanciati e riepiloghi pre-aggregati di job e client pesanti (`lib/TopKTracker.php`, senza mai creare dispositivi o RRD per singolo client); sharding deterministico CRC32 dei collettori (`lib/ShardAssigner.php`, `php plugins/pfs/poller_pfs.php [--poller=N] [--shard=K/N] [--force] [--debug]`); e upsert SQL in batch da 500 righe con paginazione a keyset (`lib/BatchUpserter.php`, `CacheRepository::upsertObservationsBatch()`, `TargetRepository::forFilesystemPage()`).
- **CLI operatore CasC e hook di ecosistema:** `cli/pfs.php` supporta `export [--format=yaml|xml]`, `validate <file> [--format=yaml|xml]`, `plan <file> [--format=yaml|xml] [--output=text|json]`, `apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]` e `health [--format=text|json]` senza alcuna dipendenza esterna da Composer. `setup.php` e `lib/EcosystemIntegration.php` registrano `abilities_register` (`pfs-list-filesystems`, `pfs-get-imbalance`, `pfs-get-health`; in sola lettura, vincolato a `Parallel Filesystem View` e ai permessi sui dispositivi Cacti), `doctor_register_checks` (`pfs_schema`, `pfs_reader_freshness`, `pfs_capacity_imbalance`), `casc_register_providers`, `replicate_out` e `remote_agent`.

Fuori ambito: qualsiasi operazione di scrittura, azzeramento, ripristino o ottimizzazione su un nodo o filesystem; un motore di alerting proprio del plugin; un demone o protocollo di rete personalizzato; dispositivi o RRD Cacti per singolo job o per singolo client; modifiche al core di Cacti, a Thold o a Maint.

## Obiettivi di compatibilità

| Componente | Obiettivo |
|---|---|
| Cacti | 1.2.31 o successive, senza patch al core |
| PHP | 8.2 o successive; `setup.php`, `poller_pfs.php` e `cli/pfs.php` rimangono analizzabili su 8.0 in modo che un runtime non supportato riceva un rifiuto anziché compromettere l'interfaccia utente di Cacti |
| Lustre | Sorgenti e manuali verificati alla versione 2.17.0; testato con fixture |
| GPFS, BeeGFS | Driver di sola lettura IBM Storage Scale (GPFS) 5.x `mmpmon`/`mmdf` e BeeGFS 7.4 / 8.x implementati e testati con fixture |
| Thold, Maint | Thold 1.8.2 e Maint 1.3, invariati |

## Progettazione

- [docs/architecture/exascale-scaling.md](../../architecture/exascale-scaling.md) documenta l'architettura di telemetria a due livelli per 100+ PB / exascale (livello server + rollup + Top-K di Cacti/`plugin_pfs` abbinato a telemetria client fuori banda LDMS/Darshan/TOKIO) e il relativo contesto nella letteratura accademica.
- [docs/PLAN.md](../../PLAN.md) contiene il piano completo: architettura, fatti upstream da cui dipende (con fonti e aspetti ancora non verificati), semantica dei contatori, modello di stato, confini di sicurezza, analisi delle prestazioni, test e rischi.
- [docs/operator-guide.md](../../operator-guide.md) tratta l'installazione, i grafici e i valori, l'aggiornamento, il backup e il ripristino, la disinstallazione e la risoluzione dei problemi; [docs/alerting.md](../../alerting.md) tratta Thold e Maint.
- [docs/node-setup.md](../../node-setup.md) descrive come preparare un server Lustre e un poller per il lettore.
- [docs/decisions/](../../decisions) raccoglie gli architecture decision record delle scelte già effettuate.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) associa ogni ID di requisito alla relativa issue.

```mermaid
flowchart LR
    N[Server di storage: Lustre OSS/MDS, GPFS NSD, BeeGFS] -->|richiesta fissa in sola lettura| S[Lettore delimitato per poller e con sharding]
    S --> B[BatchUpserter + cache KiB a 64 bit]
    B --> R[RollupPolicy + TopKTracker + ImbalanceAnalyzer]
    B --> SS[Input dello script server]
    R --> ECO[CLI CasC, capacità MCP, controlli Doctor]
    SS -->|poller_output| T[Thold e Maint]
    SS --> RRD[(RRD di Cacti: FS/Pool/Server + target opt-in)]
    RRD --> G[Grafici e permessi di Cacti]
```

## Monitoraggio delle attività

- **Milestone**: seguono le milestone del piano. Ciascuna dispone di una issue epic che elenca le sotto-issue e le evidenze di chiusura.
- **Issue**: sono classificate tramite etichette: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Le issue di tipo requirement definiscono requisiti numerati e verificabili (`REQ-AREA-NNN`) e criteri di accettazione.
- **Etichette**: includono inoltre `area:`, `phase:`, `priority:` e `status:`. Vedere [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- La [bacheca di progetto](https://github.com/users/somethingwithproof/projects/11) mostra tutte le issue suddivise per milestone.

## Contributi

Leggere [CONTRIBUTING.md](../../../CONTRIBUTING.md). I commit richiedono una firma DCO (sign-off). Le discussioni di progettazione si svolgono nelle issue; le decisioni aperte riportano l'etichetta `type: decision`.

## Sicurezza

Segnalare le vulnerabilità in privato; vedere [SECURITY.md](../../../SECURITY.md).

## Licenza

GNU General Public License v2.0, la stessa licenza di Cacti e dei suoi plugin. Vedere [LICENSE](../../../LICENSE).
