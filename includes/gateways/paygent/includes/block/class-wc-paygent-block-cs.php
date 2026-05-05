<?php
/**
 * Block checkout integration for the Paygent Convenience Store gateway.
 *
 * @package WooCommerce_Paygent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Paygent_Block_CS
 *
 * Registers `paygent_cs` as a Block payment method and provides
 * the enabled convenience store list to the React selector component.
 */
class WC_Paygent_Block_CS extends Abstract_WC_Paygent_Block_Payment {

	/**
	 * Gateway identifier.
	 *
	 * @var string
	 */
	protected $name = 'paygent_cs';

	/**
	 * Register the compiled block bundle and shared selector CSS.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		if ( ! wp_script_is( 'wc-paygent-block-cs', 'registered' ) ) {
			$asset_file = WC_PAYGENT_ABSPATH . 'build/paygent-cs.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: array(
					'dependencies' => array(),
					'version'      => WC_PAYGENT_VERSION,
				);

			wp_register_script(
				'wc-paygent-block-cs',
				WC_PAYGENT_PLUGIN_URL . 'build/paygent-cs.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
		}

		if ( function_exists( 'wp_enqueue_block_style' ) ) {
			wp_enqueue_block_style(
				'woocommerce/checkout',
				array(
					'handle' => 'wc-paygent-block-select',
					'src'    => WC_PAYGENT_PLUGIN_URL . 'assets/css/paygent-block-select.css',
					'ver'    => WC_PAYGENT_VERSION,
					'path'   => WC_PAYGENT_ABSPATH . 'assets/css/paygent-block-select.css',
				)
			);
		} else {
			wp_enqueue_style(
				'wc-paygent-block-select',
				WC_PAYGENT_PLUGIN_URL . 'assets/css/paygent-block-select.css',
				array(),
				WC_PAYGENT_VERSION
			);
		}

		return array( 'wc-paygent-block-cs' );
	}

	/**
	 * Builds the convenience store list from gateway settings.
	 *
	 * Mirrors the logic in WC_Gateway_Paygent_CS::__construct() that populates
	 * $cs_stores based on individual setting flags.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		$settings = $this->settings;
		$stores   = array();

		if ( 'yes' === ( $settings['setting_cs_se'] ?? 'no' ) ) {
			$stores[] = array(
				'id'    => '00C001',
				'label' => __( 'Seven Eleven', 'woocommerce-for-paygent-payment-main' ),
			);
		}
		if ( 'yes' === ( $settings['setting_cs_lm'] ?? 'no' ) ) {
			$stores[] = array(
				'id'    => '00C002',
				'label' => __( 'Lawson', 'woocommerce-for-paygent-payment-main' ),
			);
			$stores[] = array(
				'id'    => '00C004',
				'label' => __( 'Mini Stop', 'woocommerce-for-paygent-payment-main' ),
			);
		}
		if ( 'yes' === ( $settings['setting_cs_f'] ?? 'no' ) ) {
			$stores[] = array(
				'id'    => '00C005',
				'label' => __( 'Family Mart', 'woocommerce-for-paygent-payment-main' ),
			);
		}
		if ( 'yes' === ( $settings['setting_cs_sm'] ?? 'no' ) ) {
			$stores[] = array(
				'id'    => '00C016',
				'label' => __( 'Seicomart', 'woocommerce-for-paygent-payment-main' ),
			);
		}
		if ( 'yes' === ( $settings['setting_cs_ctd'] ?? 'no' ) ) {
			$stores[] = array(
				'id'    => '00C014',
				'label' => __( 'Daily Yamazaki', 'woocommerce-for-paygent-payment-main' ),
			);
		}

		return array_merge(
			parent::get_payment_method_data(),
			array( 'csStores' => $stores )
		);
	}

	/**
	 * Features supported by the CS gateway in Block checkout.
	 *
	 * @return string[]
	 */
	public function get_supported_features(): array {
		return array( 'products', 'refunds' );
	}
}
