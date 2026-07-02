# コンビニ決済

## 概要

番号方式のコンビニ決済（telegram_kind `030`）。申込完了後に顧客に受付番号を発行し、コンビニで支払う。

## クラスの主要プロパティ

```php
class WC_Gateway_Paygent_CS extends WC_Payment_Gateway {
    public $cs_stores;          // 利用可能コンビニ配列（cvs_company_id => 表示名）
    public $cs_slip_label;      // 支払い番号のラベル名（コンビニごとに異なる）
    public $payment_limit_date; // 支払い期限（日数、0〜60）
}
```

## コンビニ企業CD（cvs_company_id）

管理画面設定（`setting_cs_se` 等）で有効化したコンビニのみ `cs_stores` に入る。

| コード | コンビニ名 | 支払い番号ラベル |
|---|---|---|
| `00C001` | セブン-イレブン | 払込票番号（Payment slip number） |
| `00C002` | ローソン | お客様番号（Customer number） |
| `00C004` | ミニストップ | お客様番号 |
| `00C005` | ファミリーマート | （企業CDにより異なる） |
| `00C014` | デイリーヤマザキ | |
| `00C016` | セイコーマート | |

## process_payment() の基本フロー

```php
$telegram_kind = '030'; // コンビニ決済（番号方式）申込

$send_data = array(
    'trading_id'           => 'wc_' . $order_id, // prefix設定時は prefix + order_id
    'payment_amount'       => $order->get_total(),
    'customer_family_name' => mb_convert_encoding( $order->get_billing_last_name(), 'SJIS', 'UTF-8' ),
    'customer_name'        => mb_convert_encoding( $order->get_billing_first_name(), 'SJIS', 'UTF-8' ),
    'customer_tel'         => str_replace( '-', '', $order->get_billing_phone() ),
    'payment_limit_date'   => $this->payment_limit_date, // 日数をそのまま送信
    'cvs_company_id'       => $this->get_post( 'cvs_company_id' ), // チェックアウトで顧客が選択
    'sales_type'           => 1, // 先払い（出荷前入金）
);

$response = $this->paygent_request->send_paygent_request( ... );

if ( '0' === $response['result'] ) {
    // 受付番号等をオーダーメタに保存
    $order->add_meta_data( '_paygent_cvs_id', $cvs_id, true );
    $order->add_meta_data( '_paygent_receipt_number', $result_array[0]['receipt_number'], true );
    $order->add_meta_data( '_paygent_payment_limit_date', $result_array[0]['payment_limit_date'], true );
    if ( '00C001' === $cvs_id ) { // セブン-イレブンのみ
        $order->add_meta_data( '_paygent_receipt_print_url', $result_array[0]['receipt_print_url'], true );
    }
    $order->set_transaction_id( $result_array[0]['payment_id'] );
    $order->update_status( 'on-hold', $message ); // 入金待ち
}
```

## 支払い情報の表示

```php
// woocommerce_thankyou_ / メール / view_order で表示
$cvs_id         = $order->get_meta( '_paygent_cvs_id', true );
$receipt_number = $order->get_meta( '_paygent_receipt_number', true );
$limit_date     = $order->get_meta( '_paygent_payment_limit_date', true );
$print_url      = $order->get_meta( '_paygent_receipt_print_url', true );
```

**結果URL情報（receipt_print_url）の注意 — 仕様書 v2.8.21（2026/04/01）追記**:
Webページへの埋め込み表示（iframeタグ等）は不可。URLの内容が表示されなかったりエラーになることがあるため、必ずリンクとして提示する。外部サイトのため予告なく終了する可能性もある。

## 入金確認（Webhook）

`paygent_cv_webhook()` が `payment_status` で分岐:
`10`申込済→on-hold、`12`支払期限切→cancelled、`40`消込済（入金完了）→processing、
`43`速報検知済→processing、`61`速報取消済→cancelled。

## コンビニチケット発券

`2025docs/コンビニ決済+番号方式+コンビニチケット発券サービス.pdf`
バーコード形式での支払い番号提供（追加オプション）。

## ドキュメント参照

`2025docs/コンビニ決済+番号方式.pdf`
`2025docs/system/モジュールタイプ/02_PG外部インターフェース仕様説明書.pdf`（1.2.10 コンビニ決済（番号方式）申込電文）
