<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for WC_Paygent_Block_MCCC.
 */
class BlockMCCCTest extends TestCase {

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
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

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
		require_once self::BLOCK_DIR . 'class-wc-paygent-block-cc.php';
		require_once self::BLOCK_DIR . 'class-wc-paygent-block-mccc.php';

		if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( 'class WC_Payment_Tokens { public static function get_customer_tokens($user_id, $gateway_id) { return array(); } }' );
		}
	}

	public function test_script_handle_returned(): void {
		$block   = new \WC_Paygent_Block_MCCC();
		$handles = $block->get_payment_method_script_handles();
		$this->assertContains( 'wc-paygent-block-mccc', $handles );
	}

	public function test_supported_features_match_classic_gateway(): void {
		$block = new \WC_Paygent_Block_MCCC();
		$this->assertSame( array( 'products', 'refunds', 'tokenization' ), $block->get_supported_features() );
	}

	public function test_supported_features_do_not_include_subscriptions(): void {
		$block = new \WC_Paygent_Block_MCCC();
		$this->assertNotContains( 'subscriptions', $block->get_supported_features() );
	}

	public function test_installment_data_is_removed(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$block = new \WC_Paygent_Block_MCCC();
		$block->initialize();
		$data = $block->get_payment_method_data();

		$this->assertArrayNotHasKey( 'paymentMethods', $data );
		$this->assertArrayNotHasKey( 'numberOfPayments', $data );
		$this->assertArrayHasKey( 'merchantId', $data );
		$this->assertArrayHasKey( 'tokenKey', $data );
	}

	public function test_save_card_ui_is_enabled_when_store_card_info_is_on(): void {
		Functions\when( 'get_option' )->justReturn( array( 'store_card_info' => 'yes' ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$block = new \WC_Paygent_Block_MCCC();
		$block->initialize();
		$data = $block->get_payment_method_data();

		// Issue #20 fix: the save-card UI is no longer force-disabled now that
		// tokens are saved under gateway_id=paygent_mccc (see class-wc-gateway-paygent-mccc.php).
		$this->assertTrue( $data['enableSaveCard'] );
		// Issue #25: saved cards are rendered natively by WC Blocks and are
		// no longer exposed to the client.
		$this->assertArrayNotHasKey( 'savedCards', $data );
	}
}
