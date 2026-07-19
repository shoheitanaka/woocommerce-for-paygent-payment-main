# MCCC 保存カード機能の不整合修正 実装計画

作成日: 2026-07-19
状態: 未着手（Block Checkout 対応完了後に別ブランチで対応）
対象ゲートウェイ: `paygent_mccc`（多通貨クレジットカード）＋一部 `paygent_cc`

> **実行方法（opusplan）**: Claude Code で `/model opusplan` を設定し、
> 「`docs/mccc-saved-card-plan.md` に従って MCCC 保存カード修正を実装して」と依頼する。
> プランモードで本ファイルを読み込ませて実装計画を確定 → 承認後に実装、の流れを想定。
> 実装前に必ず「事前確認チェックリスト」を消化すること。

---

## 背景・現状の問題

2026-07-19 の `feature/block-cs-mb-paidy` レビュー時に発見。
検証環境: WP 7.0.2 / WC 10.9.4 / 本プラグイン 2.4.8。

### 問題1【中】: 保存チェックボックスの値が無視される（常時保存）

`WC_Gateway_Paygent_MCCC::process_payment()`（`class-wc-gateway-paygent-mccc.php:396` 付近）:

```php
if ( is_user_logged_in() && 'yes' === $this->store_card_info ) {
    $set_login = true;
    // → paygent_save_card_info の値に関係なく add_stored_user_data() でPaygentへカード保存
```

- Block JS（`src/blocks/paygent-mccc/index.js`）は `paygent_save_card_info: 'yes'/'no'` を送るが、MCCC ゲートウェイはこのキーを一切参照しない
- 管理設定 `store_card_info=yes` かつログイン中なら、ユーザーが保存を拒否してもPaygent側にカードが保存される
- CC 側（`class-wc-gateway-paygent-cc.php:735` 付近）は `$user_wants_save_card` を判定しており、この実装が正解形

### 問題2【中】: トークンの gateway_id が `paygent_cc` になり保存カードUIが永久に空

- MCCC は `$this->paygent_cc->add_stored_user_data()`（CCインスタンス経由）を呼ぶ
- `add_stored_user_data()` 内部（`class-wc-gateway-paygent-cc.php:1535` 付近）で `$token->set_gateway_id( $this->id )` → **常に `paygent_cc`** で WC トークンが保存される
- 一方、保存カードの読み出しは:
  - Block: `WC_Paygent_Block_CC::get_payment_method_data()` → `get_customer_tokens( $user_id, 'paygent_mccc' )`
  - クラシック: `WC_Gateway_Paygent_MCCC::payment_fields()` → `get_customer_tokens( $user_id, $this->id )`
- どちらも `paygent_mccc` でフィルタするため **savedCards は常に空**。Block/クラシック両方で保存カード選択UIが機能していない
- 副作用: MCCC で保存したカードが CC の保存カード一覧に出現する

### 問題3【低】: `validate_fields()` が旧キー `saveinfo` を参照（死にコード）

`class-wc-gateway-paygent-mccc.php:738`: `get_post( 'saveinfo' )` — Block/クラシックとも現在は `paygent_save_card_info` を送るため、このガード（アカウントなし保存の拒否）は機能していない。

### 問題4【低・付随】: トークン削除フックが gateway_id を確認していない

- CC: `paygent_delete_card`（`class-wc-gateway-paygent-cc.php:279` で登録）
- MCCC: `paygent_mccc_delete_card`（`class-wc-gateway-paygent-mccc.php:178` で登録）
- 両方とも `woocommerce_payment_token_deleted` に登録され、**`$token->get_gateway_id()` をチェックせずに** Paygent のカード削除APIを呼ぶ
- 影響: (a) 他社ゲートウェイ（Stripe等）のトークン削除でも Paygent 削除APIが飛ぶ、(b) CC/MCCC 両方有効時は同一トークン削除で削除APIが2回呼ばれる

### 補足事実（調査済み・2026-07-19時点）

- MCCC の 3DS2 コールバック `tds2_status_change()`（`class-wc-gateway-paygent-mccc.php:687`）は保存処理を持たない。カード保存は `process_payment()` 内の**3DS2リクエスト前**に実行される（CC の `_paygent_save_card_preference` メタによるコールバック後保存とは経路が異なる）
- MCCC の保存カード決済は `customer_card_id`（Paygent側ID）+ CVCトークンで行う。Block JS は `stored-info` に `customerCardId` を送る実装済み
- Paygent 側のカード保存はテレグラム `025`（顧客カード追加）。`customer_id` は `'wc' . $user_id` で CC と共通（site_id 単位で共有）

---

## 修正方針

### Phase 1: `add_stored_user_data()` に gateway_id を渡せるようにする

**ファイル**: `includes/gateways/paygent/class-wc-gateway-paygent-cc.php`

```php
public function add_stored_user_data( $user_id, $card_token, $test_mode, $debug, $order = null, $token_gateway_id = null ) {
    ...
    $token->set_gateway_id( $token_gateway_id ?? $this->id );
```

- 引数追加はデフォルト値付きで後方互換を維持（既存の CC / Addon_CC / paygent_tds_add_stored_card 呼び出しは無変更で動作）
- MCCC からは `'paygent_mccc'` を明示的に渡す

### Phase 2: MCCC の保存条件を CC と同等にする

**ファイル**: `includes/gateways/paygent/class-wc-gateway-paygent-mccc.php`（`process_payment()` 内）

CC の実装（`class-wc-gateway-paygent-cc.php:735` 付近）をベースに:

