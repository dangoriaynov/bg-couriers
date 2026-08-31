const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { addAnyProductToCart, gotoCheckout, fillGuestBilling, selectShippingMethod, selectSpeedyTab,
        selectCity, pickFirstOffice, fillStreet } = require('../helpers/shop');

/**
 * Европът, driven the way a customer drives it - both ways a parcel can go, and no third one.
 *
 * Worth its own file for three things no other courier here has.
 *
 * **It prices both ends of the journey in one number.** Their `deliveryType` says where the parcel
 * starts as well as where it goes (ОФ-ОФ, ОФ-ВР, ВР-ОФ, ВР-ВР), so the merchant's own end is a setting
 * and the same parcel to Sofia costs 4.59 handed over at a counter and 6.52 collected from the door.
 * If the checkout ever shows one price for both tabs, that field has stopped reaching the quote - which
 * is why the last test compares them rather than asserting either number.
 *
 * **Its street has to come off their list.** /getaddresses gives every street an id and the waybill
 * takes the id, so a typed street is a label that cannot be printed - and that only shows at the
 * packing table, hours after the customer has gone. The address test therefore asserts on the ABSENCE
 * of a free-text box, not just on the picker working.
 *
 * **Cash on delivery depends on the account, not on the courier.** Европът does ППП, but only for an
 * account they have activated it for, and this shop receipts наложен платеж through exactly that. So
 * the rule is read from the setting rather than hardcoded: with the tick off, the checkout must take
 * cash on delivery away; with it on, it must offer it. Writing "COD is absent" as a constant would turn
 * this spec into a booby trap on the day Европът activates the service.
 *
 * Nothing here places an order, so no waybill is created and no courier is called. Every price is the
 * courier's own, live, from the shop's real account.
 */

const SH = path.join(__dirname, '..', 'dev-option.sh');
// An option the merchant has never saved is not an error: `wp option get` exits 1 for a missing row,
// and BGCouriers_Settings::courier_ppp_payout() answers 'no' for anything but Speedy and Econt when the
// option is absent. So a miss reads as the code's own default rather than failing the run.
const dev = (...args) => {
  try { return execFileSync('bash', [SH, ...args], { encoding: 'utf8' }).trim(); }
  catch (e) { return ''; }
};

function amount(text) { const m = (text || '').match(/[\d]+[,.][\d]+/); return m ? parseFloat(m[0].replace(',', '.')) : 0; }

async function start(page) {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await fillGuestBilling(page, { first: 'Е2Е', last: 'Тест', phone: '0888123456', email: 'e2e-evropat@example.com' });
  await selectShippingMethod(page, 'evropat');
  const fields = page.locator('.bgc-fields[data-courier="evropat"]');
  await expect(fields, 'Европът is not offered at all - is it enabled AND in the България shipping zone?')
    .toBeVisible({ timeout: 15000 });
  return fields;
}

/** THIS courier's own rate row - not `.first()`, which reads whichever courier happens to be on top. */
async function shownPrice(page) {
  const text = await page.locator('label[for^="shipping_method_0_bgcouriers_evropat"]').first().innerText();
  return { text: text.replace(/\s+/g, ' ').trim(), price: amount(text) };
}

test('Европът offers office and address, and no locker', async ({ page }) => {
  // Their API does have lockers - /get-boxes, deliveryType 5 and 6 - but /getcountries answers
  // countryBoxDeliveryAvailable "0" for Bulgaria, so there is nothing here to deliver to. An APS tab
  // would be a promise the courier cannot keep.
  const fields = await start(page);
  await expect(fields.locator('.bgc-tab[data-method="office"]'), 'the office tab').toHaveCount(1);
  await expect(fields.locator('.bgc-tab[data-method="address"]'), 'the address tab').toHaveCount(1);
  await expect(fields.locator('.bgc-tab[data-method="automat"]'),
    'Европът has no lockers in Bulgaria - an APS tab must not be offered').toHaveCount(0);
});

