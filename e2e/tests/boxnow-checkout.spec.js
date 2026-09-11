const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, dismissStoreBanner, fillGuestBilling, selectShippingMethod, choosePayment } = require('../helpers/shop');

/**
 * A BOX NOW order goes through with the locker the customer picked.
 *
 * Reported 2026-09-11 by a shop testing the webhook on BOX NOW's stage account: the locker showed in
 * the block, and placing the order answered "Please choose a BOX NOW locker". The submit-time flush -
 * which saves the chosen courier's block before the order is sent, so a street typed a moment before
 * is not lost - read the office through the town/office selects that BOX NOW's block does not have,
 * and saved office_id 0 over the locker. Every BOX NOW order had been refused that way since the flush
 * arrived on 2026-08-15; no BOX NOW order exists on the live shop from that date.
 *
 * The widget is BOX NOW's own iframe, which cannot be driven from here; the message it posts on a pick
 * is, and that is what the plugin listens for.
 *
 * Pays by bank transfer: BOX NOW cannot collect cash, so the checkout hides cash on delivery for it.
 */
test('boxnow guest checkout keeps the locker through the order @boxnow', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await dismissStoreBanner(page);
  await selectShippingMethod(page, 'boxnow');
  const fields = page.locator('.bgc-fields[data-courier="boxnow"]');
  await expect(fields).toBeVisible({ timeout: 15000 });

  // What the widget posts when a locker is chosen (shape as BOX NOW's own plugin reads it).
  await page.evaluate(() => window.postMessage(JSON.stringify({
    boxnowLockerId: '8009', boxnowLockerName: 'BOX NOW - ж.к. Меден Рудник, ул. Балкан 50, Бургас - 24/7',
    boxnowLockerAddressLine1: 'ул. Балкан 50', boxnowLockerPostalCode: '8000',
  }), '*'));
  await expect(fields.locator('.bgc-boxnow-selected')).toBeVisible({ timeout: 10000 });
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500); // the pick's own recalculation re-renders the block from the session
  // Re-rendered from the session, the locker is still there: the save took.
  await expect(page.locator('.bgc-fields[data-courier="boxnow"] .bgc-boxnow-selected')).toBeVisible();
  await expect(page.locator('.bgc-fields[data-courier="boxnow"] .bgc-boxnow-id')).toHaveValue('8009');

  await fillGuestBilling(page, { first: 'Тест', last: 'Боксноу', email: 'e2e-boxnow@example.com', phone: '0888123456' });
  await choosePayment(page, 'bacs');
  await page.locator('#place_order').click();

  // Not "choose a locker": the order goes through with the locker on it.
  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  const order = page.locator('.woocommerce-order').first();
  await expect(order).toBeVisible({ timeout: 15000 });
  await expect(order).toContainText(/BOX NOW/i);
  await expect(order).toContainText(/Меден Рудник/);
});
