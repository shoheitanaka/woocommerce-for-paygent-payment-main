<?php

namespace Paygent\Tests\Integration;

/**
 * Regression tests for payment_id meta handling on the thankyou page.
 *
 * mb_thankyou() runs on every thankyou page view (reloads included) and used
 * add_meta_data(), which persisted duplicate payment_id rows when requests
 * overlapped. It now uses update_meta_data() so repeated runs keep one row.
 */
class MbThankyouPaymentIdMetaTest extends TestCase {

	/** @var \WC_Gateway_Paygent_MB */
	private $gateway;

	/** @var \WC_Order */
	private $order;

	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\WC_Gateway_Paygent_MB' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/class-wc-gateway-paygent-mb.php';
		}

		$this->gateway = new \WC_Gateway_Paygent_MB();
		// Pin the payment-action setting so the assertion below does not depend
		// on options another test may have saved.
		$this->gateway->update_status = 'no';
		$this->order                  = $this->create_test_order( 'paygent_mb', 55 );

		$_GET['payment_id'] = '48325797';
	}

	public function tearDown(): void {
		unset( $_GET['payment_id'] );
		$this->delete_test_order( $this->order );
		parent::tearDown();
	}

	public function test_thankyou_saves_single_payment_id_meta_across_repeated_views(): void {
		$this->gateway->mb_thankyou( $this->order->get_id() );

		// Simulate a page reload: a fresh order instance runs the hook again.
		$this->gateway->mb_thankyou( $this->order->get_id() );

		$reloaded  = wc_get_order( $this->order->get_id() );
		$meta_rows = $reloaded->get_meta( 'payment_id', false );

		$this->assertCount( 1, $meta_rows, 'payment_id meta must not be duplicated by repeated thankyou views.' );
		$this->assertSame( '48325797', $meta_rows[0]->value );
		$this->assertSame( '48325797', $reloaded->get_transaction_id() );
		$this->assertTrue( $reloaded->has_status( 'processing' ) );
	}
}
