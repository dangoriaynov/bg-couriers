const { test, expect } = require('@playwright/test');
const { addAnyProductToCart } = require('../helpers/shop');

/**
 * The CART block, and the checkout block before anything is done: no refusals yet.
 *
 * The Store API asks the plugin's order validation on every cart read - the cart block's page, the
 * checkout block's first paint - and shows the answer as a red banner. So a customer opening their cart
 * read "please enter a phone number" and "please choose your Speedy delivery point before placing the
 * order" over a cart they had not begun to check out (measured 2026-09-14 on dev). The classic checkout
 * says these things when the button is pressed; the block says them at the same moment now.
 *
 * `/blocks-cart-test/` on dev carries WooCommerce's own default cart block content (WC_Install's), not a
 * bare block: a bare `wp:woocommerce/cart` renders inner blocks under names the front end does not
 * know, and shows no shipping row at all - which is not something a shop would see.
 */
test('the cart block and a fresh checkout block show no refusal before the order is placed @blocks', async ({ page, baseURL }) => {
  test.setTimeout(180000);
  await addAnyProductToCart(page);
  await page.goto(baseURL + '/blocks-cart-test/');
  await expect(page.locator('.wp-block-woocommerce-cart-order-summary-block').first()).toBeVisible({ timeout: 30000 });
  await page.waitForTimeout(4000);
  await expect(page.locator('.wc-block-components-notice-banner')).toHaveCount(0);
  const summary = (await page.locator('.wp-block-woocommerce-cart-order-summary-block').first().innerText()).replace(/\s+/g, ' ');
  expect(summary, 'the selected courier is on the cart summary').toMatch(/Speedy|Econt|Pigeon|BOX NOW|Sameday|Express One|Европът/);

  await page.goto(baseURL + '/blocks-checkout-test/');
  await expect(page.locator('.bgc-blocks-fields .bgc-fields').first()).toBeVisible({ timeout: 30000 });
  await page.waitForTimeout(3000);
  await expect(page.locator('.wc-block-components-notice-banner', { hasText: /точка за доставка|delivery point|телефон/ })).toHaveCount(0);
});
