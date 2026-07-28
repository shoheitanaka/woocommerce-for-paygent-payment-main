# Phase code-check — 支払い方法の勝手な移行がないかのチェック

`paygent-sb-cc` の code-check と同じ目的（過去事例: Block Checkoutのドラフト注文
再利用により `payment_method` が別決済へ上書きされたバグ。MBで発生・
`set_cart_hash('')` で修正済み。CLAUDE.md「リダイレクト型決済の注意」参照）を、
CS（コンビニ決済）視点で確認する。ブラウザ試験と静的チェックの両面で行う。

## 1. 静的コードチェック（ローカルリポジトリに対して実施）

対象: `class-wc-gateway-paygent-cs.php` /
`includes/gateways/paygent/includes/block/class-wc-paygent-block-cs.php` /
`class-wc-gateway-paygent-request.php` / `class-wc-paygent-endpoint.php`

| 観点 | 確認内容 |
| --- | --- |
| set_payment_method | CS系コードに注文の `set_payment_method()` / `payment_method` 上書きがないか |
| ドラフト注文再利用 | CS決済に外部リダイレクトはないが、Block Checkout（Store API）でのドラフト注文再利用が payment_method を上書きする余地がないか（`set_cart_hash` の扱い） |
| **Webhookのpayment_type分岐** | `class-wc-paygent-endpoint.php` の `paygent_check_webhook()` は `trading_id` から注文を特定した後、**注文自身の `payment_method` を検証せず**、通知電文の `payment_type`（CSは`03`）だけで `paygent_cv_webhook()` を呼び分けている。trading_idの再利用や取り違えがあった場合、他決済の注文にCS用のステータス遷移が適用されるリスクがないか確認する（`get_paygent_trading_id()` の一意性がどこで担保されているか追う） |
| 消込済後の非干渉 | phase-refund.md 2〜3項の手動ステータス変更後、後続でCS Webhookが届いても
  （例えば試験環境ツールの誤操作等）注文が意図せず巻き戻らないか |
| **後退防止ガードの対象外ステータス（2026-07-29実機再現済み）** | `paygent_update_status_webhook()` の後退防止ガード（PR #31）は `pending/on-hold/processing/completed` の4状態を順序付けた `$base_status` 配列のみを参照しており、**`cancelled`・`refunded` はこの配列に含まれない**。そのためキャンセル済み・払い戻し済みの注文が入金完了通知（消込済40等）を受けると、後退防止が効かず無条件で `processing` へ戻る（→ phase-refund.md 4項で必ず実地確認する）。CS固有ではなく全決済Webhook共通の設計ギャップ |
| Subscriptions/継続課金 | CSは継続課金非対応。誤って `_Addon_` 系クラスやSubscriptions関連フックが
  `paygent_cs` 注文に反応する分岐がないか |

問題が疑われる箇所は `file:line` 付きでレポートに記載する（このフェーズでは修正しない）。

## 2. ブラウザ側の実地確認（各フェーズ実行中に随時）

1. phase-payment.md・phase-refund.md で作成した**全注文**について、管理画面の
   注文詳細で支払い方法表示が「コンビニ決済」のままであることを確認する
   （申込直後・試験環境ツールでのステータス更新後・手動ステータス変更後の
   いずれも）。
2. 消込済（processing）へ遷移した注文について、Webhook到達直後に
   `payment_method` や `_paygent_cvs_id` 等のCS固有メタが書き換わっていないか
   注文詳細で確認する。
3. 異常があれば再現手順・注文番号・スクリーンショットを記録する。
