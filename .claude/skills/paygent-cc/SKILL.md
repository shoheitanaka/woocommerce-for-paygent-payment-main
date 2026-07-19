---
name: paygent-cc
description: >
  Paygentクレジットカード決済スキル。通常カード決済（トークン決済）、EMV 3Dセキュア 2.0、
  継続課金（WooCommerce Subscriptions連携）、多通貨決済、デビット・プリペイド判定、
  カード情報保存（トークナイゼーション）をカバー。
  「paygent_cc」「WC_Gateway_Paygent_CC」「tds2」「3Dセキュア」「継続課金」「トークン決済」
  「クレジットカード」「paygent_mccc」「多通貨」「addon-cc」などのキーワードで発動。
compatibility: >
  WooCommerce 9.0+ / WordPress 6.7+ / PHP 8.2+。
  継続課金はWooCommerce Subscriptions必須。
  EMV 3DS2はPaygent側でオプション契約が必要。
---

# Paygent クレジットカード決済

## このスキルを使う場面

- クレジットカード決済フローの実装・修正（`class-wc-gateway-paygent-cc.php`）
- EMV 3Dセキュア 2.0 の実装（`tds2_check`、`paygent_3ds2_redirect_order()`）
- カード情報保存・削除（WooCommerceトークナイゼーション）
- WooCommerce Subscriptions 連携（継続課金）
- 多通貨カード決済（`class-wc-gateway-paygent-mccc.php`）
- デビット・プリペイド判定
- 支払い方法の設定（1回払い/分割/ボーナス/リボ）

## ファイル構成

```
includes/gateways/paygent/
├── class-wc-gateway-paygent-cc.php       ← メインクレジットカード
├── class-wc-gateway-paygent-mccc.php     ← 多通貨クレジットカード
└── class-wc-gateway-paygent-addon-cc.php ← 継続課金アドオン
```

## ゲートウェイID

| クラス | ID |
|---|---|
| `WC_Gateway_Paygent_CC` | `paygent_cc` |
| `WC_Gateway_Paygent_MCCC` | `paygent_mccc` |

## 主要 telegram_kind（仕様書 v2.8.23 準拠）

| コード | 内容 | コード内での用途 |
|---|---|---|
| `020` | CC オーソリ申込（メイン決済電文） | `process_payment()` |
| `021` | CC オーソリキャンセル | `auth_cancel` |
| `022` | CC 売上（出荷売上、キャプチャ） | 完了時売上計上 |
| `023` | CC 売上キャンセル | `sale_cancel` |
| `028` | CC 補正オーソリ（金額変更） | `auth_change` |
| `029` | CC 補正売上（金額変更） | `sale_change` |
| `025` | カード情報お預り：カード情報設定 | カード保存 |
| `116` | カード情報お預り：カード情報更新 | （洗替） |
| `026` | カード情報お預り：カード情報削除 | トークン削除フック |
| `027` | カード情報お預り：カード情報照会 | |
| `450` | EMV 3DS2.0 チャレンジフロー | 3DS2リダイレクト後 |
| `094` | 決済情報照会 | ステータス確認 |

## 020 オーソリ申込 — 主要パラメータ

| パラメータ名 | 必須 | 内容 |
|---|---|---|
| `card_token` | △ | JSトークンライブラリで生成したカードトークン |
| `stock_card_mode` | ▲ | 0=通常, 1=カード情報保存 |
| `customer_id` | △ | 保存カード使用時の顧客ID（25byte） |
| `customer_card_id` | △ | 保存カードID（18byte） |
| `3dsecure_use_type` | ▲ | 1=3DS1, 2=EMV3DS2.0 |
| `3ds_auth_id` | △ | EMV3DS認証ID（36byte） |
| `sales_mode` | ▲ | 0=オーソリのみ, 1=即時売上 |
| `payment_class` | ▲ | 10=1回払い, 23=ボーナス, 61=分割, 80=リボ |
| `payment_amount` | ○ | 決済金額 |
| `valid_check_flg` | ▲ | 1=有効性チェックオーソリ（v2.8.23で追加。決済金額は無視され0円/1円で実施） |

**重要**: 2017年4月以降契約の加盟店はカード番号の直接送信不可。必ずトークン（card_token）または保存カード（customer_card_id）を使用。

**有効性チェックフラグ（v2.8.23追加）**: `valid_check_flg=1` の場合、決済ステータスは正常時「有効性確認済（22）」/異常時「有効性確認NG（17）」となる。ステータス22の決済に対して売上・取消処理は実施できない。カード情報設定電文（025）にも同名フラグあり。

## 020 オーソリ応答 — 主要フィールド

| フィールド | 内容 |
|---|---|
| `result` | 0=正常, 1=異常, 7=3Dオーソリ必要 |
| `payment_id` | 決済ID |
| `fingerprint` | 継続課金用フィンガープリント |
| `masked_card_number` | マスクされたカード番号 |
| `3dsecure_message_version` | 3DSメッセージバージョン |

result=7 の場合は EMV 3DS2.0フローへ移行。

## カード決済ステータス（payment_status）— v2.8.23 準拠

| 値 | ステータス | | 値 | ステータス |
|---|---|---|---|---|
| `10` | 申込済 | | `32` | オーソリ取消済 |
| `11` | オーソリNG | | `33` | オーソリ期限切 |
| `17` | 有効性確認NG | | `40` | 消込済（売上済） |
| `20` | オーソリOK | | `41` | 消込済（売上取消期限切）※v2.8.22で30→41に変更 |
| `21` | オーソリ完了 | | `42` | 売上取消中 |
| `22` | 有効性確認済 | | `60` | 売上取消済 |
| `31` | オーソリ取消中 | | | |

**PIF自動キャンセル（v2.8.23追記）**: ブランドルール（PIF）対応適用済み加盟店では、オーソリOK（20）のままVISA/Mastercardは25日経過でPaygentが自動オーソリキャンセルし「オーソリ取消済（32）」へ遷移する（他ブランドは自動キャンセルなし）。カード会社との処理が正常完了しなかった場合は「オーソリ期限切（33）」へ遷移。PIF未適用の加盟店は従来どおり60日で「オーソリ期限切（33）」。**オーソリ後に長期間売上計上しない運用（auth設定）では、32/33への自動遷移を考慮すること。**

## 支払い方法コード（payment_class）

| コード | 支払い方法 |
|---|---|
| `10` | 1回払い |
| `61` | 分割払い |
| `23` | ボーナス一括 |
| `80` | リボルビング |

## supports（クレジットカード）

```php
$this->supports = array(
    'subscriptions',
    'products',
    'subscription_cancellation',
    'subscription_reactivation',
    'subscription_suspension',
    'subscription_amount_changes',
    'subscription_payment_method_change_customer',
    'subscription_payment_method_change_admin',
    'subscription_date_changes',
    'multiple_subscriptions',
    'tokenization',
    'refunds',
    'default_credit_card_form',
);
```

詳細は各referencesファイルを参照：
- [token-payment.md](references/token-payment.md) — トークン決済フロー
- [3ds2.md](references/3ds2.md) — EMV 3Dセキュア 2.0
- [subscription.md](references/subscription.md) — 継続課金
- [multi-currency.md](references/multi-currency.md) — 多通貨・デビット判定
