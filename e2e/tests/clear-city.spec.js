const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, dismissStoreBanner, selectShippingMethod, selectCity, pickFirstOffice } = require('../helpers/shop');

/**
 * The X on the town clears the town - in the session, not only on the screen.
 *
 * WooCommerce's selectWoo is a fork of Select2 4.0.3, and that build has no select2:clear event: the
 * checkout's clear handlers were bound to it and never ran. Measured on dev and prod on 2026-09-12:
 * the X emptied the town on screen, and the office stayed in its field, the session kept both, the
 * price row went on quoting for the town, and the next save sent the office without its town. That
 * half-selection is what the owner saw rendered: one office, greyed out for want of a town, no list.
 *
 * So the clear is driven the way a customer does it - the X is clicked - and what must follow is
 * measured on three sides: the office field empties, the session is told (a save carrying no town and
 * no office goes out), and the block the server renders back has neither. The map button greying is
 * not asserted here: it already followed the field's own change event and worked before the fix.
 *
 * Places no order.
 */
test('clearing the town with the X clears the office and reaches the session @core', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await dismissStoreBanner(page);
  await selectShippingMethod(page, 'speedy');
  const fields = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(fields).toBeVisible({ timeout: 15000 });
  await selectCity(page, fields, 'София');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1500);
  await pickFirstOffice(page, fields);
  await page.waitForTimeout(1500);
  const office = await fields.locator('.bgc-office').inputValue();
  expect(office, 'control: an office is chosen before the town is cleared').not.toBe('');

  // Every save the clear sends, read off the wire rather than inferred from the screen.
  const saves = [];
  page.on('request', (req) => {
    if (req.method() !== 'POST' || !req.url().includes('admin-ajax.php')) { return; }
    const body = req.postData() || '';
    if (!body.includes('action=bgcouriers_set_selection')) { return; }
    const p = new URLSearchParams(body);
    saves.push({ courier: p.get('courier'), site_id: p.get('site_id'), office_id: p.get('office_id') });
  });

  // The X, as a customer clicks it.
  await fields.locator('.bgc-city-field .select2-selection__clear').click();
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);

  // 1. The screen: no town, no office.
  expect(await fields.locator('.bgc-city').inputValue()).toBe('');
  expect(await fields.locator('.bgc-office').inputValue()).toBe('');
  // 2. The session was told - a save with neither.
  const cleared = saves.filter((s) => s.courier === 'speedy' && s.site_id === '0' && s.office_id === '0');
  expect(cleared.length, 'the clear reached the session: ' + JSON.stringify(saves)).toBeGreaterThan(0);
  // 3. And the block the server rendered back agrees - the fields above are the re-rendered ones by
  //    now, but say so explicitly: the office field has no option in it at all.
  expect(await fields.locator('.bgc-office option').count()).toBe(0);
});
