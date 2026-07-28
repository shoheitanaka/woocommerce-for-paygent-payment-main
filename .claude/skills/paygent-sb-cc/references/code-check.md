# Phase code-check — 支払い方法の勝手な移行がないかのチェック

依頼項目8に対応。過去事例: Block Checkout（Store API）のドラフト注文再利用により、
注文完了後に `payment_method` が別の決済（例: キャリア決済）へ上書きされるバグがあった
（MBで発生・`set_cart_hash('')` で修正済み。CLAUDE.md「リダイレクト型決済の注意」参照）。

ブラウザ試験と静的チェックの両面で確認する。

## 1. 静的コードチェック（ローカルリポジトリに対して実施）

対象: `class-wc-gateway-paygent-cc.php` / `class-wc-gateway-paygent-addon-cc.php` /
`includes/gateways/paygent/includes/block/` のCC系クラス
（`class-wc-paygent-block-cc.php` / `class-wc-paygent-block-mccc.php` /
`class-abstract-wc-paygent-block-payment.php`） / `class-wc-gateway-paygent-request.php`

| 観点 | 確認内容 |
| --- | --- |
| set_payment_method | CC系コードに注文の `set_payment_method()` / `payment_method` 上書きがないか。あれば呼び出し条件が正当か |
| ドラフト注文再利用 | 3DS2リダイレクト（receipt経由）で外部認証へ出る間、Store API/クラシックのドラフト再利用で payment_method が上書きされる余地がないか（`set_cart_hash` の扱い） |
| thankyou/リダイレクト復帰 | `tds2_status_change()`（thankyouフック）が payment_method に触れていないか。他ゲートウェイの thankyou/receipt フックが `paygent_cc` 注文に対して発火しない条件になっているか |
| 更新注文 | Subscriptionsの更新注文が親の支払い方法（paygent_cc）を引き継ぐか |
| Webhook | `class-wc-paygent-endpoint.php` がCC注文のステータス/支払い方法を誤って書き換えないか |

問題が疑われる箇所は `file:line` 付きでレポートに記載する（このフェーズでは修正しない）。

## 2. ブラウザ側の実地確認（各フェーズ実行中に随時）

1. 各フェーズで作成した**全注文**について、管理画面の注文詳細で支払い方法表示が
   「クレジットカード決済」のままであることを確認する（作成直後と、返金等の操作後）。
2. 3DS2チャレンジ画面から**戻らずに放置/ブラウザバック**した注文が、その後別の決済で
   再チェックアウトされた場合に、元の注文の支払い方法が書き換わらないことを1回確認する:
   - チャレンジ画面で中断 → カートに戻る → 別決済（有効なら銀行振込等の標準決済）で
     注文 → 新しい注文が別番号で作成され、中断した注文の payment_method が
     `paygent_cc` のままであること。
3. 異常があれば再現手順・注文番号・スクリーンショットを記録する。
