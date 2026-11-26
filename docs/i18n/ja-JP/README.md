<!-- Source: README.md; SHA256: 40f2c5a5e9be97e4bd2b05a9ebab2a77de51de3af1b1c21dbeb5ee7f936cdd3b -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_pfs

並列ファイルシステムの健全性と負荷状況（ターゲット間の容量と使用率の偏り、ターゲットごとのスループット、メタデータ操作レート）を Cacti に取り込む Cacti 1.2 プラグインです。最初のバックエンドは Lustre であり、GPFS（IBM Storage Scale）および BeeGFS もバックエンドインターフェースを介して順次対応します。取得したデータは通常の Cacti デバイス、データソース、およびグラフに格納されるため、Thold や Maint は変更なしでそのままアラート通知や抑制を行えます。

> **ステータス: マイルストーン 4、フェーズ 1 のコード実装完了（未リリース）。** マイルストーン 2（基盤）、3（Lustre 監視）、および 4（アラート通知とリリース準備）は `main` ブランチで完了しているか、現在レビュー中です。本プラグインは Cacti のプラグイン API を通じてインストール、アップグレード、およびアンインストールでき、PHP 8.2 未満の環境では動作を拒否します。各ポーラー上のバックグラウンドリーダーは、固定の強制コマンドスクリプト（[node/pfs-read.sh](../../../node/pfs-read.sh)）を介して SSH 経由で Lustre サーバーを読み取り、インベントリ、状態、容量、およびカウンタを鮮度情報とともにキャッシュします。各ファイルシステムは、OST および MDT データクエリ、5 種類の概要グラフ、必要に応じたターゲットごとのグラフ、および Thold 用のサマリーデータソースを備えた 1 つの Cacti デバイスとして登録されます。エンドツーエンドの実行では、変更を加えていない Thold v1.8.2 および Maint v1.3 によってサマリーゲージに対するアラート通知と抑制が動作します。なお、Thold 1.8.2 ではカウンタに対する Thold しきい値はサポートされていません（[docs/alerting.md](../../alerting.md)）。これまでのテストはすべて使い捨ての Cacti 環境でフィクスチャのノード出力を用いて実行されており、実際の Lustre ノードに対してはまだ実行していません。リリース前に残っている課題は、実ファイルシステムでのパイロット運用（[パイロット計画](../../pilot-plan.md)）、外部セキュリティレビュー（[自己レビュー](../../security-review.md)）、および文書化されたハードウェア上でのスケール測定（[スケール測定](../../research/scale.md)）です。バージョン 0.1.0 は `INFO` および [CHANGELOG.md](../../../CHANGELOG.md) に準備されていますが、まだタグ付けされていません。

## アクセスロール

| レルム | アクセス権限 |
| --- | --- |
| Parallel Filesystem View | 既存の監視ページ。 |
| Parallel Filesystem Operator | 監視ページに加え、Operations ページおよび個人用のトリアージ確認応答（acknowledgment）。 |
| Parallel Filesystem Admin | Operator の権限に加え、既存の設定および管理操作。 |

アップグレード用ファイルをコピーした後、管理者として Plugins ページを開き、新しいレルムを登録してください。Operator レルムは **Configuration > Users** またはユーザーグループから明示的に割り当てます。アップグレード後も既存の View および Admin レルムの ID と付与権限は保持されます。Admin アクセスはリクエストごとに Operator アクセスを継承し、Operator アクセスは View アクセスを継承します。これにより、ユーザーやグループへ保存済みの権限付与が追加されることはありません。プラグイン設定には、Cacti の Settings 権限に加えて Parallel Filesystem Admin アクセスも必要です。

Cacti のデバイス権限によって各ユーザーが利用できる行や操作は引き続き制限され、未マッピングのオブジェクトには管理者権限が必要です。認証情報、設定、マッピング、削除、その他の管理上の変更は管理ページに残ります。確認応答（acknowledgment）は現在のユーザーセッション内でのみ、最大 1 時間、かつ同じ観測データが最新の状態である間のみ有効です。観測データが新規取得または更新されると無効になります。また、失敗した計測を正常とみなしたり、アラートを抑制したりすることは一切ありません。

## 背景と目的

共有ファイルシステムが遅い場合、その原因を示す根拠は複数のサーバーに分散しています。Lustre はノードごとにカウンタを保持しており、`lctl get_param` は「ローカルノード上」の値を報告するため、各オブジェクトストレージサーバー（OSS）およびメタデータサーバー（MDS）は自身のターゲットの数値しか保持していません。低速なファイルシステムを調査する運用担当者は、異なる場所から次の 3 つの答えを得る必要があります。

- **他のターゲットが空いているのに、1 つのターゲットだけ容量が増えていないか？** 使用率の偏り（fill imbalance）は、新しいファイルが配置される場所や、単一ターゲットが容量不足になるタイミングに影響します。
- **どのターゲットまたはどの操作がビジー状態か？** ターゲットごとのバイトカウンタおよび MDT ごとの操作カウンタを見ることで、負荷がデータ処理に起因するのかメタデータ処理に起因するのかを判別できます。
- **監視データ自体が最新か？** グラフが平坦であることは、プラグインがサーバーを正常に参照できている場合にのみ安心材料になります。

