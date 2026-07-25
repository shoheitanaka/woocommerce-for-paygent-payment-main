<?php

namespace Paygent\Tests\Integration;

/**
 * Regression tests for the Block Checkout draft-order reuse bug (carrier payment).
 *
 * Store API reuses a pending order as a checkout draft when the order's cart
 * hash matches the current cart (DraftOrderTrait::is_valid_draft_order), which
 * overwrote payment_method with the currently selected gateway while the
 * customer was away on the carrier authentication page. As a result the
 * woocommerce_receipt_paygent_mb hook never fired on return and telegram 100
 * was never sent. payment_mb_process() must clear the order's cart hash before
 * redirecting so neither Store API nor classic checkout reuses the order.
 */
class MbCartHashClearTest extends TestCase {

	/** @var \WC_Gateway_Paygent_MB */
	private $gateway;

	/** @var \WC_Order */
	private $order;

	/** @var object Fake request client capturing sent telegrams. */
	private $fake_request;

	public function setUp(): void {
		parent::setUp();

		// The gateway class is only loaded by the plugin when the wc-paygent-mb
		// option is enabled, so load it directly for the test.
		if ( ! class_exists( '\WC_Gateway_Paygent_MB' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/class-wc-gateway-paygent-mb.php';
		}

		$this->gateway = new \WC_Gateway_Paygent_MB();

		// Extends the real request class so inherited methods (e.g.
		// order_paygent_status_completed) stay callable from constructor-registered
		// hooks that outlive this test.
		$this->fake_request = new class() extends \WC_Gateway_Paygent_Request {
			public $sent     = array();
			public $response = array();
			public function send_paygent_request( $test_mode, $order, $telegram_kind, $send_data, $debug = 'yes' ) {
				$this->sent[] = array(
					'kind' => $telegram_kind,
					'data' => $send_data,
				);
				return $this->response;
			}
			public function error_response( $response, $order ) {}
		};

		$this->gateway->paygent_request = $this->fake_request;

		$this->order = $this->create_test_order( 'paygent_mb', 5000 );
		$this->order->set_cart_hash( 'abc123carthash' );
		$this->order->save();
	}

	public function tearDown(): void {
		$this->delete_test_order( $this->order );
		parent::tearDown();
	}

	private function base_send_data( int $career_type ): array {
		return array(
			'payment_id'     => null,
			'trading_id'     => 'wcpaygent' . $this->order->get_id(),
			'amount'         => 5000,
			'career_type'    => $career_type,
			'pc_mobile_type' => 0,
			'other_url'      => 'https://example.com/other',
		);
	}

	// -------------------------------------------------------------------------
	// SoftBank path (telegram 100, direct application)
	// -------------------------------------------------------------------------

	public function test_sb_application_success_clears_cart_hash(): void {
		$this->fake_request->response = array(
			'result'       => '0',
			'result_array' => array(
				array( 'redirect_url' => 'https://example.com/pay' ),
			),
		);

		$result = $this->gateway->payment_mb_process( $this->order, $this->base_send_data( 6 ), '100' );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( '100', $this->fake_request->sent[0]['kind'] );

		$reloaded = wc_get_order( $this->order->get_id() );
		$this->assertSame( '', $reloaded->get_cart_hash(), 'Cart hash must be cleared so the pending order is not reused as a checkout draft.' );
		$this->assertSame( 'paygent_mb', $reloaded->get_payment_method() );
		$this->assertTrue( $reloaded->has_status( 'pending' ) );
	}

	// -------------------------------------------------------------------------
	// Docomo path without open_id (telegram 104, user authentication redirect)
	// -------------------------------------------------------------------------

	public function test_docomo_auth_redirect_clears_cart_hash(): void {
		$this->fake_request->response = array(
			'result'       => '0',
			'result_array' => array(
				array( 'redirect_html' => '<form action="https://example.com/auth"></form>' ),
			),
		);

		$result = $this->gateway->payment_mb_process( $this->order, $this->base_send_data( 5 ), '100' );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( '104', $this->fake_request->sent[0]['kind'] );

		$reloaded = wc_get_order( $this->order->get_id() );
		$this->assertSame( '', $reloaded->get_cart_hash(), 'Cart hash must be cleared before redirecting to carrier authentication.' );
		$this->assertSame( 'docomo', $reloaded->get_meta( '_career_type', true ) );
		$this->assertTrue( $reloaded->has_status( 'pending' ) );
	}

	// -------------------------------------------------------------------------
	// Accepted application without a redirect field restores the cart hash
	// -------------------------------------------------------------------------

	public function test_sb_application_without_redirect_restores_cart_hash(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Cart hash test product' );
		$product->set_regular_price( '100' );
		$product->save();
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! WC()->cart ) {
			$product->delete( true );
			$this->markTestSkipped( 'Cart unavailable in this environment.' );
		}
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id() );
		$expected_hash = WC()->cart->get_cart_hash();
		$this->assertNotSame( '', $expected_hash );

		// Accepted (result 0) but neither redirect_url nor redirect_html.
		$this->fake_request->response = array(
			'result'       => '0',
			'result_array' => array( array( 'trading_id' => 'wcpg' . $this->order->get_id() ) ),
		);

		$result = $this->gateway->payment_mb_process( $this->order, $this->base_send_data( 6 ), '100' );

		$this->assertSame( 'failure', $result['result'] );
		$reloaded = wc_get_order( $this->order->get_id() );
		$this->assertSame( $expected_hash, $reloaded->get_cart_hash(), 'The cart hash must be restored so checkout can resume this order.' );

		WC()->cart->empty_cart();
		$product->delete( true );
	}

	// -------------------------------------------------------------------------
	// Failure path keeps the cart hash so checkout can resume the order
	// -------------------------------------------------------------------------

	public function test_sb_application_failure_keeps_cart_hash(): void {
		$this->fake_request->response = array(
			'result'       => '1',
			'result_array' => array(),
		);

		$result = $this->gateway->payment_mb_process( $this->order, $this->base_send_data( 6 ), '100' );

		$this->assertSame( 'failure', $result['result'] );

		$reloaded = wc_get_order( $this->order->get_id() );
		$this->assertSame( 'abc123carthash', $reloaded->get_cart_hash(), 'Failed applications should keep the cart hash so the order can be resumed at checkout.' );
	}
}
