const { test, expect } = require('@playwright/test');
const { addAnyProductToCart } = require('../helpers/shop');

/**
 * The block checkout.
 *
 * WooCommerce has two checkouts and they share almost nothing. The classic one renders PHP templates and
 * fires the hooks this plugin was built on; the block one renders in React and talks to the Store API,
 * which fires none of them - checked against WooCommerce 10.4.4, all four of
 * `woocommerce_after_shipping_rate`, `woocommerce_review_order_before_shipping`,
 * `woocommerce_after_checkout_validation` and `woocommerce_checkout_create_order` are absent from
 * `src/StoreApi/` entirely.
 *
 * So on a block checkout a customer could choose Speedy, have nowhere to say WHICH office, and place the
 * order regardless - the refusal lives on a hook that never fires. The order reached the merchant with a
 * courier and no destination. That is what this pins: the Store API must refuse it, in the customer's
 * own language, and no order may appear.
 *
 * The page is a standalone one carrying the checkout block, NOT the shop's configured checkout - so this
 * proves the gate without the test having to reconfigure the site under itself.
 */
const BLOCKS_PAGE = '/blocks-checkout-test/';

test('block checkout: an order with no delivery point is refused @blocks', async ({ page, baseURL }) => {
  await addAnyProductToCart(page);
  await page.goto(baseURL + BLOCKS_PAGE);
  // The block checkout mounts, fetches the cart and resolves shipping rates before anything is real.
  await expect(page.locator('.wc-block-checkout, .wp-block-woocommerce-checkout').first()).toBeVisible({ timeout: 30000 });
  await page.waitForTimeout(6000);

  // A courier rate IS selected - that half has always worked, because a shipping method is checkout
  // agnostic. It is precisely what makes the missing destination dangerous rather than merely visible.
  const cart = await page.evaluate(async () => {
    const r = await fetch('/wp-json/wc/store/v1/cart', { headers: { 'Content-Type': 'application/json' } });
    const j = await r.json();
    return {
      selected: (j.shipping_rates?.[0]?.shipping_rates || []).filter(x => x.selected).map(x => x.name),
      errors: (j.errors || []).map(e => e.message || e.code),
    };
  });
  expect(cart.selected.length, 'a courier rate is selected').toBeGreaterThan(0);

  // ...and the Store API says why the order cannot go through, naming the courier.
  expect(cart.errors.length, `the cart must carry a blocking error (got ${JSON.stringify(cart.errors)})`).toBeGreaterThan(0);
  const courier = cart.selected[0];
  expect(cart.errors.join(' '), 'the message names the courier it is about').toContain(courier);

  // Pressing the button must not produce an order. This is the assertion that matters: everything above
  // could be true while the order sailed through anyway, which is exactly what used to happen.
  const btn = page.locator('button.wc-block-components-checkout-place-order-button');
  if (await btn.count()) {
    await btn.click();
    await page.waitForTimeout(8000);
  }
  expect(/order-received/i.test(page.url()), `no order may be created - landed on ${page.url()}`).toBe(false);
  const notice = await page.locator('.wc-block-components-notice-banner').first().textContent().catch(() => '');
  expect((notice || '').replace(/\s+/g, ' '), 'the customer is told why').toContain(courier);
});

/**
 * ...and the other half: the pickers now render inside the block, and choosing a point clears the block.
 *
 * The markup is the SAME markup the classic checkout gets - rendered by PHP and handed to the block
 * through a slot fill, rather than reimplemented in React, so there is one picker and not two that drift.
 * The hidden shipping_method input is the seam that makes it work: chosenCourier() in bgc-checkout.js
 * already falls back to one, so every behaviour built for the classic checkout runs unchanged on a
 * checkout whose radio buttons it cannot see.
 */
test('block checkout: the courier pickers render, and choosing a point unblocks the order @blocks', async ({ page, baseURL }) => {
  // The block mounts over a Store API round trip, and Sofia carries 912 points. The default 90s is not
  // enough for both, and the failure looks like a broken click rather than a slow one.
  test.setTimeout(240000);
  await addAnyProductToCart(page);
  await page.goto(baseURL + BLOCKS_PAGE);
  await expect(page.locator('.wc-block-checkout, .wp-block-woocommerce-checkout').first()).toBeVisible({ timeout: 30000 });

  // The picker arrives inside the block's shipping step, not merely somewhere on the page.
  const fields = page.locator('.bgc-blocks-fields .bgc-fields');
  await expect(fields).toHaveCount(1, { timeout: 30000 });
  expect(await page.locator('.bgc-blocks-fields .bgc-tab').count(), 'the delivery-type tabs').toBeGreaterThan(1);
  // The seam. Without it none of the behaviour below can find out which courier it is looking at.
  expect(await page.locator('input[name^="shipping_method"][type="hidden"]').inputValue())
    .toMatch(/^bgcouriers_/);

  // Choose a real point, the way a customer does - through the map, which sets courier, type, town and
  // office in one go and saves through the same AJAX the classic checkout uses.
  await page.locator('.bgc-allmap-btn, .bgc-blocks-fields .bgc-map-btn').first().click();
  await expect(page.locator('.bgc-allmap-overlay')).toBeVisible({ timeout: 20000 });
  await page.locator('.bgc-allmap-cityinput').fill('София');
  await page.locator('.bgc-allmap-cityopt', { hasText: 'СОФИЯ (1000)' }).first().click({ timeout: 25000 });
  await page.locator('.bgc-allmap-item').first().waitFor({ state: 'attached', timeout: 30000 });
  await page.locator('.bgc-allmap-item:not(.bgc-na)').first().click();
  await page.locator('.leaflet-popup .bgc-allmap-pick').first().click({ timeout: 20000 });
  await page.waitForTimeout(9000);

  // The Store API must now let the order through: the same endpoint that refused it above.
  const errors = await page.evaluate(async () => {
    const r = await fetch('/wp-json/wc/store/v1/cart', { headers: { 'Content-Type': 'application/json' } });
    return ((await r.json()).errors || []).map(e => e.message || e.code);
  });
  expect(errors.join(' | '), 'a chosen destination clears the block').not.toMatch(/точка за доставка|delivery point/i);
});
