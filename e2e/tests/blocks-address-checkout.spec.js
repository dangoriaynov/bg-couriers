const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { addAnyProductToCart } = require('../helpers/shop');

/**
 * An ADDRESS order through the checkout BLOCK, end to end.
 *
 * The block specs beside this one proved that the pickers render and that the combined map unblocks an
 * order. Nobody had walked the pickers themselves on the block - and they were dead: every delivery
 * change fires `update_checkout`, which the classic checkout answers with a re-render and
 * `updated_checkout`, and the block answered with nothing, so the pickers stayed greyed out (pointer
 * events none) from the first tab click, and the rates stayed priced for the old selection. Measured
 * 2026-09-13: ten seconds and counting on the block, four on the classic. The block also printed a
 * delivery the customer pays the courier for at the door as "Speedy  БЕЗПЛАТНО" - the classic
 * checkout's "~3,06 €" and "paid to the courier" live in label filters the Store API never runs.
 *
 * Now the block asks the Store API for a recalculation on `update_checkout` and fires
 * `updated_checkout` after it, and a recipient-pays rate carries its estimate as the line the block
 * prints beside the price.
 */
const BLOCKS_PAGE = '/blocks-checkout-test/';
const SH = path.join(__dirname, '..', 'dev-option.sh');
const dev = (...args) => execFileSync('bash', [SH, ...args], { encoding: 'utf8' }).trim();

