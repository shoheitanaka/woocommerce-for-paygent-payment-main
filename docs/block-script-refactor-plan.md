# Block スクリプト登録の重複解消リファクタ 実装計画

作成日: 2026-07-19
状態: 未着手（PR #22 マージ後に着手すること）
種別: 純リファクタ（挙動変更なし）
対象: `includes/gateways/paygent/includes/block/` 配下の全 Block クラス

> **実行方法（opusplan）**: Claude Code で `/model opusplan` を設定し、
> 「`docs/block-script-refactor-plan.md` に従ってリファクタを実装して」と依頼する。
> プランモードで本ファイルを読み込ませて実装計画を確定 → 承認後に実装、の流れを想定。
> **前提条件: PR #22 が trunk にマージ済みであること**（このファイル自身が PR #22 に
> 同梱されているため、trunk に存在すれば前提を満たしている）。

---

## 背景

PR #22 のレビューで指摘。`get_payment_method_script_handles()` 内の
「asset.php 読み込み → フォールバック → `wp_register_script()`」のボイラープレートと、
「`wp_enqueue_block_style()` / `wp_enqueue_style()` フォールバック付き CSS enqueue」が
6 クラスにコピーされている。挙動は全クラス同一パターンで、ヘルパー抽出できる。

### 現状の重複マップ

| クラス | JS バンドル | 外部 token JS | CSS |
| --- | --- | --- | --- |
| `WC_Paygent_Block_Redirect` | `paygent-redirect`（1クラス4役共有） | - | - |
| `WC_Paygent_Block_CC` | `paygent-cc` | ○（head 読み込み） | `paygent-block-cc.css` |
| `WC_Paygent_Block_MCCC` | `paygent-mccc` | ○（head 読み込み） | `paygent-block-cc.css`（CCと共有） |
| `WC_Paygent_Block_CS` | `paygent-cs` | - | `paygent-block-select.css` |
| `WC_Paygent_Block_MB` | `paygent-mb` | - | `paygent-block-select.css`（CSと共有） |
| `WC_Paygent_Block_Paidy` | `paygent-paidy` | - | - |

## 修正方針

`Abstract_WC_Paygent_Block_Payment` に protected ヘルパーを 3 つ追加し、各クラスの
`get_payment_method_script_handles()` を呼び替えに置き換える。

```php
/**
 * Register a compiled block bundle (build/{$basename}.js + .asset.php).
 * Keeps the wp_script_is() guard and the asset-file fallback.
 */
protected function register_block_bundle( string $handle, string $basename, array $extra_deps = array() ): void {
    if ( wp_script_is( $handle, 'registered' ) ) {
        return;
    }
    $asset_file = WC_PAYGENT_ABSPATH . 'build/' . $basename . '.asset.php';
    $asset      = file_exists( $asset_file )
        ? require $asset_file
        : array( 'dependencies' => array(), 'version' => WC_PAYGENT_VERSION );

    wp_register_script(
        $handle,
        WC_PAYGENT_PLUGIN_URL . 'build/' . $basename . '.js',
        array_merge( $asset['dependencies'], $extra_deps ),
        $asset['version'],
        true
    );
}

/**
 * Attach a plugin CSS file to the checkout block, with a plain enqueue
 * fallback for WP < 5.9 (wp_enqueue_block_style unavailable).
 */
protected function enqueue_checkout_block_style( string $handle, string $css_filename ): void { ... }

/**
 * Register the external PaygentToken.js (head-loaded, test/live URL switch).
 * Used by CC and MCCC. Returns the handle name.
 */
protected function register_paygent_token_script(): string { ... }
```

呼び替え例（CS の場合）:

```php
public function get_payment_method_script_handles(): array {
    $this->register_block_bundle( 'wc-paygent-block-cs', 'paygent-cs' );
    $this->enqueue_checkout_block_style( 'wc-paygent-block-select', 'paygent-block-select.css' );
    return array( 'wc-paygent-block-cs' );
}
```

## 挙動を変えてはいけないポイント（実装時の必須確認）

- [ ] ハンドル名・依存配列・version 解決（asset.php ハッシュ）・`in_footer` フラグが
      リファクタ前後で完全一致すること
- [ ] PaygentToken.js は **head 読み込み（`in_footer = false`）** を維持
      （`window.PaygentToken` がフォーム mount 前に必要）
- [ ] PaygentToken.js の test/live URL 切替（`wc-paygent-testmode` オプション）を維持
- [ ] CC/MCCC バンドルの依存に `paygent-token-js` を追加する `array_merge` を維持
- [ ] `wp_enqueue_block_style( 'woocommerce/checkout', ... )` の対象ブロック名・
      `path` パラメータ（インライン化用）を維持
- [ ] `WC_Paygent_Block_Redirect` はコンストラクタ引数でゲートウェイ名を受ける
      特殊構造 — ヘルパー化の際に共有ハンドル `wc-paygent-block-redirect` の
      二重登録ガードが壊れないこと

## テスト計画

1. **ユニット**: 既存の Block テスト（BlockRedirect / BlockCC / BlockCS / BlockMB /
   BlockPaidy / BlockMCCC、計158件）が**無変更で**全パスすること。
   テストを書き換えないと通らない場合は挙動が変わっているサイン。
2. **wp-env 実機**（このマシン: `WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8892 npx wp-env start`）:
   - チェックアウトページの HTML で 6 バンドル＋2 CSS のハンドル・URL・ver= が
     リファクタ前と一致すること（`curl` で取得して diff）
   - 手順は 2026-07-19 の検証と同じ: Store API でカート投入 → `/?page_id=604` を取得
3. `npm run build` 不要（JS 変更なし）。`composer phpcs` クリーンであること。

## 受け入れ基準

- [ ] 6 クラスの `get_payment_method_script_handles()` がヘルパー呼び出しのみになる
- [ ] 重複ボイラープレートが抽象クラス 1 箇所に集約される
- [ ] ユニットテスト 158 件が無変更で全パス
- [ ] チェックアウトページのスクリプト/CSS 出力がリファクタ前と完全一致

## 関連

- 発端: PR #22 レビュー（提案2）
- 類似の未着手計画: `docs/mccc-saved-card-plan.md`（Issue #20 — 本リファクタとは
  独立して実施可能だが、同時に MCCC を触る場合はコンフリクトに注意）