test('to an office - the price is shown and the delivery stays out of the order total', async ({ page }) => {
  const fields = await start(page);
  await selectSpeedyTab(page, fields, 'office');
  await selectCity(page, fields, 'София');
  await pickFirstOffice(page, fields);
  await page.waitForTimeout(2500);

  const { text, price } = await shownPrice(page);
  console.log('[evropat office] rate row:', text);
  expect(price, 'the courier price has to be shown, even when the customer pays it at the door').toBeGreaterThan(0);

  const sub = amount(await page.locator('.cart-subtotal .woocommerce-Price-amount').first().innerText());
  const total = amount(await page.locator('.order-total .woocommerce-Price-amount').first().innerText());
  console.log(`[evropat office] goods ${sub}, delivery shown ${price}, total ${total}`);
  expect(sub, 'the basket has to cost something').toBeGreaterThan(0);
  // "Delivery in the order total" is off for this courier: the customer pays the courier at the door,
  // so the shop must not be collecting the fee and paying it straight out again. `total > sub` would
  // not catch a regression - this shop shows its subtotal net of VAT, so tax alone makes total bigger.
  expect(total, 'the delivery is inside the order total').toBeLessThan(sub + price);
});

test('to an address - the street may only come from their own list', async ({ page }) => {
  const fields = await start(page);
  await selectSpeedyTab(page, fields, 'address');
  await selectCity(page, fields, 'София');

  // The assertion that matters is the absence: Европът refuses a street it was not given an id for, so
  // a free-text box here is a waybill that cannot be printed.
  await expect(fields.locator('.bgc-street-field input[type="text"]:not(.select2-search__field)'),
    'a courier that refuses an unlisted street must not offer a free-text street box').toHaveCount(0);

  await fillStreet(page, fields, 'Витоша');
  await page.waitForTimeout(2500);
  const { text, price } = await shownPrice(page);
  console.log('[evropat address] rate row:', text);
  expect(price, 'the courier price has to be shown').toBeGreaterThan(0);
});

test('the door costs more than the counter - their deliveryType reaches the quote', async ({ page }) => {
  // Measured on the account 2026-08-31: 4.5885 office-to-office against 5.4389 office-to-door for the
  // same 1 kg parcel. One number for both tabs means the field stopped being sent.
  const fields = await start(page);
  await selectSpeedyTab(page, fields, 'office');
  await selectCity(page, fields, 'София');
  await pickFirstOffice(page, fields);
  await page.waitForTimeout(2500);
  const office = (await shownPrice(page)).price;

  await selectSpeedyTab(page, fields, 'address');
  await fillStreet(page, fields, 'Витоша');
  await page.waitForTimeout(2500);
  const address = (await shownPrice(page)).price;

  console.log(`[evropat] office ${office} vs address ${address}`);
  expect(office, 'no office price').toBeGreaterThan(0);
  expect(address, 'delivery to the door has to cost more than to a counter').toBeGreaterThan(office);
});

test('cash on delivery follows whether this account may collect ППП', async ({ page }) => {
  // The shop receipts наложен платеж through the courier's ППП, so a courier that cannot do one has no
  // way to be paid in cash here and the checkout takes the gateway away. Европът activates ППП per
  // account on request - and their API does NOT refuse a ППП it cannot do, it prices it at 0.00 and
  // books the shipment without one. Read the setting rather than assuming either answer.
  const ppp = dev('get', 'bgcouriers_evropat_ppp_payout') === 'yes';
  console.log(`[evropat] ППП payout for this account: ${ppp ? 'on' : 'off'}`);

  const fields = await start(page);
  await selectSpeedyTab(page, fields, 'office');
  await selectCity(page, fields, 'София');
  await pickFirstOffice(page, fields);
  await page.waitForTimeout(3000);

  const cod = page.locator('#payment_method_cod');
  if (ppp) {
    await expect(cod, 'ППП is on for this courier, so cash on delivery must be offered').toHaveCount(1);
  } else {
    await expect(cod, 'without ППП this shop cannot receipt cash for this courier - the gateway must go')
      .toHaveCount(0);
    // And the customer must not be left with nothing: something prepaid has to remain.
    const others = await page.locator('.wc_payment_method input[type="radio"]').count();
    expect(others, 'no payment method at all is left for Европът').toBeGreaterThan(0);
  }
});
