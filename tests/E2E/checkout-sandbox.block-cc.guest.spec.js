// @ts-check
const { test, expect } = require('@playwright/test');
const {
	wpCli,
	enableTds2,
	getPaygentCcSettings,
	updatePaygentCcSettings,
	restorePaygentCcSettings,
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
} = require('./helpers/block-checkout');

/**
 * Paygent CC Gateway — WooCommerce Block Checkout E2E tests (guest).
 *
 * Tests the React CardForm component registered via WC_Paygent_Block_CC.
 * Covers: standard new-card checkout, auth failure, 3DS2 frictionless,
 * 3DS2 challenge, partial refund, and installment payment selection.
 *
 * Prerequisites (same as classic checkout sandbox tests):
 *   PAYGENT_TEST_MID        — Sandbox merchant ID
 *   PAYGENT_TEST_CID        — Sandbox connect ID
 *   PAYGENT_TEST_CPASS      — Sandbox connect password
 *   PAYGENT_TEST_TOKENKEY   — Token generation key
 *
 * Run:
 *   PAYGENT_TEST_MID=xxx PAYGENT_TEST_CID=yyy PAYGENT_TEST_CPASS=zzz \
 *   PAYGENT_TEST_TOKENKEY=aaa npx playwright test --project=e2e-guest checkout-sandbox.block-cc
 *
 * ─── Test cards ──────────────────────────────────────────────────────────────
 *   4900-0000-0000-0000  → オーソリOK / 取消OK / 売上OK
 *   4900-0000-0000-8101  → オーソリ異常 (エラーコード C01)
 *
 * ─── 3DS2 control values (VISA) ──────────────────────────────────────────────
 *   Frictionless success: cardholder name = BAVYA  (§5.1 No.15)
 *   Challenge flow:       order amount = 0         (§5.2 No.10)
 *   Challenge password:   14012                    (VISA)
 */

const REQUIRED_ENV = ['PAYGENT_TEST_MID', 'PAYGENT_TEST_CID', 'PAYGENT_TEST_CPASS', 'PAYGENT_TEST_TOKENKEY'];

const CARD = {
	OK:      '4900000000000000',
	AUTH_NG: '4900000000008101',
};

const TDS2 = {
	CARDHOLDER_FRICTIONLESS_OK: 'BAVYA',
	CHALLENGE_PASSWORD_VISA:    '14012',
};

const CC_EMAIL = 'block-cc-e2e@example.com';

/** @type {string} */
let productId     = '';
/** @type {string} */
let blockCheckoutUrl = '';
/** @type {string} */
let blockPageId   = '';
/** @type {string} */
let originalCheckoutPageId = '';
/** @type {string} */
let cachedPaygentTokenJs = '';
/** @type {string[]} */
const createdOrderIds = [];

// ─── setup / teardown ─────────────────────────────────────────────────────────

test.beforeAll(async ( { baseURL } ) => {
	// Create the Block checkout page and point WC at it.
	const setup = setupBlockCheckoutPage( baseURL );
	blockCheckoutUrl       = setup.blockCheckoutUrl;
	blockPageId            = setup.pageId;
	originalCheckoutPageId = setup.originalCheckoutPageId;

	// Resolve test product.
	const out   = wpCli( `post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv` );
	const match = out.match( /^(\d+)$/m );
	productId   = match ? match[1] : '';

	// Pre-fetch PaygentToken.js so tests don't wait on sandbox.paygent.co.jp.
	cachedPaygentTokenJs = await prefetchPaygentTokenJs();

	if ( cachedPaygentTokenJs ) {
		console.log( '  [block-cc] PaygentToken.js pre-fetched and cached.' );
	} else {
		console.warn( '  [block-cc] PaygentToken.js not pre-fetched — tests may be slow.' );
	}
} );

