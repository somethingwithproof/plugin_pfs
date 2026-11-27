<!-- Source: README.md; SHA256: e88d6875d113220a91cfc0a9d230c829a78477b17e6aa7e44dda38c9591c86ad -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_pfs

Ein Cacti-1.2-Plugin, das Zustand und Auslastung paralleler Dateisysteme in Cacti integriert: Kapazität und ungleichmäßige Auslastung (Fill Imbalance) über Targets hinweg, Durchsatz pro Target sowie Metadaten-Operationsraten. Lustre ist das erste Backend. GPFS (IBM Storage Scale) und BeeGFS folgen hinter einer Backend-Schnittstelle. Die Daten landen in regulären Cacti-Geräten, Datenquellen und Graphen, sodass Thold und Maint unverändert darauf basierend alarmieren und Alarme unterdrücken können.

> **Status: Meilenstein 4, Code für Phase 1 vollständig, noch nicht veröffentlicht.** Die Meilensteine 2 (Grundlagen), 3 (Lustre-Beobachtung) und 4 (Alarmierung und Release-Vorbereitung) sind auf `main` abgeschlossen oder befinden sich im Review. Das Plugin lässt sich über die Plugin-API von Cacti installieren, aktualisieren und deinstallieren und lehnt PHP-Versionen unter 8.2 ab. Ein Hintergrund-Reader auf jedem Poller liest Lustre-Server per SSH über ein fest definiertes Forced-Command-Skript ([node/pfs-read.sh](../../../node/pfs-read.sh)) aus und speichert Inventar, Zustand, Kapazität und Zähler samt Aktualitätszeitstempel im Cache. Jedes Dateisystem wird als Cacti-Gerät mit OST- und MDT-Datenabfragen (Data Queries), fünf Übersichtsgraphen, bedarfsweisen Graphen pro Target und einer zusammenfassenden Datenquelle für Thold abgebildet. Thold v1.8.2 und Maint v1.3 alarmieren im End-to-End-Durchlauf unverändert auf den zusammenfassenden Messwerten (Gauges) und unterdrücken diese; Thold-Schwellenwerte auf Zählern (Counters) werden mit Thold 1.8.2 nicht unterstützt ([docs/alerting.md](../../alerting.md)). Alle bisherigen Tests laufen mit Fixture-Ausgaben von Nodes in einer Wegwerf-Cacti-Instanz: Das Plugin wurde noch nicht gegen einen echten Lustre-Node ausgeführt. Vor einer Veröffentlichung noch offen sind Pilotbetriebe auf echten Dateisystemen ([Pilotplan](../../pilot-plan.md)), das externe Security-Review ([Selbstprüfung](../../security-review.md)) sowie Skalierungsmessungen auf dokumentierter Hardware ([Skalierung](../../research/scale.md)). Version 0.1.0 ist in `INFO` und [CHANGELOG.md](../../../CHANGELOG.md) vorbereitet, aber noch nicht getaggt.

## Zugriffsrollen

| Realm | Zugriff |
| --- | --- |
| Parallel Filesystem View | Bestehende Überwachungsseiten. |
| Parallel Filesystem Operator | Überwachung sowie die Seite „Operations“ und persönliche Triage-Bestätigungen. |
| Parallel Filesystem Admin | Operator-Zugriff sowie die bestehenden Konfigurations- und Verwaltungsaktionen. |

Öffnen Sie nach dem Kopieren der Upgrade-Dateien als Administrator die Seite „Plugins“, um den neuen Realm zu registrieren. Weisen Sie den Operator-Realm explizit unter **Configuration > Users** oder Benutzergruppen zu. Upgrades behalten die bestehenden IDs und Berechtigungen für die Realms View und Admin bei. Admin-Zugriff erbt bei jeder Anfrage den Operator-Zugriff, und Operator-Zugriff erbt den View-Zugriff; dadurch werden Benutzern oder Gruppen keine zusätzlichen gespeicherten Berechtigungen hinzugefügt. Für Plugin-Einstellungen ist zusätzlich zur Cacti-Berechtigung „Settings“ der Zugriff über „Parallel Filesystem Admin“ erforderlich.

