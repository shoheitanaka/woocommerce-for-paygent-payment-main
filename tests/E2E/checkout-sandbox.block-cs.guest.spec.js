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
	goToBlockCheckout,
	fillBillingBlock,
	selectPaymentMethodBlock,
	placeOrderBlock,
	blockCheckoutErrorNotice,
	raceOutcome,
	SANDBOX_ENV_ERROR,
} = require('./helpers/block-checkout');

/**
 * Paygent CS (Convenience Store) Gateway — WooCommerce Block Checkout E2E tests (guest).
 *
 * Tests the React ConvenienceStoreForm component registered via WC_Paygent_Block_CS:
 * store selection (cvs_company_id) → telegram 030 (番号方式申込) → thank-you page
 * payment/receipt number display.
 *
 * Prerequisites:
 *   PAYGENT_TEST_MID   — Sandbox merchant ID
 *   PAYGENT_TEST_CID   — Sandbox connect ID
 *   PAYGENT_TEST_CPASS — Sandbox connect password
 *
 * Run:
 *   PAYGENT_TEST_MID=xxx PAYGENT_TEST_CID=yyy PAYGENT_TEST_CPASS=zzz \
 *   npm run e2e:block-cs
 *
 * Notes:
 *   - The CS gateway needs no card token, so PaygentToken.js is not involved.
 *   - process_payment() completes synchronously (no external redirect); the
 *     receipt number is stored in _paygent_receipt_number order meta and
 *     rendered on the thank-you page via woocommerce_thankyou_paygent_cs.
 */

const REQUIRED_ENV = ['PAYGENT_TEST_MID', 'PAYGENT_TEST_CID', 'PAYGENT_TEST_CPASS'];

const CS_LABEL = /コンビニ|Convenience Store/i;

const STORE = {
	SEVEN_ELEVEN: '00C001',
	LAWSON:       '00C002',
};

/** @type {string} */
let productId = '';
/** @type {string} */
let blockCheckoutUrl = '';
/** @type {string} */
let blockPageId = '';
/** @type {string} */
let originalCheckoutPageId = '';
/** @type {string | null} */
let csOptionSnapshot = null;
/** @type {Record<string, any>} */
let csSettingsSnapshot = {};
/** @type {string[]} */
const createdOrderIds = [];

// ─── setup / teardown ─────────────────────────────────────────────────────────

test.beforeAll(async ({ baseURL }) => {
	// Create the Block checkout page and point WC at it.
	const setup = setupBlockCheckoutPage(baseURL);
	blockCheckoutUrl       = setup.blockCheckoutUrl;
	blockPageId            = setup.pageId;
	originalCheckoutPageId = setup.originalCheckoutPageId;

	// Enable the CS gateway (registration option + WC gateway settings).
	csOptionSnapshot   = snapshotWpOption('wc-paygent-cs');
	csSettingsSnapshot = getGatewaySettings('paygent_cs');
	wpCli(`option update wc-paygent-cs yes`);
	updateGatewaySettings('paygent_cs', {
		enabled:        'yes',
		setting_cs_se:  'yes',
		setting_cs_lm:  'yes',
		setting_cs_f:   'yes',
		setting_cs_sm:  'yes',
		setting_cs_ctd: 'yes',
	});
	wpCli(`cache flush`);

	// Resolve test product.
	const out   = wpCli(`post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`);
	const match = out.match(/^(\d+)$/m);
	productId   = match ? match[1] : '';
});

