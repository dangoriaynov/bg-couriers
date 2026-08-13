const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout } = require('../helpers/shop');

/**
 * The combined map is the one place a customer can compare pickup points ACROSS couriers. What matters
 * is not that a map appears - it is that the dialog refuses to plot anything until it knows where, that
 * it really does carry more than one courier, and that choosing a point leaves the checkout in exactly
 * the state a manual courier + city + office selection would.
 *
 * Every one of them drives the dialog by clicking, including the city. An earlier version set the
 * city's value in JavaScript because the picker was awkward to drive, and that shortcut walked
 * straight past a defect the first person to open the dialog hit in one click.
 */

/**
 * Pick a city the way a customer does: type, then click a suggestion.
 *
 * Every test here does it this way. An earlier version set the select's value in JavaScript because
 * select2 was awkward to drive - and that shortcut walked straight past a bug the first person to open
 * the dialog hit in one click. The suggest is now the plugin's own, so there is nothing left to work
 * around: anything a customer must click, these click.
 */
async function chooseCity(page, term, label) {
  await page.locator('.bgc-allmap-cityinput').fill(term);
  const option = page.locator('.bgc-allmap-cityopt', { hasText: label }).first();
  await option.waitFor({ state: 'visible', timeout: 15000 });
  // Clicking IS the assertion: a list painted under or outside the dialog still reports itself
  // visible, but it cannot be clicked.
  await option.click({ timeout: 10000 });
  return page.locator('.bgc-allmap-cityinput').inputValue();
}

test('combined map: nothing is plotted until a place is chosen @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);

  await page.locator('.bgc-allmap-btn').click();
  const dlg = page.locator('.bgc-allmap-overlay');
  await expect(dlg).toBeVisible();

  // This is the rule the owner asked for: no city, no map.
  await expect(dlg.locator('.bgc-allmap-show')).toBeDisabled();
  await expect(dlg.locator('.bgc-allmap-body')).toBeHidden();

  const label = await chooseCity(page, 'София', 'СОФИЯ (1000)');
  expect(label).toContain('СОФИЯ');
  await expect(dlg.locator('.bgc-allmap-show')).toBeEnabled();
});

test('combined map: it carries several couriers at once, each with its own price @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);
  await page.locator('.bgc-allmap-btn').click();
  await chooseCity(page, 'София', 'СОФИЯ (1000)');
  await page.locator('.bgc-allmap-show').click();

  await expect(page.locator('.bgc-allmap-item').first()).toBeVisible({ timeout: 20000 });

  // More than one courier is the entire point - a map showing one courier's offices already exists.
  const couriers = await page.evaluate(() =>
    Array.from(new Set(window.BGCouriersAllMap.points().map(p => p.courier))));
  expect(couriers.length).toBeGreaterThan(1);

  // Every point that can be chosen shows what that courier charges.
  const priced = await page.locator('.bgc-allmap-item:not(.bgc-na) .p').count();
  const choosable = await page.locator('.bgc-allmap-item:not(.bgc-na)').count();
  expect(choosable).toBeGreaterThan(0);
  expect(priced).toBe(choosable);

  // The legend names every courier on the map and starts with all of them showing - a map that
  // opened already filtered would be hiding part of the choice it exists to present.
  const chips = page.locator('.bgc-allmap-chip');
  expect(await chips.count()).toBe(couriers.length);
  expect(await page.locator('.bgc-allmap-chip.on').count()).toBe(couriers.length);

  // And it filters both halves at once: switching a courier off must empty its rows AND its pins,
  // or the map and the list beside it would disagree about what is on offer.
  const rowsBefore = await page.locator('.bgc-allmap-item:visible').count();
  const pinsBefore = await page.locator('.leaflet-marker-icon').count();
  await chips.first().click();
  await page.waitForTimeout(600);
  expect(await page.locator('.bgc-allmap-item:visible').count()).toBeLessThan(rowsBefore);
  expect(await page.locator('.leaflet-marker-icon').count()).toBeLessThan(pinsBefore);

  // Each chip carries its courier's own logo as well as its colour: the colour identifies a pin,
  // the logo is what the customer actually recognises.
  expect(await page.locator('.bgc-allmap-chip img').count()).toBe(couriers.length);
});

