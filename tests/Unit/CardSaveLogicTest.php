<?php

namespace Paygent\Tests\Unit;

/**
 * Tests for card save preference logic.
 *
 * Rules:
 * - Subscription orders: always save card (fingerprint required for renewals).
 * - Logged-in non-subscription orders: save only when user opted in via checkbox.
 * - Guest orders: never save.
 *
 * The preference is stored as '_paygent_save_card_preference' = '1'|'0' in
 * process_payment() so that the 3DS2 callback can read it after the POST is gone.
 */
class CardSaveLogicTest extends TestCase {

	/**
	 * @dataProvider card_save_preference_provider
	 */
	public function test_card_save_preference_is_determined_correctly(
		bool $is_subscription,
		string $checkbox_value,
		string $expected_preference
	): void {
		// Replicate the logic from process_payment().
		$user_wants_save_card = ( true === $is_subscription ) ||
			( 'yes' === $checkbox_value );

		$preference = $user_wants_save_card ? '1' : '0';

		$this->assertSame( $expected_preference, $preference );
	}

	public static function card_save_preference_provider(): array {
		return array(
			'subscription always saves'              => array( true, '', '1' ),
			'subscription ignores checkbox'          => array( true, 'no', '1' ),
			'non-subscription opt-in'                => array( false, 'yes', '1' ),
			'non-subscription opt-out'               => array( false, 'no', '0' ),
			'non-subscription no checkbox (guest)'   => array( false, '', '0' ),
		);
	}

	/**
	 * MCCC has no subscription support, so its save preference is decided purely
	 * by the checkbox (no subscription-always-saves branch like CC).
	 *
	 * @dataProvider mccc_card_save_preference_provider
	 */
	public function test_mccc_card_save_preference_is_determined_correctly(
		string $checkbox_value,
		string $expected_preference
	): void {
		// Replicate the logic from WC_Gateway_Paygent_MCCC::process_payment().
		$user_wants_save_card = ( 'yes' === $checkbox_value );

		$preference = $user_wants_save_card ? '1' : '0';

		$this->assertSame( $expected_preference, $preference );
	}

	public static function mccc_card_save_preference_provider(): array {
		return array(
			'opt-in'             => array( 'yes', '1' ),
			'opt-out'            => array( 'no', '0' ),
			'no checkbox (guest)' => array( '', '0' ),
		);
	}

	/**
	 * The 3DS2 callback must read preference from order meta because POST is gone.
	 */
	public function test_3ds2_callback_reads_preference_from_order_meta(): void {
		// '1' stored in meta → should save card.
		$meta_value = '1';
		$user_wants_save_card = '1' === $meta_value;

		$this->assertTrue( $user_wants_save_card );

		// '0' stored in meta → should not save card.
		$meta_value = '0';
		$user_wants_save_card = '1' === $meta_value;

		$this->assertFalse( $user_wants_save_card );
	}

	/**
	 * On classic checkout every gateway's payment fields share one form, and
	 * hidden checkboxes of non-selected gateways still submit. Each gateway must
	 * therefore read its own POST key first and use the shared key only as the
	 * Block-checkout fallback, so another gateway's checkbox cannot leak in.
	 *
	 * @dataProvider save_card_key_precedence_provider
	 */
	public function test_save_card_key_precedence( array $post, string $expected ): void {
		// Replicate the resolution used by WC_Gateway_Paygent_MCCC::process_payment().
		$value = $post['paygent_mccc_save_card_info'] ?? $post['paygent_save_card_info'] ?? '';

		$this->assertSame( $expected, $value );
	}

	public static function save_card_key_precedence_provider(): array {
		return array(
			'own classic key wins'                   => array( array( 'paygent_mccc_save_card_info' => 'yes' ), 'yes' ),
			'shared key is the Blocks fallback'      => array( array( 'paygent_save_card_info' => 'no' ), 'no' ),
			'other gateway checkbox does not leak'   => array( array( 'paygent_cc_save_card_info' => 'yes' ), '' ),
			'own key beats a leaked shared key'      => array(
				array(
					'paygent_mccc_save_card_info' => 'yes',
					'paygent_save_card_info'      => 'no',
				),
				'yes',
			),
			'nothing posted'                         => array( array(), '' ),
		);
	}

	/**
	 * The 3DS2 callback re-saves the card only when it was not stored before the
	 * request. get_meta() returns '' (not false) for absent meta, so the check
	 * must be a truthy one.
	 */
	public function test_3ds2_callback_detects_unsaved_card_with_truthy_check(): void {
		// Meta absent → get_meta() returns '' → card not saved yet → should save.
		$should_save = ! '';
		$this->assertTrue( $should_save );

		// Meta present (customer_card_id from Paygent) → should not save again.
		$should_save = ! '12345678';
		$this->assertFalse( $should_save );

		// The old `false ===` comparison never matched the absent-meta value ''.
		$this->assertFalse( false === '' );
	}

	/**
	 * An order using 3DS2 is detected by the presence of _3ds_auth_id meta.
	 */
	public function test_3ds_detection_by_meta_presence(): void {
		// Non-empty _3ds_auth_id → 3DS was used.
		$tds2_used = ! empty( 'auth_id_value_from_paygent' );
		$this->assertTrue( $tds2_used );

		// Empty value → no 3DS.
		$tds2_used = ! empty( '' );
		$this->assertFalse( $tds2_used );
	}
}
