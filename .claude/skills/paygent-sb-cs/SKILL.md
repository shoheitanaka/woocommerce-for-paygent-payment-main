---
name: paygent-sb-cs
description: >
  Paygentコンビニ決済（番号方式）のWEB試験環境（サンドボックス）動作確認スキル。
  Claude in Chromeでリモートの試験環境サイトと、Paygent試験環境ツール
  （https://sandbox.paygent.co.jp/testtool/statusupdatesearch）を操作し、
  ローソンでの申込〜消込（入金完了）〜支払期限切れ〜返金/キャンセル操作・通知を
  検証してレポートを作成する。「/paygent-sb-cs <URL> [フェーズ]」で呼び出す。
  「サンドボックス決済試験」「試験環境動作確認」「sb-cs」「コンビニ決済試験」
  「statusupdatesearch」などのキーワードで発動。
compatibility: >
  WEB上のPaygent試験環境サイト（ローカルwp-envは対象外→paygent-sandbox-checkを使用）。
  ChromeでWP管理画面にログイン済みであること。WP Mail Logging有効化必須。
  Paygent CS設定でローソン（setting_cs_lm=yes）が有効・test_mode=yesであること。
  試験環境ツール（sandbox.paygent.co.jp/testtool/）用クライアント証明書（.pfx）が
  実行環境のブラウザに設定済みであること（未設定の場合はPhase 0-5で検出して停止）。
---

# Paygent コンビニ決済（番号方式）サンドボックス動作確認（Claude in Chrome）

WEB上の試験環境サイト＋Paygent試験環境ツールをブラウザ操作で検証するスキル。
`paygent-sb-cc` と対をなす決済別スキルで、構成・絶対ルールは共通方針を踏襲する。

**実際のコンビニ実店舗での支払いは試験できない**ため、ペイジェント公式の
試験環境ツール（決済ステイタスのシミュレーション）を使って「顧客がローソンで
実際に支払った」状態を人工的に発生させ、Webhookでの注文ステータス更新まで含めて
一連の処理を検証する。コンビニは**ローソン（コンビニ企業CD `00C002`）のみ**を対象とする
（セブンイレブン等、他コンビニの実試験環境は用意されていないため）。

## 呼び出し形式

```text
/paygent-sb-cs <試験環境URL> [フェーズ]
```

| フェーズ | 内容 | 参照ファイル |
| --- | --- | --- |
| `all`（省略時） | 全フェーズを順に実行 | — |
| `payment` | ローソンでの申込〜消込（入金完了）〜支払期限切れ＋通知確認 | [phase-payment.md](references/phase-payment.md) |
| `refund` | 消込後の注文操作（返金・キャンセル）＋通知確認 | [phase-refund.md](references/phase-refund.md) |
| `code-check` | 他決済への勝手な移行がないかのコード静的チェック | [code-check.md](references/code-check.md) |

試験環境ツールの画面仕様・操作手順は [testtool.md](references/testtool.md) を参照。
レポート様式は [report-format.md](references/report-format.md) を参照。

## 絶対ルール（停止条件）

1. **URL未指定なら停止**してユーザーに確認する。
2. **ログイン済みが原則**。`{URL}/wp-admin/` へアクセスしてログインフォームが表示されたら
   **即停止**し「試験環境にログインされていません」と報告する。
   自分でログインを試みない。認証情報を尋ねてログインを代行しない。
3. **本番環境の疑い**（実顧客と思われる注文が多数、Paygent設定がテスト環境モードでない等）
   があれば停止してユーザーに確認する。
4. **注文全削除はユーザーの最終確認後のみ**実行する（下記 Phase 0-4）。
5. ブラウザ操作が同じ箇所で2〜3回失敗したら停止し、状況と試したことを報告する。
6. JSの confirm/alert を出す操作は事前に把握し、出た場合の操作不能に注意する
   （ダイアログで固まったらユーザーに手動で閉じてもらう）。
7. **試験環境ツール（sandbox.paygent.co.jp/testtool/）でクライアント証明書の
   選択ダイアログが表示され進めなくなったら即停止**し、ユーザーに証明書設定を
   確認してもらう（自動操作の対象外になりうるため、代行を試みない）。
8. **`wc-paygent-test-cpass`（マーチャント接続パスワード）の値はチャット出力・
   レポート・コミットするファイルのいずれにも書き残さない**。試験環境ツールの
   フォームへ入力する用途にのみ、その場で使う。

## Phase 0: 共通前処理（全フェーズ実行前に必ず実施）

### 0-1. ブラウザ準備

Chrome用ツールを **1回のToolSearchでまとめて** ロードする:
`tabs_context_mcp` / `navigate` / `computer` / `read_page` / `tabs_create_mcp` /
`form_input` / `get_page_text` / `find` / `javascript_tool`。
`tabs_context_mcp` で現状確認後、新規タブで作業する。

### 0-2. ログイン検証

