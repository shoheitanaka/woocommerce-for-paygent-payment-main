<?php

namespace Paygent\Tests\Integration;

/**
 * Regression tests for the on-completion sale request (telegram 101).
 *
 * order_paygent_status_completed() used to require isset( $payment->paymentaction ),
 * but the MB and Paidy gateways have no paymentaction setting, so completing an
 * order never sent the sale (capture) telegram and the Paygent-side status stayed
 * at authorized (20/21). Gateways without paymentaction must send the sale request;
 * gateways that charged at purchase time (paymentaction === 'sale') must not.
 */
class MbStatusCompletedSaleTest extends TestCase {

	/** @var \WC_Gateway_Paygent_MB */
	private $gateway;

	/** @var \WC_Order */
	private $order;

	/** @var object Fake request client capturing sent telegrams. */
	private $fake_request;

	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\WC_Gateway_Paygent_MB' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/class-wc-gateway-paygent-mb.php';
		}

		$this->gateway = new \WC_Gateway_Paygent_MB();

		$this->fake_request = new class() extends \WC_Gateway_Paygent_Request {
			public $sent      = array();
			public $responses = array();
			public function send_paygent_request( $test_mode, $order, $telegram_kind, $send_data, $debug = 'yes' ) {
				$this->sent[] = array(
					'kind' => $telegram_kind,
					'data' => $send_data,
				);
				return $this->responses[ $telegram_kind ] ?? array(
					'result'       => '0',
					'result_array' => array( array() ),
				);
			}
		};

		$this->gateway->paygent_request = $this->fake_request;

		$this->order = $this->create_test_order( 'paygent_mb', 4400 );
		$this->order->set_transaction_id( '48325757' );
		$this->order->update_meta_data( '_paygent_order_id', 'wcpg' . $this->order->get_id() );
		$this->order->update_meta_data( '_career_type', 'docomo' );
		$this->order->save();
	}

	public function tearDown(): void {
		$this->delete_test_order( $this->order );
		parent::tearDown();
	}

	public function test_completed_sends_sale_telegram_for_gateway_without_paymentaction(): void {
		$this->assertFalse(
			property_exists( $this->gateway, 'paymentaction' ) && null !== ( $this->gateway->paymentaction ?? null ),
			'Precondition: MB gateway must not define paymentaction.'
		);

		$this->gateway->order_mb_status_completed( $this->order->get_id() );

		$this->assertSame( array( '094', '101' ), array_column( $this->fake_request->sent, 'kind' ), 'Status is checked first, then the sale telegram is sent.' );
		$this->assertSame( '48325757', $this->fake_request->sent[1]['data']['payment_id'] );
		$this->assertSame( 'wcpg' . $this->order->get_id(), $this->fake_request->sent[1]['data']['trading_id'] );

		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		$this->assertNotEmpty( $notes );
		$this->assertStringContainsString( 'set to sale at Paygent', $notes[0]->content );
	}

	public function test_completed_does_not_rebuild_trading_id_from_prefix(): void {
		// Orders without the application-time trading_id meta must rely on the
		// immutable payment_id alone (empty trading_id), never on a value
		// rebuilt from the current prefix option.
		$this->order->delete_meta_data( '_paygent_order_id' );
		$this->order->save();

		$this->gateway->order_mb_status_completed( $this->order->get_id() );

		$this->assertSame( array( '094', '101' ), array_column( $this->fake_request->sent, 'kind' ) );
		foreach ( $this->fake_request->sent as $telegram ) {
			$this->assertSame( '', $telegram['data']['trading_id'], 'trading_id must be omitted when only payment_id is reliable.' );
			$this->assertSame( '48325757', $telegram['data']['payment_id'] );
		}
	}

	public function test_completed_skips_sale_telegram_when_already_captured(): void {
		// A webhook-driven completion can run after Paygent already captured
		// the payment (e.g. capture from the Paygent admin site).
		$this->fake_request->responses['094'] = array(
			'result'       => '0',
			'result_array' => array( array( 'payment_status' => '40' ) ),
		);

		$this->gateway->order_mb_status_completed( $this->order->get_id() );

		$this->assertSame( array( '094' ), array_column( $this->fake_request->sent, 'kind' ), 'No capture telegram may be sent for an already-captured payment.' );
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		$this->assertStringContainsString( 'already captured at Paygent', $notes[0]->content );
	}

	public function test_failed_capture_moves_order_back_to_on_hold(): void {
		$this->fake_request->responses['101'] = array( 'result' => '1', 'responseCode' => 'P004' );
		$this->fake_request->responses['094'] = array(
			'result'       => '0',
			'result_array' => array( array( 'payment_status' => '21' ) ),
		);

		$this->gateway->order_mb_status_completed( $this->order->get_id() );

		$this->assertSame( array( '094', '101', '094' ), array_column( $this->fake_request->sent, 'kind' ), 'Pre-check, failed capture, then re-check.' );
		$reloaded = wc_get_order( $this->order->get_id() );
		$this->assertTrue( $reloaded->has_status( 'on-hold' ), 'A genuinely uncaptured payment must pull the order back to on-hold.' );
		$notes = array_column( wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) ), 'content' );
		$this->assertStringContainsString( 'moved back to on-hold', implode( "\n", $notes ) );
	}

	public function test_failed_subscription_sale_keeps_status_and_adds_note(): void {
		// 121 uses the subscription inquiry lifecycle (125 / running_id), so a
		// failure must not trigger the one-time 094 re-check nor an on-hold
		// rollback that could re-send 121 for an already-sold period.
		$this->fake_request->responses['121'] = array( 'result' => '1', 'responseCode' => '7003' );

		$this->fake_request->order_paygent_status_completed( $this->order->get_id(), '121', $this->gateway, array( 'running_id' => '12345' ) );

		$this->assertSame( array( '121' ), array_column( $this->fake_request->sent, 'kind' ), 'No 094 inquiry may run for subscription sales.' );
		$reloaded = wc_get_order( $this->order->get_id() );
		$this->assertTrue( $reloaded->has_status( 'pending' ), 'A failed subscription sale must not change the order status.' );
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		$this->assertStringContainsString( 'subscription sale', $notes[0]->content );
	}

	public function test_failed_paidy_capture_retry_falls_back_to_status_check(): void {
		$paidy_like = new class() {
			/** @var string */
			public $id = 'paygent_paidy';
			/** @var string */
			public $test_mode = 'yes';
			/** @var string */
			public $debug = 'no';
		};
		$this->order->set_payment_method( 'paygent_paidy' );
		$this->order->save();

		$this->fake_request->responses['341'] = array( 'result' => '1', 'responseCode' => 'P004' );
		$this->fake_request->responses['094'] = array(
			'result'       => '0',
			'result_array' => array( array( 'payment_status' => '20' ) ),
		);

		$this->fake_request->order_paygent_status_completed( $this->order->get_id(), '341', $paidy_like );

		$this->assertSame( array( '094', '341', '341', '094' ), array_column( $this->fake_request->sent, 'kind' ), 'Pre-check, capture, Paidy retry, then the failure re-check.' );
		$reloaded = wc_get_order( $this->order->get_id() );
		$this->assertTrue( $reloaded->has_status( 'on-hold' ), 'An uncaptured Paidy payment must not stay completed after both attempts fail.' );
	}

	public function test_completed_skips_sale_telegram_when_paymentaction_is_sale(): void {
		$sale_gateway                  = new class() extends \WC_Gateway_Paygent_MB {
			public $paymentaction = 'sale';
		};
		$sale_gateway->paygent_request = $this->fake_request;

		$sale_gateway->order_mb_status_completed( $this->order->get_id() );

		$this->assertCount( 0, $this->fake_request->sent, 'No sale telegram when the gateway charged at purchase time.' );
	}

	public function test_completed_skips_sale_telegram_for_other_payment_method(): void {
		$this->order->set_payment_method( 'paygent_cc' );
		$this->order->save();

		$this->gateway->order_mb_status_completed( $this->order->get_id() );

		$this->assertCount( 0, $this->fake_request->sent, 'No sale telegram for orders paid by another gateway.' );
	}
}