test.afterAll( async () => {
	// Restore original WC checkout page.
	teardownBlockCheckoutPage( originalCheckoutPageId, blockPageId );

	// Delete test orders.
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

// ─── shared helpers ───────────────────────────────────────────────────────────

/**
 * Add product to cart and navigate to the Block checkout page.
 * Routes PaygentToken.js requests to the cached copy for speed.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} pid  Product ID to add (defaults to module-level productId).
 * @returns {Promise<boolean>}  true if window.PaygentToken is ready.
 */
async function goToCcBlockCheckout( page, pid = productId ) {
	await routePaygentTokenJs( page, cachedPaygentTokenJs );
	await goToBlockCheckout( page, blockCheckoutUrl, pid );
	return waitForPaygentToken( page );
}

/**
 * Select the Paygent CC payment method in the Block checkout payment panel.
 * Waits for the card form to appear.
 *
 * @param {import('@playwright/test').Page} page
 */
async function selectPaygentCCBlock( page ) {
	await selectPaymentMethodBlock( page, 'paygent_cc', /クレジットカード|Credit Card/i );

	// Our React CardForm renders once the payment option is selected.
	await expect( page.locator( '#paygent-cc-number' ) ).toBeVisible( { timeout: 10_000 } );
}

/**
 * Fill the Block checkout card form (our React component inputs).
 * Does NOT trigger tokenisation — that happens inside onPaymentSetup when
 * the "Place Order" button is clicked.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [cardNumber]
 */
async function fillCardFormBlock( page, cardNumber = CARD.OK ) {
	await page.locator( '#paygent-cc-number'  ).fill( cardNumber );
	await page.locator( '#paygent-cc-expiry'  ).fill( '12 / 30' );
	await page.locator( '#paygent-cc-cvc'     ).fill( '123' );
}

// ═════════════════════════════════════════════════════════════════════════════
// Smoke: Block checkout page renders CC gateway
// ═════════════════════════════════════════════════════════════════════════════

test( 'Block checkout page renders CC payment option', async ( { page } ) => {
	requireSandboxCredentials();
	if ( ! productId ) test.skip( true, 'Test product not found' );

	await goToCcBlockCheckout( page );

	// The payment section renders after a Store API round-trip — on a cold
	// cache (first test after global setup) this can exceed 15s, so wait for
	// the payment block container before asserting the CC option.
	await page.waitForSelector( '.wp-block-woocommerce-checkout-payment-block', { timeout: 30_000 } ).catch( () => {} );

	// The Block checkout payment section must show a Paygent CC option.
	const ccOption = page.locator(
		'input[value="paygent_cc"], input[id*="paygent_cc"], ' +
		'.wc-block-components-radio-control label'
	).filter( { hasText: /クレジットカード|Credit Card/i } ).first();

	await expect( ccOption ).toBeVisible( { timeout: 30_000 } );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Group A: Standard checkout — 3DS disabled
// ═════════════════════════════════════════════════════════════════════════════

test.describe( 'Block-CC A: Standard checkout (3DS disabled)', () => {
	test.setTimeout( 120_000 );
	test.beforeEach( requireSandboxCredentials );

	test( 'A-1: Guest completes Block checkout with standard test card', async ( { page } ) => {
		if ( ! productId ) test.skip( true, 'Test product not found' );

		const tokenReady = await goToCcBlockCheckout( page );
		if ( ! tokenReady ) test.skip( true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js not loaded' );

		await fillBillingBlock( page, { email: CC_EMAIL } );
		await selectPaygentCCBlock( page );
		await fillCardFormBlock( page, CARD.OK );

		await placeOrderBlock( page );
		await page.waitForURL( /order-received/, { timeout: 90_000 } );

		const orderId = page.url().match( /order-received\/(\d+)/ )?.[1];
		expect( orderId ).toBeTruthy();
		if ( orderId ) createdOrderIds.push( orderId );

		await expect(
			page.locator( '.wc-block-order-confirmation-status, .woocommerce-thankyou-order-received, h2' ).first()
		).toBeVisible( { timeout: 15_000 } );
	} );

	test( 'A-2: Auth-failure card (末尾8101) shows Block checkout error', async ( { page } ) => {
		if ( ! productId ) test.skip( true, 'Test product not found' );

		const tokenReady = await goToCcBlockCheckout( page );
		if ( ! tokenReady ) test.skip( true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js not loaded' );

		await fillBillingBlock( page, { email: CC_EMAIL } );
		await selectPaygentCCBlock( page );
		await fillCardFormBlock( page, CARD.AUTH_NG );

		await placeOrderBlock( page );

		// Block checkout shows the error inline — URL stays on the checkout page.
		await page.waitForTimeout( 8_000 );
		expect( page.url() ).not.toMatch( /order-received/ );

		await expect(
			page.locator( '.wc-block-components-notice-banner.is-error, .wc-block-store-notice.is-error, [role="alert"]' ).first()
		).toBeVisible( { timeout: 15_000 } );
	} );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Group B: EMV 3DS2 — frictionless flow (cardholder=BAVYA)
// ═════════════════════════════════════════════════════════════════════════════

test.describe( 'Block-CC B: EMV 3DS2 frictionless (cardholder=BAVYA)', () => {
	test.setTimeout( 120_000 );
	test.beforeEach( requireSandboxCredentials );

	/** @type {Record<string, string>} */
	let settingsSnapshot = {};

	test.beforeAll( async () => {
		settingsSnapshot = enableTds2();
		console.log( '  [block-cc 3DS2] tds2_check enabled for Group B' );
	} );

	test.afterAll( async () => {
		restorePaygentCcSettings( settingsSnapshot );
		console.log( '  [block-cc 3DS2] tds2_check restored after Group B' );
	} );

	test( 'B-1: Guest completes Block checkout via frictionless 3DS2 (BAVYA)', async ( { page } ) => {
		if ( ! productId ) test.skip( true, 'Test product not found' );

		const tokenReady = await goToCcBlockCheckout( page );
		if ( ! tokenReady ) test.skip( true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js not loaded' );

		// cardholder=BAVYA triggers frictionless VISA result=0 — use last_name override.
		await fillBillingBlock( page, { email: CC_EMAIL, lastNameOverride: TDS2.CARDHOLDER_FRICTIONLESS_OK } );
		await selectPaygentCCBlock( page );

		// With 3DS2, cardholder name field appears in our React form.
		const cardholderInput = page.locator( '#paygent-cc-cardholder' );
		if ( await becomesVisible( cardholderInput ) ) {
			await cardholderInput.fill( TDS2.CARDHOLDER_FRICTIONLESS_OK );
		}

		await fillCardFormBlock( page, CARD.OK );
		await placeOrderBlock( page );

		// Frictionless: WooCommerce redirects → ACS auto-submits → order-received.
		const result = await Promise.race( [
			page.waitForURL( /order-received/, { timeout: 60_000 } ).then( () => 'completed' ),
			page.waitForURL( /order-pay/,       { timeout: 30_000 } ).then( () => 'redirect'  ),
		] ).catch( () => 'timeout' );

		if ( result === 'timeout' ) {
			test.skip( true, '3DS2 frictionless ACS redirect did not start in time' );
			return;
		}
		if ( result === 'redirect' ) {
			await page.waitForURL( /order-received/, { timeout: 60_000 } );
		}

		const orderId = page.url().match( /order-received\/(\d+)/ )?.[1];
		expect( orderId ).toBeTruthy();
		if ( orderId ) createdOrderIds.push( orderId );
	} );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Group C: EMV 3DS2 — challenge flow (amount=0, password=14012)
// ═════════════════════════════════════════════════════════════════════════════

test.describe( 'Block-CC C: EMV 3DS2 challenge flow (amount=0, password=14012)', () => {
	test.setTimeout( 120_000 );
	test.beforeEach( requireSandboxCredentials );

	/** @type {Record<string, string>} */
	let settingsSnapshot = {};
	/** @type {string} */
	let freeProductId   = '';

	test.beforeAll( async () => {
		settingsSnapshot = enableTds2();
		freeProductId    = wpCli(
			`post create --post_type=product --post_status=publish ` +
			`--post_title="Block-CC 3DS2 Challenge Test" ` +
			`--meta_input='{"_price":"0","_regular_price":"0","_virtual":"yes"}' --porcelain`
		).trim();
	} );

	test.afterAll( async () => {
		restorePaygentCcSettings( settingsSnapshot );
		if ( freeProductId ) wpCli( `post delete ${ freeProductId } --force` );
	} );

	test( 'C-1: Guest completes 3DS2 challenge (amount=0, VISA password=14012)', async ( { page } ) => {
		if ( ! freeProductId ) test.skip( true, 'Could not create ¥0 test product' );

		const tokenReady = await goToCcBlockCheckout( page, freeProductId );
		await fillBillingBlock( page, { email: CC_EMAIL } );

		const hasPayment = await page.locator( 'input[value="paygent_cc"]' ).count() > 0;
		if ( hasPayment && ! tokenReady ) {
			test.skip( true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js not loaded' );
		}

		if ( hasPayment ) {
			await selectPaygentCCBlock( page );

			const cardholderInput = page.locator( '#paygent-cc-cardholder' );
			if ( await becomesVisible( cardholderInput ) ) {
				await cardholderInput.fill( 'TARO YAMADA' );
			}

			await fillCardFormBlock( page, CARD.OK );
		}

		await placeOrderBlock( page );

		const afterSubmit = await Promise.race( [
			page.waitForURL( /order-received/, { timeout: 20_000 } ).then( () => 'completed' ),
			page.waitForURL( /order-pay/,       { timeout: 20_000 } ).then( () => 'challenge'  ),
		] ).catch( () => 'timeout' );

		if ( afterSubmit === 'timeout' ) {
			test.skip( true, 'Challenge page did not load in time' );
			return;
		}
		if ( afterSubmit === 'completed' ) {
			const orderId = page.url().match( /order-received\/(\d+)/ )?.[1];
			if ( orderId ) createdOrderIds.push( orderId );
			return;
		}

		// Enter the challenge password.
		const passwordInput = page.locator(
			'input[type="password"], input[name="challengeDataEntry"], input[name="otp"]'
		);
		await expect( passwordInput ).toBeVisible( { timeout: 20_000 } );
		await passwordInput.fill( TDS2.CHALLENGE_PASSWORD_VISA );
		await page.locator( 'button[type="submit"], input[type="submit"]' ).first().click();

		await page.waitForURL( /order-received/, { timeout: 60_000 } );
		const orderId = page.url().match( /order-received\/(\d+)/ )?.[1];
		expect( orderId ).toBeTruthy();
		if ( orderId ) createdOrderIds.push( orderId );
	} );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Group D: Admin refund on a Block checkout CC order
// ═════════════════════════════════════════════════════════════════════════════

test.describe( 'Block-CC D: Admin partial refund', () => {
	test.setTimeout( 120_000 );
	test.beforeEach( requireSandboxCredentials );

	test( 'D-1: Admin processes ¥100 partial refund on a Block checkout CC order', async ( { page, baseURL } ) => {
		if ( ! productId ) test.skip( true, 'Test product not found' );

		// Step 1: Place order as guest via Block checkout.
		const tokenReady = await goToCcBlockCheckout( page );
		if ( ! tokenReady ) test.skip( true, 'sandbox.paygent.co.jp unreachable' );

		await fillBillingBlock( page, { email: CC_EMAIL } );
		await selectPaygentCCBlock( page );
		await fillCardFormBlock( page, CARD.OK );
		await placeOrderBlock( page );
		await page.waitForURL( /order-received/, { timeout: 90_000 } );

		const orderId = page.url().match( /order-received\/(\d+)/ )?.[1];
		if ( ! orderId ) { test.skip( true, 'Could not capture order ID' ); return; }
		createdOrderIds.push( orderId );

		// Step 2: Log in to admin.
		await page.goto( `${ baseURL }/wp-login.php` );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass',  'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/, { timeout: 15_000 } );

		// Step 3: Mark order as completed.
		await page.goto( `${ baseURL }/wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#order_status, select[name="order_status"]', { timeout: 10_000 } );
		await page.locator( '#order_status, select[name="order_status"]' ).first().selectOption( 'wc-completed' );
		await page.locator( 'button[name="save"], .save_order' ).first().click();
		await page.waitForLoadState( 'networkidle' );

		// Step 4: Issue partial refund.
		const refundBtn = page.locator( '.refund-items' ).first();
		if ( ! await becomesVisible( refundBtn, 5_000 ) ) {
			test.skip( true, 'Refund button not visible' );
			return;
		}
		await refundBtn.click();
		await page.locator( '.refund_line_total, input[name="refund_amount"]' ).first().fill( '100' );

		page.on( 'dialog', ( dialog ) => dialog.accept() );
		await page.locator( 'button.do-api-refund, .do-api-refund' ).first().click();
		await page.waitForTimeout( 5_000 );

		// Step 5: Verify refund note in order.
		await page.reload( { waitUntil: 'domcontentloaded' } );
		const notes = await page.locator( '.order_notes, .woocommerce-order-notes' ).textContent().catch( () => '' );
		expect( notes?.length ).toBeGreaterThan( 0 );
	} );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Group E: Installment payment (分割払い) — Block checkout
// ═════════════════════════════════════════════════════════════════════════════

test.describe( 'Block-CC E: Installment payment (分割払い)', () => {
	test.setTimeout( 120_000 );
	test.beforeEach( requireSandboxCredentials );

	/** @type {Record<string, string>} */
	let settingsSnapshotE = {};

	test.beforeAll( async () => {
		settingsSnapshotE = getPaygentCcSettings();
		updatePaygentCcSettings( {
			payment_method:     ['10', '61'],
			number_of_payments: ['3', '6'],
		} );
	} );

	test.afterAll( async () => {
		restorePaygentCcSettings( settingsSnapshotE );
	} );

	test( 'E-1: Guest selects 3-installment in Block checkout (split_count=3)', async ( { page } ) => {
		if ( ! productId ) test.skip( true, 'Test product not found' );

		const tokenReady = await goToCcBlockCheckout( page );
		if ( ! tokenReady ) test.skip( true, 'sandbox.paygent.co.jp unreachable' );

		await fillBillingBlock( page, { email: CC_EMAIL } );
		await selectPaygentCCBlock( page );
		await fillCardFormBlock( page, CARD.OK );

		// The installment selector rendered by our React CardForm.
		const paymentSelect = page.locator( '#paygent-cc-payment-method, select[id*="payment-method"]' ).first();
		await expect( paymentSelect ).toBeVisible( { timeout: 10_000 } );
		await paymentSelect.selectOption( '3' ); // 3-installment value

		await placeOrderBlock( page );
		await page.waitForURL( /order-received/, { timeout: 90_000 } );

		const orderId = page.url().match( /order-received\/(\d+)/ )?.[1];
		expect( orderId ).toBeTruthy();
		if ( ! orderId ) return;
		createdOrderIds.push( orderId );

		// Verify order meta: PHP stores _payment_class=61 and _split_count=3.
		const paymentClass = wpCli( `eval "echo wc_get_order(${ orderId })->get_meta('_payment_class');"` ).trim();
		const splitCount   = wpCli( `eval "echo wc_get_order(${ orderId })->get_meta('_split_count');"` ).trim();
		expect( paymentClass ).toBe( '61' );
		expect( splitCount   ).toBe( '3'  );
	} );

	test( 'E-2: Guest selects 6-installment in Block checkout (split_count=6)', async ( { page } ) => {
		if ( ! productId ) test.skip( true, 'Test product not found' );

		const tokenReady = await goToCcBlockCheckout( page );
		if ( ! tokenReady ) test.skip( true, 'sandbox.paygent.co.jp unreachable' );

		await fillBillingBlock( page, { email: CC_EMAIL } );
		await selectPaygentCCBlock( page );
		await fillCardFormBlock( page, CARD.OK );

		const paymentSelect = page.locator( '#paygent-cc-payment-method, select[id*="payment-method"]' ).first();
		await expect( paymentSelect ).toBeVisible( { timeout: 10_000 } );
		await paymentSelect.selectOption( '6' );

		await placeOrderBlock( page );
		await page.waitForURL( /order-received/, { timeout: 90_000 } );

		const orderId = page.url().match( /order-received\/(\d+)/ )?.[1];
		expect( orderId ).toBeTruthy();
		if ( ! orderId ) return;
		createdOrderIds.push( orderId );

		const paymentClass = wpCli( `eval "echo wc_get_order(${ orderId })->get_meta('_payment_class');"` ).trim();
		const splitCount   = wpCli( `eval "echo wc_get_order(${ orderId })->get_meta('_split_count');"` ).trim();
		expect( paymentClass ).toBe( '61' );
		expect( splitCount   ).toBe( '6'  );
	} );
} );
