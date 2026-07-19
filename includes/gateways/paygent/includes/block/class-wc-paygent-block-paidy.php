<?php
/**
 * Block checkout integration for the Paygent Paidy gateway.
 *
 * @package WooCommerce_Paygent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Paygent_Block_Paidy
 *
 * Registers `paygent_paidy` as a Block payment method.
 *
 * Paidy is a redirect-type gateway: after order placement WooCommerce
 * redirects the customer to the Paidy payment page. No custom form
 * is rendered in the checkout block itself.
 */
class WC_Paygent_Block_Paidy extends Abstract_WC_Paygent_Block_Payment {

	/**
	 * Gateway identifier.
	 *
	 * @var string
	 */
	protected $name = 'paygent_paidy';

	/**
	 * Register the compiled block bundle.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		if ( ! wp_script_is( 'wc-paygent-block-paidy', 'registered' ) ) {
			$asset_file = WC_PAYGENT_ABSPATH . 'build/paygent-paidy.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: array(
					'dependencies' => array(),
					'version'      => WC_PAYGENT_VERSION,
				);

			wp_register_script(
				'wc-paygent-block-paidy',
				WC_PAYGENT_PLUGIN_URL . 'build/paygent-paidy.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
		}

		return array( 'wc-paygent-block-paidy' );
	}

	/**
	 * Passes the standard description plus the Paidy-specific description.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		return array_merge(
			parent::get_payment_method_data(),
			array(
				'paidyDescription' => $this->settings['paidy_description'] ?? '',
				'icon_url'         => WC_PAYGENT_PLUGIN_URL . 'assets/images/paidy_logo_100_2023.png',
			)
		);
	}

	/**
	 * Features supported by the Paidy gateway in Block checkout.
	 *
	 * @return string[]
	 */
	public function get_supported_features(): array {
		return array( 'products', 'refunds' );
	}
}
