---
name: paygent-digital
description: >
  Paygentデジタル決済スキル。PayPay・Paidy・楽天ペイ・Apple Pay・Google Pay・Alipay国際決済・
  銀聯ネット決済をカバー。これらはすべてリダイレクト型決済（woocommerce_receipt_フック）。
  「paygent_paypay」「WC_Gateway_Paygent_PayPay」「paygent_paidy」「paygent_rakuten」
  「PayPay」「Paidy」「楽天ペイ」「Apple Pay」「Google Pay」「Alipay」「銀聯」
  などのキーワードで発動。
compatibility: >
  WooCommerce 9.0+ / WordPress 6.7+ / PHP 8.2+。
  各デジタル決済はPaygent側で個別に契約が必要。
---

# Paygent デジタル決済（QR・ウォレット系）

## このスキルを使う場面

- PayPay / Paidy / 楽天ペイ / Apple Pay / Google Pay / Alipay / 銀聯 の実装・修正
- リダイレクト型決済フロー（`woocommerce_receipt_`→外部サービス→`woocommerce_thankyou_`）
- 各決済のサンクスページでの結果確認
- 取消・返金処理

## ファイル構成

```
includes/gateways/paygent/
├── class-wc-gateway-paygent-paypay.php       ← PayPay
├── class-wc-gateway-paygent-paidy.php        ← Paidy
└── class-wc-gateway-paygent-rakuten-pay.php  ← 楽天ペイ
```

Apple Pay / Google Pay / Alipay / 銀聯は現時点では個別クラスファイルなし（拡張時に追加予定）。

## ゲートウェイID

| 決済手段 | ID |
|---|---|
| PayPay | `paygent_paypay` |
| Paidy | `paygent_paidy` |
| 楽天ペイ | `paygent_rakuten_pay` |

## 主要 telegram_kind（PDF仕様書準拠）

### PayPay（仕様書 v1.7）

| コード | 内容 |
|---|---|
| `420` | PayPay決済申込 |
| `421` | PayPay取消返金 |
| `422` | PayPay売上（キャプチャ） |

### 楽天ペイ

| コード | 内容 |
|---|---|
| `270` | 楽天ペイ申込 |
| `271` | 楽天ペイ売上 |
| `272` | 楽天ペイ取消 |
| `273` | 楽天ペイ補正（金額変更） |

### Paidy（仕様書 v1.0.6）

| コード | 内容 |
|---|---|
| `340` | Paidyオーソリキャンセル |
| `341` | Paidy売上（確定） |
| `342` | Paidy返金（売上後の返金） |
| `343` | Paidy決済情報検証 |

Paidyの申込はJSチェックアウト（Paidy側のUI）で行われるため申込電文はない。
コード上は processing 時の返金＝340（オーソリキャンセル）、completed 時の返金＝342（返金）。

### Apple Pay

| コード | 内容 |
|---|---|
| `320` | Apple Payオーソリ |
| `321` | Apple Payオーソリキャンセル |
| `322` | Apple Pay売上 |
| `323` | Apple Pay売上キャンセル |
| `324` | Apple Pay補正オーソリ |
| `325` | Apple Pay補正売上 |

### Google Pay

| コード | 内容 |
|---|---|
| `350` | Google Payオーソリ |
| `351` | Google Payオーソリキャンセル |
| `352` | Google Pay売上 |
| `353` | Google Pay売上キャンセル |
| `354` | Google Pay補正オーソリ |
| `355` | Google Pay補正売上 |

**Apple Pay v1.1.4 / Google Pay v1.1.1（2026/06/29）の変更**:
- 売上取消でレスポンス詳細 `1Gxx`（イシュアーエラー）が返るケースを追記
- ブランドルール（PIF）対応適用済み加盟店では、オーソリOK（20）のままVISA/Mastercardは25日経過で自動オーソリキャンセルされ「オーソリ取消済（32）」へ遷移（他ブランドは対象外）。処理が正常完了しなかった場合は「オーソリ期限切（33）」へ。PIF未適用加盟店は60日で「オーソリ期限切（33）」。

### Alipay国際決済

| コード | 内容 |
|---|---|
| `310` | Alipay国際決済申込 |
| `311` | Alipay国際決済取消 |

### 銀聯ネット決済

| コード | 内容 |
|---|---|
| `300` | 銀聯ネット決済申込 |
| `301` | 銀聯ネット決済取消 |

## 共通フロー（リダイレクト型）

```
process_payment()
  → telegram送信（申込）
  → 成功: redirect_html を orderメタに保存
  → woocommerce_receipt_{id} で外部サービスへPOSTリダイレクト
  → 外部決済完了
  → woocommerce_thankyou_{id} で結果確認・ステータス更新
```

## PayPay 420 申込 主要パラメータ

| パラメータ名 | 必須 | 内容 |
|---|---|---|
| `payment_amount` | ○ | 決済金額（7byte） |
| `return_url` | ○ | 購入完了通知URL（512byte）|
| `cancel_url` | ○ | キャンセルURL（512byte） |
| `site_id` | ▲ | サイトID（省略時は基本サイト） |

応答の `redirect_html` はShift_JIS文字セットのFORMタグ文字列。

## PayPay 421 取消返金 主要パラメータ

| パラメータ名 | 必須 | 内容 |
|---|---|---|
| `repayment_amount` | ▲ | 返金金額（省略時は全額返金） |

詳細は [payment-flows.md](references/payment-flows.md) と [cancel-refund.md](references/cancel-refund.md) を参照。
