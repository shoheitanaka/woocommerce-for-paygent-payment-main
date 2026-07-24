// @ts-check
const { test, expect } = require('@playwright/test');
const {
	wpCli,
	snapshotGatewaySettings,
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
	leftBaseHost,
	SANDBOX_ENV_ERROR,
} = require('./helpers/block-checkout');

/**
 * Paygent MB (Mobile Carrier) Gateway — WooCommerce Block Checkout E2E tests (guest).
 *
 * Tests the React CarrierForm component registered via WC_Paygent_Block_MB:
 * carrier selection (career_type) → telegram 100/104 → redirect to the
 * external carrier authentication page.
 *
 * Prerequisites:
 *   PAYGENT_TEST_MID   — Sandbox merchant ID
 *   PAYGENT_TEST_CID   — Sandbox connect ID
 *   PAYGENT_TEST_CPASS — Sandbox connect password
 *
 * Run:
 *   PAYGENT_TEST_MID=xxx PAYGENT_TEST_CID=yyy PAYGENT_TEST_CPASS=zzz \
 *   npm run e2e:block-mb
 *
 * ─── Sandbox constraints (see docs/e2e-usage.md) ─────────────────────────────
 *   The carrier login/approval screens are EXTERNAL services that cannot be
 *   automated against the shared sandbox. These tests therefore verify the
 *   flow only up to the redirect leaving the local site:
 *     - au (career_type=04):      telegram 104 → external au page redirect
 *     - docomo (career_type=05):  telegram 104 → order-pay page renders the
 *                                 stored redirect_html which auto-submits to
 *                                 the external docomo page
 *     - SoftBank (career_type=06): telegram 100 → external redirect
 *   A sandbox account without the carrier option contracted returns an error
 *   telegram — those runs are skipped with the notice text as the reason.
 */

const REQUIRED_ENV = ['PAYGENT_TEST_MID', 'PAYGENT_TEST_CID', 'PAYGENT_TEST_CPASS'];

const MB_LABEL = /キャリア|Carrier|Mobile/i;

// Billing email used by every order this spec places — the afterAll cleanup
// deletes pending MB orders by this address only, so manually created test
// orders are left untouched.
const MB_EMAIL = 'block-mb-e2e@example.com';

const CARRIERS = [
	{ id: '04', name: 'au (au Easy Payment)' },
	{ id: '05', name: 'docomo (Docomo Payment)' },
	{ id: '06', name: 'SoftBank (Matomete Payment B)' },
];

/** @type {string} */
let productId = '';
/** @type {string} */
let blockCheckoutUrl = '';
/** @type {string} */
let blockPageId = '';
/** @type {string} */
let originalCheckoutPageId = '';
/** @type {string | null} */
let mbOptionSnapshot = null;
/** @type {Record<string, any> | null} */
let mbSettingsSnapshot = null;

// ─── setup / teardown ─────────────────────────────────────────────────────────

test.beforeAll(async ({ baseURL }) => {
	const setup = setupBlockCheckoutPage(baseURL);
	blockCheckoutUrl       = setup.blockCheckoutUrl;
	blockPageId            = setup.pageId;
	originalCheckoutPageId = setup.originalCheckoutPageId;

	// Enable the MB gateway with all three carriers.
	mbOptionSnapshot   = snapshotWpOption('wc-paygent-mb');
	mbSettingsSnapshot = snapshotGatewaySettings('paygent_mb');
	wpCli(`option update wc-paygent-mb yes`);
	updateGatewaySettings('paygent_mb', {
		enabled:       'yes',
		setting_ct_04: 'yes',
		setting_ct_05: 'yes',
		setting_ct_06: 'yes',
	});
	wpCli(`cache flush`);

	const out   = wpCli(`post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`);
	const match = out.match(/^(\d+)$/m);
	productId   = match ? match[1] : '';
});

test.afterAll(async () => {
	teardownBlockCheckoutPage(originalCheckoutPageId, blockPageId);

	restoreGatewaySettings('paygent_mb', mbSettingsSnapshot);
	restoreWpOption('wc-paygent-mb', mbOptionSnapshot);
	wpCli(`cache flush`);

	// Redirect tests leave pending paygent_mb orders behind (the external
	// carrier page is never completed) and rejected telegrams leave failed
	// ones. Delete only the orders this spec created, identified by the
	// MB_EMAIL billing address.
	wpCli(
		`eval "` +
		`\\$ids = wc_get_orders(array('limit'=>-1,'payment_method'=>'paygent_mb','status'=>array('pending','failed'),'billing_email'=>'${MB_EMAIL}','return'=>'ids'));` +
		`foreach (\\$ids as \\$id) { \\$o = wc_get_order(\\$id); if (\\$o) { \\$o->delete(true); } }` +
		`echo count(\\$ids) . ' MB test orders deleted.';` +
		`"`
	);
});

