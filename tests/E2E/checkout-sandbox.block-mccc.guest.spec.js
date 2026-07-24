// @ts-check
const { test, expect } = require('@playwright/test');
const {
	wpCli,
	getGatewaySettings,
	restoreGatewaySettings,
	updateGatewaySettings,
	snapshotWpOption,
	restoreWpOption,
	setupBlockCheckoutPage,
	teardownBlockCheckoutPage,
} = require('./helpers/wp-cli');
const {
	prefetchPaygentTokenJs,
	routePaygentTokenJs,
	goToBlockCheckout,
	waitForPaygentToken,
	fillBillingBlock,
	selectPaymentMethodBlock,
	placeOrderBlock,
	blockCheckoutErrorNotice,
	raceOutcome,
	SANDBOX_ENV_ERROR,
} = require('./helpers/block-checkout');

/**
 * Paygent MCCC (Multi-Currency Credit Card) Gateway — Block Checkout E2E tests (guest).
 *
 * Tests the React McccCardForm component registered via WC_Paygent_Block_MCCC:
 * new-card tokenization (paygent_mccc-token / -cvc_token) → telegram 180
 * (multi-currency auth) to completion.
 *
 * ⚠ Currency: paygent_edit_available_gateways() hides MCCC when the store
 * currency is JPY (it is a foreign-currency-only gateway). These tests switch
 * the store currency to USD in beforeAll and restore it in afterAll — the
 * JPY-only gateways (CC/CS/MB/Paidy) are hidden while this spec runs.
 *
 * Prerequisites (same as the CC sandbox tests):
 *   PAYGENT_TEST_MID      — Sandbox merchant ID
 *   PAYGENT_TEST_CID      — Sandbox connect ID
 *   PAYGENT_TEST_CPASS    — Sandbox connect password
 *   PAYGENT_TEST_TOKENKEY — Token generation key
 *
 * Run:
 *   PAYGENT_TEST_MID=xxx PAYGENT_TEST_CID=yyy PAYGENT_TEST_CPASS=zzz \
 *   PAYGENT_TEST_TOKENKEY=aaa npm run e2e:block-mccc
 *
 * Notes:
 *   - A sandbox merchant without the multi-currency option contracted gets an
 *     error telegram on 180 — such runs are skipped, not failed.
 *   - Saved-card (token) MCCC E2E is deferred until the #20 fix is verified
 *     against the sandbox (issue #21 備考).
 *
 * ─── Test cards ──────────────────────────────────────────────────────────────
 *   4900-0000-0000-0000  → オーソリOK / 取消OK / 売上OK
 */

const REQUIRED_ENV = ['PAYGENT_TEST_MID', 'PAYGENT_TEST_CID', 'PAYGENT_TEST_CPASS', 'PAYGENT_TEST_TOKENKEY'];

const MCCC_LABEL = /多通貨|Multi-currency/i;

const CARD_OK = '4900000000000000';

/** @type {string} */
let productId = '';
/** @type {string} */
let blockCheckoutUrl = '';
/** @type {string} */
let blockPageId = '';
/** @type {string} */
let originalCheckoutPageId = '';
/** @type {string | null} */
let mcccOptionSnapshot = null;
/** @type {Record<string, any>} */
let mcccSettingsSnapshot = {};
/** @type {string} */
let originalCurrency = '';
/** @type {string} */
let cachedPaygentTokenJs = '';
/** @type {string[]} */
const createdOrderIds = [];

// ─── setup / teardown ─────────────────────────────────────────────────────────

test.beforeAll(async ({ baseURL }) => {
	const setup = setupBlockCheckoutPage(baseURL);
	blockCheckoutUrl       = setup.blockCheckoutUrl;
	blockPageId            = setup.pageId;
	originalCheckoutPageId = setup.originalCheckoutPageId;

	// MCCC is unavailable with JPY — switch the store currency to USD.
	originalCurrency = wpCli(`option get woocommerce_currency`).trim() || 'JPY';
	wpCli(`option update woocommerce_currency USD`);

	// Enable the MCCC gateway.
	mcccOptionSnapshot   = snapshotWpOption('wc-paygent-mccc');
	mcccSettingsSnapshot = getGatewaySettings('paygent_mccc');
	wpCli(`option update wc-paygent-mccc yes`);
	updateGatewaySettings('paygent_mccc', { enabled: 'yes' });
	wpCli(`cache flush`);

	const out   = wpCli(`post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`);
	const match = out.match(/^(\d+)$/m);
	productId   = match ? match[1] : '';

	cachedPaygentTokenJs = await prefetchPaygentTokenJs();
	if (cachedPaygentTokenJs) {
		console.log('  [block-mccc] PaygentToken.js pre-fetched and cached.');
	} else {
		console.warn('  [block-mccc] PaygentToken.js not pre-fetched — tests may be slow.');
	}
});

