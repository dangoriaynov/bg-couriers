const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, dismissStoreBanner, selectShippingMethod, selectSpeedyTab, selectCity } = require('../helpers/shop');

/**
 * What the checkout does when a lookup does not come back as the list it stands for.
 *
 * The per-IP limiter refuses a burst, and for a while it did that by answering HTTP 200 with an object
 * carrying a bgc_busy flag. That reads harmlessly and is not: these endpoints do not share a shape. The
 * office and street lookups answer with a LIST, and the browser calls .filter and .map on what comes
 * back, so an object arriving in a list's place does not leave the dropdown empty - it throws and takes
 * the field out of the page. Worse, the office list is cached BEFORE it is touched, and cached into
 * sessionStorage, so one such answer broke that town for the rest of the visit and across a reload,
 * long after the shop was perfectly willing to answer for it.
 *
 * Two answers are driven through the same customer path, because they fail differently:
 *
 *  - HTTP 429, which is what the limiter sends now. jQuery routes a non-2xx away from every success
 *    handler here, so the correct behaviour is that nothing happens at all.
 *  - HTTP 200 with something that is not a list. That is the answer that was shipped to dev, and it is
 *    also what admin-ajax sends of its own accord - a bare 0 - when the action is gone from under an
 *    open page, which a plugin update does. No status code saves this one; only the browser refusing to
 *    believe it.
 *
 * Both then require the same two things: nothing thrown, and nothing kept - proved by letting the shop
 * answer properly afterwards and requiring the very same field to fill.
 *
 * Places no order and books no waybill.
 */

/** Drive the office and street fields while every lookup is answered by `reply`, then let it recover. */
async function driveCheckout(page, reply) {
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));

  let intercepting = true;
  const seen = { offices: 0, streets: 0 };
  await page.route(/admin-ajax\.php.*action=bgcouriers_(offices|streets)/, async (route) => {
    if (!intercepting) { return route.fallback(); }
    seen[/action=bgcouriers_offices/.test(route.request().url()) ? 'offices' : 'streets']++;
    await route.fulfill(reply);
  });

  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await dismissStoreBanner(page);
  await selectShippingMethod(page, 'speedy');
  const fields = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(fields).toBeVisible({ timeout: 15000 });

  // The town list is not rate-limited, so a town can still be chosen while the offices are not coming
  // back - which is the state a customer would actually be in.
  await selectCity(page, fields, 'София');
  await page.waitForTimeout(1200);

  // The office dropdown, opened and typed into. Typing is the point: the filter runs on what came back.
  await selectSpeedyTab(page, fields, 'office');
  await fields.locator('.bgc-office-row .select2-selection').click();
  const search = page.locator('.select2-search__field');
  await expect(search).toBeVisible({ timeout: 10000 });
  await search.fill('младост');
  await page.waitForTimeout(1500);
  await page.keyboard.press('Escape');

  // And the street field on the address tab, where the same answer is handed to .map.
  await selectSpeedyTab(page, fields, 'address');
  await fields.locator('.bgc-street-field .select2-selection').click();
  const street = page.locator('.select2-search__field');
  await expect(street).toBeVisible({ timeout: 10000 });
  await street.fill('витоша');
  await page.waitForTimeout(1500);
  await page.keyboard.press('Escape');

  expect(seen.offices, 'the office lookup was actually intercepted').toBeGreaterThan(0);
  expect(seen.streets, 'the street lookup was actually intercepted').toBeGreaterThan(0);
  expect(errors, 'an answer that is not a list must not throw in the page').toEqual([]);

  // Nothing may have been kept. The office lists live in sessionStorage, so anything wrong written
  // there outlives the page, and the town stays broken across a reload too.
  const kept = await page.evaluate(() => {
    const bad = [];
    for (let i = 0; i < sessionStorage.length; i++) {
      const k = sessionStorage.key(i);
      if (k.indexOf('bgcouriers_off:') !== 0) { continue; }
      let v = null;
      try { v = JSON.parse(sessionStorage.getItem(k)); } catch (e) { bad.push(k + ' (not JSON)'); continue; }
      if (!Array.isArray(v)) { bad.push(k + ' -> ' + JSON.stringify(v)); }
    }
    return bad;
  });
  expect(kept, 'it must not be kept as this town own office list').toEqual([]);

  // Now let the shop answer, and the very same field must fill - which it cannot do if what came back
  // before was remembered.
  intercepting = false;
  await selectSpeedyTab(page, fields, 'office');
  await fields.locator('.bgc-office-row .select2-selection').click();
  const opt = page.locator('.select2-results__option[role="option"]').first();
  await expect(opt, 'the offices arrive once the shop answers properly').toBeVisible({ timeout: 25000 });
  await expect(opt).not.toHaveText(/searching|няма|no result/i);
  await page.keyboard.press('Escape');

  expect(errors, 'and still nothing threw').toEqual([]);
}

test.describe('a lookup that is not a list @busy', () => {
  test('a refused lookup breaks nothing and is not remembered', async ({ page }) => {
    await driveCheckout(page, { status: 429, contentType: 'application/json',
                                body: JSON.stringify({ success: false, data: { bgc_busy: true } }) });
  });

  test('nor does one that comes back 200 carrying something that is not a list', async ({ page }) => {
    // Exactly the body the limiter used to send, at the status it used to send it.
    await driveCheckout(page, { status: 200, contentType: 'application/json',
                                body: JSON.stringify({ bgc_busy: true }) });
  });

  test('nor admin-ajax answering 0 because the action went away under an open page', async ({ page }) => {
    await driveCheckout(page, { status: 200, contentType: 'text/html', body: '0' });
  });

  /**
   * The town dropdowns read the same way, and they are not rate-limited - so the answer that reaches
   * them is never a refusal, only admin-ajax's 0. They are covered here because the guard that stops
   * them dying on it is the same one, and a guard applied to some of the fields and not the rest is
   * the state this whole change is undoing.
   *
   * On the ADDRESS tab deliberately. For an office or a locker the town list is served from a list
   * preloaded into the page, and no request is made at all - the first version of this test typed on
   * the default tab, intercepted nothing, and proved nothing. Address is the tab where the town is
   * genuinely looked up.
   */
  test('a town lookup that is not a list leaves the field alive', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    let intercepting = true;
    let seen = 0;
    await page.route(/admin-ajax\.php.*action=bgcouriers_search_cities/, async (route) => {
      if (!intercepting) { return route.fallback(); }
      seen++;
      await route.fulfill({ status: 200, contentType: 'text/html', body: '0' });
    });

    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await dismissStoreBanner(page);
    await selectShippingMethod(page, 'speedy');
    const fields = page.locator('.bgc-fields[data-courier="speedy"]');
    await expect(fields).toBeVisible({ timeout: 15000 });

    await selectSpeedyTab(page, fields, 'address');
    await fields.locator('.bgc-city-field .select2-selection').click();
    const search = page.locator('.select2-search__field');
    await expect(search).toBeVisible({ timeout: 10000 });
    await search.fill('соф');
    await page.waitForTimeout(1500);
    await page.keyboard.press('Escape');

    expect(seen, 'the town lookup was actually intercepted').toBeGreaterThan(0);
    expect(errors, 'a town lookup that is not a list must not throw').toEqual([]);

    // And the field is not merely un-thrown, it still works.
    intercepting = false;
    await selectCity(page, fields, 'София');
    await expect(fields.locator('.bgc-city-field .select2-selection')).toContainText(/СОФИЯ/i, { timeout: 15000 });
    expect(errors, 'and still nothing threw').toEqual([]);
  });
});
