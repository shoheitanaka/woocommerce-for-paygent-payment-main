---
name: paygent-bank
description: >
  Paygent銀行・コンビニ系決済スキル。仮想口座決済（ATM）・銀行ネット決済・口座振替・
  コンビニ決済（番号方式・チケット発券）・電子マネー（WebMoney）をカバー。
  「paygent_atm」「WC_Gateway_Paygent_ATM」「paygent_cs」「WC_Gateway_Paygent_CS」
  「paygent_bn」「WC_Gateway_Paygent_BN」「仮想口座」「コンビニ」「銀行ネット」「口座振替」
  などのキーワードで発動。
compatibility: >
  WooCommerce 9.0+ / WordPress 6.7+ / PHP 8.2+。
---

# Paygent 銀行・コンビニ系決済

## このスキルを使う場面

- コンビニ決済の実装・修正（`class-wc-gateway-paygent-cs.php`）
- 仮想口座（ATM）決済の実装・修正（`class-wc-gateway-paygent-atm.php`）
- 銀行ネット決済の実装・修正（`class-wc-gateway-paygent-bn.php`）
- 支払い期限・支払い番号の表示
- 入金確認Webhook処理
- 口座振替（direct debit）の実装

## ファイル構成

```
includes/gateways/paygent/
├── class-wc-gateway-paygent-cs.php   ← コンビニ決済
├── class-wc-gateway-paygent-atm.php  ← 仮想口座（ATM）
└── class-wc-gateway-paygent-bn.php   ← 銀行ネット
```

## ゲートウェイID

| 決済手段 | ID | クラス |
|---|---|---|
| コンビニ決済 | `paygent_cs` | `WC_Gateway_Paygent_CS` |
| 仮想口座（ATM） | `paygent_atm` | `WC_Gateway_Paygent_ATM` |
| 銀行ネット | `paygent_bn` | `WC_Gateway_Paygent_BN` |

## 主要 telegram_kind（仕様書 v2.8.23 準拠）

| コード | 決済 | 内容 |
|---|---|---|
| `010` | ATM決済 | 申込（本プラグインの「仮想口座（ATM）」ゲートウェイが使用） |
| `030` | コンビニ（番号方式） | 申込 |
| `040` | コンビニ（払込票方式） | 申込 ※本プラグイン未使用 |
| `060` | 銀行ネット決済ASP | 申込 |
| `070` | 仮想口座決済 | 申込 ※010とは別サービス。本プラグイン未使用 |
| `150`/`152`/`153` | 電子マネー | 申込/取消/補正売上 ※本プラグイン未使用 |
| `094` | 全決済共通 | 照会 |

**注意**: 口座振替は別紙PDFの専用電文（本プラグイン未実装）。「040=銀行ネット」「060=口座振替」という旧記載は誤り。

## ATM（010）申込 主要パラメータ

| パラメータ名 | 必須 | 内容 |
|---|---|---|
| `payment_amount` | ○ | 決済金額 |
| `payment_detail` | ○ | 明細内容（SJIS変換必要） |
| `payment_detail_kana` | ▲ | 明細内容カナ（SJIS変換必要） |
| `payment_limit_date` | ○ | 支払い期限（取引発生日からの日数、0〜60日） |

応答は収納機関方式：`pay_center_number`（収納機関番号）, `customer_number`（お客様番号）,
`conf_number`（確認番号）, `payment_limit_date`。それぞれ `_pay_center_number` 等のオーダーメタに保存。

## コンビニ（030）申込 主要パラメータ

| パラメータ名 | 必須 | 内容 |
|---|---|---|
| `payment_amount` | ○ | 決済金額 |
| `customer_family_name` / `customer_name` | ○ | 姓・名（SJIS変換必要） |
| `customer_tel` | ○ | 電話番号（ハイフン除去） |
| `cvs_company_id` | ○ | コンビニ企業CD |
| `payment_limit_date` | ○ | 支払い期限（取引発生日からの日数、0〜60日） |
| `sales_type` | ○ | 1=先払い（出荷前入金） |

### コンビニ企業CD（cvs_company_id）

| コード | コンビニ名 |
|---|---|
| `00C001` | セブン-イレブン |
| `00C002` | ローソン |
| `00C004` | ミニストップ |
| `00C005` | ファミリーマート |
| `00C014` | デイリーヤマザキ |
| `00C016` | セイコーマート |

### 030 応答の主要フィールド

- `receipt_number`: 受付番号（`_paygent_receipt_number` に保存）
- `payment_limit_date`: 支払期限（`_paygent_payment_limit_date` に保存）
- `receipt_print_url`: 結果URL情報（セブン-イレブン `00C001` のみ `_paygent_receipt_print_url` に保存）

**v2.8.21（2026/04/01）追記**: 結果URL情報（`receipt_print_url`）はWebページへの埋め込み表示（iframe等）不可。リンクとして提示すること。

## 共通特徴

- 後払い型：決済申込後、顧客が後日コンビニ/ATM/銀行で支払い
- WooCommerceのオーダーステータスは申込後「保留（on-hold）」
- 入金完了はWebhook（`POST /wp-json/paygent/v1/check`）で受信

## 入金確認Webhook

通知には `trading_id` / `payment_id` / `payment_status` / `payment_type` が含まれる。
`payment_type`: 01=ATM, 02=カード, 03=コンビニ番号方式, 05=銀行ネット, 06=キャリア, 17=楽天ペイ, 22=Paidy, 26=PayPay。

`WC_Paygent_Endpoint::paygent_check_webhook()` がオーダーを特定し、決済別ハンドラでステータス更新：

| payment_status | コンビニ（03） | 銀行ネット（05） |
|---|---|---|
| `10` 申込済 | on-hold | on-hold |
| `12` 支払期限切 | cancelled | - |
| `15` 申込中断 | - | cancelled |
| `40` 消込済（入金完了） | processing | processing |
| `43` 速報検知済 | processing | - |
| `61` 速報取消済 | cancelled | - |

（旧記載の「`payment_status=30`で`payment_complete()`」は誤り。入金完了は `40`＝消込済。）

詳細は [convenience-store.md](references/convenience-store.md) と [bank-payments.md](references/bank-payments.md) を参照。