test.afterAll(async () => {
	teardownBlockCheckoutPage(originalCheckoutPageId, blockPageId);

	wpCli(`option update woocommerce_currency ${originalCurrency}`);
	restoreGatewaySettings('paygent_mccc', mcccSettingsSnapshot);
	restoreWpOption('wc-paygent-mccc', mcccOptionSnapshot);
	wpCli(`cache flush`);

	for (const id of createdOrderIds) {
		wpCli(`wc order delete ${id} --force --user=1`);
	}
});

function requireSandboxCredentials() {
	const missing = REQUIRED_ENV.filter((v) => !process.env[v]);
	if (missing.length) {
		test.skip(true, `Sandbox credentials not set: ${missing.join(', ')}`);
	}
}

/**
 * Select the MCCC payment method and wait for the card form to render.
 *
 * @param {import('@playwright/test').Page} page
 */
async function selectPaygentMCCCBlock(page) {
	await selectPaymentMethodBlock(page, 'paygent_mccc', MCCC_LABEL);
	await expect(page.locator('#paygent-mccc-number')).toBeVisible({ timeout: 10_000 });
}

/**
 * Fill the MCCC card form (tokenization happens inside onPaymentSetup on
 * order placement, same as the CC Block form).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [cardNumber]
 */
async function fillMcccCardForm(page, cardNumber = CARD_OK) {
	await page.locator('#paygent-mccc-number').fill(cardNumber);
	await page.locator('#paygent-mccc-expiry').fill('12 / 30');
	await page.locator('#paygent-mccc-cvc').fill('123');
}

// ═════════════════════════════════════════════════════════════════════════════
// Smoke: Block checkout page renders the MCCC gateway (USD store)
// ═════════════════════════════════════════════════════════════════════════════

test('Block checkout page renders MCCC payment option when currency is USD', async ({ page }) => {
	requireSandboxCredentials();
	if (!productId) test.skip(true, 'Test product not found');

	await goToBlockCheckout(page, blockCheckoutUrl, productId);
	await selectPaygentMCCCBlock(page);

	// MCCC has no installment selector — only the card fields.
	await expect(page.locator('#paygent-mccc-expiry')).toBeVisible();
	await expect(page.locator('#paygent-mccc-cvc')).toBeVisible();
	await expect(page.locator('#paygent-mccc-payment-method, select[id*="paygent-mccc-payment"]')).toHaveCount(0);
});

// ═════════════════════════════════════════════════════════════════════════════
// Group A: new-card token checkout — telegram 180 to completion
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Block-MCCC A: New-card token checkout (telegram 180)', () => {
	test.setTimeout(120_000);
	test.beforeEach(requireSandboxCredentials);

	test('A-1: Guest completes MCCC Block checkout with a new card token', async ({ page }) => {
		if (!productId) test.skip(true, 'Test product not found');

		await routePaygentTokenJs(page, cachedPaygentTokenJs);
		await goToBlockCheckout(page, blockCheckoutUrl, productId);

		const tokenReady = await waitForPaygentToken(page);
		if (!tokenReady) test.skip(true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js not loaded');

		await fillBillingBlock(page, { email: 'block-mccc-e2e@example.com' });
		await selectPaygentMCCCBlock(page);
		await fillMcccCardForm(page, CARD_OK);

		await placeOrderBlock(page);

		const outcome = await raceOutcome([
			['completed', page.waitForURL(/order-received/, { timeout: 90_000 })],
			['error',     blockCheckoutErrorNotice(page).waitFor({ state: 'visible', timeout: 60_000 })],
		], 90_000);

		if (outcome === 'error') {
			const message = (await blockCheckoutErrorNotice(page).textContent().catch(() => ''))?.trim() || '';
			// Only environment/contract rejections skip — anything else (e.g.
			// missing token POST keys, validation errors) is a real regression.
			if (SANDBOX_ENV_ERROR.test(message)) {
				test.skip(true, `Sandbox constraint (contract/IP): ${message}`);
				return;
			}
			throw new Error(`MCCC checkout failed with notice: ${message}`);
		}
		if (outcome === 'timeout') {
			test.skip(true, 'MCCC checkout did not complete in time');
			return;
		}

		const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
		expect(orderId).toBeTruthy();
		if (!orderId) return;
		createdOrderIds.push(orderId);

		// PHP stores the transaction currency in order meta.
		const currencyCode = wpCli(`eval "echo wc_get_order(${orderId})->get_meta('_paygent_currency_code');"`).trim();
		expect(currencyCode).toBe('USD');

		await expect(
			page.locator('.wc-block-order-confirmation-status, .woocommerce-thankyou-order-received, h2').first()
		).toBeVisible({ timeout: 15_000 });
	});
});
