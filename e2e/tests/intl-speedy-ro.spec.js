const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { addAnyProductToCart, gotoCheckout, fillGuestBilling, selectShippingMethod,
        selectSpeedyTab, pickFirstOffice } = require('../helpers/shop');

/**
 * A parcel to ROMANIA, end to end, with a REAL waybill at the end of it.
 *
 * Excluded from the default run - it is the only spec here that deliberately books a shipment at a
 * courier, and it does that because nothing short of it proves the feature. Speedy's domestic service
 * (505) and its international one (202) are mutually exclusive: each is refused outright where the other
 * applies. So a waybill coming back for a Romanian address IS the proof that the plugin booked 202 - a
 * mistake here does not produce a wrong label, it produces no label at all.
 *
 *     BGC_REAL_WAYBILL=1 npx playwright test intl-speedy-ro
 *
 * The waybill is voided again in afterEach, and the number is printed whether the cancel worked or not:
 * a cancel that reported success and did not take looks exactly like one that did, and only the number
 * lets anyone re-check it at the courier. The suite's own teardown sweep is the second net.
 *
 * Dev has to be set up for this first, and none of it is something the plugin can do for itself:
 *   - Speedy settings -> "Also deliver to" includes Romania, and Sync now has been run since
 *   - Romania is in a WooCommerce shipping zone that carries the Speedy method
 *   - at least one purchasable product
 * Each is asserted below with the reason, rather than skipped past: this spec is only ever run on
 * purpose, and a silent skip would answer "does it work?" with "nothing happened".
 *
 * The order is PREPAID, and that is the feature rather than a convenience. Dev's cash-on-delivery is
 * legal only because Speedy does the ППП, and no courier's ППП crosses the border - Speedy refuses the
 * postal money transfer for a foreign address outright and returns no price at all for the shipment. So
 * the plugin takes cash on delivery off the checkout the moment the destination is abroad, and this
 * spec asserts that too. Bank transfer is switched on for the run and off again after, because a
 * payment method left enabled changes what every other spec sees.
 *
 * SKIPPED while international delivery is unfinished and switched off in the plugin: no shop is
 * offered a foreign rate at all, so the country picker this spec drives is never rendered. Turn it
 * back on with add_filter('bgcouriers_intl_enabled', '__return_true') on the site under test and
 * drop the .skip. See docs/international-shipping.md.
 */

const SH = path.join(__dirname, '..', 'dev-option.sh');
const dev = (...args) => execFileSync('bash', [SH, ...args], { encoding: 'utf8' }).trim();

let orderId = '';
let booked = '';

// The one prepaid way to pay this shop has. Enabled for this spec only, and afterwards put back the way
// it was FOUND rather than switched off: a shop whose cash on delivery is legal only through the
// courier's ППП has no way to be paid abroad, so with no prepaid gateway every rate for a foreign
// address is correctly refused - and dev, left that way by an earlier run of this spec, then showed the
// next person who picked a foreign country an empty delivery box.
let prepaidWas = 'no';
test.beforeAll(() => {
  prepaidWas = dev('gateway', 'bacs');
  console.log(`[intl] bank transfer was ${prepaidWas}, on for this spec: ${dev('gateway', 'bacs', 'yes')}`);
});
test.afterAll(() => { console.log(`[intl] bank transfer back to ${dev('gateway', 'bacs', prepaidWas)}`); });

test.afterEach(async () => {
  if (!orderId) { return; }
  const id = orderId;
  orderId = '';
  let out;
  try {
    out = dev('cancel', id);
  } catch (e) {
    // The cancel itself failed to run - ssh refused, timed out, dev unreachable. That leaves a LIVE
    // waybill on a shared live courier account, and the one thing that makes it recoverable is the
    // number, said here rather than only inside a stack trace nobody scrolls to.
    console.log(`[intl] CANCEL FAILED TO RUN for order ${id} - ${booked || 'waybill unknown'} MAY STILL BE LIVE`);
    console.log(`[intl] void it by hand: bash e2e/dev-option.sh cancel ${id}`);
    throw e;
  }
  console.log(`[intl] order ${id}: ${out}`);
  // Printed AND asserted: leaving a live waybill behind on a shared live account is the one outcome
  // this spec must never have.
  expect(out, `the booked waybill must be voided again (order ${id}, ${booked || 'waybill unknown'})`)
    .toMatch(/^(CANCELLED|NOTHING)/);
});

