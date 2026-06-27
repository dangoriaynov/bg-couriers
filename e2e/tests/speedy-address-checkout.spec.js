const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, fillGuestBilling, selectShippingMethod, selectSpeedyTab, selectCity, fillStreet } = require('../helpers/shop');

function amount(text) { const m = (text || '').match(/[\d]+[,.][\d]+/); return m ? parseFloat(m[0].replace(',', '.')) : 0; }

test('speedy guest checkout to ADDRESS, COD @speedy', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await selectShippingMethod(page, 'speedy');
  const fields = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(fields).toBeVisible({ timeout: 15000 });

  await selectSpeedyTab(page, fields, 'address');
  await expect(fields.locator('.bgc-address-rows')).toBeVisible({ timeout: 10000 });
  await expect(fields.locator('.bgc-office-row')).toBeHidden();

  await selectCity(page, fields, 'София');
  // Let the city recalc + re-render settle BEFORE typing the street (no later re-render wipes it).
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2000);

  await fillStreet(page, fields, 'Вит'); // type + pick a street from the autocomplete
  await fields.locator('.bgc-street-no').fill('5');
  await page.waitForTimeout(1000);
  await page.waitForLoadState('networkidle').catch(() => {});
  await expect.poll(async () => await fields.locator('.bgc-street').inputValue(), { timeout: 15000 }).not.toBe('');
  await expect(fields.locator('.bgc-street-no')).toHaveValue('5', { timeout: 15000 });
  await page.waitForTimeout(1500);

  const sub = amount(await page.locator('.cart-subtotal .woocommerce-Price-amount').first().innerText());
  const ship = amount(await page.locator('.woocommerce-shipping-totals .woocommerce-Price-amount').first().innerText());
  const total = amount(await page.locator('.order-total .woocommerce-Price-amount').first().innerText());
  console.log(`ADDRESS Subtotal: ${sub}, Shipping: ${ship}, Total: ${total}`);
  expect(sub).toBeGreaterThan(0);
  expect(total).toBeGreaterThan(sub); // shipping (+VAT) added on top of goods (ship parse is informational with 2 methods in the zone)

  await fillGuestBilling(page, { first: 'Тест', last: 'Адрес', email: 'e2e-addr@example.com', phone: '0888123456' });
  await page.locator('#place_order').click();

  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  const order = page.locator('.woocommerce-order').first();
  await expect(order).toBeVisible({ timeout: 15000 });
  await expect(order).toContainText(/Speedy/i);
});
