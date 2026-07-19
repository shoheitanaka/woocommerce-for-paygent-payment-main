<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for WC_Paygent_Block_Paidy.
 */
class BlockPaidyTest extends TestCase {

	private const BLOCK_DIR  = __DIR__ . '/../../includes/gateways/paygent/includes/block/';
	private const PLUGIN_URL = 'http://localhost/wp-content/plugins/paygent/';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'WC_PAYGENT_ABSPATH' ) ) {
			define( 'WC_PAYGENT_ABSPATH', dirname( __DIR__, 2 ) . '/' );
		}
		if ( ! defined( 'WC_PAYGENT_PLUGIN_URL' ) ) {
			define( 'WC_PAYGENT_PLUGIN_URL', self::PLUGIN_URL );
		}
		if ( ! defined( 'WC_PAYGENT_VERSION' ) ) {
			define( 'WC_PAYGENT_VERSION', '2.4.8' );
		}
	}

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( null );

		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( '
				namespace Automattic\WooCommerce\Blocks\Payments\Integrations;
				abstract class AbstractPaymentMethodType {
					protected $name;
					protected $settings = array();
					abstract public function initialize(): void;
					abstract public function is_active(): bool;
					abstract public function get_payment_method_script_handles(): array;
					abstract public function get_payment_method_data(): array;
				}
			' );
		}

		require_once self::BLOCK_DIR . 'class-abstract-wc-paygent-block-payment.php';
		require_once self::BLOCK_DIR . 'class-wc-paygent-block-paidy.php';
	}

	/**
	 * Initialize a block with the given Paidy settings.
	 *
	 * @param array $settings Gateway settings returned for the settings option.
	 * @return \WC_Paygent_Block_Paidy
	 */
	private function block_with_settings( array $settings ): \WC_Paygent_Block_Paidy {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) use ( $settings ) {
				if ( 'woocommerce_paygent_paidy_settings' === $option ) {
					return $settings;
				}
				return $default;
			}
		);

		$block = new \WC_Paygent_Block_Paidy();
		$block->initialize();
		return $block;
	}

	public function test_script_handle_returned(): void {
		$block   = new \WC_Paygent_Block_Paidy();
		$handles = $block->get_payment_method_script_handles();
		$this->assertContains( 'wc-paygent-block-paidy', $handles );
	}

	public function test_supported_features_include_refunds(): void {
		$block = new \WC_Paygent_Block_Paidy();
		$this->assertSame( array( 'products', 'refunds' ), $block->get_supported_features() );
	}

	public function test_icon_url_uses_shared_payment_label_key(): void {
		$block = $this->block_with_settings( array() );
		$data  = $block->get_payment_method_data();

		$this->assertArrayHasKey( 'icon_url', $data );
		$this->assertStringEndsWith( 'assets/images/paidy_logo_100_2023.png', $data['icon_url'] );
	}

	public function test_paidy_description_is_sanitized_with_kses(): void {
		Functions\when( 'wp_kses_post' )->alias(
			static function ( $value ) {
				return '[kses]' . $value;
			}
		);
		$block = $this->block_with_settings( array( 'paidy_description' => '<p>paidy</p>' ) );

		$this->assertSame( '[kses]<p>paidy</p>', $block->get_payment_method_data()['paidyDescription'] );
	}
}
