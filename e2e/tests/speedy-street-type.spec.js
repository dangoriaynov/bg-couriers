const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { addAnyProductToCart, gotoCheckout, fillGuestBilling, selectShippingMethod, selectSpeedyTab, selectCity, choosePayment } = require('../helpers/shop');

/**
 * Two streets of one name, and the order knows which.
 *
 * Sofia has a бул. ВИТОША and a ул. ВИТОША, and Speedy refuses the bare name in a town that has both
 * (measured 2026-09-13 against /validation/address: "За избраното населено място се изисква ул./бул. от
 * номенклатура"). The checkout listed both off Speedy's own list, keyed by the bare name - so to select2
 * they were ONE row: whichever the customer clicked, the box kept the first; once one was chosen the
 * other showed as already selected, and a click on it merely closed the list. The order then carried
 * "ВИТОША" and the waybill was refused at the packing table.
 *
 * Now each row is keyed by its label, the box keeps the bare name as its value (what every courier's
 * label reads) and shows the label, and Speedy's id and the type ride beside it into the order.
 *
 * The second test books a REAL waybill to ул. ВИТОША and voids it again - it is the only proof that
 * Speedy accepts what the order now carries, and it runs only when asked for by name:
 *
 *     BGC_REAL_WAYBILL=1 npx playwright test speedy-street-type
 */

const SH = path.join(__dirname, '..', 'dev-option.sh');
const dev = (...args) => execFileSync('bash', [SH, ...args], { encoding: 'utf8' }).trim();

async function openStreetList(page, fields, term) {
  await fields.locator('.bgc-street-field .select2-selection').click();
  const search = page.locator('.select2-search__field');
  await expect(search).toBeVisible({ timeout: 10000 });
  await search.fill(term);
  const rows = page.locator('.select2-results__option[role="option"]');
  await expect(rows.first()).toBeVisible({ timeout: 15000 });
  await page.waitForTimeout(400); // the list settles after the AJAX answer replaces the "Searching..." row
  return rows;
}

// Anchored: "бул. ВИТОША" CONTAINS "ул. ВИТОША", so a substring match on the row text picks the wrong one.
const UL  = /^ул\. ВИТОША$/;
const BUL = /^бул\. ВИТОША$/;
const shown = (fields) => fields.locator('.bgc-street-field .select2-selection__rendered');

/** Drive the checkout to Speedy / address / София and pick the street off the list by its label. */
async function pickVitosha(page, label) {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await selectShippingMethod(page, 'speedy');
  const fields = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(fields).toBeVisible({ timeout: 15000 });
  await selectSpeedyTab(page, fields, 'address');
  await expect(fields.locator('.bgc-address-rows')).toBeVisible({ timeout: 10000 });
  await selectCity(page, fields, 'София');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2000);
  const rows = await openStreetList(page, fields, 'Витоша');
  await rows.filter({ hasText: label }).first().click();
  return fields;
}

test('both ВИТОША rows are offered, either can be picked, and the order carries which @speedy', async ({ page }) => {
  const fields = await pickVitosha(page, UL);
  await expect(shown(fields)).toHaveText(/^×?\s*ул\. ВИТОША$/);
  await expect(fields.locator('.bgc-street')).toHaveValue('ВИТОША'); // the bare name is the value
  await expect(fields.locator('.bgc-street-type')).toHaveValue('ул.');
  const ulId = await fields.locator('.bgc-street-id').inputValue();
  expect(Number(ulId), 'the street chosen off the list carries its Speedy id').toBeGreaterThan(0);

  // The OTHER street of that name is a second row, and it can be picked after the first one was.
  const rows = await openStreetList(page, fields, 'Витоша');
  const both = rows.filter({ hasText: /^(бул\.|ул\.) ВИТОША$/ });
  await expect(both, 'бул. ВИТОША and ул. ВИТОША are two rows').toHaveCount(2);
  await both.filter({ hasText: BUL }).click();
  await expect(shown(fields)).toHaveText(/^×?\s*бул\. ВИТОША$/);
  await expect(fields.locator('.bgc-street-type')).toHaveValue('бул.');
  const bulId = await fields.locator('.bgc-street-id').inputValue();
  expect(Number(bulId)).toBeGreaterThan(0);
  expect(bulId).not.toBe(ulId);

  // And a typed street carries neither - it is not off the list.
  const typed = await openStreetList(page, fields, 'Моя Улица');
  await typed.first().click();
  await expect(fields.locator('.bgc-street')).toHaveValue('Моя Улица');
  await expect(fields.locator('.bgc-street-id')).toHaveValue('0');
  await expect(fields.locator('.bgc-street-type')).toHaveValue('');

  // Back to ул. ВИТОША, place the order, read what it carries.
  const again = await openStreetList(page, fields, 'Витоша');
  await again.filter({ hasText: UL }).first().click();
  await fields.locator('.bgc-street-no').fill('10');
  await page.waitForTimeout(1200);
  await fillGuestBilling(page, { first: 'Тест', last: 'Витоша', email: 'e2e-street@example.com', phone: '0888123456' });
  await choosePayment(page, 'cod');
  await page.locator('#place_order').click();
  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  const orderId = (page.url().match(/order-received\/(\d+)/) || [])[1] || '';
  expect(orderId, 'could not read the order number off the thank-you page').toBeTruthy();
  expect(dev('meta', orderId, '_bgcouriers_street_name')).toBe('ВИТОША');
  expect(dev('meta', orderId, '_bgcouriers_street_type')).toBe('ул.');
  expect(dev('meta', orderId, '_bgcouriers_street_id')).toBe(ulId);
  expect(dev('meta', orderId, '_bgcouriers_street_no')).toBe('10');
});

let bookedOrder = '';
let booked = '';
test.afterEach(async () => {
  if (!bookedOrder) { return; }
  const id = bookedOrder;
  bookedOrder = '';
  let out;
  try {
    out = dev('cancel', id);
  } catch (e) {
    console.log(`[street] CANCEL FAILED TO RUN for order ${id} - ${booked || 'waybill unknown'} MAY STILL BE LIVE`);
    console.log(`[street] void it by hand: bash e2e/dev-option.sh cancel ${id}`);
    throw e;
  }
  console.log(`[street] order ${id}: ${out}`);
  expect(out, `the booked waybill must be voided again (order ${id}, ${booked || 'waybill unknown'})`).toMatch(/^CANCELLED /);
});

test('Speedy accepts a waybill to ул. ВИТОША chosen off the list @speedy @books-real-waybill', async ({ page }) => {
  const fields = await pickVitosha(page, UL);
  await expect(shown(fields)).toHaveText(/^×?\s*ул\. ВИТОША$/);
  await expect(fields.locator('.bgc-street-type')).toHaveValue('ул.');
  await fields.locator('.bgc-street-no').fill('10');
  await page.waitForTimeout(1200);
  await fillGuestBilling(page, { first: 'Тест', last: 'Витоша', email: 'e2e-street@example.com', phone: '0888123456' });
  await choosePayment(page, 'cod');
  await page.locator('#place_order').click();
  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  bookedOrder = (page.url().match(/order-received\/(\d+)/) || [])[1] || '';
  expect(bookedOrder, 'could not read the order number off the thank-you page').toBeTruthy();
  booked = dev('label', bookedOrder);
  console.log(`[street] order ${bookedOrder}: ${booked}`);
  expect(booked, 'Speedy refused the shipment to ул. ВИТОША').toMatch(/^WAYBILL \S+ speedy$/);
});
