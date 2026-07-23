// @ts-check
const { expect } = require('@playwright/test');

/**
 * Shared helpers for WooCommerce Block checkout E2E specs.
 *
 * Extracted from checkout-sandbox.block-cc.guest.spec.js so the CS / MB /
 * Paidy / MCCC Block specs can reuse the same navigation, billing-fill and
 * payment-method-selection logic without duplicating it per file.
 */

/**
 * Pre-fetch PaygentToken.js from the Paygent sandbox so tests can serve it
 * from cache instead of waiting 60-90s per page load. The script's own API
 * calls use absolute URLs, so caching the file does not affect tokenization.
 *
 * @returns {Promise<string>} The script source, or '' when unreachable.
 */
function prefetchPaygentTokenJs() {
	const https = require('https');
	return new Promise((resolve) => {
		const req = https.get(
			'https://sandbox.paygent.co.jp/js/PaygentToken.js',
			{ timeout: 30_000 },
			(res) => {
				const chunks = /** @type {Buffer[]} */ ([]);
				res.on('data', (c) => chunks.push(c));
				res.on('end', () => resolve(Buffer.concat(chunks).toString()));
				res.on('error', () => resolve(''));
			}
		);
		req.on('error', () => resolve(''));
		req.on('timeout', () => { req.destroy(); resolve(''); });
	});
}

/**
 * Route PaygentToken.js requests on this page to a cached copy.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} cachedJs  Cached script source (no-op when empty).
 */
async function routePaygentTokenJs(page, cachedJs) {
	if (!cachedJs) return;
	await page.route(/sandbox\.paygent\.co\.jp\/js\/PaygentToken/, (route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/javascript',
			body: cachedJs,
		})
	);
}

/**
 * Add a product to the cart and navigate to the Block checkout page,
 * waiting for the Checkout block to hydrate.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} blockCheckoutUrl  URL returned by setupBlockCheckoutPage().
 * @param {string} productId         Product to add to the cart.
 */
async function goToBlockCheckout(page, blockCheckoutUrl, productId) {
	const origin = blockCheckoutUrl.replace(/\/paygent-block-checkout-e2e\/$/, '');

	if (productId) {
		await page.goto(`${origin}/?add-to-cart=${productId}`, { waitUntil: 'domcontentloaded' });
	}
	await page.goto(blockCheckoutUrl, { waitUntil: 'domcontentloaded' });

	await page.waitForSelector('.wp-block-woocommerce-checkout', { timeout: 30_000 });

	// Wait for React hydration to complete: WooCommerce removes .is-loading when
	// ready. Without this, form fills happen before React initialises state and
	// the values are wiped.
	await page.waitForFunction(
		() => !document.querySelector('.wp-block-woocommerce-checkout.is-loading'),
		{ timeout: 30_000 }
	).catch(() => {});
}

/**
 * Wait until window.PaygentToken is defined (card-token gateways only).
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>}
 */
function waitForPaygentToken(page) {
	return page.waitForFunction(
		() => typeof window.PaygentToken !== 'undefined',
		{ timeout: 30_000 }
	).then(() => true).catch(() => false);
}

/**
 * Wait up to timeoutMs for the locator to become visible, returning a
 * boolean instead of throwing. Unlike locator.isVisible() — which checks
 * the CURRENT state only and ignores its timeout option — this actually
 * waits for the element to appear.
 *
 * @param {import('@playwright/test').Locator} locator
 * @param {number} [timeoutMs]
 * @returns {Promise<boolean>}
 */
function becomesVisible(locator, timeoutMs = 3_000) {
	return locator.waitFor({ state: 'visible', timeout: timeoutMs })
		.then(() => true, () => false);
}

/**
 * Fill the Block checkout billing fields.
 * WooCommerce Block checkout uses id="billing-first_name" etc.
 *
 * For logged-in members with a saved address, WC Blocks collapses the form
 * into an address summary card — in that case the "Edit" button is clicked
 * first to expand the fields. When no fields and no Edit button are present
 * the saved address is reused as-is and filling is skipped.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ email?: string, lastNameOverride?: string }} [opts]
 */
