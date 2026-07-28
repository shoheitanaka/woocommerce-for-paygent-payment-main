---
name: paygent-sb-cc
description: >
  PaygentクレジットカードのWEB試験環境（サンドボックス）動作確認スキル。
  Claude in Chromeでリモートの試験環境サイトを操作し、3DS2決済パターン網羅・
  返金/キャンセル・カード保存・定期購入・メール送信を検証してレポートを作成する。
  「/paygent-sb-cc <URL> [フェーズ]」で呼び出す。
  「サンドボックス決済試験」「試験環境動作確認」「sb-cc」「3DS2試験」などのキーワードで発動。
compatibility: >
  WEB上のPaygent試験環境サイト（ローカルwp-envは対象外→paygent-sandbox-checkを使用）。
  ChromeでWP管理画面にログイン済みであること。WP Mail Logging有効化必須。
  定期購入フェーズはWooCommerce Subscriptions必須。
---

# Paygent クレジットカード サンドボックス動作確認（Claude in Chrome）

WEB上の試験環境サイトをブラウザ操作で検証するスキル。ローカルwp-env向けの
チェックリストスキル `paygent-sandbox-check` とは別物。

## 呼び出し形式

```text
/paygent-sb-cc <試験環境URL> [フェーズ]
```

| フェーズ | 内容 | 参照ファイル |
| --- | --- | --- |
| `all`（省略時） | 全フェーズを順に実行 | — |
| `3ds2` | 3DS2決済パターン網羅＋返金/キャンセル＋通知確認 | [phase-3ds2.md](references/phase-3ds2.md) |
| `vault` | カード保存の登録/削除＋保存カード注文の返金/通知 | [phase-vault.md](references/phase-vault.md) |
| `subscription` | 定期購入の決済/強制継続/一時停止/キャンセル | [phase-subscription.md](references/phase-subscription.md) |
| `code-check` | 他決済への勝手な移行がないかのコード静的チェック | [code-check.md](references/code-check.md) |

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
| WooCommerce Subscriptions有効 | プラグイン一覧 | subscriptionフェーズをスキップし報告 |
| Paygent CC設定 | `wp-admin/admin.php?page=wc-settings&tab=checkout&section=paygent_cc` | 下記 |
| テスト商品（通常/定期） | 商品一覧 | 停止して報告（試験環境の既存商品を使うのが原則） |
| Block/クラシック両チェックアウトページ | 固定ページ確認 | 片方しかなければユーザーに確認 |
| メール有効化状態 | WooCommerce→設定→メール | 記録のみ（メール判定の基準にする） |

Paygent CC設定で確認・記録するキー: テスト環境モード、`tds2_check`（=yes必須）、
`paymentaction`（授権/売上マトリクスで切替対象）、`store_card_info`（vaultフェーズで=yes必須）、
`attempt`（アテンプト区分の期待値解釈に必要）、`attempt_notice_email`、`debug`（=yes推奨）。
設定変更した場合は元の値を記録し、終了時に**必ず元へ戻す**。

### 0-4. 注文の全削除（要ユーザー確認）

1. 注文一覧（`admin.php?page=wc-orders`）・定期購入一覧・WP Mail Loggingのログ一覧、
   それぞれで**現在の件数を確認**する（WP Mail Loggingのログも本ステップで削除するため、
   件数を必ず数える）。
2. AskUserQuestionで「注文◯件・定期購入◯件・メールログ◯件を完全削除してよいか」を
   確認する（メールログの件数も明記し、診断用に残したい既存メール履歴がないか
   ユーザーが判断できるようにする）。
3. 承認後: 全選択→ゴミ箱へ移動→ゴミ箱表示→完全に削除。定期購入も同様
   （定期購入を先に削除すると関連注文の削除がスムーズ）。
4. WP Mail Loggingのログも全削除する（以後のメール検証を差分で正確に行うため）。

### 0-5. テストカード・3DS2制御値

- **カード番号**: `4980000000001000`（VISA・末尾X=1でオーソリ/取消/売上系すべてOK）
- **有効期限**: 当月から20年以内の未来（例: 12/30）／ **CVC**: 123
- **3DS2の結果はカード名義人で制御**する（金額制御は使わない）。
  名義人パターン表は [phase-3ds2.md](references/phase-3ds2.md) 参照。

## 実行の進め方

- 各決済実行ごとに「パターン名・チェックアウト種別・設定・注文番号・結果・メール」を
  その場で記録する（レポートの元データ）。
- 注文作成後は必ず管理画面の注文詳細で **支払い方法が「クレジットカード決済」のまま**
  であることを確認する（過去に他決済へ勝手に移行するバグ事例あり→code-check参照）。
- 失敗系パターンでは「顧客にPaygentの実エラーが表示されること」
  「Something went wrongにならないこと」を確認する（Block Checkout既知バグ領域）。
- フェーズ完了ごとに中間サマリーをユーザーに報告してから次フェーズへ進む。

## レポート作成（全フェーズ共通・最後に必ず実施）

`report/` フォルダ（リポジトリ直下）に `sandbox-cc-YYYYMMDD.md` を作成する。
様式は [report-format.md](references/report-format.md) に従う。
**初回実行時**（report/ に既存レポートがない場合）はドラフトをユーザーとレビューして
形式を確定し、確定した形式を report-format.md に反映すること。
