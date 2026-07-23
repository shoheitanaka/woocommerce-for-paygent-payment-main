// @ts-check
const { test, expect } = require('@playwright/test');
const {
	wpCli,
	getPaygentCcSettings,
	updatePaygentCcSettings,
	setupBlockCheckoutPage,
	teardownBlockCheckoutPage,
} = require('./helpers/wp-cli');
const {
	prefetchPaygentTokenJs,
	routePaygentTokenJs,
	goToBlockCheckout,
	waitForPaygentToken,
	becomesVisible,
	fillBillingBlock,
	selectPaymentMethodBlock,
	placeOrderBlock,
	blockCheckoutErrorNotice,
	raceOutcome,
} = require('./helpers/block-checkout');

/**
 * Paygent CC Gateway — WooCommerce Block Checkout E2E tests (logged-in member).
 *
 * Tests saved-card flows:
 *   F-1: First checkout with new card + "save card" checkbox → card saved in WC Tokens
 *   F-2: Second checkout selecting the saved card (CVC re-entry only)
 *
 * Prerequisites:
 *   Same PAYGENT_TEST_MID / CID / CPASS / TOKENKEY env vars as guest tests.
 *
 * The test user `paygent-e2e-member` is created by global.setup.js.
 *
 * Run:
 *   PAYGENT_TEST_MID=xxx ... npx playwright test --project=e2e-member checkout-sandbox.block-cc.member
 */

const REQUIRED_ENV = ['PAYGENT_TEST_MID', 'PAYGENT_TEST_CID', 'PAYGENT_TEST_CPASS', 'PAYGENT_TEST_TOKENKEY'];

const CARD = { OK: '4900000000000000' };

const MEMBER = {
	login:    'paygent-e2e-member',
	password: 'member-e2e-pass-1',
	email:    'paygent-e2e-member@example.com',
};

/** @type {string} */
let productId = '';
/** @type {string} */
let blockCheckoutUrl = '';
/** @type {string} */
let blockPageId = '';
/** @type {string} */
let originalCheckoutPageId = '';
/** @type {string} */
let cachedPaygentTokenJs = '';
/** @type {string[]} */
const createdOrderIds = [];

// ─── setup / teardown ─────────────────────────────────────────────────────────

test.beforeAll( async ( { baseURL } ) => {
	const setup = setupBlockCheckoutPage( baseURL );
	blockCheckoutUrl       = setup.blockCheckoutUrl;
	blockPageId            = setup.pageId;
	originalCheckoutPageId = setup.originalCheckoutPageId;

	// Enable store_card_info so "save card" feature is available.
	const current = getPaygentCcSettings();
	if ( current.store_card_info !== 'yes' ) {
		updatePaygentCcSettings( { store_card_info: 'yes' } );
	}

	const out   = wpCli( `post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv` );
	const match = out.match( /^(\d+)$/m );
	productId   = match ? match[1] : '';

	cachedPaygentTokenJs = await prefetchPaygentTokenJs();
} );

test.afterAll( async () => {
	teardownBlockCheckoutPage( originalCheckoutPageId, blockPageId );

	// Clean up saved tokens for the member user.
	const memberId = wpCli( `user get ${ MEMBER.login } --field=ID` ).trim();
	if ( memberId.match( /^\d+$/ ) ) {
		wpCli( `eval "
			\\$tokens = WC_Payment_Tokens::get_customer_tokens( ${ memberId }, 'paygent_cc' );
			foreach ( \\$tokens as \\$t ) { \\$t->delete(); }
			echo 'done';
		"` );
	}

	for ( const id of createdOrderIds ) {
		wpCli( `wc order delete ${ id } --force --user=1` );
	}
} );

function requireSandboxCredentials() {
	const missing = REQUIRED_ENV.filter( ( v ) => ! process.env[v] );
	if ( missing.length ) {
		test.skip( true, `Sandbox credentials not set: ${ missing.join( ', ' ) }` );
	}
}

// ─── helpers ─────────────────────────────────────────────────────────────────
// Navigation / billing / place-order logic lives in helpers/block-checkout.js;
// only the CC-specific pieces are defined here.

async function goToCcBlockCheckout( page, pid = productId ) {
	await routePaygentTokenJs( page, cachedPaygentTokenJs );
	await goToBlockCheckout( page, blockCheckoutUrl, pid );
	return waitForPaygentToken( page );
}

async function selectPaygentCCBlock( page ) {
	await selectPaymentMethodBlock( page, 'paygent_cc', /クレジットカード|Credit Card/i );
	// When saved cards exist the stored-card CVC field appears instead of #paygent-cc-number.
	await expect(
		page.locator( '#paygent-cc-number, #paygent-cc-stored-cvc' ).first()
	).toBeVisible( { timeout: 10_000 } );
}

async function fillNewCardBlock( page, cardNumber = CARD.OK ) {
	await page.locator( '#paygent-cc-number' ).fill( cardNumber );
	await page.locator( '#paygent-cc-expiry' ).fill( '12 / 30' );
	await page.locator( '#paygent-cc-cvc'    ).fill( '123' );
}

/**
 * Place the order and wait for the thank-you page.
 * Environment-level sandbox rejections (unregistered source IP / connection
 * failure) skip the test instead of failing it — real payment errors still
 * fail with the notice text.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>} Order ID ('' when the test was skipped).
 */
