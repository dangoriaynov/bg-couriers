const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, fillGuestBilling } = require('../helpers/shop');

function amount(text) {
  const m = (text || '').match(/[\d]+[,.][\d]+/);
  return m ? parseFloat(m[0].replace(',', '.')) : 0;
}

test('speedy guest checkout to ADDRESS, COD', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);

  const fields = page.locator('.bgc-fields');
  await expect(fields).toBeVisible({ timeout: 15000 });

  // Choose "to address" — the address block appears, the office picker hides.
  await fields.locator('input[name="bgc_method"][value="address"]').check();
  await expect(fields.locator('.bgc-address-rows')).toBeVisible({ timeout: 10000 });
  await expect(fields.locator('.bgc-office-row')).toBeHidden();

  // City (gives the site id for the city-level price + validation).
  await fields.locator('.bgc-row').filter({ has: page.locator('.bgc-city') }).locator('.select2-selection').click();
  await page.locator('.select2-search__field').fill('София');
  await expect(page.locator('.select2-results__option').first()).toBeVisible({ timeout: 15000 });
  await page.locator('.select2-results__option').first().click();
  // Let the city's recalc + re-render fully settle BEFORE typing the street, so no later
  // re-render wipes it (the address save itself does not trigger a recalc).
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2000);

  // Street + number (the debounced save fires ~600ms after the last input; no recalc).
  await fields.locator('.bgc-street').fill('Витоша');
  await fields.locator('.bgc-street-no').fill('5');
  // Let the debounced save + update_checkout land, then confirm the values survived the
  // re-render — i.e. they are now in the session — BEFORE touching billing. Billing's own
  // update_checkout would otherwise re-render the address inputs and drop an unsaved value.
  await page.waitForTimeout(900);
  await page.waitForLoadState('networkidle').catch(() => {});
  await expect(fields.locator('.bgc-street')).toHaveValue('Витоша', { timeout: 15000 });
  await expect(fields.locator('.bgc-street-no')).toHaveValue('5', { timeout: 15000 });
  await page.waitForTimeout(1500);

  // Shipping is priced (city-level) and summed into the total.
  const sub = amount(await page.locator('.cart-subtotal .woocommerce-Price-amount').first().innerText());
  const ship = amount(await page.locator('.woocommerce-shipping-totals .woocommerce-Price-amount').first().innerText());
  const total = amount(await page.locator('.order-total .woocommerce-Price-amount').first().innerText());
  console.log(`ADDRESS Subtotal: ${sub}, Shipping: ${ship}, Total: ${total}`);
  expect(sub).toBeGreaterThan(0);
  expect(ship).toBeGreaterThan(0);
  expect(total).toBeGreaterThanOrEqual(sub + ship - 0.05);

  await fillGuestBilling(page, { first: 'Тест', last: 'Адрес', email: 'e2e-addr@example.com', phone: '0888123456' });
  await page.locator('#place_order').click();

  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  const order = page.locator('.woocommerce-order').first();
  await expect(order).toBeVisible({ timeout: 15000 });
  await expect(order).toContainText(/Speedy/i);
});
