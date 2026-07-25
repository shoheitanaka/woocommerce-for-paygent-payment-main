<?php

namespace Paygent\Tests\Integration;

/**
 * Tests for paygent_cart_cancel() authentication.
 *
 * The cancellation return URL is public and the trading_id is guessable, so
 * the order key carried on cancel_url must be verified before cancelling.
 * Cancellation is accepted from pending and on-hold (the application webhook
 * may advance the order before the customer returns), never without a valid
 * key.
 */
class MbCartCancelAuthTest extends TestCase {

	/** @var \WC_Gateway_Paygent_MB */
	private $gateway;

	/** @var \WC_Order */
	private $order;

	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\WC_Gateway_Paygent_MB' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/class-wc-gateway-paygent-mb.php';
		}
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! WC()->session ) {
			$this->markTestSkipped( 'Session unavailable in this environment.' );
		}

		$this->gateway = new \WC_Gateway_Paygent_MB();
		$this->order   = $this->create_test_order( 'paygent_mb', 55 );
		$this->order->update_meta_data( '_career_type', 'docomo' );
		$this->order->save();

		$_GET['mb_cancel']  = 'yes';
		$_GET['trading_id'] = 'wcpg' . $this->order->get_id();
	}

	public function tearDown(): void {
		unset( $_GET['mb_cancel'], $_GET['trading_id'], $_GET['order_id'], $_GET['key'] );
		wc_clear_notices();
		$this->delete_test_order( $this->order );
		parent::tearDown();
	}

	public function test_cancel_with_valid_key_cancels_pending_order(): void {
		$_GET['key'] = $this->order->get_order_key();

		$this->gateway->paygent_cart_cancel();

		$this->assertTrue( wc_get_order( $this->order->get_id() )->has_status( 'cancelled' ) );
	}

	public function test_cancel_with_valid_key_cancels_on_hold_order(): void {
		$this->order->update_status( 'on-hold' );
		$_GET['key'] = $this->order->get_order_key();

		$this->gateway->paygent_cart_cancel();

		$this->assertTrue( wc_get_order( $this->order->get_id() )->has_status( 'cancelled' ) );
	}

	public function test_cancel_with_invalid_key_is_rejected(): void {
		$_GET['key'] = 'wc_order_invalidkey123';

		$this->gateway->paygent_cart_cancel();

		$this->assertTrue( wc_get_order( $this->order->get_id() )->has_status( 'pending' ), 'A guessed trading_id without the order key must not cancel the order.' );
	}

	public function test_cancel_without_key_is_rejected(): void {
		$this->gateway->paygent_cart_cancel();

		$this->assertTrue( wc_get_order( $this->order->get_id() )->has_status( 'pending' ) );
	}

	public function test_cancel_url_carries_the_order_id_and_key(): void {
		$send_data = $this->gateway->set_send_data( array(), $this->order->get_id() );

		$this->assertStringContainsString( 'mb_cancel=yes', $send_data['cancel_url'] );
		$this->assertStringContainsString( 'order_id=' . $this->order->get_id(), $send_data['cancel_url'] );
		$this->assertStringContainsString( 'key=' . $this->order->get_order_key(), $send_data['cancel_url'] );
	}

	public function test_cancel_with_order_id_ignores_subscription_trading_id(): void {
		// Subscription checkouts send a trading_id derived from the
		// subscription, so the checkout order must be located via the
		// order_id carried on cancel_url.
		$_GET['trading_id'] = 'wcpg999999';
		$_GET['order_id']   = (string) $this->order->get_id();
		$_GET['key']        = $this->order->get_order_key();

		$this->gateway->paygent_cart_cancel();

		$this->assertTrue( wc_get_order( $this->order->get_id() )->has_status( 'cancelled' ) );
	}
}
