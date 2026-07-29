<?php
/**
 * Abstract Paygent Block Payment Method Integration
 *
 * @package WooCommerce_Paygent_Payment
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for all Paygent Block checkout payment method integrations.
 *
 * Reads gateway settings directly from the database without instantiating
 * the gateway class, keeping initialization lightweight.
 *
 * Subclasses must set $name (the gateway ID) and implement
 * get_payment_method_script_handles().
 */
abstract class Abstract_WC_Paygent_Block_Payment extends AbstractPaymentMethodType {
	// $settings is declared in AbstractPaymentMethodType; do not redeclare.

	/**
	 * Loads gateway settings from the database.
	 *
	 * @return void
	 */
	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Returns true when the gateway is enabled.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return filter_var( $this->settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Returns data passed to the payment method's JavaScript component via getSetting().
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->settings['title'] ?? $this->get_default_gateway_title(),
			'description' => wp_kses_post( $this->settings['description'] ?? '' ),
			'supports'    => $this->get_supported_features(),
		);
	}

	/**
	 * Fallback title for stores whose settings option has never been saved
	 * from the admin form (no 'title' key stored yet). Without this the Block
	 * checkout would render an empty payment method label.
	 *
	 * Read from the registered gateway instance, which resolves the
	 * form-field default — gateways are already loaded when Block checkout
	 * data is built, so this adds no extra initialization cost.
	 *
	 * @return string
	 */
	protected function get_default_gateway_title(): string {
		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return '';
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways[ $this->name ] ) ) {
			return '';
		}
		return (string) $gateways[ $this->name ]->get_title();
	}

	/**
	 * Returns the features supported by this gateway in Block checkout context.
	 *
	 * @return string[]
	 */
	public function get_supported_features(): array {
		return array( 'products' );
	}

	/**
	 * Attaches the bundled i18n/ JSON translations to a block script handle.
	 *
	 * Call after wp_register_script(). When no bundled JSON exists for the
	 * current locale, WordPress falls back to the language pack in WP_LANG_DIR.
	 *
	 * @param string $handle Registered script handle.
	 * @return void
	 */
	protected function set_script_translations( string $handle ): void {
		wp_set_script_translations(
			$handle,
			'woocommerce-for-paygent-payment-main',
			WC_PAYGENT_ABSPATH . 'i18n'
		);
	}
}
