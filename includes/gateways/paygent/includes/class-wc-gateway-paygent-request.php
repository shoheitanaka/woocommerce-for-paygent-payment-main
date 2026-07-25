<?php
/**
 * Paygent Payment Gateway
 *
 * Functions of a Paygent Payment Gateway.
 *
 * @class       WC_Gateway_Paygent_Request
 * @version     2.4.8
 * @package     PaygentForWooCommerce
 * @author      Artisan Workshop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ArtisanWorkshop\PluginFramework\v2_0_13 as Framework;

use PaygentModule\System\PaygentB2BModule;

/**
 * Generates requests to send to Paygent
 */
class WC_Gateway_Paygent_Request {

	/**
	 * Merchant id for Paygent.
	 *
	 * @var string
	 */
	public $merchant_id;

	/**
	 * Connect id for Paygent.
	 *
	 * @var string
	 */
	public $connect_id;

	/**
	 * Connect password for Paygent.
	 *
	 * @var string
	 */
	public $connect_password;

	/**
	 * TEST Merchant id for Paygent.
	 *
	 * @var string
	 */
	public $merchant_test_id;

	/**
	 * TEST Connect id for Paygent.
	 *
	 * @var string
	 */
	public $connect_test_id;

	/**
	 * TEST Connect password for Paygent.
	 *
	 * @var string
	 */
	public $connect_test_password;

	/**
	 * Site id for Paygent.
	 *
	 * @var integer
	 */
	public $site_id;

	/**
	 * Prefix Order
	 *
	 * @var string
	 */
	public $prefix_order;

	/**
	 * Framework.
	 *
	 * @var object
	 */
	public $jp4wc_framework;

	/**
	 * Setting data.
	 *
	 * @var array
	 */
	public $app;

	/**
	 * Socket for SSL communication
	 *
	 * @var resource
	 */
	private $ch;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Paygent Setting IDs.
		$this->merchant_id           = get_option( 'wc-paygent-mid' );
		$this->connect_id            = get_option( 'wc-paygent-cid' );
		$this->connect_password      = get_option( 'wc-paygent-cpass' );
		$this->merchant_test_id      = get_option( 'wc-paygent-test-mid' );
		$this->connect_test_id       = get_option( 'wc-paygent-test-cid' );
		$this->connect_test_password = get_option( 'wc-paygent-test-cpass' );
		$this->site_id               = get_option( 'wc-paygent-sid' );
		$this->prefix_order          = get_option( 'wc-paygent-prefix_order' );

