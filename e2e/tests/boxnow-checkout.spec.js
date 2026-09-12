const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, dismissStoreBanner, fillGuestBilling, selectShippingMethod, selectCity, pickFirstOffice, choosePayment } = require('../helpers/shop');

/**
 * A BOX NOW order goes through with the locker the customer picked - chosen the way every other locker
 * is chosen: a town, then a locker from the list.
 *
 * Until 2026-09-12 BOX NOW was the one courier with a block of its own: BOX NOW's map widget in an
 * iframe - a different window, centred on Athens, asking the browser for a location, and unable to
 * take the town the customer had already named for the other couriers (owner: "boxnow works very
 * badly ... may we have it unified with other couriers?"). BOX NOW publishes no town list, so the
 * towns are read off its lockers (each carries its town and postal code) and the block is the same
 * one Speedy, Econt and the rest have. This spec drives that block; the iframe is gone.
 *
 * Earlier (2026-09-11): the submit-time flush read the office through selects the old block did not
 * have and saved office_id 0 over the locker, so every BOX NOW order was refused with "choose a
 * locker". With one block for every courier there is no second path for that to happen on.
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
  // The standard block: no widget button, a town field and a locker field.
  expect(await fields.locator('.bgc-boxnow-pick').count()).toBe(0);
  await expect(fields.locator('.bgc-city-field')).toBeVisible();

  await selectCity(page, fields, 'Бургас');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1500);
  await expect(fields.locator('.bgc-office-row')).toBeVisible({ timeout: 15000 });
  await pickFirstOffice(page, fields);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);
  const lockerId = await page.locator('.bgc-fields[data-courier="boxnow"] .bgc-office').inputValue();
  expect(Number(lockerId), 'a locker id is on the field after the re-render').toBeGreaterThan(0);
  const lockerLabel = await page.locator('.bgc-fields[data-courier="boxnow"] .bgc-office option:checked').innerText();
  expect(lockerLabel).toContain('Бургас');

  await fillGuestBilling(page, { first: 'Тест', last: 'Боксноу', email: 'e2e-boxnow@example.com', phone: '0888123456' });
  await choosePayment(page, 'bacs');
  await page.locator('#place_order').click();

  // Not "choose a locker": the order goes through with the locker on it.
  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  const order = page.locator('.woocommerce-order').first();
  await expect(order).toBeVisible({ timeout: 15000 });
  await expect(order).toContainText(/BOX NOW/i);
  await expect(order).toContainText(/Бургас/);
});

/**
 * The town carries over. Named for Speedy, it is already there when BOX NOW is chosen - by its name,
 * because BOX NOW lists Sofia under its lowest locker's postal code, which need not be the 1000 the
 * other couriers use; the carry falls back from the code to the name.
 */
test('boxnow gets the town named for another courier @boxnow', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await dismissStoreBanner(page);
  await selectShippingMethod(page, 'speedy');
  const speedy = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(speedy).toBeVisible({ timeout: 15000 });
  await selectCity(page, speedy, 'София');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2000);

  await selectShippingMethod(page, 'boxnow');
  const fields = page.locator('.bgc-fields[data-courier="boxnow"]');
  await expect(fields).toBeVisible({ timeout: 15000 });
  await expect(fields.locator('.bgc-city option:checked')).toHaveText(/София/i, { timeout: 15000 });
  // And the locker list for it opens with more than one locker in it.
  await fields.locator('.bgc-office-row .select2-selection').click();
  const options = page.locator('.select2-results__option[role="option"]');
  await expect(options.first()).toBeVisible({ timeout: 20000 });
  expect(await options.count()).toBeGreaterThan(1);
  await page.keyboard.press('Escape');
});
