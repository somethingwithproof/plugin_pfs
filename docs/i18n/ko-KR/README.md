<!-- Source: README.md; SHA256: e88d6875d113220a91cfc0a9d230c829a78477b17e6aa7e44dda38c9591c86ad -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_pfs

병렬 파일시스템의 상태와 부하를 Cacti로 가져오는 Cacti 1.2 플러그인입니다. 타깃(target) 전반의 용량 및 사용률 불균형(fill imbalance), 타깃별 처리량(throughput), 메타데이터 작업 속도를 수집합니다. 첫 번째 백엔드는 Lustre이며, GPFS(IBM Storage Scale)와 BeeGFS는 백엔드 인터페이스 뒤에 이어서 지원될 예정입니다. 수집된 데이터는 일반 Cacti 디바이스, 데이터 소스, 그래프로 저장되므로 Thold와 Maint가 별도 수정 없이 알림을 생성하고 억제할 수 있습니다.

> **상태: 마일스톤 4, 1단계(Phase 1) 코드 구현 완료, 미배포.** 마일스톤 2(기반 구축), 3(Lustre 관측), 4(알림 및 릴리스 준비)는 `main` 브랜치에 반영되었거나 검토 중입니다. 이 플러그인은 Cacti의 플러그인 API를 통해 설치, 업그레이드, 제거되며 PHP 8.2 미만 버전에서는 실행을 거부합니다. 각 폴러(poller)의 백그라운드 리더(reader)는 고정된 강제 명령(forced-command) 스크립트([node/pfs-read.sh](../../../node/pfs-read.sh))를 사용해 SSH로 Lustre 서버를 조회하고 인벤토리, 상태, 용량, 카운터와 그 최신성(freshness) 정보를 캐시합니다. 각 파일시스템은 OST 및 MDT 데이터 쿼리, 5개의 개요 그래프, 필요에 따라 생성되는 타깃별 그래프, 그리고 Thold용 요약 데이터 소스를 갖춘 하나의 Cacti 디바이스로 등록됩니다. 수정하지 않은 상태의 Thold v1.8.2와 Maint v1.3은 엔드투엔드(end-to-end) 실행에서 요약 게이지(summary gauges)에 대한 알림 생성 및 억제를 정상적으로 수행합니다. 단, Thold 1.8.2에서는 카운터에 대한 Thold 임계값 설정이 지원되지 않습니다([docs/alerting.md](../../alerting.md)). 지금까지의 모든 테스트는 일회성 Cacti 환경에서 픽스처(fixture) 노드 출력으로만 수행되었으며, 실제 Lustre 노드를 대상으로 실행된 적은 없습니다. 릴리스 전에 남아 있는 과제는 실제 파일시스템에서의 파일럿 테스트([파일럿 계획](../../pilot-plan.md)), 외부 보안 검토([자체 검토](../../security-review.md)), 문서화된 하드웨어에서의 규모 측정([규모 측정](../../research/scale.md))입니다. 버전 0.1.0은 `INFO`와 [CHANGELOG.md](../../../CHANGELOG.md)에 준비되어 있으나 아직 태그가 지정되지 않았습니다.

## 접근 역할

| 영역(Realm) | 접근 권한 |
| --- | --- |
| Parallel Filesystem View | 기존 모니터링 페이지. |
| Parallel Filesystem Operator | 모니터링 페이지, Operations 페이지, 개인 트리아지(triage) 확인(acknowledgment) 기능. |
| Parallel Filesystem Admin | Operator 권한과 더불어 기존 구성 및 관리 작업. |

업그레이드 파일을 복사한 후 관리자 권한으로 Plugins 페이지를 열어 새 영역(realm)을 등록하세요. **Configuration > Users** 또는 사용자 그룹에서 Operator 영역을 명시적으로 할당하세요. 업그레이드 시 기존 View 및 Admin 영역 ID와 부여된 권한은 유지됩니다. 요청이 처리될 때마다 Admin 권한은 Operator 권한을, Operator 권한은 View 권한을 상속하며, 이 과정에서 사용자나 그룹에 저장된 권한 항목이 추가로 생성되지는 않습니다. 플러그인 설정을 변경하려면 Cacti의 Settings 권한 외에 Parallel Filesystem Admin 권한도 필요합니다.

