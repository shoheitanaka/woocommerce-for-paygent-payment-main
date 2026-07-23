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
} = require('./helpers/block-checkout');

/**
 * Paygent Paidy Gateway — WooCommerce Block Checkout E2E tests (guest).
 *
 * Paidy is a redirect-type gateway: process_payment() sends no Paygent
 * telegram and immediately redirects to the order-pay (receipt) page, where
 * paidy_make_order() launches the Paidy checkout modal via the Paidy JS SDK
 * (api_public_key). The Paygent API (telegram 340) is only used for
 * cancel/refund, so no Paygent sandbox credentials are needed here.
 *
 * Prerequisites:
 *   PAYGENT_TEST_PAIDY_PUBKEY — Paidy sandbox API public key (pk_test_...).
 *                               Without it the modal-launch assertion is
 *                               limited to the "key not set" notice.
 *
 * Run:
 *   PAYGENT_TEST_PAIDY_PUBKEY=pk_test_xxx npm run e2e:block-paidy
 *
 * Sandbox constraint: the Paidy authentication flow (SMS code) inside the
 * modal cannot be automated — the test stops at modal launch (issue #21:
 * 「注文 → Paidy 画面リダイレクトまで」).
 */

const PAIDY_LABEL = /Paidy|あと払い|ペイディ/i;

const PAIDY_PUBKEY = process.env.PAYGENT_TEST_PAIDY_PUBKEY || '';

/** @type {string} */
let productId = '';
/** @type {string} */
let blockCheckoutUrl = '';
/** @type {string} */
let blockPageId = '';
/** @type {string} */
let originalCheckoutPageId = '';
/** @type {string | null} */
let paidyOptionSnapshot = null;
/** @type {Record<string, any>} */
let paidySettingsSnapshot = {};
/** @type {string[]} */
const createdOrderIds = [];

// ─── setup / teardown ─────────────────────────────────────────────────────────

test.beforeAll(async ({ baseURL }) => {
	const setup = setupBlockCheckoutPage(baseURL);
	blockCheckoutUrl       = setup.blockCheckoutUrl;
	blockPageId            = setup.pageId;
	originalCheckoutPageId = setup.originalCheckoutPageId;

	// Enable the Paidy gateway; inject the sandbox public key when provided.
	paidyOptionSnapshot   = snapshotWpOption('wc-paygent-paidy');
	paidySettingsSnapshot = getGatewaySettings('paygent_paidy');
	wpCli(`option update wc-paygent-paidy yes`);
	updateGatewaySettings('paygent_paidy', {
		enabled: 'yes',
		...(PAIDY_PUBKEY ? { test_api_public_key: PAIDY_PUBKEY } : {}),
	});
	wpCli(`cache flush`);

	const out   = wpCli(`post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`);
	const match = out.match(/^(\d+)$/m);
	productId   = match ? match[1] : '';
});

test.afterAll(async () => {
	teardownBlockCheckoutPage(originalCheckoutPageId, blockPageId);

	restoreGatewaySettings('paygent_paidy', paidySettingsSnapshot);
	restoreWpOption('wc-paygent-paidy', paidyOptionSnapshot);
	wpCli(`cache flush`);

	for (const id of createdOrderIds) {
		wpCli(`wc order delete ${id} --force --user=1`);
	}
});

// ═════════════════════════════════════════════════════════════════════════════
// Smoke: Block checkout page renders the Paidy gateway
// ═════════════════════════════════════════════════════════════════════════════

test('Block checkout page renders Paidy payment option', async ({ page }) => {
	if (!productId) test.skip(true, 'Test product not found');

	await goToBlockCheckout(page, blockCheckoutUrl, productId);
	await selectPaymentMethodBlock(page, 'paygent_paidy', PAIDY_LABEL);

	// The redirect-type content shows the (optional) descriptions only — the
	// radio being selectable is the essential assertion.
	const radio = page.locator('input[value="paygent_paidy"], input[id*="paygent_paidy"]').first();
	await expect(radio).toBeChecked({ timeout: 10_000 });
});

// ═════════════════════════════════════════════════════════════════════════════
// Group A: order placement → order-pay page → Paidy modal launch
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Block-Paidy A: Redirect to the Paidy payment screen', () => {
	test.setTimeout(120_000);

	test('A-1: Guest order redirects to order-pay and launches the Paidy modal', async ({ page }) => {
		if (!productId) test.skip(true, 'Test product not found');

		await goToBlockCheckout(page, blockCheckoutUrl, productId);
		await fillBillingBlock(page, { email: 'block-paidy-e2e@example.com' });
		await selectPaymentMethodBlock(page, 'paygent_paidy', PAIDY_LABEL);

		await placeOrderBlock(page);

		// process_payment() redirects to the order-pay (receipt) page.
		await page.waitForURL(/order-pay/, { timeout: 90_000 });

		const orderId = page.url().match(/order-pay\/(\d+)/)?.[1];
		expect(orderId).toBeTruthy();
		if (orderId) createdOrderIds.push(orderId);

		if (!PAIDY_PUBKEY) {
			// Without a key the receipt page renders an explicit notice instead
			// of the modal — the redirect itself is still verified above.
			await expect(
				page.locator('h2').filter({ hasText: /API Public key is not set/i })
			).toBeVisible({ timeout: 15_000 });
			test.info().annotations.push({
				type: 'skip-partial',
				description: 'PAYGENT_TEST_PAIDY_PUBKEY not set — modal launch not verified',
			});
			return;
		}

		// With a key, paidy_make_order() calls Paidy.launch() on window load,
		// which injects the Paidy checkout iframe.
		await expect(
			page.locator('iframe[src*="paidy"], iframe[name*="paidy"], iframe[id*="paidy"]').first()
		).toBeVisible({ timeout: 60_000 });
	});
});
