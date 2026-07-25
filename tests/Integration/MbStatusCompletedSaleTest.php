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

		$this->assertCount( 1, $this->fake_request->sent, 'Sale telegram must be sent on completion.' );
		$this->assertSame( '101', $this->fake_request->sent[0]['kind'] );
		$this->assertSame( '48325757', $this->fake_request->sent[0]['data']['payment_id'] );
		$this->assertSame( 'wcpg' . $this->order->get_id(), $this->fake_request->sent[0]['data']['trading_id'] );

		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		$this->assertNotEmpty( $notes );
		$this->assertStringContainsString( 'set to sale at Paygent', $notes[0]->content );
	}

	public function test_failed_capture_moves_order_back_to_on_hold(): void {
		$this->fake_request->responses['101'] = array( 'result' => '1', 'responseCode' => 'P004' );
		$this->fake_request->responses['094'] = array(
			'result'       => '0',
			'result_array' => array( array( 'payment_status' => '21' ) ),
		);

		$this->gateway->order_mb_status_completed( $this->order->get_id() );

		$this->assertSame( array( '101', '094' ), array_column( $this->fake_request->sent, 'kind' ) );
		$reloaded = wc_get_order( $this->order->get_id() );
		$this->assertTrue( $reloaded->has_status( 'on-hold' ), 'A genuinely uncaptured payment must pull the order back to on-hold.' );
		$notes = array_column( wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) ), 'content' );
		$this->assertStringContainsString( 'moved back to on-hold', implode( "\n", $notes ) );
	}

	public function test_failed_capture_with_already_captured_payment_keeps_status(): void {
		$this->fake_request->responses['101'] = array( 'result' => '1', 'responseCode' => '7003' );
		$this->fake_request->responses['094'] = array(
			'result'       => '0',
			'result_array' => array( array( 'payment_status' => '40' ) ),
		);

		$this->gateway->order_mb_status_completed( $this->order->get_id() );

		$reloaded = wc_get_order( $this->order->get_id() );
		$this->assertTrue( $reloaded->has_status( 'pending' ), 'An already-captured payment must not change the order status.' );
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		$this->assertStringContainsString( 'already reports this payment as captured', $notes[0]->content );
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
