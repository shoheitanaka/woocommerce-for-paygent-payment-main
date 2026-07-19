# ATM決済・銀行ネット・口座振替

## ATM決済（プラグイン上の「仮想口座（ATM）」）

Pay-easy（ペイジー）方式。収納機関番号・お客様番号・確認番号を発行し、銀行/郵便局のATMやネットバンキングで支払う。

```php
class WC_Gateway_Paygent_ATM extends WC_Payment_Gateway {
    public $payment_detail;       // 明細内容
    public $payment_detail_kana;  // 明細内容カナ
    public $payment_limit_date;   // 支払い期限（日数、0〜60）
}
```

```php
// 申込 telegram_kind: '010'（ATM決済申込）
$send_data = array(
    'trading_id'          => 'wc_' . $order_id,
    'payment_amount'      => $order->get_total(),
    'payment_limit_date'  => $this->payment_limit_date, // 日数をそのまま送信
    'payment_detail'      => mb_convert_encoding( $this->payment_detail, 'SJIS', 'UTF-8' ),
    'payment_detail_kana' => mb_convert_encoding( $this->payment_detail_kana, 'SJIS', 'UTF-8' ),
);

// レスポンス（収納機関方式）— オーダーメタに保存して表示に使う
$pay_center_number = $response['result_array'][0]['pay_center_number']; // 収納機関番号 → _pay_center_number
$customer_number   = $response['result_array'][0]['customer_number'];   // お客様番号   → _customer_number
$conf_number       = $response['result_array'][0]['conf_number'];       // 確認番号     → _conf_number
```

※ 仕様書の「1.2.19. 仮想口座決済申込電文（電文種別ID=070）」は口座番号を発行する別サービスで、本プラグインでは未使用。

### ドキュメント

`2025docs/仮想口座決済/導入補足資料（仮想口座決済）_包括契約.pdf`
`2025docs/仮想口座決済/導入補足資料（仮想口座決済）_直接契約.pdf`

---

## 銀行ネット決済

ネットバンキング経由の即時決済。リダイレクト型。

```php
// telegram_kind: '060' 銀行ネット決済ASP申込
// → リダイレクト → 金融機関選択・ネットバンキング画面 → コールバック
// 入金完了はWebhookの payment_status '40'（消込済）で受信 → processing へ
```

### ドキュメント

`2025docs/銀行ネット決済.pdf`

---

## 口座振替決済

顧客の銀行口座から定期的に引き落とす方式。

- モジュールタイプ: `2025docs/口座振替決済/02_PG外部インターフェース仕様説明書（別紙：口座振替）.pdf`
- リンクタイプ: `2025docs/口座振替決済/02_リンクタイプインターフェース仕様説明書（別紙：口座振替受付）.pdf`
- 導入: `2025docs/口座振替決済/導入補足資料（口座振替決済）.pdf`

---

## 電子マネー（WebMoney）

`2025docs/電子マネー決済（WebMoney）.pdf`

---

## Webhook経由の入金確認

後払い系（コンビニ・ATM）は入金後にPaygentからWebhook（決済情報差分通知）が送信される：

```
POST /wp-json/paygent/v1/check
trading_id=wc_123&payment_id=xxx&payment_status=40&payment_type=03
```

`WC_Paygent_Endpoint::paygent_check_webhook()` でオーダーを特定し、`payment_type` 別の
ハンドラ（`paygent_cv_webhook()` / `paygent_bn_webhook()` 等）でステータスを更新する。
入金完了は `payment_status=40`（消込済）で、オーダーは「processing」になる。
