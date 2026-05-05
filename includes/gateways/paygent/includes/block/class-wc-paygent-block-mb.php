<?php
/**
 * Block checkout integration for the Paygent Mobile Carrier gateway.
 *
 * @package WooCommerce_Paygent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Paygent_Block_MB
 *
 * Registers `paygent_mb` as a Block payment method and provides
 * the enabled carrier list to the React selector component.
 */
class WC_Paygent_Block_MB extends Abstract_WC_Paygent_Block_Payment {

	/**
	 * Gateway identifier.
	 *
	 * @var string
	 */
	protected $name = 'paygent_mb';

	/**
	 * Register the compiled block bundle and shared selector CSS.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		if ( ! wp_script_is( 'wc-paygent-block-mb', 'registered' ) ) {
			$asset_file = WC_PAYGENT_ABSPATH . 'build/paygent-mb.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: array(
					'dependencies' => array(),
					'version'      => WC_PAYGENT_VERSION,
				);

			wp_register_script(
				'wc-paygent-block-mb',
				WC_PAYGENT_PLUGIN_URL . 'build/paygent-mb.js',
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

		return array( 'wc-paygent-block-mb' );
	}

	/**
	 * Builds the carrier list from gateway settings.
	 *
	 * Mirrors the logic in WC_Gateway_Paygent_MB::__construct() that populates
	 * $career_types based on individual setting flags.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		$settings = $this->settings;
		$carriers = array();

		if ( 'yes' === ( $settings['setting_ct_04'] ?? 'no' ) ) {
			$carriers[] = array(
				'id'    => '04',
				'label' => __( 'au Easy Payment', 'woocommerce-for-paygent-payment-main' ),
			);
		}
		if ( 'yes' === ( $settings['setting_ct_05'] ?? 'no' ) ) {
			$carriers[] = array(
				'id'    => '05',
				'label' => __( 'Docomo Payment', 'woocommerce-for-paygent-payment-main' ),
			);
		}
		if ( 'yes' === ( $settings['setting_ct_06'] ?? 'no' ) ) {
			$carriers[] = array(
				'id'    => '06',
				'label' => __( 'SoftBank Matomete Payment(B)', 'woocommerce-for-paygent-payment-main' ),
			);
		}

		return array_merge(
			parent::get_payment_method_data(),
			array( 'carrierTypes' => $carriers )
		);
	}

	/**
	 * Features supported by the MB gateway in Block checkout.
	 *
	 * @return string[]
	 */
	public function get_supported_features(): array {
		return array( 'products', 'refunds' );
	}
}