async function fillBillingBlock(page, opts = {}) {
	const email     = opts.email || 'block-e2e@example.com';
	const lastName  = opts.lastNameOverride || '太郎';
	const firstName = opts.lastNameOverride ? '' : 'テスト';

	const lastNameVisible = await becomesVisible(page.locator('#billing-last_name'));
	if (!lastNameVisible) {
		const editBtn = page.locator('.wc-block-components-address-card__edit, button')
			.filter({ hasText: /^Edit$/i }).first();
		if (await becomesVisible(editBtn)) {
			await editBtn.click();
			await page.waitForSelector('#billing-last_name:visible', { timeout: 10_000 }).catch(() => {});
		} else {
			return;
		}
	}

	const countryEl = page.locator('#billing-country');
	if (await countryEl.count() > 0) {
		const val = await countryEl.inputValue().catch(() => '');
		if (val !== 'JP') {
			await countryEl.selectOption('JP').catch(() => {});
			await page.waitForTimeout(800);
		}
	}

	const fillTextFields = async () => {
		await page.locator('#billing-last_name').fill(lastName);
		await page.locator('#billing-first_name').fill(firstName).catch(() => {});
		await page.locator('#email').fill(email);
		await page.locator('#billing-phone').fill('0312345678').catch(() => {});
		await page.locator('#billing-postcode').fill('1000001');
		await page.locator('#billing-address_1').fill('千代田区1-1-1');
		await page.locator('#billing-city').fill('東京都');
	};

	// Fill text fields BEFORE selecting state. The state-change triggers a WC
	// REST API call that causes React to re-render and clear fields filled
	// afterward. networkidle is unreliable on the checkout page (WC polls
	// continuously), so a fixed pause is used instead.
	await fillTextFields();

	// Select prefecture LAST. WC JP state code for Tokyo is 'JP13'.
	await page.locator('#billing-state').selectOption('JP13')
		.catch(() => page.locator('#billing-state').selectOption({ label: 'Tokyo' }).catch(() => {}));

	// Brief pause for the state-change API response, then re-fill any text
	// fields that the API response may have reset back to empty.
	await page.waitForTimeout(1_500);
	const lastNameCurrent = await page.locator('#billing-last_name').inputValue().catch(() => '');
	if (!lastNameCurrent) {
		await fillTextFields();
	}
}

/**
 * Select a payment method in the Block checkout payment panel.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} gatewayId     e.g. 'paygent_cs'
 * @param {RegExp} labelPattern  Fallback label text, e.g. /コンビニ|Convenience/i
 */
async function selectPaymentMethodBlock(page, gatewayId, labelPattern) {
	const radio = page.locator(`input[value="${gatewayId}"]`)
		.or(page.locator(`input[id*="${gatewayId}"]`))
		.first();

	if (await radio.count() > 0) {
		const isChecked = await radio.isChecked().catch(() => false);
		if (!isChecked) {
			// Block checkout radios may be hidden; click the surrounding label.
			const label = page.locator(`label[for="${await radio.getAttribute('id')}"]`)
				.or(page.locator('.wc-block-components-radio-control label').filter({ hasText: labelPattern }).first());
			await label.first().click().catch(async () => radio.check({ force: true }));
		}
	} else {
		// Fallback: click the label by title text.
		await page.locator('.wc-block-components-radio-control label, .wc-block-components-payment-method-label')
			.filter({ hasText: labelPattern })
			.first()
			.click();
	}
}

/**
 * Click "Place Order" in the Block checkout.
 *
 * @param {import('@playwright/test').Page} page
 */
async function placeOrderBlock(page) {
	const placeOrderBtn = page.locator(
		'.wc-block-components-checkout-place-order-button, ' +
		'.wp-block-woocommerce-checkout-actions-block button[type="submit"], ' +
		'button.wc-block-checkout__actions_row-place-order-button'
	).first();
	await expect(placeOrderBtn).toBeVisible({ timeout: 10_000 });
	await placeOrderBtn.click();
}

/**
 * Locator for the inline Block checkout error notice.
 *
 * Deliberately excludes bare [role="alert"]: the "added to your cart" info
 * notice also carries that role and would win any outcome race instantly.
 *
 * @param {import('@playwright/test').Page} page
 */
function blockCheckoutErrorNotice(page) {
	return page.locator(
		'.wc-block-components-notice-banner.is-error, .wc-block-store-notice.is-error'
	).first();
}

/**
 * Race several outcome promises and return the tag of the first one that
 * resolves. A promise that REJECTS (e.g. waitForURL timeout) is silently
 * dropped instead of settling the race, so branches may use different
 * timeouts. Falls back to 'timeout' after timeoutMs.
 *
 * @param {Array<[string, Promise<any>]>} entries  [tag, promise] pairs.
 * @param {number} timeoutMs
 * @returns {Promise<string>}
 */
function raceOutcome(entries, timeoutMs) {
	const never = new Promise(() => {});
	/** @type {NodeJS.Timeout} */
	let timerId;
	const timer = new Promise((resolve) => {
		timerId = setTimeout(() => resolve('timeout'), timeoutMs);
		timerId.unref?.();
	});
	return Promise.race([
		...entries.map(([tag, promise]) => promise.then(() => tag, () => never)),
		timer,
	]).finally(() => clearTimeout(timerId));
}

/**
 * Predicate for page.waitForURL: true once the browser has left the site
 * under test. The host is derived from baseURL instead of assuming
 * localhost, so it also works when E2E_BASE_URL points elsewhere.
 *
 * @param {string} baseURL
 * @returns {(url: URL) => boolean}
 */
function leftBaseHost(baseURL) {
	const baseHost = new URL(baseURL).host;
	return (url) => url.host !== '' && url.host !== baseHost;
}

module.exports = {
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
	leftBaseHost,
};