		$this->jp4wc_framework = new Framework\JP4WC_Framework();
	}

	/**
	 * Get the Paygent request Post data for an order
	 *
	 * @param  boolean $test_mode Test mode.
	 * @param  object  $order WC_Order object.
	 * @param  string  $telegram_kind Telegram kind.
	 * @param  array   $send_data Send data.
	 * @param  string  $debug Debug mode.
	 * @return array
	 */
	public function send_paygent_request( $test_mode, $order, $telegram_kind, $send_data, $debug = 'yes' ) {
		$data = $this->merchant_data( $test_mode );

		$process = new PaygentB2BModule();
		$process->init();
		$process->reqPut( 'merchant_id', $data['merchant_id'] );
		$process->reqPut( 'connect_id', $data['connect_id'] );
		$process->reqPut( 'connect_password', $data['connect_password'] );
		$process->reqPut( 'telegram_kind', $telegram_kind );
		$process->reqPut( 'telegram_version', '1.0' );

		// Make Hash check header.
		if ( get_option( 'wc-paygent-hash_check' ) ) {
			if ( $test_mode ) {
				$hash_code = get_option( 'wc-paygent-test-hash_code' );
			} else {
				$hash_code = get_option( 'wc-paygent-hash_code' );
			}
			$hash_data = array(
				'merchant_id'      => $data['merchant_id'],
				'connect_id'       => $data['connect_id'],
				'connect_password' => $data['connect_password'],
				'telegram_kind'    => $telegram_kind,
				'telegram_version' => '1.0',
				'trading_id'       => $send_data['trading_id'],
			);
			if ( isset( $send_data['payment_id'] ) ) {
				$hash_data['payment_id'] = $send_data['payment_id'];
			}
			if ( isset( $send_data['payment_amount'] ) ) {
				$hash_data['payment_amount'] = $send_data['payment_amount'];
			} elseif ( isset( $send_data['amount'] ) ) {
				$hash_data['payment_amount'] = $send_data['amount'];
			}
			$hash_data['request_date'] = date_i18n( 'YmdHis' );
			$send_data['request_date'] = date_i18n( 'YmdHis' );
			$send_data['hc']           = $this->make_hash_data( $hash_data, $hash_code );
		}

		// set send_data to reqPut.
		foreach ( $send_data as $key => $value ) {
			$process->reqPut( $key, $value );
		}

		// Save debug send data.
		$send_message = 'telegram_kind : ' . $telegram_kind . "\n";
		if ( ! is_null( $order ) ) {
			$send_message .= __( 'This request send data for order ID:', 'woocommerce-for-paygent-payment-main' ) . $order->get_id() . "\n";
		}
		foreach ( $send_data as $key => $value ) {
			$request_array[ $key ] = mb_convert_encoding( $value, 'UTF-8', 'SJIS' );
		}
		$send_message .= __( 'The request transmission data is shown below.', 'woocommerce-for-paygent-payment-main' ) . "\n" . $this->jp4wc_framework->jp4wc_array_to_message( $request_array );
		$this->jp4wc_framework->jp4wc_debug_log( $send_message, $debug, 'wc-paygent' );

		$process->post();

		$res_array = array();
		while ( $process->hasResNext() ) {
			$res_array[] = $process->resNext();
		}

		$result_data = array(
			'result'         => $process->getResultStatus(),
			'responseCode'   => $process->getResponseCode(),
			'responseDetail' => $process->getResponseDetail(),
			'result_array'   => $res_array,
		);

		// Save debug response data.
		$send_message  = 'telegram_kind : ' . $telegram_kind . "\n";
		$send_message .= 'result : ' . $result_data['result'] . "\n";
		if ( '0' !== $result_data['result'] ) {
			$send_message .= 'responseCode : ' . $result_data['responseCode'] . "\n";
			$send_message .= 'responseDetail : ' . mb_convert_encoding( $result_data['responseDetail'], 'UTF-8', 'SJIS' ) . "\n";
		}
		if ( ! is_null( $order ) ) {
			$send_message .= __( 'This response data for order ID:', 'woocommerce-for-paygent-payment-main' ) . $order->get_id() . "\n";
		}
		if ( isset( $res_array[0] ) ) {
			$response_array = array();
			foreach ( $res_array[0] as $key => $value ) {
				$response_array[ $key ] = mb_convert_encoding( $value, 'UTF-8', 'SJIS' );
			}
			$send_message .= __( 'The response transmission data is shown below.', 'woocommerce-for-paygent-payment-main' ) . "\n" . $this->jp4wc_framework->jp4wc_array_to_message( $response_array );
			$this->jp4wc_framework->jp4wc_debug_log( $send_message, $debug, 'wc-paygent' );
		}

		return $result_data;
	}

	/**
	 * Set the Paygent IDs for request.
	 *
	 * @param  bool $test_mode Test mode.
	 * @return array
	 */
	public function merchant_data( $test_mode ) {
		if ( '1' === $test_mode ) {
			$data['merchant_id']      = $this->merchant_test_id;
			$data['connect_id']       = $this->connect_test_id;
			$data['connect_password'] = $this->connect_test_password;
		} else {
			$data['merchant_id']      = $this->merchant_id;
			$data['connect_id']       = $this->connect_id;
			$data['connect_password'] = $this->connect_password;
		}
		return $data;
	}

	/**
	 * Update Sale from Auth to Paygent System
	 *
	 * @param int    $order_id Order ID.
	 * @param string $telegram_kind Telegram kind.
	 * @param object $payment Payment object.
	 * @param array  $send_data Send data.
	 */
	public function order_paygent_status_completed( $order_id, $telegram_kind, $payment, $send_data = array() ) {
		$order                = wc_get_order( $order_id );
		$order_payment_method = $order->get_payment_method();
		// Gateways without a paymentaction setting (MB, Paidy) always authorize first,
		// so the sale request must be sent on completion; only skip when the gateway
		// explicitly charged at purchase time (paymentaction === 'sale').
		$paymentaction = $payment->paymentaction ?? '';
		if ( 'sale' !== $paymentaction && $order_payment_method === $payment->id ) {
			$send_data['payment_id'] = $order->get_transaction_id();
			// A trading_id rebuilt from the current prefix option can mismatch the
			// one sent on application, so it must go through the shared resolver.
			$send_data['trading_id'] = $this->get_paygent_trading_id( $order, ! empty( $send_data['payment_id'] ) );
			// Set Site ID.
			if ( '1' !== $this->site_id ) {
				$send_data['site_id'] = $this->site_id;
			}
			// A completion may run after Paygent already captured the payment
			// (e.g. status webhook moved the order to completed after an
			// admin-site capture), so check first and skip the redundant
			// request. Subscription sales (121) use a different lifecycle and
			// are sent as-is.
			if ( '121' !== $telegram_kind ) {
				$inquiry_check  = array(
					'payment_id' => $send_data['payment_id'],
					'trading_id' => $send_data['trading_id'],
				);
				$inquiry        = $this->send_paygent_request( $payment->test_mode, $order, '094', $inquiry_check, $payment->debug );
				$inquiry_status = isset( $inquiry['result_array'][0]['payment_status'] ) ? (string) $inquiry['result_array'][0]['payment_status'] : '';
				if ( in_array( $inquiry_status, array( '40', '41', '44' ), true ) ) {
					$order->add_order_note( __( 'The payment is already captured at Paygent, so the capture request was skipped.', 'woocommerce-for-paygent-payment-main' ) );
					return;
				}
			}
			$response = $this->send_paygent_request( $payment->test_mode, $order, $telegram_kind, $send_data, $payment->debug );
			if ( '0' === $response['result'] ) {
				$order->add_order_note( __( 'Success this order set to sale at Paygent.', 'woocommerce-for-paygent-payment-main' ) );
			} elseif ( 'paygent_paidy' === $order_payment_method ) {
				// Paidy Payment: retry with the raw order ID as trading_id, but keep
				// the resolved identifiers for the failure re-check below.
				$retry_send_data               = $send_data;
				$retry_send_data['trading_id'] = $order_id;
				$response_again                = $this->send_paygent_request( $payment->test_mode, $order, $telegram_kind, $retry_send_data, $payment->debug );
				if ( '0' === $response_again['result'] ) {
					$order->add_order_note( __( 'Success this order set to sale at Paygent.', 'woocommerce-for-paygent-payment-main' ) );
				} else {
					$order->add_order_note( __( 'Failed this order set to sale at Paygent.', 'woocommerce-for-paygent-payment-main' ) );
					$this->handle_failed_capture_on_completion( $order, $payment, $send_data, $response_again );
				}
			} elseif ( '121' === $telegram_kind ) {
				// Subscription sales have their own inquiry lifecycle (125 with
				// running_id), so the one-time-payment inquiry cannot tell whether
				// this billing period was sold. Record the failure without touching
				// the order status to avoid re-sending 121 for a sold period.
				$error_code = isset( $response['responseCode'] ) ? $response['responseCode'] : '';
				// translators: %s: Paygent error code.
				$order->add_order_note( sprintf( __( 'Failed to capture the subscription sale at Paygent (error code: %s). Please check the payment on the Paygent admin site.', 'woocommerce-for-paygent-payment-main' ), $error_code ) );
			} else {
				$this->handle_failed_capture_on_completion( $order, $payment, $send_data, $response );
			}
		}
	}

	/**
	 * Handle a failed capture request sent on order completion.
	 *
	 * The payment may in fact already be captured (e.g. carrier payments applied
	 * with a same-time sale flag reject a second capture with a status-mismatch
	 * error), so the actual Paygent status is checked first. Only when the
	 * payment is really not captured is the order pulled back to on-hold so the
	 * failure cannot go unnoticed.
	 *
	 * @param WC_Order $order Order object.
	 * @param object   $payment Payment gateway object.
	 * @param array    $send_data Data sent with the capture telegram.
	 * @param array    $response Failed capture response.
	 * @return void
	 */
	protected function handle_failed_capture_on_completion( $order, $payment, $send_data, $response ) {
		$send_data_check = array(
			'payment_id' => isset( $send_data['payment_id'] ) ? $send_data['payment_id'] : '',
			'trading_id' => isset( $send_data['trading_id'] ) ? $send_data['trading_id'] : '',
		);
		$inquiry        = $this->send_paygent_request( $payment->test_mode, $order, '094', $send_data_check, $payment->debug );
		$inquiry_ok     = isset( $inquiry['result'] ) && '0' === $inquiry['result'] && isset( $inquiry['result_array'][0]['payment_status'] );
		$inquiry_status = $inquiry_ok ? (string) $inquiry['result_array'][0]['payment_status'] : '';
		if ( $inquiry_ok && in_array( $inquiry_status, array( '40', '41', '44' ), true ) ) {
			$order->add_order_note( __( 'The capture request failed, but Paygent already reports this payment as captured. No further action is needed.', 'woocommerce-for-paygent-payment-main' ) );
			return;
		}
		$error_code   = isset( $response['responseCode'] ) ? $response['responseCode'] : '';
		$error_detail = isset( $response['responseDetail'] ) ? mb_convert_encoding( $response['responseDetail'], 'UTF-8', 'SJIS' ) : '';
		if ( ! $inquiry_ok ) {
			// The remote state could not be verified (the capture may in fact
			// have succeeded and only the response was lost), so the status is
			// kept and manual reconciliation is requested instead.
			$order->add_order_note(
				// translators: %1$s: Paygent error code, %2$s: Paygent error message.
				sprintf( __( 'The capture request failed (error code: %1$s %2$s) and the follow-up status inquiry did not return a result. The order status was kept — please verify the payment on the Paygent admin site.', 'woocommerce-for-paygent-payment-main' ), $error_code, $error_detail )
			);
			return;
		}
		$order->update_status(
			'on-hold',
			// translators: %1$s: Paygent error code, %2$s: Paygent error message.
			sprintf( __( 'Capture at Paygent failed (error code: %1$s %2$s), so the order was moved back to on-hold. Please try completing the order again or capture the payment on the Paygent admin site.', 'woocommerce-for-paygent-payment-main' ), $error_code, $error_detail )
		);
	}

	/**
	 * Resolve the trading ID to send to Paygent for an order.
	 *
	 * Prefers the trading_id actually sent on application (stored as
	 * _paygent_order_id meta). For legacy orders without the meta the
	 * checkout-time trading_id cannot be reconstructed reliably (the prefix
	 * option may have changed since), so when the telegram also carries a
	 * payment_id the telegram relies on it alone — Paygent accepts either ID,
	 * and an empty trading_id counts as "not set". Only when no payment_id is
	 * available fall back to rebuilding from the current settings.
	 *
	 * @param WC_Order $order          Order object.
	 * @param bool     $has_payment_id Whether the telegram also carries a payment_id.
	 * @return string
	 */
	public function get_paygent_trading_id( $order, $has_payment_id = false ) {
		$paygent_order_id = $order->get_meta( '_paygent_order_id' );
		if ( $paygent_order_id ) {
			return $paygent_order_id;
		}
		if ( $has_payment_id ) {
			return '';
		}
		if ( $this->prefix_order ) {
			return $this->prefix_order . $order->get_id();
		}
		return 'wc_' . $order->get_id();
	}

	/**
	 * Process refund for Paygent.
	 *
	 * @param int    $order_id Order ID.
	 * @param int    $amount Amount.
	 * @param array  $telegram_array Telegram array.
	 * @param array  $permit_statuses Permit statuses.
	 * @param array  $send_data_refund Send data for refund.
	 * @param object $payment Payment object.
	 * @param array  $status_messages Optional explanations for non-refundable statuses,
	 *                                keyed like $permit_statuses (career type or 0) then
	 *                                by payment status. Appended to the failure note.
	 *
	 * @return mixed
	 */
	public function paygent_process_refund( $order_id, $amount, $telegram_array, $permit_statuses, $send_data_refund, $payment, $status_messages = array() ) {
		if ( is_null( $amount ) ) {
			return false;
		}

		$order                         = wc_get_order( $order_id );
		$transaction_id                = $order->get_transaction_id();
		$is_subscription               = function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order_id );
		$send_data_check['payment_id'] = $transaction_id;
		// Set Order ID for Paygent. Subscription refunds drop the payment_id
		// below, so they cannot rely on it and need a constructed trading_id.
		$send_data_check['trading_id']  = $this->get_paygent_trading_id( $order, ! empty( $transaction_id ) && ! $is_subscription );
		$send_data_refund['payment_id'] = $transaction_id;
		$send_data_refund['trading_id'] = $send_data_check['trading_id'];

		$telegram_kind_check = '094';// Information inquiry.
		$order_result        = $this->send_paygent_request( $payment->test_mode, $order, $telegram_kind_check, $send_data_check, $payment->debug );
		$order_total         = $order->get_total();
		if ( $is_subscription ) {
			unset( $send_data_refund['payment_id'] );
		}
		// The inquiry response returns every field as a string while gateways may
		// declare permit statuses as integers (and numeric career keys are always
		// stored as integers by PHP), so both sides must be normalized before
		// comparison. career_type is empty for non-carrier payments.
		$order_info      = isset( $order_result['result_array'][0] ) && is_array( $order_result['result_array'][0] ) ? $order_result['result_array'][0] : array();
		$response_status = isset( $order_info['payment_status'] ) ? (string) $order_info['payment_status'] : '';
		$response_career = isset( $order_info['career_type'] ) && '' !== (string) $order_info['career_type'] ? (int) $order_info['career_type'] : null;
		$status_hint     = '';
		if ( null !== $response_career && isset( $status_messages[ $response_career ][ $response_status ] ) ) {
			$status_hint = ' ' . $status_messages[ $response_career ][ $response_status ];
		} elseif ( isset( $status_messages[0][ $response_status ] ) ) {
			$status_hint = ' ' . $status_messages[0][ $response_status ];
		}
		if ( $amount === $order_total ) {
			foreach ( $permit_statuses as $key => $permit_status ) {
				if ( 0 === $key || ( null !== $response_career && (int) $key === $response_career ) ) {
					if ( isset( $permit_status['auth_cancel'] ) && in_array( $response_status, array_map( 'strval', $permit_status['auth_cancel'] ), true ) === true ) {
						$telegram_kind_del = $telegram_array['auth_cancel'];// Authority Cancel.
					} elseif ( isset( $permit_status['sale_cancel'] ) && in_array( $response_status, array_map( 'strval', $permit_status['sale_cancel'] ), true ) === true ) {
						$telegram_kind_del = $telegram_array['sale_cancel'];// Sales Cancel.
					}
				}
			}
			if ( ! isset( $telegram_kind_del ) ) {
				// translators: %s: payment status.
				$message = __( 'Failed Refund. ', 'woocommerce-for-paygent-payment-main' ) . sprintf( __( 'Not matched payment_status %s for refund.', 'woocommerce-for-paygent-payment-main' ), $response_status ) . $status_hint;
				$order->add_order_note( $message );
				return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
			}
			// Carrier subscription cancellation (122) needs the prepared refund
			// data (running_id / running_target_ym); every other cancellation
			// telegram (102, CC 021/023, ...) carries the payment / trading
			// identifiers, including on subscription orders of other gateways.
			$del_result = $this->send_paygent_request( $payment->test_mode, $order, $telegram_kind_del, ( $is_subscription && '122' === $telegram_kind_del ) ? $send_data_refund : $send_data_check, $payment->debug );
			if ( '1' === $del_result['result'] ) {
				$message = __( 'Failed Refund. ', 'woocommerce-for-paygent-payment-main' ) . __( 'Error Code :', 'woocommerce-for-paygent-payment-main' ) . $del_result['responseCode'] . __( ' Error message :', 'woocommerce-for-paygent-payment-main' ) . mb_convert_encoding( $del_result['responseDetail'], 'UTF-8', 'SJIS' );
				$order->add_order_note( $message );
				return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
			} elseif ( '0' === $del_result['result'] ) {
				$message = __( 'This order has been successfully refunded by Paygent.', 'woocommerce-for-paygent-payment-main' );
				$order->add_order_note( $message );
				return true;
			} else {
				$message = __( 'Failed Refund.', 'woocommerce-for-paygent-payment-main' );
				$order->add_order_note( $message );
				return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
			}
		} elseif ( $amount < $order_total ) {
			foreach ( $permit_statuses as $key => $permit_status ) {
				if ( 0 === $key || ( null !== $response_career && (int) $key === $response_career ) ) {
					if ( isset( $permit_status['auth_change'] ) && in_array( $response_status, array_map( 'strval', $permit_status['auth_change'] ), true ) ) {
						$telegram_kind_refund = $telegram_array['auth_change'];// Authory Change.
					} elseif ( isset( $permit_status['sale_change'] ) && in_array( $response_status, array_map( 'strval', $permit_status['sale_change'] ), true ) ) {
						$telegram_kind_refund = $telegram_array['sale_change'];// Sales Change.
					}
				}
			}
			if ( ! isset( $telegram_kind_refund ) ) {
				// translators: %s: payment status.
				$message = __( 'Failed Refund. ', 'woocommerce-for-paygent-payment-main' ) . sprintf( __( 'Not matched payment_status %s for refund.', 'woocommerce-for-paygent-payment-main' ), $response_status ) . $status_hint;
				$order->add_order_note( $message );
				return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
			}
			$refund_result = $this->send_paygent_request( $payment->test_mode, $order, $telegram_kind_refund, $send_data_refund, $payment->debug );
			if ( '1' === $refund_result['result'] ) {
				$message = __( 'Failed Refund. ', 'woocommerce-for-paygent-payment-main' ) . __( 'Error Code :', 'woocommerce-for-paygent-payment-main' ) . $refund_result['responseCode'] . __( ' Error message :', 'woocommerce-for-paygent-payment-main' ) . mb_convert_encoding( $refund_result['responseDetail'], 'UTF-8', 'SJIS' );
				$order->add_order_note( $message );
				return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
			} elseif ( '0' === $refund_result['result'] ) {
				$order->set_transaction_id( $refund_result['result_array'][0]['payment_id'] );
				$order->save();
				$order->add_order_note(
					__( 'This order has been successfully partial refunded by Paygent.', 'woocommerce-for-paygent-payment-main' )
					// translators: %1$s: base_payment_id, %2$s: payment_id.
					. sprintf( __( 'payment_id changed from %1$s to %2$s.', 'woocommerce-for-paygent-payment-main' ), $refund_result['result_array'][0]['base_payment_id'], $refund_result['result_array'][0]['payment_id'] )
				);
				return true;
			} else {
				$message = __( 'Failed Refund.', 'woocommerce-for-paygent-payment-main' );
				$order->add_order_note( $message );
				return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
			}
		}
		return false;
	}

	/**
	 * Error response for Paygent
	 *
	 * @param  array  $response Response data.
	 * @param  object $order WC_Order object.
	 * @return void
	 */
	public function error_response( $response, $order ) {
		$order_id = $order->get_id();
		if ( '1' === $response['result'] ) {// System Error
			// Other transaction error.
			$code        = str_replace( '″', '', $response['responseDetail'] );
			$code        = str_replace( '”', '', $response['responseDetail'] );
			$error_texts = $this->error_text();
			if ( isset( $error_texts[ $code ] ) ) {
				$message = $code . ':' . $error_texts[ $code ];
			} else {
				$message = $response['responseDetail'];
			}

			$order->add_order_note( __( 'paygent Payment failed. Sysmte Error: ', 'woocommerce-for-paygent-payment-main' ) . $response['responseCode'] . ':' . mb_convert_encoding( $message, 'UTF-8', 'auto' ) . ':' . $response['responseDetail'] );
			if ( $this->is_customer_checkout_context() ) {
				wc_add_notice( __( 'Sorry, there was an error: ', 'woocommerce-for-paygent-payment-main' ) . $response['responseCode'] . ':' . mb_convert_encoding( $message, 'UTF-8', 'auto' ), 'error' );
			}
		} else {
			// No response or unexpected response.
			$order->add_order_note( __( 'paygent Payment failed. Some trouble happened.', 'woocommerce-for-paygent-payment-main' ) . $response['result'] . ':' . $response['responseCode'] . ':' . mb_convert_encoding( $response['responseDetail'], 'UTF-8', 'auto' ) . ':wc_' . $order_id );
			if ( $this->is_customer_checkout_context() ) {
				wc_add_notice( __( 'No response from payment gateway server. Try again later or contact the site administrator.', 'woocommerce-for-paygent-payment-main' ), 'error' );
			}
		}
	}

	/**
	 * Whether the current request is a customer-facing checkout: the classic
	 * checkout page or a Store API (Block checkout) request. is_checkout()
	 * alone misses Store API requests, so Block checkout customers only saw
	 * a generic "Something went wrong" message instead of the Paygent error.
	 * Notices queued during a Store API payment request are surfaced in the
	 * Block checkout error response. wc_is_store_api_request() (WC 6.9+) is
	 * used instead of the broader WC()->is_rest_api_request() so notices are
	 * not queued into unrelated REST requests (e.g. wc/v3 admin API calls).
	 *
	 * @return bool
	 */
	protected function is_customer_checkout_context() {
		if ( is_checkout() ) {
			return true;
		}
		return function_exists( 'wc_is_store_api_request' ) && wc_is_store_api_request();
	}

	/**
	 * Make hash data via hash code
	 *
	 * @param  array  $hash_data Hash data.
	 * @param  string $hash_code Hash code.
	 * @return string
	 */
	public function make_hash_data( $hash_data, $hash_code ) {
		$header_text = '';
		foreach ( $hash_data as $key => $value ) {
			if ( isset( $value ) ) {
				$header_text = $header_text . $value;
			}
		}
		$header_text = $header_text . $hash_code;
		$hc          = hash( 'sha256', $header_text );
		return $hc;
	}

	/**
	 * Get payment status from Paygent API
	 *
	 * @param WC_Order $order        Order object.
	 * @param object   $this_gateway Payment gateway object.
	 * @return array   Response from Paygent API
	 */
	public function paygent_get_payment_status( $order, $this_gateway ) {
		$telegram_kind           = '094'; // Information inquiry.
		$transaction_id          = $order->get_transaction_id();
		$send_data['payment_id'] = $transaction_id;
		// Set Order ID for Paygent.
		$send_data['trading_id'] = $this->get_paygent_trading_id( $order, ! empty( $transaction_id ) );
		$order_result            = $this->send_paygent_request( $this_gateway->test_mode, $order, $telegram_kind, $send_data, $this_gateway->debug );
		if ( '0' !== $order_result['result'] ) {
			// No response or unexpected response.
			$this->error_response( $order_result, $order );
			return false;
		}
		return $order_result['result_array'][0];
	}

	/**
	 * Error text for Paygemnt
	 *
	 * @return array
	 */
	public function error_text() {
		return array(
			'1G02' => __( '[Issuer error] Insufficient card loan balance', 'woocommerce-for-paygent-payment-main' ),
			'1G03' => __( '[Issuer error] Card loan limit exceeded', 'woocommerce-for-paygent-payment-main' ),
			'1G04' => __( '[Issuer error] Insufficient cash loan balance', 'woocommerce-for-paygent-payment-main' ),
			'1G05' => __( '[Issuer error] Cashing limit exceeded', 'woocommerce-for-paygent-payment-main' ),
			'1G06' => __( '[Issuer error] Insufficient debit card balance', 'woocommerce-for-paygent-payment-main' ),
			'1G07' => __( '[Issuer error] Debit card limit exceeded', 'woocommerce-for-paygent-payment-main' ),
			'1G12' => __( '[Issuer error] Card cannot be used', 'woocommerce-for-paygent-payment-main' ),
			'1G22' => __( '[Issuer error] Permanent payment ban', 'woocommerce-for-paygent-payment-main' ),
			'1G30' => __( '[Issuer error] Transaction judgment pending (attended judgment)', 'woocommerce-for-paygent-payment-main' ),
			'1G42' => __( '[Issuer error] PIN code error', 'woocommerce-for-paygent-payment-main' ),
			'1G44' => __( '[Issuer error] Incorrect card confirmation number', 'woocommerce-for-paygent-payment-main' ),
			'1G45' => __( '[Issuer error] Card confirmation number not entered', 'woocommerce-for-paygent-payment-main' ),
			'1G46' => __( '[Issuer error] JIS 2nd page information error', 'woocommerce-for-paygent-payment-main' ),
			'1G54' => __( '[Issuer error] Number of uses per day amount exceeded', 'woocommerce-for-paygent-payment-main' ),
			'1G55' => __( '[Issuer error] Daily usage limit exceeded', 'woocommerce-for-paygent-payment-main' ),
			'1G56' => __( '[Issuer error] Credit card import', 'woocommerce-for-paygent-payment-main' ),
			'1G60' => __( '[Issuer error] Accident card', 'woocommerce-for-paygent-payment-main' ),
			'1G61' => __( '[Issuer error] Invalid card', 'woocommerce-for-paygent-payment-main' ),
			'1G65' => __( '[Issuer error] Membership number error', 'woocommerce-for-paygent-payment-main' ),
			'1G67' => __( '[Issuer error] Product code error', 'woocommerce-for-paygent-payment-main' ),
			'1G68' => __( '[Issuer error] Amount error', 'woocommerce-for-paygent-payment-main' ),
			'1G69' => __( '[Issuer error] Tax shipping error', 'woocommerce-for-paygent-payment-main' ),
			'1G70' => __( '[Issuer error] Bonus count error', 'woocommerce-for-paygent-payment-main' ),
			'1G71' => __( '[Issuer error] Bonus month error', 'woocommerce-for-paygent-payment-main' ),
			'1G72' => __( '[Issuer error] Bonus amount errorv', 'woocommerce-for-paygent-payment-main' ),
			'1G73' => __( '[Issuer error] Payment start month error', 'woocommerce-for-paygent-payment-main' ),
			'1G74' => __( '[Issuer error] Division count error', 'woocommerce-for-paygent-payment-main' ),
			'1G75' => __( '[Issuer error] Split amount error', 'woocommerce-for-paygent-payment-main' ),
			'1G76' => __( '[Issuer error] Initial amount error', 'woocommerce-for-paygent-payment-main' ),
			'1G77' => __( '[Issuer error] Business classification error', 'woocommerce-for-paygent-payment-main' ),
			'1G78' => __( '[Issuer error] Payment classification error', 'woocommerce-for-paygent-payment-main' ),
			'1G79' => __( '[Issuer error] Inquiry category error', 'woocommerce-for-paygent-payment-main' ),
			'1G80' => __( '[Issuer error] Cancellation classification error', 'woocommerce-for-paygent-payment-main' ),
			'1G81' => __( '[Issuer error] Handling classification/transaction classification error', 'woocommerce-for-paygent-payment-main' ),
			'1G83' => __( '[Issuer error] Expiration date error', 'woocommerce-for-paygent-payment-main' ),
			'1G84' => __( '[Issuer error] Approval number error', 'woocommerce-for-paygent-payment-main' ),
			'1G85' => __( '[Issuer error] CAFIS agency processing error', 'woocommerce-for-paygent-payment-main' ),
			'1G92' => __( '[Issuer error] Optional message output', 'woocommerce-for-paygent-payment-main' ),
			'1G94' => __( '[Issuer error] Cycle number error', 'woocommerce-for-paygent-payment-main' ),
			'1G95' => __( '[Issuer error] The relevant business has ended online', 'woocommerce-for-paygent-payment-main' ),
			'1G96' => __( '[Issuer error] Accident card data error', 'woocommerce-for-paygent-payment-main' ),
			'1G97' => __( '[Issuer error] Rejection of the request', 'woocommerce-for-paygent-payment-main' ),
			'1G98' => __( '[Issuer error] Business error for the company concerned', 'woocommerce-for-paygent-payment-main' ),
			'1G99' => __( '[Issuer error] Connection request refused by our company', 'woocommerce-for-paygent-payment-main' ),
		);
	}
}
