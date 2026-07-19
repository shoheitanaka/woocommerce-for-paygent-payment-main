<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for WC_Paygent_Block_CS.
 */
class BlockCSTest extends TestCase {

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
		require_once self::BLOCK_DIR . 'class-wc-paygent-block-cs.php';
	}

	/**
	 * Initialize a block with the given CS settings.
	 *
	 * @param array $settings Gateway settings returned for the settings option.
	 * @return \WC_Paygent_Block_CS
	 */
	private function block_with_settings( array $settings ): \WC_Paygent_Block_CS {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) use ( $settings ) {
				if ( 'woocommerce_paygent_cs_settings' === $option ) {
					return $settings;
				}
				return $default;
			}
		);

		$block = new \WC_Paygent_Block_CS();
		$block->initialize();
		return $block;
	}

	public function test_script_handle_returned(): void {
		$block   = new \WC_Paygent_Block_CS();
		$handles = $block->get_payment_method_script_handles();
		$this->assertContains( 'wc-paygent-block-cs', $handles );
	}

	public function test_supported_features_do_not_include_refunds(): void {
		$block = new \WC_Paygent_Block_CS();
		$this->assertSame( array( 'products' ), $block->get_supported_features() );
	}

	public function test_all_stores_enabled(): void {
		$block = $this->block_with_settings(
			array(
				'setting_cs_se'  => 'yes',
				'setting_cs_lm'  => 'yes',
				'setting_cs_f'   => 'yes',
				'setting_cs_sm'  => 'yes',
				'setting_cs_ctd' => 'yes',
			)
		);

		$ids = array_column( $block->get_payment_method_data()['csStores'], 'id' );
		$this->assertSame( array( '00C001', '00C002', '00C004', '00C005', '00C016', '00C014' ), $ids );
	}

	public function test_lawson_flag_adds_lawson_and_ministop(): void {
		$block = $this->block_with_settings( array( 'setting_cs_lm' => 'yes' ) );

		$ids = array_column( $block->get_payment_method_data()['csStores'], 'id' );
		$this->assertSame( array( '00C002', '00C004' ), $ids );
	}

	public function test_no_stores_when_all_flags_disabled(): void {
		$block = $this->block_with_settings( array( 'enabled' => 'yes' ) );

		$this->assertSame( array(), $block->get_payment_method_data()['csStores'] );
	}

	public function test_description_is_sanitized_with_kses(): void {
		Functions\when( 'wp_kses_post' )->alias(
			static function ( $value ) {
				return '[kses]' . $value;
			}
		);
		$block = $this->block_with_settings( array( 'description' => '<p>desc</p>' ) );

		$this->assertSame( '[kses]<p>desc</p>', $block->get_payment_method_data()['description'] );
	}

	public function test_is_active_reflects_enabled_setting(): void {
		$this->assertTrue( $this->block_with_settings( array( 'enabled' => 'yes' ) )->is_active() );
		$this->assertFalse( $this->block_with_settings( array( 'enabled' => 'no' ) )->is_active() );
	}
}
