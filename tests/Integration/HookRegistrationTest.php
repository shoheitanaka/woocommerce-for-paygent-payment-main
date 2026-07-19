<?php

namespace Paygent\Tests\Integration;

/**
 * Integration tests for gateway hook registration.
 *
 * The MCCC gateway builds an internal WC_Gateway_Paygent_CC helper instance,
 * whose constructor registers global hooks. Those registrations must be
 * removed for the helper — otherwise a paygent_cc token deletion fires the
 * Paygent delete telegram once per CC instance (issue #20 acceptance
 * criterion: the delete API must be called exactly once).
 */
class HookRegistrationTest extends TestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\WC_Gateway_Paygent_MCCC' ) ) {
			require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-mccc.php';
		}
	}

	public function test_mccc_helper_cc_instance_hooks_are_removed(): void {
		$mccc = new \WC_Gateway_Paygent_MCCC();

		$this->assertFalse(
			has_action( 'woocommerce_payment_token_deleted', array( $mccc->paygent_cc, 'paygent_delete_card' ) ),
			'Helper CC instance must not listen to token deletion.'
		);
		$this->assertFalse(
			has_action( 'woocommerce_order_status_completed', array( $mccc->paygent_cc, 'order_paygent_cc_status_completed' ) ),
			'Helper CC instance must not listen to order completion.'
		);
	}

	public function test_mccc_own_delete_handler_is_registered(): void {
		$mccc = new \WC_Gateway_Paygent_MCCC();

		$this->assertNotFalse(
			has_action( 'woocommerce_payment_token_deleted', array( $mccc, 'paygent_mccc_delete_card' ) )
		);
	}

	public function test_standalone_cc_gateway_keeps_its_hooks(): void {
		$cc   = new \WC_Gateway_Paygent_CC();
		$mccc = new \WC_Gateway_Paygent_MCCC();

		$this->assertNotFalse(
			has_action( 'woocommerce_payment_token_deleted', array( $cc, 'paygent_delete_card' ) ),
			'Removing the helper instance hooks must not unhook other CC instances.'
		);
	}
}
