const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, fillGuestBilling } = require('../helpers/shop');

function amount(text) {
  const m = (text || '').match(/[\d]+[,.][\d]+/);
  return m ? parseFloat(m[0].replace(',', '.')) : 0;
}

test('speedy guest checkout to AUTOMAT (locker), COD', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);

  const fields = page.locator('.bgc-fields');
  await expect(fields).toBeVisible({ timeout: 15000 });

  // Choose "to automat" — a separate option; the picker lists the cached lockers.
  await fields.locator('input[name="bgc_method"][value="automat"]').check();
  await expect(fields.locator('.bgc-address-rows')).toBeHidden();

  // City → loads automats for that city.
  await fields.locator('.bgc-row').filter({ has: page.locator('.bgc-city') }).locator('.select2-selection').click();
  await page.locator('.select2-search__field').fill('София');
  await expect(page.locator('.select2-results__option').first()).toBeVisible({ timeout: 15000 });
  await page.locator('.select2-results__option').first().click();

  // A locker is auto-selected.
  await expect(fields.locator('.bgc-office-row')).toBeVisible({ timeout: 20000 });
  await expect.poll(async () => await fields.locator('.bgc-office').inputValue(), { timeout: 20000 }).not.toBe('');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);

  const sub = amount(await page.locator('.cart-subtotal .woocommerce-Price-amount').first().innerText());
  const ship = amount(await page.locator('.woocommerce-shipping-totals .woocommerce-Price-amount').first().innerText());
  const total = amount(await page.locator('.order-total .woocommerce-Price-amount').first().innerText());
  console.log(`AUTOMAT Subtotal: ${sub}, Shipping: ${ship}, Total: ${total}`);
  expect(sub).toBeGreaterThan(0);
  expect(ship).toBeGreaterThan(0);
  expect(total).toBeGreaterThanOrEqual(sub + ship - 0.05);

  await fillGuestBilling(page, { first: 'Тест', last: 'Автомат', email: 'e2e-apt@example.com', phone: '0888123456' });
  await page.locator('#place_order').click();

  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  const order = page.locator('.woocommerce-order').first();
  await expect(order).toBeVisible({ timeout: 15000 });
  await expect(order).toContainText(/Speedy/i);
});
