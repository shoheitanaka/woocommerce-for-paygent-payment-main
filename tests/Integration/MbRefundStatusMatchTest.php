<?php

namespace Paygent\Tests\Integration;

/**
 * Regression tests for refund permit-status matching (paygent_process_refund).
 *
 * The 094 inquiry response returns payment_status / career_type as strings,
 * but the MB gateway declares its permit statuses as integers (numeric array
 * keys are always integers in PHP). The old strict comparisons never matched,
 * so the career loop fell through without choosing a telegram kind and a
 * broken request with an empty telegram_kind was sent, leaving only a generic
 * "Refund failed" note. Both sides are now normalized before comparison and
 * an unmatched status returns a WP_Error naming the status.
 */
class MbRefundStatusMatchTest extends TestCase {

	/** @var \WC_Gateway_Paygent_MB */
	private $gateway;

	/** @var \WC_Order */
	private $order;

	/** @var object Fake request client returning canned responses per telegram kind. */
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
				return $this->responses[ $telegram_kind ] ?? array( 'result' => '1' );
			}
		};

		$this->gateway->paygent_request = $this->fake_request;

		$this->order = $this->create_test_order( 'paygent_mb', 55 );
		$this->order->set_transaction_id( '48325776' );
		$this->order->update_meta_data( '_paygent_order_id', 'wcpg' . $this->order->get_id() );
		$this->order->update_meta_data( '_career_type', 'docomo' );
		$this->order->save();
	}

	public function tearDown(): void {
		$this->delete_test_order( $this->order );
		parent::tearDown();
	}

	private function inquiry_response( string $payment_status, string $career_type = '5' ): array {
		return array(
			'result'       => '0',
			'result_array' => array(
				array(
					'payment_status' => $payment_status,
					'career_type'    => $career_type,
					'payment_id'     => '48325776',
				),
			),
		);
	}

	public function test_docomo_full_refund_at_status_40_is_rejected_with_message(): void {
		$this->fake_request->responses['094'] = $this->inquiry_response( '40' );

		$result = $this->gateway->process_refund( $this->order->get_id(), $this->order->get_total() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'payment_status 40', $result->get_error_message() );
		$this->assertStringContainsString( 'Capture Completed (44)', $result->get_error_message(), 'The note must explain when the refund becomes possible.' );
		$this->assertCount( 1, $this->fake_request->sent, 'Only the inquiry telegram must be sent; no broken cancel telegram.' );
		$this->assertSame( '094', $this->fake_request->sent[0]['kind'] );
	}

	public function test_docomo_partial_refund_at_status_40_is_rejected_with_guidance(): void {
		$this->fake_request->responses['094'] = $this->inquiry_response( '40' );

		$result = $this->gateway->process_refund( $this->order->get_id(), '10' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'Capture Completed (44)', $result->get_error_message() );
		$this->assertCount( 1, $this->fake_request->sent );
	}

	public function test_docomo_full_refund_at_status_44_sends_cancel_telegram(): void {
		$this->fake_request->responses['094'] = $this->inquiry_response( '44' );
		$this->fake_request->responses['102'] = array(
			'result'       => '0',
			'result_array' => array( array() ),
		);

		$result = $this->gateway->process_refund( $this->order->get_id(), $this->order->get_total() );

		$this->assertTrue( $result );
		$this->assertSame( array( '094', '102' ), array_column( $this->fake_request->sent, 'kind' ) );
	}

	public function test_docomo_full_refund_at_status_21_sends_auth_cancel_telegram(): void {
		$this->fake_request->responses['094'] = $this->inquiry_response( '21' );
		$this->fake_request->responses['102'] = array(
			'result'       => '0',
			'result_array' => array( array() ),
		);

		$result = $this->gateway->process_refund( $this->order->get_id(), $this->order->get_total() );

		$this->assertTrue( $result );
		$this->assertSame( array( '094', '102' ), array_column( $this->fake_request->sent, 'kind' ) );
	}

	// -------------------------------------------------------------------------
	// au / SoftBank: captured (40) payments allow full cancellation (102) only;
	// correction (103) is limited to the authorized statuses by the spec.
	// -------------------------------------------------------------------------

	public function test_au_full_refund_at_status_40_sends_cancel_telegram(): void {
		$this->fake_request->responses['094'] = $this->inquiry_response( '40', '4' );
		$this->fake_request->responses['102'] = array(
			'result'       => '0',
			'result_array' => array( array() ),
		);

		$result = $this->gateway->process_refund( $this->order->get_id(), $this->order->get_total() );

		$this->assertTrue( $result );
		$this->assertSame( array( '094', '102' ), array_column( $this->fake_request->sent, 'kind' ) );
	}

	public function test_au_partial_refund_at_status_40_is_rejected_with_guidance(): void {
		$this->fake_request->responses['094'] = $this->inquiry_response( '40', '4' );

		$result = $this->gateway->process_refund( $this->order->get_id(), '10' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'only full refunds after capture', $result->get_error_message() );
		$this->assertCount( 1, $this->fake_request->sent, 'No correction telegram may be sent at status 40 for au.' );
	}

	public function test_sb_full_refund_at_status_40_sends_cancel_telegram(): void {
		$this->fake_request->responses['094'] = $this->inquiry_response( '40', '6' );
		$this->fake_request->responses['102'] = array(
			'result'       => '0',
			'result_array' => array( array() ),
		);

		$result = $this->gateway->process_refund( $this->order->get_id(), $this->order->get_total() );

		$this->assertTrue( $result );
		$this->assertSame( array( '094', '102' ), array_column( $this->fake_request->sent, 'kind' ) );
	}

	public function test_sb_partial_refund_at_status_40_is_rejected_with_guidance(): void {
		$this->fake_request->responses['094'] = $this->inquiry_response( '40', '6' );

		$result = $this->gateway->process_refund( $this->order->get_id(), '10' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'only full refunds after capture', $result->get_error_message() );
		$this->assertCount( 1, $this->fake_request->sent, 'No correction telegram may be sent at status 40 for SoftBank.' );
	}

	public function test_sb_partial_refund_at_status_21_sends_auth_change_telegram(): void {
		$this->fake_request->responses['094'] = $this->inquiry_response( '21', '6' );
		$this->fake_request->responses['103'] = array(
			'result'       => '0',
			'result_array' => array(
				array(
					'payment_id'      => '48325999',
					'base_payment_id' => '48325776',
				),
			),
		);

		$result = $this->gateway->process_refund( $this->order->get_id(), '10' );

		$this->assertTrue( $result );
		$this->assertSame( array( '094', '103' ), array_column( $this->fake_request->sent, 'kind' ) );
	}

	public function test_docomo_partial_refund_at_status_21_sends_auth_change_telegram(): void {
		$this->fake_request->responses['094'] = $this->inquiry_response( '21' );
		$this->fake_request->responses['103'] = array(
			'result'       => '0',
			'result_array' => array(
				array(
					'payment_id'      => '48325999',
					'base_payment_id' => '48325776',
				),
			),
		);

		$result = $this->gateway->process_refund( $this->order->get_id(), '10' );

		$this->assertTrue( $result );
		$this->assertSame( array( '094', '103' ), array_column( $this->fake_request->sent, 'kind' ) );
	}
}