각 사용자가 조회하거나 수행할 수 있는 행과 작업은 여전히 Cacti 디바이스 권한에 의해 제한되며, 매핑되지 않은 객체에는 관리자 권한이 필요합니다. 자격 증명, 구성, 매핑, 삭제 및 기타 관리 변경 작업은 관리 페이지에서만 수행할 수 있습니다. 확인(acknowledgment) 상태는 현재 사용자 세션에서만 최대 1시간 동안, 그리고 동일한 관측 데이터가 최신(fresh) 상태로 유지되는 동안에만 유효합니다. 새 관측 데이터가 들어오거나 관측 내용이 변경되면 확인 상태는 무효화됩니다. 확인 처리를 하더라도 실패한 측정을 정상(healthy)으로 표시하거나 알림을 억제하지는 않습니다.

## 도입 배경

공유 파일시스템이 느려지면 그 단서는 여러 서버에 흩어져 있습니다. Lustre는 노드별로 카운터를 유지합니다. `lctl get_param`은 "로컬 노드(on the local node)"의 값만 보고하므로, 각 객체 스토리지 서버(OSS)와 메타데이터 서버(MDS)는 자신의 타깃에 해당하는 수치만 보유합니다. 느려진 파일시스템을 조사하는 운영자는 서로 다른 위치에서 다음 세 가지 질문에 대한 답을 얻어야 합니다.

- **다른 타깃이 비어 있는 동안 특정 타깃만 차고 있습니까?** 사용률 불균형(fill imbalance)은 새 파일이 배치되는 위치와 단일 타깃의 공간이 소진되는 시점에 영향을 미칩니다.
- **어떤 타깃이나 작업이 바쁩니까?** 타깃별 바이트 카운터와 MDT별 작업 카운터를 통해 부하가 데이터 중심인지 메타데이터 중심인지 확인할 수 있습니다.
- **모니터링 자체는 최신 상태입니까?** 그래프가 평탄하게 유지되는 것은 플러그인이 서버를 정상적으로 조회할 수 있었을 때만 안심할 수 있습니다.

Cacti는 호스트를 그래프로 시각화하고 Thold로 임계값을 감시하지만, Cacti GitHub 조직에는 Lustre, GPFS 또는 BeeGFS 모니터링을 위한 저장소가 없습니다(2026-11-14 검색 기준). 이 플러그인은 스토리지 노드에서 허용 목록(allowlist)에 등록된 읽기 전용 값만 읽어 각 파일시스템을 데이터 쿼리의 인덱스로 타깃을 표현하는 Cacti 디바이스로 모델링함으로써 이 공백을 메웁니다. 별도의 유사 기능을 새로 구축하는 대신 Cacti의 그래프, 권한, 원격 데이터 수집기(remote data collectors), Thold, Maint를 그대로 재사용합니다.

## 계획된 범위

