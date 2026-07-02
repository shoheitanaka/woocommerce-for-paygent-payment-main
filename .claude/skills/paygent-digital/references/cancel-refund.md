# デジタル決済 取消・返金

## PayPay 返金

```php
// process_refund() 内での telegram_array
// 421: PayPay取消返金電文（申込取消・返金共通）
$telegram_array = array(
    'auth_cancel'  => '421', // PayPay 取消返金
    'sale_cancel'  => '421',
    'auth_change'  => '421',
    'sale_change'  => '421',
);
// repayment_amount: 返金金額（省略時は全額）
```

## 楽天ペイ 返金

楽天ペイは `woocommerce_order_status_completed` で売上計上が必要。

```php
// process_refund(): 取消は272（オーソリOK 20 / 消込済 40 の両方に対応、常に全額取消）
// 部分返金は273（補正）だが未実装のためガードでfalseを返す
// 完了時売上計上: order_rakutenpay_status_completed() が271（売上）を送信
// payment_id（transaction_id）と trading_id（_paygent_order_id メタ）を指定
```

**履歴**: 2026-07までこのファイルはPaidy用電文（340/342/341）を誤送信していた
（Paidyクラスからのコピペ）。楽天ペイ別紙仕様書 v1.07 の正しい電文は
**271（売上）/ 272（取消）/ 273（補正）**。2026-07-02に修正済み。

## Paidy 特殊処理

Paidyの返金時はtrading_idに2つのパターンがある（`_paygent_order_id`メタまたは`$order_id`）。
`order_paygent_status_completed()` 内でPaidy専用の再試行ロジックがある。

## payment_status の主要値（仕様書 v2.8.23 準拠）

| 値 | 意味 |
|---|---|
| `10` | 申込済 |
| `11` | オーソリNG |
| `12` | 支払期限切 |
| `15` | 申込中断 |
| `20` | オーソリOK |
| `21` | オーソリ完了 |
| `32` | オーソリ取消済 |
| `33` | オーソリ期限切 |
| `40` | 消込済（売上済） |
| `41` | 消込済（売上取消期限切）※v2.8.22で30→41に変更 |
| `60` | 売上取消済 |

`094`（情報照会）のレスポンスや差分通知Webhookで確認できる。
（旧記載の「20=決済完了, 30=売上計上済み, 40=取消済み, 50=返金済み」は誤り。）
