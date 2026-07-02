# Paygent API通信

## PaygentB2BModule の使い方

```php
use PaygentModule\System\PaygentB2BModule;

$process = new PaygentB2BModule();
$process->init();

// 必須パラメータ
$process->reqPut( 'merchant_id',      $merchant_id );
$process->reqPut( 'connect_id',       $connect_id );
$process->reqPut( 'connect_password', $connect_password );
$process->reqPut( 'telegram_kind',    $telegram_kind );
$process->reqPut( 'telegram_version', '1.0' );

// 決済固有パラメータを追加
foreach ( $send_data as $key => $value ) {
    $process->reqPut( $key, $value );
}

$process->post();

// レスポンス取得
$res_array = array();
while ( $process->hasResNext() ) {
    $res_array[] = $process->resNext();
}

$result = $process->getResultStatus();     // '0'=成功, '1'=エラー, その他=通信異常
$code   = $process->getResponseCode();
$detail = $process->getResponseDetail();   // SJIS エンコード
```

## 文字コード注意

PaygentB2BModuleはSJISで通信する。レスポンス値をログや画面表示する際は必ず変換すること：

```php
mb_convert_encoding( $value, 'UTF-8', 'SJIS' )
```

send_dataのvalueも同様にSJIS→UTF-8変換が必要な場合がある（`mb_convert_encoding($value, 'UTF-8', 'SJIS')`）。

## WC_Gateway_Paygent_Request::send_paygent_request()

全決済クラスから呼ぶ共通ラッパー。ハッシュチェック付与・デバッグログ保存を自動処理する。

```php
$response = $this->paygent_request->send_paygent_request(
    $this->test_mode,  // '1'=テスト, それ以外=本番
    $order,            // WC_Order or null
    $telegram_kind,    // 電文種別コード
    $send_data,        // リクエストパラメータ配列
    $this->debug       // 'yes'|'no'
);

// レスポンス構造
$response['result']         // '0'=成功
$response['responseCode']   // エラーコード
$response['responseDetail'] // エラー詳細（SJIS）
$response['result_array']   // レスポンスデータ配列（$res_array[0]が主データ）
```

## 主要 telegram_kind（電文種別）— 仕様書 v2.8.23 / 各別紙・コード準拠

| コード | 内容 | 決済手段 |
|---|---|---|
| `020` | オーソリ（トークン与信。valid_check_flg で有効性チェック可） | CC |
| `021` | オーソリキャンセル（auth_cancel） | CC |
| `022` | 売上（キャプチャ） | CC |
| `023` | 売上キャンセル（sale_cancel） | CC |
| `028` | 補正オーソリ（auth_change、部分返金） | CC |
| `029` | 補正売上（sale_change、部分返金） | CC |
| `025` | カード情報お預り：カード情報設定 | CC |
| `116` | カード情報お預り：カード情報更新 | CC |
| `026` | カード情報お預り：カード情報削除 | CC |
| `027` | カード情報お預り：カード情報照会 | CC |
| `450` | EMV 3DS2.0 チャレンジフロー（トークン決済別紙） | CC |
| `180`〜`185` | 多通貨カード（オーソリ/取消/売上/売上取消/補正） | MCCC |
| `091` | 決済情報差分照会（全決済共通） | 共通 |
| `094` | 決済情報照会（全決済共通） | 共通 |
| `010` | ATM決済 申込 | ATM |
| `030` | コンビニ決済（番号方式）申込 | CS |
| `040` | コンビニ決済（払込票方式）申込 ※本プラグイン未使用 | CS |
| `060` | 銀行ネット決済ASP 申込 | BN |
| `100`/`101`/`102`/`103`/`104` | キャリア都度課金 申込/売上/取消/補正/ユーザ認証 | MB |
| `120`/`121`/`122`/`124`/`125`/`126` | キャリア継続課金 申込/売上/取消/終了/照会/変更 | MB |
| `270`/`271`/`272`/`273` | 楽天ペイ 申込/売上/取消/補正 | Rakuten |
| `340`/`341`/`342`/`343` | Paidy オーソリキャンセル/売上/返金/決済情報検証 | Paidy |
| `420`/`421`/`422` | PayPay 申込/取消返金/売上 | PayPay |
| `320`〜`325` | Apple Pay オーソリ/取消/売上/売上取消/補正オーソリ/補正売上 | ApplePay |
| `350`〜`355` | Google Pay オーソリ/取消/売上/売上取消/補正オーソリ/補正売上 | GooglePay |

**注意**: 過去のリファレンスにあった 024/031/092/093/200/210/300/310/330/430/440/501/521/551 は誤り。上表が仕様書・実装コードと一致する正しいコード。

## trading_id の決定ロジック

```php
$paygent_order_id = $order->get_meta( '_paygent_order_id' );
if ( $paygent_order_id ) {
    $send_data['trading_id'] = $paygent_order_id;
} elseif ( $this->prefix_order ) {
    $send_data['trading_id'] = $this->prefix_order . $order->get_id();
} else {
    $send_data['trading_id'] = 'wc_' . $order_id;
}
```

`_paygent_order_id` は決済完了後にPaygentから返却されたtrading_idを保存したもの（初回申込時はWC側で生成）。