| 단계 | 범위 | 마일스톤 |
|---|---|---|
| 0, 조사(research) | 릴리스 매트릭스, 노드 접근 방식 결정, 업스트림 동작 확인, 카운터 및 페일오버 동작, 규모 측정 | [M1](https://github.com/somethingwithproof/plugin_pfs/milestone/1) |
| 1, 관측(observe) | Lustre 인벤토리, 용량 및 사용률, OST 처리량, MDT 작업 속도, 그래프, Thold 알림, Maint 억제, 상태(health) 페이지 | [M2](https://github.com/somethingwithproof/plugin_pfs/milestone/2), [M3](https://github.com/somethingwithproof/plugin_pfs/milestone/3), [M4](https://github.com/somethingwithproof/plugin_pfs/milestone/4) |
| 2, 심화(depth) | Cacti 원격 데이터 수집기, 제한된 객체 수 범위 내의 작업(job) 수준 및 클라이언트 수준 뷰 | [M5](https://github.com/somethingwithproof/plugin_pfs/milestone/5) |
| 3, 백엔드(backends) | GPFS(IBM Storage Scale) 및 BeeGFS 백엔드 | [M6](https://github.com/somethingwithproof/plugin_pfs/milestone/6) |

범위 제외(Out of scope): 노드나 파일시스템에 대한 모든 쓰기, 초기화(clear), 재설정(reset) 또는 튜닝 작업, 플러그인 자체 알림 엔진, 사용자 지정 데몬 또는 네트워크 프로토콜, 작업(job)별 데이터 소스, Cacti 코어·Thold·Maint 수정.

## 호환성 목표

| 구성 요소 | 목표 |
|---|---|
| Cacti | 1.2.31 이상, 코어 패치 불필요 |
| PHP | 8.2 이상. 지원되지 않는 런타임에서 Cacti UI가 깨지는 대신 명확한 거부 메시지를 표시하도록 `setup.php`는 8.0에서도 파싱 가능하게 유지됩니다 |
| Lustre | 마일스톤 1에서 릴리스 라인을 확정할 예정입니다. 현재까지 확인한 소스 및 매뉴얼 기준은 2.17.0입니다 |
| GPFS, BeeGFS | 3단계(Phase 3)에서 지원하며, 조사를 통해 지원 릴리스를 확정할 예정입니다 |
| Thold, Maint | 별도 수정 불필요 |

## 설계

- [docs/PLAN.md](../../PLAN.md)는 전체 계획 문서로 아키텍처, 의존하는 업스트림 사실(출처 및 미검증 항목 포함), 카운터 시맨틱, 상태 모델, 보안 경계, 성능 계산, 테스트, 위험 요소를 다룹니다.
- [docs/operator-guide.md](../../operator-guide.md)는 설치, 그래프와 측정값, 업그레이드, 백업 및 복원, 제거, 문제 해결을 다루며, [docs/alerting.md](../../alerting.md)는 Thold와 Maint를 다룹니다.
- [docs/node-setup.md](../../node-setup.md)는 리더(reader)를 위해 Lustre 서버와 폴러를 준비하는 방법을 설명합니다.
- [docs/decisions/](../../decisions)에는 이미 내린 선택에 대한 아키텍처 결정 기록(ADR)이 보관되어 있습니다.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md)는 모든 요구사항 ID를 해당 이슈에 매핑합니다.

```mermaid
flowchart LR
    N["Lustre 서버: OSS, MDS"] -->|고정 읽기 전용 요청| S["Cacti 폴러의 노드 소스"]
    S --> R["리더(Reader)"]
    R --> C[("관측 캐시")]
    C --> SS["스크립트 서버 입력"]
    SS -->|poller_output| T["Thold 및 Maint"]
    SS --> RRD[("Cacti RRD")]
    RRD --> G["Cacti 그래프 및 권한"]
```

## 작업 추적 방식

- **마일스톤(Milestones)**은 계획의 마일스톤을 따릅니다. 각 마일스톤에는 하위 이슈와 종료 근거(exit evidence)를 나열한 에픽(epic) 이슈가 있습니다.
- **이슈(Issues)**는 레이블(`type: requirement`, `type: research`, `type: decision`, `type: epic`)로 유형이 구분됩니다. 요구사항 이슈에는 번호가 부여된 테스트 가능한 요구사항(`REQ-AREA-NNN`)과 수락 기준(acceptance criteria)이 명시됩니다.
- **레이블(Labels)**에는 `area:`, `phase:`, `priority:`, `status:` 접두사도 함께 사용됩니다. 자세한 내용은 [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels)를 참고하세요.
- [프로젝트 보드](https://github.com/users/somethingwithproof/projects/11)에서 모든 마일스톤의 전체 이슈를 확인할 수 있습니다.

## 기여

[CONTRIBUTING.md](../../../CONTRIBUTING.md)를 읽어주세요. 커밋에는 DCO 서명(sign-off)이 필요합니다. 설계 논의는 이슈에서 진행되며, 미결정 사안에는 `type: decision` 레이블이 붙습니다.

## 보안

취약점은 비공개로 제보해 주세요. 자세한 내용은 [SECURITY.md](../../../SECURITY.md)를 참고하세요.

## 라이선스

Cacti 및 Cacti 플러그인과 동일한 GNU General Public License v2.0을 따릅니다. 자세한 내용은 [LICENSE](../../../LICENSE)를 참고하세요.