test('combined map: searching narrows the list and the map together @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);
  await page.locator('.bgc-allmap-btn').click();
  await chooseCity(page, 'Пловдив', 'ПЛОВДИВ (4000)');
  await page.locator('.bgc-allmap-show').click();
  await expect(page.locator('.bgc-allmap-item').first()).toBeVisible({ timeout: 20000 });

  const rows0 = await page.locator('.bgc-allmap-item:visible').count();
  await page.locator('.bgc-allmap-search').fill('тракия');
  await page.waitForTimeout(700);
  const rows1 = await page.locator('.bgc-allmap-item:visible').count();
  const pins1 = await page.locator('.leaflet-marker-icon').count();
  expect(rows1).toBeGreaterThan(0);
  expect(rows1).toBeLessThan(rows0);
  // The two halves must agree: a row the map does not show, or a pin the list does not have, tells
  // the customer two different things about what is on offer.
  expect(pins1).toBe(rows1);
});

test('combined map: choosing a point sets the courier, the delivery type and the office @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);
  const before = await page.locator('input[name^="shipping_method"]:checked').inputValue();

  await page.locator('.bgc-allmap-btn').click();
  await chooseCity(page, 'София', 'СОФИЯ (1000)');
  await page.locator('.bgc-allmap-show').click();
  await expect(page.locator('.bgc-allmap-item').first()).toBeVisible({ timeout: 20000 });

  // Take a point belonging to a courier that is NOT the one already selected, so the assertion below
  // proves the dialog switched the shipping method rather than finding it already right.
  const pick = await page.evaluate((cur) => {
    const bare = v => String(v || '').replace('bgcouriers_', '').split(':')[0];
    const pts = window.BGCouriersAllMap.points();
    const i = pts.findIndex(p => p.available && p.courier !== bare(cur));
    if (i < 0) { return null; }
    document.querySelectorAll('.bgc-allmap-item')[i].click();   // focus it, opening its popup
    return { i, courier: pts[i].courier, officeId: pts[i].office.office_id, type: pts[i].type,
             cityId: pts[i].cityId };
  }, before);
  expect(pick, 'the map must offer a courier other than the one already chosen').not.toBeNull();

  await page.locator('.bgc-allmap-pick').first().click();
  await expect(page.locator('.bgc-allmap-overlay')).toHaveCount(0);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(5000);

  const after = await page.locator('input[name^="shipping_method"]:checked').inputValue();
  expect(after).toContain('bgcouriers_' + pick.courier);

  const fields = page.locator(`.bgc-fields[data-courier="${pick.courier}"]`);
  await expect(fields).toBeVisible();
  // The delivery type comes from the POINT - office and locker share one map now.
  expect(await fields.getAttribute('data-method')).toBe(pick.type);
  expect(String(await fields.locator('.bgc-office').inputValue())).toBe(String(pick.officeId));
  // The CITY is asserted because leaving it out is how a real defect reached the owner: the office
  // landed, the city came back empty, and an order cannot be placed from an office with no town.
  expect(String(await fields.locator('.bgc-city').inputValue())).toBe(String(pick.cityId));
});

/** The dialog is meant to be easier the second time: it remembers the place you were looking at. */
test('combined map: the place and destination survive a reload @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);
  await page.locator('.bgc-allmap-btn').click();
  await chooseCity(page, 'София', 'СОФИЯ (1000)');
  await page.locator('.bgc-allmap-close').click();

  await page.reload();
  await page.waitForTimeout(2500);
  await page.locator('.bgc-allmap-btn').click();
  const dlg = page.locator('.bgc-allmap-overlay');
  await expect(dlg.locator('.bgc-allmap-show')).toBeEnabled();
});