```php
$user_wants_save_card = ( 'yes' === sanitize_text_field( wp_unslash( $_POST['paygent_save_card_info'] ?? '' ) ) );
$using_stored_card    = ( $this->jp4wc_framework->get_post( 'paygent-use-stored-payment-info' ) === 'yes' );
$set_login            = false;
if ( is_user_logged_in() && 'yes' === $this->store_card_info && ( $user_wants_save_card || $using_stored_card ) ) {
    $set_login = true;
    if ( $using_stored_card ) {
        $send_data['customer_card_id'] = $this->jp4wc_framework->get_post( 'stored-info' );
    } else {
        $stored_user_card_data         = $this->paygent_cc->add_stored_user_data( $card_user_id, $card_token, $this->test_mode, $this->debug, $order, $this->id );
        $send_data['customer_card_id'] = $stored_user_card_data['result_array'][0]['customer_card_id'];
    }
    ...
}
```

- MCCC はサブスク非対応なので CC にある `$subscription` 分岐は不要
- 3DS2（tds2_check=yes）でも保存はリクエスト前に行われる現行経路を維持（挙動変更しない）

### Phase 3: `validate_fields()` の旧キー整理

**ファイル**: `class-wc-gateway-paygent-mccc.php:738`

`saveinfo` → `paygent_save_card_info` に置換（CC 側にも同パターンがあれば同時に確認）。

### Phase 4: トークン削除フックに gateway_id ガード追加

**ファイル**: CC `paygent_delete_card` / MCCC `paygent_mccc_delete_card` の両方

```php
if ( $token->get_gateway_id() !== $this->id ) {
    return;
}
```

- 注意: Phase 1 適用前に保存された「MCCC 経由だが gateway_id=paygent_cc のトークン」は CC 側ハンドラが削除することになる（Paygent 側 customer_card_id は共通なので実害なし）

### Phase 4.5: Block 保存カード UI の再有効化

2026-07-19 の PR #22 レビュー対応で、`WC_Paygent_Block_MCCC::get_payment_method_data()` が
`enableSaveCard = false` / `savedCards = []` を強制する暫定措置を入れた
（機能しない保存チェックボックスを出さないため）。Phase 1〜3 の完了後に
この強制上書きを削除し、Block の保存カード UI を再有効化すること。

### Phase 5: 既存データの移行（**移行しない**方針を推奨）

- 既存の MCCC 経由保存カードは `gateway_id=paygent_cc` のトークンとして存在し、CC 経由保存分と**判別する手段がない**（customer_card_id は Paygent 側で共通管理）
- よって一括移行はせず、以下のみ実施:
  - リリースノート/changelog に「MCCC の保存カードは再登録が必要」と明記
  - 既存トークンは CC の保存カードとして引き続き利用可能（同一マーチャント・同一 customer_id のため決済自体は成立する）

---

## 事前確認チェックリスト（実装開始前に必ず）

- [ ] Paygent サンドボックスで MCCC（telegram 180）が有効なマーチャント設定か確認（`wc-paygent-test-mid` = 41078 で MCCC 利用可否）
- [ ] テレグラム `025`（カード保存）が MCCC 契約でも同一 site_id で使えるか仕様書確認（`2025docs/` の多通貨決済PDF）
- [ ] 保存カード決済時の MCCC リクエストで `customer_card_id` + `card_cvc_token` の組み合わせが仕様通りか確認（`/paygent-cc` スキル参照）
- [ ] CC の `set_stored_card()` が MCCC からも呼ばれている（`class-wc-gateway-paygent-mccc.php:415`）— `set_login` の意味と 180 電文への影響を仕様書で確認
- [ ] Addon_CC（サブスク）が `add_stored_user_data()` を呼ぶ箇所の引数互換を確認

## テスト計画

1. **ユニット**: 保存条件判定のケース網羅（チェックON/OFF × ログイン有無 × store_card_info設定 × 保存カード利用）
2. **統合（wp-env）**: 決済後の WC トークンが `gateway_id=paygent_mccc` で作成されること／CC 一覧に出ないこと
3. **サンドボックス実決済**（`npm run e2e` 系 or 手動、Block・クラシック両方）:
   - 新規カード＋保存チェックON → 決済成功・トークン作成・次回チェックアウトで savedCards 表示
   - 新規カード＋保存チェックOFF → 決済成功・トークン**非**作成・Paygent 側にも保存されない
   - 保存カード選択＋CVC → 決済成功
   - tds2_check=yes での上記一式
   - マイアカウント > お支払い方法からのカード削除 → Paygent 削除API呼び出し（1回のみ）
   - 他ゲートウェイのトークン削除で Paygent API が呼ばれないこと
4. **回帰**: CC（Block/クラシック、保存カード、3DS2、サブスク）の既存 E2E がグリーンであること

## 受け入れ基準

- [ ] 保存チェックOFFで決済したとき、Paygent にも WooCommerce にもカードが保存されない
- [ ] 保存チェックONで決済すると `gateway_id=paygent_mccc` のトークンが作成され、Block/クラシック両方の保存カードUIに表示される
- [ ] 保存カード＋CVCで決済が成功する
- [ ] トークン削除は自ゲートウェイのトークンのみ処理する（CC/MCCC とも）
- [ ] CC の全既存テスト（unit 137 / integration 38 / E2E）がパスする

## 関連

- レビュー記録: 2026-07-19 `feature/block-cs-mb-paidy` レビュー（WP 7.0.2 / WC 10.9.4 検証）
- GitHub Issue: [artisanworkshop/woocommerce-for-paygent-payment-main#20](https://github.com/artisanworkshop/woocommerce-for-paygent-payment-main/issues/20)
- 参考実装: `class-wc-gateway-paygent-cc.php` の `$user_wants_save_card` / `_paygent_save_card_preference` 周り
