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
  await page.goto((baseURL || 'https://dev.dobavki.club') + BLOCKS_PAGE);
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