Cacti-Geräteberechtigungen schränken weiterhin die für jeden Benutzer verfügbaren Zeilen und Aktionen ein; nicht zugeordnete Objekte erfordern einen Administrator. Zugangsdaten, Konfiguration, Zuordnung, Löschung und weitere administrative Änderungen verbleiben auf den Verwaltungsseiten. Bestätigungen gelten nur in der aktuellen Benutzersitzung, maximal eine Stunde lang und nur so lange, wie dieselbe Beobachtung aktuell bleibt. Neue oder geänderte Beobachtungen machen sie ungültig. Sie markieren niemals eine fehlgeschlagene Messung als fehlerfrei und unterdrücken keine Alarme.

## Warum

Wenn ein gemeinsam genutztes Dateisystem langsam ist, verteilen sich die Hinweise auf mehrere Server. Lustre führt seine Zähler pro Node: `lctl get_param` meldet Werte „auf dem lokalen Node“, sodass jeder Object Storage Server (OSS) und Metadata Server (MDS) nur die Zahlen seiner eigenen Targets vorhält. Ein Operator, der ein langsames Dateisystem untersucht, benötigt drei Antworten aus unterschiedlichen Quellen:

- **Füllt sich ein Target, während andere leer bleiben?** Eine ungleichmäßige Auslastung beeinflusst, wo neue Dateien abgelegt werden und wann ein einzelnes Target keinen Speicherplatz mehr hat.
- **Welche Targets oder Operationen sind stark ausgelastet?** Byte-Zähler pro Target und Operationszähler pro MDT zeigen, ob die Last daten- oder metadatenlimitiert ist.
- **Ist die Überwachung selbst aktuell?** Ein flacher Graph ist nur dann beruhigend, wenn das Plugin die Server tatsächlich erreichen konnte.

Cacti erstellt Graphen für Hosts und überwacht Schwellenwerte mit Thold, und die Cacti-GitHub-Organisation verfügt über kein Repository zur Überwachung von Lustre, GPFS oder BeeGFS (geprüft am 2026-11-14). Das Plugin schließt diese Lücke, indem es per Allowlist freigegebene, schreibgeschützte Werte von den Storage-Nodes ausliest und jedes Dateisystem als Cacti-Gerät abbildet, dessen Targets die Indizes einer Datenabfrage sind. Es nutzt Cactis Graphen, Berechtigungen, Remote Data Collectors, Thold und Maint weiter, anstatt parallele Eigenentwicklungen dafür aufzubauen.

## Geplanter Umfang

