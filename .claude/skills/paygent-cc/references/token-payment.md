# クレジットカード トークン決済フロー

## 概要

カード番号をPaygentのJavaScriptトークンライブラリで直接Paygentサーバーへ送信し、
マーチャントサイトはカード番号に触れずにトークンのみを受け取る方式（PCI DSS簡素化）。

## トークン取得（フロントエンド）

```php
// paygent_token_scripts_method() でJSを読み込み
public function paygent_token_scripts_method() {
    if ( is_checkout() ) {
        // Paygentのトークンライブラリを enqueue
        wp_enqueue_script( 'paygent-token', ... );
    }
}
```

フォームからのカード情報送信をインターセプトし、PaygentのAPIでトークン化してhidden fieldに格納する。

## process_payment() の基本フロー

```php
public function process_payment( $order_id ) {
    $order = wc_get_order( $order_id );

    // telegram_kind: '020' = トークン与信（3DS2チャレンジ後の認証実行は '450'）
    $telegram_kind = '020';

    // トークンはチェックアウトフォームの 'paygent_cc-token' から取得し、
    // '_paygent_card_token' オーダーメタにも保存される
    $card_token = $this->jp4wc_framework->get_post( 'paygent_cc-token' );

    $send_data = array(
        'trading_id'     => 'wc_' . $order_id,
        'payment_amount' => $order->get_total(),
        'payment_class'  => $this->payment_method, // 10=1回払い等
        'card_token'     => $card_token,
    );

    // カード保存時（set_stored_card() 内で設定）
    // $send_data['stock_card_mode'] = 1;            // カード情報保存
    // $send_data['customer_id']     = $card_user_id; // 顧客ID
    // 保存済みカード利用時は card_token を unset し
    // $send_data['customer_card_id'] と $send_data['card_set_method'] = 'token' を設定

    $response = $this->paygent_request->send_paygent_request(
        $this->test_mode, $order, $telegram_kind, $send_data, $this->debug
    );

    if ( '0' === $response['result'] ) {
        $order->set_transaction_id( $response['result_array'][0]['payment_id'] );
        $order->payment_complete();
        return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
    }

    $this->paygent_request->error_response( $response, $order );
    return array( 'result' => 'failure', 'redirect' => wc_get_checkout_url() );
}
```

## カード保存（トークナイゼーション）— カード情報お預り機能

```php
// カード情報設定（登録） telegram_kind: '025'（valid_check_flg で有効性チェック可）
// カード情報更新（洗替）  telegram_kind: '116'
// カード情報削除          telegram_kind: '026'
// カード情報照会          telegram_kind: '027'

// カード削除フック
add_action( 'woocommerce_payment_token_deleted', array( $this, 'paygent_delete_card' ), 10, 2 );
// → telegram_kind '026' で customer_id / customer_card_id を指定して削除
```

登録時は `customer_id`（WPユーザーに紐づくID）を指定し、応答の `customer_card_id` を
WooCommerceのペイメントトークンとして保存する。決済時は `customer_card_id` + `card_set_method='token'` を送信。

## セキュリティ注意事項

- カード番号・CVVはサーバーに届かない（PCI DSS SAQ A対応）
- `$_POST['paygent_token']` は必ず `sanitize_text_field()` でサニタイズ
- デバッグログにトークン値が記録されないよう注意
- `store_card_info` 設定でカード保存機能を加盟店側でON/OFF可能