test.afterAll(async () => {
	teardownBlockCheckoutPage(originalCheckoutPageId, blockPageId);

	restoreGatewaySettings('paygent_cs', csSettingsSnapshot);
	restoreWpOption('wc-paygent-cs', csOptionSnapshot);
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
 * Select the CS payment method and wait for the store selector to render.
 *
 * @param {import('@playwright/test').Page} page
 */
async function selectPaygentCSBlock(page) {
	await selectPaymentMethodBlock(page, 'paygent_cs', CS_LABEL);
	await expect(page.locator('#paygent-cs-store')).toBeVisible({ timeout: 10_000 });
}

/**
 * Place the order and wait for the thank-you page.
 * Environment-level sandbox rejections skip the test instead of failing it:
 *   P007 — CS payment not contracted for this sandbox merchant
 *   P015 — source IP not registered with the sandbox
 *   P016 — connection failure
 * Real telegram 030 errors still fail with the notice text.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>} Order ID ('' when the test was skipped).
 */
async function placeOrderAndAwaitThankyou(page) {
	await placeOrderBlock(page);

	const outcome = await raceOutcome([
		['completed', page.waitForURL(/order-received/, { timeout: 90_000 })],
		['error',     blockCheckoutErrorNotice(page).waitFor({ state: 'visible', timeout: 60_000 })],
	], 90_000);

	if (outcome === 'error') {
		const message = (await blockCheckoutErrorNotice(page).textContent().catch(() => ''))?.trim() || '';
		if (SANDBOX_ENV_ERROR.test(message)) {
			test.skip(true, `Sandbox constraint (contract/IP): ${message}`);
			return '';
		}
		throw new Error(`CS checkout failed with notice: ${message}`);
	}
	if (outcome === 'timeout') {
		throw new Error('CS checkout did not reach order-received in time');
	}

	const orderId = page.url().match(/order-received\/(\d+)/)?.[1] || '';
	expect(orderId).toBeTruthy();
	if (orderId) createdOrderIds.push(orderId);
	return orderId;
}

// ═════════════════════════════════════════════════════════════════════════════
// Smoke: Block checkout page renders the CS gateway with a store selector
// ═════════════════════════════════════════════════════════════════════════════

test('Block checkout page renders CS payment option with store selector', async ({ page }) => {
	requireSandboxCredentials();
	if (!productId) test.skip(true, 'Test product not found');

	await goToBlockCheckout(page, blockCheckoutUrl, productId);
	await selectPaygentCSBlock(page);

	// All five admin-enabled stores are rendered as options.
	const optionValues = await page.locator('#paygent-cs-store option').evaluateAll(
		(opts) => opts.map((o) => /** @type {HTMLOptionElement} */ (o).value)
	);
	expect(optionValues).toContain(STORE.SEVEN_ELEVEN);
	expect(optionValues).toContain(STORE.LAWSON);
});

// ═════════════════════════════════════════════════════════════════════════════
// Group A: telegram 030 checkout — store selection → thank-you receipt number
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Block-CS A: Convenience store checkout (telegram 030)', () => {
	test.setTimeout(120_000);
	test.beforeEach(requireSandboxCredentials);

	test('A-1: Guest completes CS checkout (Seven Eleven) and sees the receipt number', async ({ page }) => {
		if (!productId) test.skip(true, 'Test product not found');

		await goToBlockCheckout(page, blockCheckoutUrl, productId);
		await fillBillingBlock(page, { email: 'block-cs-e2e@example.com' });
		await selectPaygentCSBlock(page);
		await page.locator('#paygent-cs-store').selectOption(STORE.SEVEN_ELEVEN);

		const orderId = await placeOrderAndAwaitThankyou(page);
		if (!orderId) return;

		// PHP stores the store id and receipt number in order meta.
		const cvsId = wpCli(`eval "echo wc_get_order(${orderId})->get_meta('_paygent_cvs_id');"`).trim();
		expect(cvsId).toBe(STORE.SEVEN_ELEVEN);

		const receiptNumber = wpCli(`eval "echo wc_get_order(${orderId})->get_meta('_paygent_receipt_number');"`).trim();
		expect(receiptNumber).not.toBe('');

		// The thank-you page renders the receipt number via woocommerce_thankyou_paygent_cs.
		await expect(page.locator('body')).toContainText(receiptNumber, { timeout: 15_000 });
	});

	test('A-2: Selected store (Lawson) is transmitted as cvs_company_id', async ({ page }) => {
		if (!productId) test.skip(true, 'Test product not found');

		await goToBlockCheckout(page, blockCheckoutUrl, productId);
		await fillBillingBlock(page, { email: 'block-cs-e2e@example.com' });
		await selectPaygentCSBlock(page);
		await page.locator('#paygent-cs-store').selectOption(STORE.LAWSON);

		const orderId = await placeOrderAndAwaitThankyou(page);
		if (!orderId) return;

		// The non-default selection must reach process_payment() unchanged.
		const cvsId = wpCli(`eval "echo wc_get_order(${orderId})->get_meta('_paygent_cvs_id');"`).trim();
		expect(cvsId).toBe(STORE.LAWSON);

		const receiptNumber = wpCli(`eval "echo wc_get_order(${orderId})->get_meta('_paygent_receipt_number');"`).trim();
		expect(receiptNumber).not.toBe('');
	});
});
