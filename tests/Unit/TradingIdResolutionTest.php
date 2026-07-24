<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for WC_Gateway_Paygent_Request::get_paygent_trading_id()
 *
 * Resolution order:
 *   1. _paygent_order_id meta (the trading_id actually sent on application).
 *   2. Empty string when the telegram also carries a payment_id — Paygent
 *      accepts either ID and requires both to match when both are sent, so
 *      an unknown checkout-time trading_id must not be guessed from the
 *      current prefix option.
 *   3. Rebuild from the prefix option, then 'wc_' + order ID, only when no
 *      payment_id is available (e.g. subscription refunds).
 */
class TradingIdResolutionTest extends TestCase {

	/** @var \WC_Gateway_Paygent_Request */
	private $request;

	protected function setUp(): void {
		parent::setUp();

		// Stub WordPress option functions required by the constructor.
		Functions\when( 'get_option' )->justReturn( '' );

		require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/includes/class-wc-gateway-paygent-request.php';

		$this->request = new \WC_Gateway_Paygent_Request();
	}

	/**
	 * Build a WC_Order stub with a fixed _paygent_order_id meta and ID.
	 */
	private function make_order( string $paygent_order_id, int $id = 822 ): \WC_Order {
		return new class( $paygent_order_id, $id ) extends \WC_Order {
			public function __construct( private string $paygent_order_id, private int $id ) {}
			public function get_meta( string $key ): mixed {
				return '_paygent_order_id' === $key ? $this->paygent_order_id : '';
			}
			public function get_id(): int {
				return $this->id;
			}
		};
	}

	public function test_stored_meta_wins_regardless_of_payment_id(): void {
		$this->request->prefix_order = 'wcpg';
		$order                       = $this->make_order( 'oldprefix822' );

		$this->assertSame( 'oldprefix822', $this->request->get_paygent_trading_id( $order, true ) );
		$this->assertSame( 'oldprefix822', $this->request->get_paygent_trading_id( $order, false ) );
	}

	public function test_missing_meta_with_payment_id_returns_empty_string(): void {
		$this->request->prefix_order = 'wcpg';
		$order                       = $this->make_order( '' );

		// The trading_id must not be rebuilt from the current prefix: the
		// telegram relies on the payment_id alone instead.
		$this->assertSame( '', $this->request->get_paygent_trading_id( $order, true ) );
	}

	public function test_missing_meta_without_payment_id_falls_back_to_prefix(): void {
		$this->request->prefix_order = 'wcpg';
		$order                       = $this->make_order( '' );

		$this->assertSame( 'wcpg822', $this->request->get_paygent_trading_id( $order, false ) );
	}

	public function test_missing_meta_without_payment_id_and_prefix_falls_back_to_wc(): void {
		$this->request->prefix_order = '';
		$order                       = $this->make_order( '' );

		$this->assertSame( 'wc_822', $this->request->get_paygent_trading_id( $order, false ) );
	}

	public function test_has_payment_id_defaults_to_false(): void {
		$this->request->prefix_order = '';
		$order                       = $this->make_order( '' );

		$this->assertSame( 'wc_822', $this->request->get_paygent_trading_id( $order ) );
	}
}