Cacti はホストのグラフ化や Thold によるしきい値監視を行えますが、Cacti の GitHub 組織には Lustre、GPFS、または BeeGFS を監視するためのリポジトリが存在しません（2026-11-14 調査時点）。本プラグインは、ストレージノードから許可リストに登録された読み取り専用の値を取得し、各ファイルシステムをデータクエリのインデックスとしてターゲットを持つ Cacti デバイスとして表現することで、このギャップを埋めます。独自の同等機能を別途構築するのではなく、Cacti のグラフ機能、権限管理、リモートデータコレクター、Thold、および Maint をそのまま再利用します。

## 計画されているスコープ

| フェーズ | スコープ | マイルストーン |
|---|---|---|
| 0, 調査 | リリース対象マトリクス、ノードアクセス方式の決定、アップストリーム挙動の確認、カウンタおよびフェイルオーバーの挙動、スケール測定 | [M1](https://github.com/somethingwithproof/plugin_pfs/milestone/1) |
| 1, 監視 | Lustre インベントリ、容量と使用率、OST スループット、MDT 操作レート、グラフ、Thold アラート通知、Maint 抑制、健全性ページ | [M2](https://github.com/somethingwithproof/plugin_pfs/milestone/2), [M3](https://github.com/somethingwithproof/plugin_pfs/milestone/3), [M4](https://github.com/somethingwithproof/plugin_pfs/milestone/4) |
| 2, 拡張 | Cacti リモートデータコレクター、上限オブジェクト数内でのジョブ単位およびクライアント単位のビュー | [M5](https://github.com/somethingwithproof/plugin_pfs/milestone/5) |
| 3, バックエンド | GPFS（IBM Storage Scale）および BeeGFS バックエンド | [M6](https://github.com/somethingwithproof/plugin_pfs/milestone/6) |

スコープ外: ノードまたはファイルシステムに対する書き込み、クリア、リセット、チューニング操作、プラグイン独自のアラートエンジン、カスタムデーモンまたはネットワークプロトコル、ジョブごとのデータソース、Cacti コア・Thold・Maint への変更。

## 互換性ターゲット

| コンポーネント | ターゲット |
|---|---|
| Cacti | 1.2.31 以降、コアへのパッチ不要 |
| PHP | 8.2 以降。`setup.php` は PHP 8.0 でも構文解析可能な状態を維持し、サポート外のランタイムでは Cacti UI を壊すのではなく動作拒否を返します |
| Lustre | リリース系列はマイルストーン 1 で確定予定。これまでに確認したソースおよびマニュアルは 2.17.0 時点のものです |
| GPFS, BeeGFS | フェーズ 3。対象リリースは調査により確定予定 |
| Thold, Maint | 変更不要 |

## 設計

- [docs/PLAN.md](../../PLAN.md) は全体計画書です。アーキテクチャ、依存するアップストリームの仕様（出典および未検証事項を含む）、カウンタのセマンティクス、状態モデル、セキュリティ境界、パフォーマンス計算、テスト、およびリスクを記載しています。
- [docs/operator-guide.md](../../operator-guide.md) ではインストール、グラフと取得値、アップグレード、バックアップとリストア、アンインストール、およびトラブルシューティングを扱い、[docs/alerting.md](../../alerting.md) では Thold と Maint について説明しています。
- [docs/node-setup.md](../../node-setup.md) では、リーダー向けに Lustre サーバーとポーラーを準備する手順を説明しています。
- [docs/decisions/](../../decisions) には、決定済みの選択に関するアーキテクチャ決定記録（ADR）を格納しています。
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) は、すべての要件 ID と対応する Issue を対応付けています。

```mermaid
flowchart LR
    N["Lustre サーバー: OSS、MDS"] -->|固定の読み取り専用リクエスト| S["Cacti ポーラー上のノードソース"]
    S --> R["リーダー"]
    R --> C[("観測キャッシュ")]
    C --> SS["スクリプトサーバー入力"]
    SS -->|poller_output| T["Thold および Maint"]
    SS --> RRD[("Cacti RRD")]
    RRD --> G["Cacti グラフおよび権限"]
```

## 作業の管理方法

- **マイルストーン** は計画書のマイルストーンに対応しています。各マイルストーンには、サブ Issue と完了エビデンスをまとめたエピック Issue があります。
- **Issue** はラベルで分類されています（`type: requirement`、`type: research`、`type: decision`、`type: epic`）。要件 Issue には、番号付きのテスト可能な要件（`REQ-AREA-NNN`）と受け入れ基準が記載されています。
- **ラベル** には `area:`、`phase:`、`priority:`、`status:` も使用されます。詳細は [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels) を参照してください。
- [プロジェクトボード](https://github.com/users/somethingwithproof/projects/11) では、全マイルストーンにわたるすべての Issue を確認できます。

## コントリビューション

[CONTRIBUTING.md](../../../CONTRIBUTING.md) をお読みください。コミットには DCO サインオフが必要です。設計に関する議論は Issue 上で行い、未決定事項には `type: decision` ラベルを付与します。

## セキュリティ

脆弱性は非公開で報告してください。詳細は [SECURITY.md](../../../SECURITY.md) を参照してください。

## ライセンス

Cacti およびそのプラグインと同じ GNU General Public License v2.0 です。詳細は [LICENSE](../../../LICENSE) を参照してください。
