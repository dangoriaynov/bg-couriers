const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, fillGuestBilling, selectShippingMethod, selectSpeedyTab,
        selectCity, pickFirstOffice, fillStreet, choosePayment } = require('../helpers/shop');

/**
 * Express One, driven the way a customer drives it - all three ways a parcel can go.
 *
 * Worth its own file rather than a line in another courier's, because Express One is the only courier
 * here whose ADDRESS delivery depends on something the checkout never stores: the API refuses a street
 * it was not given an id for, and the order carries only the name. The street the customer picks in the
 * office of this spec is the one looked up again when the waybill is made, so a checkout that lets a
 * free-typed street through is a label that cannot be printed - and that only shows at the packing
 * table. Driving the picker is the test of it.
 *
 * Nothing here is rehearsed against a fixture: it runs against the live dev shop, and Express One's own
 * test environment is what answers, so the prices are the courier's own and no real parcel is created.
 *
 * The orders are cash on delivery, which on this shop is a statement about the contract and not a
 * convenience: dev receipts наложен платеж through the courier's ППП, so the plugin offers it only for a
 * courier whose contract pays out that way. Express One's does (owner, 2026-08-25), and the tick on its
 * tab is what says so - untick it and cash on delivery disappears from this checkout, the way it is
 * absent for BOX NOW. Each spec asserts the collection is priced too: the courier charges for taking the
 * money at the door, and a quote that forgot it is the shop paying the difference.
 */

function amount(text) { const m = (text || '').match(/[\d]+[,.][\d]+/); return m ? parseFloat(m[0].replace(',', '.')) : 0; }

async function totals(page, label) {
  const sub = amount(await page.locator('.cart-subtotal .woocommerce-Price-amount').first().innerText());
  const total = amount(await page.locator('.order-total .woocommerce-Price-amount').first().innerText());
  console.log(`[expressone ${label}] subtotal ${sub}, total ${total}`);
  expect(sub, 'the basket has to cost something for the shipping to be visible on top of it').toBeGreaterThan(0);
  expect(total, 'delivery is charged with the order on dev, so the total must exceed the goods').toBeGreaterThan(sub);
  return { sub, total };
}

async function start(page, method) {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await selectShippingMethod(page, 'expressone');
  const fields = page.locator('.bgc-fields[data-courier="expressone"]');
  await expect(fields, 'Express One is not offered at all - check it is enabled and in the shipping zone')
    .toBeVisible({ timeout: 15000 });
  await selectSpeedyTab(page, fields, method);
  await selectCity(page, fields, 'София');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1500);
  return fields;
}

async function place(page, who) {
  await fillGuestBilling(page, { first: 'Тест', last: who, email: 'e2e-expressone@example.com', phone: '0888123456' });
  await expect(page.locator('#payment_method_cod'),
    'cash on delivery is missing - is "COD payout via ППП" still ticked on the Express One tab?')
    .toHaveCount(1);
  await choosePayment(page, 'cod');
  await page.locator('#place_order').click();
  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  const order = page.locator('.woocommerce-order').first();
  await expect(order).toBeVisible({ timeout: 15000 });
  await expect(order, 'the order has to say which courier is carrying it').toContainText(/Express One/i);
}

test('expressone guest checkout to an office, COD @expressone', async ({ page }) => {
  const fields = await start(page, 'office');
  await expect(fields.locator('.bgc-office-row')).toBeVisible({ timeout: 15000 });
  await pickFirstOffice(page, fields);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);
  await totals(page, 'office');
  await place(page, 'Офис');
});

/**
 * The locker. Express One calls its own EXOBOX, and it is a different price from the counter - the
 * cheapest of the three - which is only true because the quote names the point it is going to.
 */
test('expressone guest checkout to an EXOBOX locker, COD @expressone', async ({ page }) => {
  const fields = await start(page, 'automat');
  await expect(fields.locator('.bgc-office-row')).toBeVisible({ timeout: 15000 });
  await pickFirstOffice(page, fields);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);
  await totals(page, 'locker');
  await place(page, 'Локер');
});

/**
 * The address, picked from Express One's own street list. The number is typed; the street may not be,
 * and this is the spec that says so.
 */
test('expressone guest checkout to an address, COD @expressone', async ({ page }) => {
  const fields = await start(page, 'address');
  await fillStreet(page, fields, 'Цветан Лазаров');
  await fields.locator('.bgc-street-no').fill('117');
  await fields.locator('.bgc-street-no').blur();
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);
  await totals(page, 'address');
  await place(page, 'Адрес');
});