test('block checkout: the pickers work after a tab click, the rate says what the courier collects, and an address order carries its street @blocks @speedy', async ({ page, baseURL }) => {
  test.setTimeout(240000);
  await addAnyProductToCart(page);
  await page.goto(baseURL + BLOCKS_PAGE);
  await expect(page.locator('.wc-block-checkout, .wp-block-woocommerce-checkout').first()).toBeVisible({ timeout: 30000 });
  const fields = () => page.locator('.bgc-blocks-fields .bgc-fields[data-courier="speedy"]');
  await expect(fields()).toBeVisible({ timeout: 30000 });
  await page.waitForTimeout(2500);
  await page.locator('.woocommerce-store-notice__dismiss-link').click({ timeout: 3000 }).catch(() => {});

  // What the courier collects at the door is said on the rate row, not hidden behind "Free".
  const speedyRow = page.locator('.wc-block-components-radio-control__option', { hasText: 'Speedy' }).first();
  await expect(speedyRow).toContainText(/~\s?\d+[,.]\d{2}\s?€/);
  await expect(speedyRow).toContainText('на куриера при получаване');

  // A tab click used to leave the block dead for good.
  await fields().locator('.bgc-tab', { hasText: 'До адрес' }).click();
  await expect(fields()).toHaveAttribute('data-method', 'address');
  await expect(page.locator('.bgc-blocks-fields .bgc-fields.bgc-loading')).toHaveCount(0, { timeout: 10000 });
  await expect(fields().locator('.bgc-address-rows')).toBeVisible();

  await fields().locator('.bgc-city-field .select2-selection').click();
  await page.locator('.select2-search__field').fill('София');
  await page.locator('.select2-results__option[role="option"]').first().click({ timeout: 15000 });
  await expect(fields().locator('.bgc-city')).toHaveValue('68134', { timeout: 15000 });
  await expect(page.locator('.bgc-blocks-fields .bgc-fields.bgc-loading')).toHaveCount(0, { timeout: 10000 });

  await fields().locator('.bgc-street-field .select2-selection').click();
  await page.locator('.select2-search__field').fill('Витоша');
  const rows = page.locator('.select2-results__option[role="option"]');
  await expect(rows.filter({ hasText: /^ул\. ВИТОША$/ })).toHaveCount(1, { timeout: 15000 });
  await rows.filter({ hasText: /^ул\. ВИТОША$/ }).click();
  await expect(fields().locator('.bgc-street')).toHaveValue('ВИТОША');
  await expect(fields().locator('.bgc-street-type')).toHaveValue('ул.');
  await fields().locator('.bgc-street-no').fill('10');
  await fields().locator('.bgc-street-no').blur();
  await page.waitForTimeout(2500);

  // The block's own form. Its address reaches the server on blur, a moment later - wait for it, or the
  // order is refused for a phone the server has not been told yet.
  await page.fill('#email', 'e2e-blocks-address@example.com');
  await page.fill('#shipping-first_name', 'Тест');
  await page.fill('#shipping-last_name', 'Блок');
  await page.fill('#shipping-address_1', 'x');
  await page.fill('#shipping-city', 'София');
  await page.fill('#shipping-postcode', '1000');
  await page.fill('#shipping-phone', '0888123456');
  await page.locator('#shipping-phone').blur();
  await page.waitForResponse(r => /update-customer|store\/v1\/batch/.test(r.url()), { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(3000);
  await page.locator('#radio-control-wc-payment-method-options-cod').check({ force: true });
  await page.waitForTimeout(1500);
  await page.locator('button:has-text("Place Order")').click();
  await expect(page).toHaveURL(/order-received/i, { timeout: 60000 });
  const orderId = (page.url().match(/order-received\/(\d+)/) || [])[1] || '';
  expect(orderId, 'could not read the order number off the thank-you page').toBeTruthy();
  expect(dev('meta', orderId, '_bgcouriers_courier')).toBe('speedy');
  expect(dev('meta', orderId, '_bgcouriers_method')).toBe('address');
  expect(dev('meta', orderId, '_bgcouriers_street_name')).toBe('ВИТОША');
  expect(dev('meta', orderId, '_bgcouriers_street_type')).toBe('ул.');
  expect(dev('meta', orderId, '_bgcouriers_street_id')).toBe('1314');
  expect(dev('meta', orderId, '_bgcouriers_street_no')).toBe('10');
});

/**
 * The house number typed LAST and the button pressed straight after. Our fields are saved by a
 * fire-and-forget POST and the Store API validates against the session; on the classic checkout the
 * form's submit is held until the save lands, and the block had no such hold - 70 ms between the two
 * and the order came back "Моля, въведете улица и номер" with the number on the screen (measured
 * 2026-09-13). The block's checkout validation now waits for the flush.
 */
test('block checkout: a house number typed a moment before Place Order reaches the order @blocks @speedy', async ({ page, baseURL }) => {
  test.setTimeout(240000);
  await addAnyProductToCart(page);
  await page.goto(baseURL + BLOCKS_PAGE);
  const fields = () => page.locator('.bgc-blocks-fields .bgc-fields[data-courier="speedy"]');
  await expect(fields()).toBeVisible({ timeout: 30000 });
  await page.waitForTimeout(2500);
  await page.locator('.woocommerce-store-notice__dismiss-link').click({ timeout: 3000 }).catch(() => {});
  // The block's own form first, and time for it to reach the server.
  await page.fill('#email', 'e2e-blocks-race@example.com');
  await page.fill('#shipping-first_name', 'Тест');
  await page.fill('#shipping-last_name', 'Гонка');
  await page.fill('#shipping-address_1', 'x');
  await page.fill('#shipping-city', 'София');
  await page.fill('#shipping-postcode', '1000');
  await page.fill('#shipping-phone', '0888123456');
  await page.locator('#shipping-phone').blur();
  await page.waitForResponse(r => /update-customer|store\/v1\/batch/.test(r.url()), { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(3000);
  await page.locator('#radio-control-wc-payment-method-options-cod').check({ force: true });
  // Then the courier fields, the number last.
  await fields().locator('.bgc-tab', { hasText: 'До адрес' }).click();
  await expect(page.locator('.bgc-blocks-fields .bgc-fields.bgc-loading')).toHaveCount(0, { timeout: 10000 });
  await fields().locator('.bgc-city-field .select2-selection').click();
  await page.locator('.select2-search__field').fill('София');
  await page.locator('.select2-results__option[role="option"]').first().click({ timeout: 15000 });
  await expect(fields().locator('.bgc-city')).toHaveValue('68134', { timeout: 15000 });
  await expect(page.locator('.bgc-blocks-fields .bgc-fields.bgc-loading')).toHaveCount(0, { timeout: 10000 });
  await fields().locator('.bgc-street-field .select2-selection').click();
  await page.locator('.select2-search__field').fill('Шипка');
  const rows = page.locator('.select2-results__option[role="option"]');
  await expect(rows.first()).toBeVisible({ timeout: 15000 });
  await page.waitForTimeout(500);
  await rows.first().click();
  await page.waitForTimeout(2500);
  await fields().locator('.bgc-street-no').fill('77');
  await page.locator('button:has-text("Place Order")').click(); // no blur, no wait: the race
  await expect(page).toHaveURL(/order-received/i, { timeout: 60000 });
  const orderId = (page.url().match(/order-received\/(\d+)/) || [])[1] || '';
  expect(orderId).toBeTruthy();
  expect(dev('meta', orderId, '_bgcouriers_street_no')).toBe('77');
  expect(dev('meta', orderId, '_bgcouriers_street_name')).toBe('Шипка');
});
