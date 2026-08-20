const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { addAnyProductToCart, gotoCheckout, selectShippingMethod } = require('../helpers/shop');

/**
 * A foreign address this shop CANNOT deliver to has to say so, and offer the way back.
 *
 * The state is not hypothetical: a shop whose cash on delivery is receipted through the courier's ППП
 * has no way at all to be paid abroad, so every rate for a foreign address is correctly refused - and
 * the country picker, which renders underneath a rate, goes with them. What the customer was left with
 * was WooCommerce's stock "no shipping methods, check your address" on an address with nothing wrong
 * with it, and nothing on the page to change it back with. This spec is that screen.
 *
 * It books nothing and places no order, so it runs with every other spec. It does force the prepaid
 * gateway OFF for its own length (and back to what it FOUND afterwards), because the dead end only
 * exists while the shop has no prepaid method - with one enabled the Romanian rate is offered and there
 * is nothing here to test.
 */

const SH = path.join(__dirname, '..', 'dev-option.sh');
const dev = (...args) => execFileSync('bash', [SH, ...args], { encoding: 'utf8' }).trim();

let prepaidWas = 'no';
test.beforeAll(() => {
  // Stated, not assumed: the dead end only exists while cash on delivery is receipted through the
  // courier's ППП. On a shop with its own cash register every rate abroad is offered and nothing below
  // holds - which would otherwise fail further down with a message pointing at the wrong cause.
  const mode = dev('get', 'bgcouriers_cod_fiscalization');
  expect(mode, 'dev is not in ППП mode, so there is no dead end to test').toBe('ppp');
  prepaidWas = dev('gateway', 'bacs');
  console.log(`[deadend] bank transfer was ${prepaidWas}, off for this spec: ${dev('gateway', 'bacs', 'no')}`);
});
test.afterAll(() => { console.log(`[deadend] bank transfer back to ${dev('gateway', 'bacs', prepaidWas)}`); });

test('an undeliverable country says why, and takes the customer back @speedy', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await selectShippingMethod(page, 'speedy');
  const fields = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(fields).toBeVisible({ timeout: 15000 });

  const country = fields.locator('.bgc-country');
  await expect(country, 'no country picker - switch Romania on in Speedy settings and run Sync now')
    .toBeVisible({ timeout: 10000 });
  await country.selectOption('RO');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);

  // The premise of the whole spec: with no prepaid method there is nothing left to choose.
  await expect(page.locator('input[name^="shipping_method"]'),
    'a rate survived - is a prepaid gateway enabled on dev after all?').toHaveCount(0);

  // Cash on delivery has to be gone WITH them. It is legal on this shop only through the courier's ППП,
  // which stops at the border - and the message about to be asserted says the order can only be prepaid,
  // so COD sitting underneath it is the shop offering to take money it cannot receipt. It did: the
  // gateway filter waited for a chosen courier, and with every rate refused there was none to ask.
  await expect(page.locator('#payment_method_cod'),
    'cash on delivery is still offered for a country the shop says must be prepaid').toHaveCount(0);

  // And now the screen itself. Named country, stated reason, and a way out.
  const dead = page.locator('.bgc-no-shipping');
  await expect(dead, 'the customer got WooCommerce\'s stock message instead of the reason')
    .toBeVisible({ timeout: 10000 });
  await expect(dead).toContainText('Румъния');
  const back = dead.locator('a[href*="bgcouriers_home"]');
  await expect(back, 'nothing on the page leads back out of the dead end').toHaveCount(1);

  await back.click();
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);

  // Back where the shop can actually deliver: the rates return, and so does the picker - set to home.
  await expect(page.locator('input[name^="shipping_method"][value^="bgcouriers_"]').first(),
    'the way back did not bring the delivery options back').toBeVisible({ timeout: 15000 });
  await expect(page.locator('.bgc-no-shipping')).toHaveCount(0);
  await expect(page.locator('#payment_method_cod'), 'and cash on delivery is back where the ППП works')
    .toHaveCount(1);
  const home = page.locator('.bgc-fields[data-courier="speedy"] .bgc-country');
  if (await home.count()) { await expect(home).toHaveValue('BG'); }
});
