<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for WC_Paygent_Block_MB.
 */
class BlockMBTest extends TestCase {

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
		Functions\when( 'wp_enqueue_style' )->justReturn( null );

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
		require_once self::BLOCK_DIR . 'class-wc-paygent-block-mb.php';
	}

	/**
	 * Initialize a block with the given MB settings.
	 *
	 * @param array $settings Gateway settings returned for the settings option.
	 * @return \WC_Paygent_Block_MB
	 */
	private function block_with_settings( array $settings ): \WC_Paygent_Block_MB {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) use ( $settings ) {
				if ( 'woocommerce_paygent_mb_settings' === $option ) {
					return $settings;
				}
				return $default;
			}
		);

		$block = new \WC_Paygent_Block_MB();
		$block->initialize();
		return $block;
	}

	public function test_script_handle_returned(): void {
		$block   = new \WC_Paygent_Block_MB();
		$handles = $block->get_payment_method_script_handles();
		$this->assertContains( 'wc-paygent-block-mb', $handles );
	}

	public function test_supported_features_include_refunds(): void {
		$block = new \WC_Paygent_Block_MB();
		$this->assertSame( array( 'products', 'refunds' ), $block->get_supported_features() );
	}

	public function test_all_carriers_enabled(): void {
		$block = $this->block_with_settings(
			array(
				'setting_ct_04' => 'yes',
				'setting_ct_05' => 'yes',
				'setting_ct_06' => 'yes',
			)
		);

		$ids = array_column( $block->get_payment_method_data()['carrierTypes'], 'id' );
		$this->assertSame( array( '04', '05', '06' ), $ids );
	}

	public function test_only_enabled_carriers_are_returned(): void {
		$block = $this->block_with_settings(
			array(
				'setting_ct_04' => 'yes',
				'setting_ct_05' => 'no',
				'setting_ct_06' => 'yes',
			)
		);

		$ids = array_column( $block->get_payment_method_data()['carrierTypes'], 'id' );
		$this->assertSame( array( '04', '06' ), $ids );
	}

	public function test_no_carriers_when_all_flags_disabled(): void {
		$block = $this->block_with_settings( array( 'enabled' => 'yes' ) );

		$this->assertSame( array(), $block->get_payment_method_data()['carrierTypes'] );
	}

	public function test_is_active_reflects_enabled_setting(): void {
		$this->assertTrue( $this->block_with_settings( array( 'enabled' => 'yes' ) )->is_active() );
		$this->assertFalse( $this->block_with_settings( array( 'enabled' => 'no' ) )->is_active() );
	}
}