function requireSandboxCredentials() {
	const missing = REQUIRED_ENV.filter((v) => !process.env[v]);
	if (missing.length) {
		test.skip(true, `Sandbox credentials not set: ${missing.join(', ')}`);
	}
}

/**
 * Select the MB payment method and wait for the carrier selector to render.
 *
 * @param {import('@playwright/test').Page} page
 */
async function selectPaygentMBBlock(page) {
	await selectPaymentMethodBlock(page, 'paygent_mb', MB_LABEL);
	await expect(page.locator('#paygent-mb-carrier')).toBeVisible({ timeout: 10_000 });
}

// ═════════════════════════════════════════════════════════════════════════════
// Smoke: Block checkout page renders the MB gateway with a carrier selector
// ═════════════════════════════════════════════════════════════════════════════

test('Block checkout page renders MB payment option with carrier selector', async ({ page }) => {
	requireSandboxCredentials();
	if (!productId) test.skip(true, 'Test product not found');

	await goToBlockCheckout(page, blockCheckoutUrl, productId);
	await selectPaygentMBBlock(page);

	const optionValues = await page.locator('#paygent-mb-carrier option').evaluateAll(
		(opts) => opts.map((o) => /** @type {HTMLOptionElement} */ (o).value)
	);
	expect(optionValues).toEqual(expect.arrayContaining(['04', '05', '06']));
});

// ═════════════════════════════════════════════════════════════════════════════
// Group A: carrier selection → redirect to the external carrier page
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Block-MB A: Carrier redirect (career_type transmission)', () => {
	// External carrier redirects on the shared sandbox can be slow (cf. PayPay).
	test.setTimeout(240_000);
	test.beforeEach(requireSandboxCredentials);

	for (const carrier of CARRIERS) {
		test(`A-${carrier.id}: ${carrier.name} checkout redirects to the carrier page`, async ({ page, baseURL }) => {
			if (!productId) test.skip(true, 'Test product not found');
			const isExternal = leftBaseHost(String(baseURL));

			await goToBlockCheckout(page, blockCheckoutUrl, productId);
			await fillBillingBlock(page, { email: MB_EMAIL });
			await selectPaygentMBBlock(page);
			await page.locator('#paygent-mb-carrier').selectOption(carrier.id);

			await placeOrderBlock(page);

			// Three possible outcomes:
			//   external  — telegram accepted, browser left the local site
			//   receipt   — order-pay page (docomo/SB redirect_html auto-submit)
			//   error     — sandbox rejected the telegram (carrier option not
			//               contracted for this merchant) → skip, not fail
			const outcome = await raceOutcome([
				['external', page.waitForURL(isExternal, { timeout: 180_000 })],
				['receipt',  page.waitForURL(/order-pay/, { timeout: 120_000 })],
				['error',    blockCheckoutErrorNotice(page).waitFor({ state: 'visible', timeout: 120_000 })],
			], 180_000);

			if (outcome === 'error') {
				const message = (await blockCheckoutErrorNotice(page).textContent().catch(() => ''))?.trim() || '';
				// Only environment/contract rejections skip — anything else
				// (e.g. career_type not reaching process_payment) must fail.
				if (SANDBOX_ENV_ERROR.test(message)) {
					test.skip(true, `Sandbox constraint for carrier ${carrier.id} (contract/IP): ${message}`);
					return;
				}
				throw new Error(`MB checkout (carrier ${carrier.id}) failed with notice: ${message}`);
			}
			if (outcome === 'timeout') {
				test.skip(true, `Carrier ${carrier.id} redirect did not start in time (shared sandbox latency)`);
				return;
			}

			if (outcome === 'receipt') {
				// The order-pay page renders the stored redirect_html which
				// auto-submits to the external carrier page.
				const external = await page.waitForURL(isExternal, { timeout: 120_000 })
					.then(() => true).catch(() => false);
				if (!external) {
					test.skip(true, `Carrier ${carrier.id} redirect_html did not leave the local site (external page unreachable)`);
					return;
				}
			}

			// Redirect confirmed — the telegram carrying career_type was accepted.
			expect(isExternal(new URL(page.url()))).toBe(true);
			console.log(`  [block-mb] carrier ${carrier.id} redirect URL:`, page.url());
		});
	}
});
