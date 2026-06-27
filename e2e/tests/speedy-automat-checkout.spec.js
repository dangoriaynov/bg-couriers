const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, fillGuestBilling, selectShippingMethod, selectSpeedyTab, selectCity, pickFirstOffice } = require('../helpers/shop');

function amount(text) { const m = (text || '').match(/[\d]+[,.][\d]+/); return m ? parseFloat(m[0].replace(',', '.')) : 0; }

test('speedy guest checkout to AUTOMAT (locker), COD @speedy', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await selectShippingMethod(page, 'speedy');
  const fields = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(fields).toBeVisible({ timeout: 15000 });

  await selectSpeedyTab(page, fields, 'automat');
  await expect(fields.locator('.bgc-address-rows')).toBeHidden();

  await selectCity(page, fields, 'София');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1500);
  await expect(fields.locator('.bgc-office-row')).toBeVisible({ timeout: 15000 });
  await pickFirstOffice(page, fields); // automats are filtered by the tab type
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);

  const sub = amount(await page.locator('.cart-subtotal .woocommerce-Price-amount').first().innerText());
  const ship = amount(await page.locator('.woocommerce-shipping-totals .woocommerce-Price-amount').first().innerText());
  const total = amount(await page.locator('.order-total .woocommerce-Price-amount').first().innerText());
  console.log(`AUTOMAT Subtotal: ${sub}, Shipping: ${ship}, Total: ${total}`);
  expect(sub).toBeGreaterThan(0);
  expect(total).toBeGreaterThan(sub); // shipping (+VAT) added on top of goods (ship parse is informational with 2 methods in the zone)

  await fillGuestBilling(page, { first: 'Тест', last: 'Автомат', email: 'e2e-apt@example.com', phone: '0888123456' });
  await page.locator('#place_order').click();

  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  const order = page.locator('.woocommerce-order').first();
  await expect(order).toBeVisible({ timeout: 15000 });
  await expect(order).toContainText(/Speedy/i);
});
