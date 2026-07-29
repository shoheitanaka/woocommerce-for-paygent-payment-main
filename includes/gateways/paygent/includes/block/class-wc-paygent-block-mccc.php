<?php
/**
 * Block checkout integration for the Paygent Multi-Currency Credit Card gateway.
 *
 * @package WooCommerce_Paygent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Paygent_Block_MCCC
 *
 * Registers `paygent_mccc` as a Block payment method.
 *
 * MCCC (telegram_kind 180) is structurally identical to the standard CC
 * gateway (telegram_kind 020) but processes transactions in the customer's
 * billing currency. It reuses the CC card form UI with the `paygent_mccc`
 * field prefix and always applies a single (one-time) payment class.
 *
 * Inherits merchant ID / token key lookup and saved-card data from
 * WC_Paygent_Block_CC.  Payment method (installment) options are
 * excluded because MCCC always sets payment_class = 10 server-side.
 */
class WC_Paygent_Block_MCCC extends WC_Paygent_Block_CC {

	/**
	 * Gateway identifier.
	 *
	 * @var string
	 */
	protected $name = 'paygent_mccc';

	/**
	 * Register PaygentToken.js, CC CSS, and the MCCC-specific JS bundle.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		// PaygentToken.js — same external library as CC.
		if ( ! wp_script_is( 'paygent-token-js', 'registered' ) ) {
			$token_js_url = '1' === get_option( 'wc-paygent-testmode' )
				? 'https://sandbox.paygent.co.jp/js/PaygentToken.js'
				: 'https://token.paygent.co.jp/js/PaygentToken.js';

			wp_register_script(
				'paygent-token-js',
				$token_js_url,
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				false // Load in <head>.
			);
		}

		// Reuse CC CSS (identical card form styles).
		if ( function_exists( 'wp_enqueue_block_style' ) ) {
			wp_enqueue_block_style(
				'woocommerce/checkout',
				array(
					'handle' => 'wc-paygent-block-cc',
					'src'    => WC_PAYGENT_PLUGIN_URL . 'assets/css/paygent-block-cc.css',
					'ver'    => WC_PAYGENT_VERSION,
					'path'   => WC_PAYGENT_ABSPATH . 'assets/css/paygent-block-cc.css',
				)
			);
		} else {
			wp_enqueue_style(
				'wc-paygent-block-cc',
				WC_PAYGENT_PLUGIN_URL . 'assets/css/paygent-block-cc.css',
				array(),
				WC_PAYGENT_VERSION
			);
		}

		if ( ! wp_script_is( 'wc-paygent-block-mccc', 'registered' ) ) {
			$asset_file = WC_PAYGENT_ABSPATH . 'build/paygent-mccc.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: array(
					'dependencies' => array(),
					'version'      => WC_PAYGENT_VERSION,
				);

			wp_register_script(
				'wc-paygent-block-mccc',
				WC_PAYGENT_PLUGIN_URL . 'build/paygent-mccc.js',
				array_merge( $asset['dependencies'], array( 'paygent-token-js' ) ),
				$asset['version'],
				true
			);
		}

		$this->set_script_translations( 'wc-paygent-block-mccc' );

		return array( 'wc-paygent-block-mccc' );
	}

	/**
	 * Returns CC-compatible payment data, minus installment options.
	 *
	 * MCCC's process_payment() always sets payment_class = 10 (one-time),
	 * so paymentMethods and numberOfPayments are not needed on the client.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		$data = parent::get_payment_method_data();
		unset( $data['paymentMethods'] );
		unset( $data['numberOfPayments'] );

		return $data;
	}

	/**
	 * Features supported by the MCCC gateway in Block checkout.
	 *
	 * Matches WC_Gateway_Paygent_MCCC::$supports — unlike CC, the MCCC
	 * gateway does not support WooCommerce Subscriptions.
	 *
	 * @return string[]
	 */
	public function get_supported_features(): array {
		return array( 'products', 'refunds', 'tokenization' );
	}
}