| Phase | Umfang | Meilensteine |
|---|---|---|
| 0, Recherche | Release-Matrix, Entscheidung zum Node-Zugriff, Bestätigung des Upstream-Verhaltens, Zähler- und Failover-Verhalten, Skalierungsmessungen | [M1](https://github.com/somethingwithproof/plugin_pfs/milestone/1) |
| 1, Beobachtung | Lustre-Inventar, Kapazität und Füllstand, OST-Durchsatz, MDT-Operationsraten, Graphen, Thold-Alarmierung, Maint-Unterdrückung, Statusseite | [M2](https://github.com/somethingwithproof/plugin_pfs/milestone/2), [M3](https://github.com/somethingwithproof/plugin_pfs/milestone/3), [M4](https://github.com/somethingwithproof/plugin_pfs/milestone/4) |
| 2, Tiefe | Cacti Remote Data Collectors, Ansichten auf Job- und Client-Ebene innerhalb einer begrenzten Objektanzahl | [M5](https://github.com/somethingwithproof/plugin_pfs/milestone/5) |
| 3, Backends | Backends für GPFS (IBM Storage Scale) und BeeGFS | [M6](https://github.com/somethingwithproof/plugin_pfs/milestone/6) |

Außerhalb des Umfangs: jegliche Schreib-, Lösch-, Zurücksetz- oder Tuning-Operation auf einem Node oder Dateisystem; eine plugin-eigene Alarmierungs-Engine; ein eigener Daemon oder ein eigenes Netzwerkprotokoll; Datenquellen pro Job; Änderungen am Cacti-Core, an Thold oder an Maint.

## Kompatibilitätsziele

| Komponente | Ziel |
|---|---|
| Cacti | 1.2.31 oder neuer, keine Core-Patches |
| PHP | 8.2 oder neuer; `setup.php` bleibt unter 8.0 parsbar, sodass eine nicht unterstützte Laufzeitumgebung eine Ablehnung statt einer defekten Cacti-Oberfläche ausgibt |
| Lustre | Release-Linien werden bis Meilenstein 1 festgelegt; bisher geprüfte Quellen und Handbücher entsprechen Stand 2.17.0 |
| GPFS, BeeGFS | Phase 3; Releases werden durch Recherche festgelegt |
| Thold, Maint | Keine Änderungen erforderlich |

## Design

- [docs/PLAN.md](../../PLAN.md) enthält den vollständigen Plan: Architektur, vorausgesetzte Upstream-Fakten (mit Quellen und Angaben dazu, was noch nicht verifiziert ist), Zählersemantik, Zustandsmodell, Sicherheitsgrenzen, Leistungsberechnung, Tests und Risiken.
- [docs/operator-guide.md](../../operator-guide.md) behandelt Installation, Graphen und Messwerte, Upgrade, Backup und Wiederherstellung, Deinstallation sowie Fehlerbehebung; [docs/alerting.md](../../alerting.md) behandelt Thold und Maint.
- [docs/node-setup.md](../../node-setup.md) beschreibt, wie ein Lustre-Server und ein Poller für den Reader vorbereitet werden.
- [docs/decisions/](../../decisions) enthält Architecture Decision Records für bereits getroffene Entscheidungen.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) ordnet jede Anforderungs-ID dem zugehörigen Issue zu.

```mermaid
flowchart LR
    N[Lustre-Server: OSS, MDS] -->|feste schreibgeschützte Anfrage| S[Node-Quelle auf dem Cacti-Poller]
    S --> R[Reader]
    R --> C[(Beobachtungs-Cache)]
    C --> SS[Script-Server-Eingabe]
    SS -->|poller_output| T[Thold und Maint]
    SS --> RRD[(Cacti-RRDs)]
    RRD --> G[Cacti-Graphen und Berechtigungen]
```

## Nachverfolgung der Arbeit

- **Meilensteine** folgen den Meilensteinen des Plans. Jeder besitzt ein Epic-Issue, das seine Unter-Issues und Abschlussnachweise auflistet.
- **Issues** werden über Labels typisiert: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Anforderungs-Issues enthalten nummerierte, testbare Anforderungen (`REQ-AREA-NNN`) und Akzeptanzkriterien.
- **Labels** tragen außerdem `area:`, `phase:`, `priority:` und `status:`. Siehe [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- Das [Projektboard](https://github.com/users/somethingwithproof/projects/11) zeigt alle Issues über alle Meilensteine hinweg.

## Mitwirken

Lesen Sie [CONTRIBUTING.md](../../../CONTRIBUTING.md). Commits benötigen ein DCO-Sign-off. Designdiskussionen finden in Issues statt; offene Entscheidungen tragen das Label `type: decision`.

## Sicherheit

Melden Sie Sicherheitslücken vertraulich; siehe [SECURITY.md](../../../SECURITY.md).

## Lizenz

GNU General Public License v2.0, dieselbe Lizenz wie Cacti und seine Plugins. Siehe [LICENSE](../../../LICENSE).