async function placeOrderAndAwaitThankyou( page ) {
	await placeOrderBlock( page );

	const outcome = await raceOutcome( [
		[ 'completed', page.waitForURL( /order-received/, { timeout: 90_000 } ) ],
		[ 'error',     blockCheckoutErrorNotice( page ).waitFor( { state: 'visible', timeout: 60_000 } ) ],
	], 90_000 );

	if ( outcome === 'error' ) {
		const message = ( await blockCheckoutErrorNotice( page ).textContent().catch( () => '' ) )?.trim() || '';
		if ( /P01[56]|IPアドレス/.test( message ) ) {
			test.skip( true, `Sandbox unreachable from this environment: ${ message }` );
			return '';
		}
		throw new Error( `Block CC checkout failed with notice: ${ message }` );
	}
	if ( outcome === 'timeout' ) {
		throw new Error( 'Block CC checkout did not reach order-received in time' );
	}

	const orderId = page.url().match( /order-received\/(\d+)/ )?.[1] || '';
	expect( orderId ).toBeTruthy();
	return orderId;
}

// ═════════════════════════════════════════════════════════════════════════════
// Group F: Saved card flow
// ═════════════════════════════════════════════════════════════════════════════

test.describe( 'Block-CC F: Saved card (保存カード)', () => {
	test.setTimeout( 120_000 );
	test.beforeEach( requireSandboxCredentials );

	test( 'F-1: Member completes Block checkout with new card + save card checked', async ( { page, baseURL } ) => {
		if ( ! productId ) test.skip( true, 'Test product not found' );

		const tokenReady = await goToCcBlockCheckout( page );
		if ( ! tokenReady ) test.skip( true, 'sandbox.paygent.co.jp unreachable' );

		await fillBillingBlock( page, { email: MEMBER.email } );
		await selectPaygentCCBlock( page );

		// WooCommerce Blocks renders the save card checkbox when showSaveOption: true.
		// The container class is "wc-block-components-payment-methods__save-card-info".
		// When checked, WC Blocks sets shouldSavePayment: true and passes it to
		// CardForm, which forwards it as paygent_save_card_info: 'yes' in paymentMethodData.
		const saveCardInput = page.locator(
			'.wc-block-components-payment-methods__save-card-info input[type="checkbox"]'
		).first();

		const checkboxVisible = await becomesVisible( saveCardInput, 5_000 );
		if ( checkboxVisible ) {
			const alreadyChecked = await saveCardInput.isChecked().catch( () => false );
			if ( ! alreadyChecked ) {
				await saveCardInput.check( { force: true } );
			}
			console.log( '  [F-1] Save card checkbox checked.' );
		} else {
			console.log( '  [F-1] Save card checkbox not visible — store_card_info may not be enabled.' );
		}

		await fillNewCardBlock( page );
		const orderId = await placeOrderAndAwaitThankyou( page );
		if ( ! orderId ) return;
		createdOrderIds.push( orderId );

		// Verify WC Payment Token was created when checkbox was visible and checked.
		const memberId = wpCli( `user get ${ MEMBER.login } --field=ID` ).trim();
		if ( checkboxVisible && memberId.match( /^\d+$/ ) ) {
			const tokenCount = wpCli( `eval "
				\\$tokens = WC_Payment_Tokens::get_customer_tokens( ${ memberId }, 'paygent_cc' );
				echo count( \\$tokens );
			"` ).trim();
			expect( parseInt( tokenCount, 10 ) ).toBeGreaterThanOrEqual( 1 );
		}
	} );

	test( 'F-2: Member uses saved card on second Block checkout', async ( { page } ) => {
		if ( ! productId ) test.skip( true, 'Test product not found' );

		// Verify there is a saved card before attempting this test.
		const memberId = wpCli( `user get ${ MEMBER.login } --field=ID` ).trim();
		if ( ! memberId.match( /^\d+$/ ) ) {
			test.skip( true, 'Member user not found' );
			return;
		}
		const tokenCount = wpCli( `eval "
			\\$tokens = WC_Payment_Tokens::get_customer_tokens( ${ memberId }, 'paygent_cc' );
			echo count( \\$tokens );
		"` ).trim();
		if ( parseInt( tokenCount, 10 ) < 1 ) {
			test.skip( true, 'No saved card found — run F-1 first with save card checked' );
			return;
		}

		const tokenReady = await goToCcBlockCheckout( page );
		if ( ! tokenReady ) test.skip( true, 'sandbox.paygent.co.jp unreachable' );

		await fillBillingBlock( page, { email: MEMBER.email } );

		// Saved cards are rendered natively by WC Blocks as top-level radio
		// options (wc-saved-payment-method-token-paygent_cc). Select the first
		// saved token instead of the gateway's own card form.
		const savedTokenRadio = page
			.locator( 'input[name="wc-saved-payment-method-token-paygent_cc"]' )
			.first();

		const hasSavedToken = await becomesVisible( savedTokenRadio, 5_000 );
		if ( ! hasSavedToken ) {
			test.skip( true, 'Native saved-card option not rendered — token may not be loaded in this session' );
			return;
		}

		await savedTokenRadio.check( { force: true } ).catch( () => {} );

		// SavedTokenForm renders below the selected token; enter the CVC.
		await page.locator( '#paygent_cc-saved-token-cvc' ).fill( '123' );

		const orderId = await placeOrderAndAwaitThankyou( page );
		if ( ! orderId ) return;
		createdOrderIds.push( orderId );

		// Verify the order used a stored card: _paygent_customer_card_id should be set.
		const customerCardId = wpCli( `eval "echo wc_get_order(${ orderId })->get_meta('_paygent_customer_card_id');"` ).trim();
		expect( customerCardId.length ).toBeGreaterThan( 0 );
	} );
} );