test.skip('speedy guest checkout to ROMANIA books a real waybill @speedy @books-real-waybill', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await selectShippingMethod(page, 'speedy');
  const fields = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(fields).toBeVisible({ timeout: 15000 });

  const country = fields.locator('.bgc-country');
  await expect(country, 'no country picker - switch Romania on in Speedy settings and run Sync now')
    .toBeVisible({ timeout: 10000 });
  await country.selectOption('RO');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);

  // Still on offer after the recalculation: abroad there is no cached price and no flat fallback, so a
  // courier that cannot quote live simply stops being offered. Its row vanishing IS the failure.
  await expect(page.locator('input[name^="shipping_method"][value^="bgcouriers_speedy"]'),
    'Speedy stopped being offered for Romania - no live price, or Romania is not in a shipping zone that carries it')
    .toHaveCount(1);
  await selectSpeedyTab(page, fields, 'office');

  // A named town, not simply the first in the list. The delivery options are offered per town, and the
  // first Romanian one alphabetically (1 DECEMBRIE) has no Speedy office at all - the office tab greys
  // itself out there, which is correct and makes for a spec that fails on nothing. Bucharest has 32.
  await fields.locator('.bgc-city-field .select2-selection').click();
  const citySearch = page.locator('.select2-search__field');
  await expect(citySearch, 'the town field never opened').toBeVisible({ timeout: 10000 });
  await citySearch.fill('BUCURESTI');
  const firstCity = page.locator('.select2-results__option[role="option"]').first();
  await expect(firstCity, 'no Romanian towns cached - run Sync now on dev').toBeVisible({ timeout: 20000 });
  await firstCity.click();
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1500);

  // The office tab has to have SURVIVED the town: a town without one disables it, and clicking a
  // disabled tab leaves the address form up and the office list never appears.
  await expect(fields.locator('.bgc-tab[data-method="office"].active'),
    'the office option is not available in this town').toBeVisible({ timeout: 10000 });

  await expect(fields.locator('.bgc-office-row')).toBeVisible({ timeout: 15000 });
  await pickFirstOffice(page, fields);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);

  const total = await page.locator('.order-total .woocommerce-Price-amount').first().innerText();
  console.log(`[intl] order total with Romanian delivery: ${total}`);

  // Cash on delivery must be gone: it is legal on this shop only through Speedy's ППП, and that stops at
  // the border. Its still being on offer would mean the shop was about to collect money it cannot receipt.
  await expect(page.locator('#payment_method_cod'),
    'cash on delivery is still offered for Romania - the ППП does not travel and this must be hidden')
    .toHaveCount(0);
  // And a prepaid one has to have taken its place. Present and CHOSEN, not visible: WooCommerce hides
  // the radio of a payment method that is the only one on offer and selects it - which is exactly what
  // this shop looks like abroad once cash on delivery is gone, so asserting on visibility would fail on
  // the very state the feature is meant to produce.
  const prepaid = page.locator('#payment_method_bacs');
  await expect(prepaid, 'no prepaid payment method - an international order here has no way to pay')
    .toHaveCount(1);
  await expect(prepaid, 'the prepaid method is on offer but not chosen').toBeChecked();

  // A Romanian mobile. The plugin's checkout rejects it for a Bulgarian delivery and must accept it here.
  await fillGuestBilling(page, { first: 'Andrei', last: 'Popescu', email: 'e2e-ro@example.com', phone: '0722123456' });
  await page.locator('#place_order').click();

  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  orderId = (page.url().match(/order-received\/(\d+)/) || [])[1] || '';
  expect(orderId, 'could not read the order number off the thank-you page').toBeTruthy();

  // What the checkout wrote, before anything is asked of the courier.
  expect(dev('meta', orderId, '_bgcouriers_country'), 'the order must remember it is going to Romania').toBe('RO');
  expect(dev('meta', orderId, '_bgcouriers_courier')).toBe('speedy');

  // And now the courier itself. Auto-labelling is off for the whole run (global-setup), so this is the
  // only waybill the suite books, and it books it deliberately.
  booked = dev('label', orderId);
  console.log(`[intl] ${booked}`);
  expect(booked, 'Speedy refused the Romanian shipment').toMatch(/^WAYBILL \S+ speedy$/);
});