`{URL}/wp-admin/` へ移動。管理画面ダッシュボードが表示されればOK。
ログインフォームが出たら**即停止**（絶対ルール2）。

### 0-3. 環境検証（結果はレポートの「環境」欄に記録）

| 確認項目 | 場所 | NG時 |
| --- | --- | --- |
| WooCommerce有効 | プラグイン一覧 | 停止 |
| WP Mail Logging有効 | プラグイン一覧（メール検証に必須） | 停止して報告 |
| Paygent共通設定（マーチャント認証情報） | `admin.php?page=jp4wc-paygent-output` | 下記 |
| Paygent CS設定 | `wp-admin/admin.php?page=wc-settings&tab=checkout&section=paygent_cs` | 下記 |
| テスト商品 | 商品一覧 | 停止して報告（試験環境の既存商品を使うのが原則） |
| Block/クラシック両チェックアウトページ | 固定ページ確認 | 片方しかなければユーザーに確認 |
| メール有効化状態 | WooCommerce→設定→メール | 記録のみ（メール判定の基準にする） |

**Paygent共通設定**（`jp4wc-paygent-output`、全決済手段共通・素のテキスト入力欄なので
値がそのまま画面に表示される）で確認・**その場で使うだけで記録はしない**値:
テスト環境用 `Merchant ID` / `Connect ID` / `Connect Password`
（オプション名 `wc-paygent-test-mid` / `-test-cid` / `-test-cpass`）。
試験環境ツールの決済検索画面で使う。**Connect Passwordはレポートに書かない**
（絶対ルール8）。

**Paygent CS設定**で確認・記録するキー: テスト環境モード（`test_mode`=yes必須）、
ローソン・ミニストップ有効化（`setting_cs_lm`=yes必須。noなら停止して報告）、
`payment_limit_date`（支払期限日数）、`debug`（=yes推奨）。
設定変更した場合は元の値を記録し、終了時に**必ず元へ戻す**。

### 0-4. 注文の全削除（要ユーザー確認）

1. 注文一覧（`admin.php?page=wc-orders`）・WP Mail Loggingのログ一覧、
   それぞれで**現在の件数を確認**する。
2. AskUserQuestionで「注文◯件・メールログ◯件を完全削除してよいか」を確認する。
3. 承認後: 全選択→ゴミ箱へ移動→ゴミ箱表示→完全に削除。
4. WP Mail Loggingのログも全削除する（以後のメール検証を差分で正確に行うため）。

### 0-5. 試験環境ツールへのアクセス確認

`https://sandbox.paygent.co.jp/testtool/statusupdatesearch` へ新規タブで移動する。

- クライアント証明書の選択ダイアログなしで【決済検索画面】が表示されればOK。
- 証明書選択ダイアログが出て進めない場合は**即停止**（絶対ルール7）。
- 画面仕様・入力項目は [testtool.md](references/testtool.md) を参照。

### 0-6. テストデータ定数

- **対象コンビニ**: ローソン（コンビニ企業CD `00C002`、CVSタイプ=02）。チェックアウトで
  複数コンビニが選択可能でも必ずローソンを選ぶ。
- **決済ID（試験環境ツールの検索キー）**: 注文の Transaction ID（`_transaction_id`）。
  CS決済は成功時に `set_transaction_id()` するが、CC/MCCCと異なり注文メモに
  Transaction IDを明記しないため、管理画面で見えない場合は
  [testtool.md](references/testtool.md) のフォールバック手順（デバッグログ／
  カスタムフィールド表示）で確認する。
- **利用者電話番号 `99999999999`**: このコンビニ企業CD帯（Aタイプ接続時）では
  申込を意図的に異常応答させる制御値。失敗系パターンの確認に使う
  （詳細は [phase-payment.md](references/phase-payment.md)）。

## 実行の進め方

- 各操作ごとに「パターン名・チェックアウト種別・注文番号・試験環境ツールでの操作・
  結果・メール」をその場で記録する（レポートの元データ）。
- 注文作成後は必ず管理画面の注文詳細で **支払い方法が「コンビニ決済」のまま**
  であることを確認する（→code-check参照）。
- 失敗系パターンでは「顧客にPaygentの実エラーが表示されること」
  「Something went wrongにならないこと」を確認する（Block Checkout既知バグ領域）。
- 試験規模は成功系をBlock・クラシック両方でフルに、失敗系（支払期限切れ・
  電話番号異常等）は片方の設定のみで確認する（paygent-sb-cc シリーズの共通方針）。
- フェーズ完了ごとに中間サマリーをユーザーに報告してから次フェーズへ進む。

## レポート作成（全フェーズ共通・最後に必ず実施）

`report/` フォルダ（リポジトリ直下）に `sandbox-cs-YYYYMMDD.md` を作成する。
様式は [report-format.md](references/report-format.md) に従う。
**初回実行時**（report/ に `sandbox-cs-*.md` が存在しない場合）はドラフトを
ユーザーとレビューして形式を確定し、確定した形式を report-format.md に反映すること。
