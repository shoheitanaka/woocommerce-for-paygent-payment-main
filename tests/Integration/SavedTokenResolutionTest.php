<?php

namespace Paygent\Tests\Integration;

/**
 * Integration tests for WC_Gateway_Paygent_CC::get_posted_saved_token_customer_card_id().
 *
 * The Block checkout native saved-card flow posts a WC payment token id as
 * wc-{gateway_id}-payment-token. The gateway must resolve it to the Paygent
 * customer_card_id only when the token exists, belongs to the current user,
 * belongs to the same gateway, and carries a customer_card_id meta.
 */
class SavedTokenResolutionTest extends TestCase {

	/**
	 * Test user id.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Created token ids for cleanup.
	 *
	 * @var int[]
	 */
	private $token_ids = array();

	public function setUp(): void {
		parent::setUp();
		update_option( 'wc-paygent-cc', 'yes' );
		$this->user_id = wp_insert_user(
			array(
				'user_login' => 'paygent_token_test_' . wp_rand(),
				'user_pass'  => wp_generate_password(),
				'role'       => 'customer',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	public function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->token_ids as $token_id ) {
			$token = \WC_Payment_Tokens::get( $token_id );
			if ( $token ) {
				$token->delete( true );
			}
		}
		$this->token_ids = array();
		wp_set_current_user( 0 );
		if ( $this->user_id ) {
			wp_delete_user( $this->user_id );
		}
		unset( $_POST['wc-paygent_cc-payment-token'] );
		delete_option( 'wc-paygent-cc' );
		parent::tearDown();
	}

	/**
	 * Create a CC token with customer_card_id meta.
	 *
	 * @param int    $user_id          Token owner.
	 * @param string $gateway_id       Gateway id.
	 * @param string $customer_card_id Paygent customer card id ('' to skip meta).
	 * @return \WC_Payment_Token_CC
	 */
	private function create_token( int $user_id, string $gateway_id = 'paygent_cc', string $customer_card_id = 'ccid-123' ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'test-token-' . wp_rand() );
		$token->set_gateway_id( $gateway_id );
		$token->set_user_id( $user_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '1234' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		if ( '' !== $customer_card_id ) {
			$token->add_meta_data( 'customer_card_id', $customer_card_id );
		}
		$token->save();
		$this->token_ids[] = $token->get_id();

		return $token;
	}

	/**
	 * Get the CC gateway instance.
	 *
	 * @return \WC_Gateway_Paygent_CC
	 */
	private function gateway(): \WC_Gateway_Paygent_CC {
		return new \WC_Gateway_Paygent_CC();
	}

	public function test_returns_null_when_no_token_posted(): void {
		unset( $_POST['wc-paygent_cc-payment-token'] );
		$this->assertNull( $this->gateway()->get_posted_saved_token_customer_card_id( 'paygent_cc' ) );
	}

	public function test_resolves_customer_card_id_for_own_token(): void {
		$token = $this->create_token( $this->user_id );

		$_POST['wc-paygent_cc-payment-token'] = (string) $token->get_id();
		$this->assertSame( 'ccid-123', $this->gateway()->get_posted_saved_token_customer_card_id( 'paygent_cc' ) );
	}

	public function test_rejects_token_of_another_user(): void {
		$other_user = wp_insert_user(
			array(
				'user_login' => 'paygent_token_other_' . wp_rand(),
				'user_pass'  => wp_generate_password(),
				'role'       => 'customer',
			)
		);
		$token      = $this->create_token( $other_user );

		$_POST['wc-paygent_cc-payment-token'] = (string) $token->get_id();
		$this->assertFalse( $this->gateway()->get_posted_saved_token_customer_card_id( 'paygent_cc' ) );

		wp_delete_user( $other_user );
	}

	public function test_rejects_token_of_another_gateway(): void {
		$token = $this->create_token( $this->user_id, 'paygent_mccc' );

		$_POST['wc-paygent_cc-payment-token'] = (string) $token->get_id();
		$this->assertFalse( $this->gateway()->get_posted_saved_token_customer_card_id( 'paygent_cc' ) );
	}

	public function test_rejects_token_without_customer_card_id_meta(): void {
		$token = $this->create_token( $this->user_id, 'paygent_cc', '' );

		$_POST['wc-paygent_cc-payment-token'] = (string) $token->get_id();
		$this->assertFalse( $this->gateway()->get_posted_saved_token_customer_card_id( 'paygent_cc' ) );
	}

	public function test_rejects_nonexistent_token_id(): void {
		$_POST['wc-paygent_cc-payment-token'] = '999999999';
		$this->assertFalse( $this->gateway()->get_posted_saved_token_customer_card_id( 'paygent_cc' ) );
	}

	public function test_rejects_token_for_logged_out_user(): void {
		$token = $this->create_token( $this->user_id );
		wp_set_current_user( 0 );

		$_POST['wc-paygent_cc-payment-token'] = (string) $token->get_id();
		$this->assertFalse( $this->gateway()->get_posted_saved_token_customer_card_id( 'paygent_cc' ) );
	}

	public function test_mccc_token_resolves_via_mccc_gateway_id(): void {
		$token = $this->create_token( $this->user_id, 'paygent_mccc', 'ccid-mccc' );

		$_POST['wc-paygent_mccc-payment-token'] = (string) $token->get_id();
		$this->assertSame( 'ccid-mccc', $this->gateway()->get_posted_saved_token_customer_card_id( 'paygent_mccc' ) );
		unset( $_POST['wc-paygent_mccc-payment-token'] );
	}
}
