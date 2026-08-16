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

  // This is the rule the owner asked for: no city, no map. There is no button to press either -
  // choosing a place IS the instruction to show it.
  await expect(dlg.locator('.bgc-allmap-body')).toBeHidden();
  expect(await dlg.locator('.bgc-allmap-show').count()).toBe(0);

  const label = await chooseCity(page, 'София', 'СОФИЯ (1000)');
  expect(label).toContain('СОФИЯ');
  // The map arrives on its own, and says it is working while it does.
  await expect(page.locator('.bgc-allmap-item').first()).toBeVisible({ timeout: 20000 });
});

/**
 * The city box must not wait on the server.
 *
 * Every admin-ajax request costs a full WordPress boot: measured on the live shop at ~5s, and the SAME
 * ~5s for an action with no handler at all - so it is the boot, not the lookup. The courier's own city
 * field has always felt instant because it filters an index the page already carries; this one asked
 * the server on every keystroke and looked broken for five seconds at a time.
 *
 * The assertion is therefore about requests, not milliseconds: a timing threshold would be flaky on a
 * loaded shop, while "it did not ask" is exactly the property that makes it fast.
 */
test('combined map: the city list comes from the page, not the server @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);

  const asked = [];
  page.on('request', r => {
    if (r.url().includes('action=bgcouriers_allmap_cities')
        || (r.postData() || '').includes('bgcouriers_allmap_cities')) { asked.push(r.url()); }
  });

  await page.locator('.bgc-allmap-btn').click();
  await page.locator('.bgc-allmap-cityinput').fill('София');
  // No waitFor on a network response - the whole point is that none is coming. The timeout here is
  // generous on purpose: what proves the speed is the request count asserted below, not how quickly a
  // loaded dev site can paint. Making this tight only bought a flaky test.
  const option = page.locator('.bgc-allmap-cityopt', { hasText: 'СОФИЯ' }).first();
  await option.waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForTimeout(800);   // long enough for a debounced request to have gone out

  expect(asked, `the city box called the server: ${asked[0] || ''}`).toHaveLength(0);

  // And the suggestions it produced locally still work end to end: picking one plots that city.
  await option.click({ timeout: 10000 });
  await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
  expect(await page.locator('.bgc-allmap-cityinput').inputValue()).toContain('СОФИЯ');
});

test('combined map: it carries several couriers at once, each with its own price @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);
  await page.locator('.bgc-allmap-btn').click();
  await chooseCity(page, 'София', 'СОФИЯ (1000)');

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
  // :visible on the pins too. A pin switched off is hidden by style and STAYS in the layer - taking a
  // few hundred markers out of Leaflet and putting them back is what made a legend click block for a
  // second and a half - so counting the elements no longer counts what is on the map.
  const rowsBefore = await page.locator('.bgc-allmap-item:visible').count();
  const pinsBefore = await page.locator('.leaflet-marker-icon:visible').count();
  await chips.first().click();
  await page.waitForTimeout(600);
  expect(await page.locator('.bgc-allmap-item:visible').count()).toBeLessThan(rowsBefore);
  expect(await page.locator('.leaflet-marker-icon:visible').count()).toBeLessThan(pinsBefore);

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
  await expect(page.locator('.bgc-allmap-item').first()).toBeVisible({ timeout: 20000 });

  const rows0 = await page.locator('.bgc-allmap-item:visible').count();
  await page.locator('.bgc-allmap-search').fill('тракия');
  await page.waitForTimeout(700);
  const rows1 = await page.locator('.bgc-allmap-item:visible').count();
  const pins1 = await page.locator('.leaflet-marker-icon:visible').count();
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
  await expect(page.locator('.bgc-allmap-item').first()).toBeVisible({ timeout: 20000 });
  await page.locator('.bgc-allmap-close').click();

  await page.reload();
  await page.waitForTimeout(2500);
  await page.locator('.bgc-allmap-btn').click();
  // The remembered place opens straight onto its map - no city to retype, no button to press.
  await expect(page.locator('.bgc-allmap-item').first()).toBeVisible({ timeout: 20000 });
  expect(await page.locator('.bgc-allmap-cityinput').inputValue()).toContain('СОФИЯ');
});

/**
 * A LOCKER specifically, and from a courier that does not offer offices at all.
 *
 * The general test above takes whatever point comes first, which is usually an office - and this case
 * broke without it noticing: choosing a Sameday locker landed the customer on Sameday's "to address"
 * tab with an empty street form, the locker saved but invisible. One of the recalculations rendered
 * the block with no delivery type at all, and the tabs fell back to the courier's first one.
 */
test('combined map: choosing a locker lands on the locker tab, not the address form @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);

  await page.locator('.bgc-allmap-btn').click();
  await chooseCity(page, 'София', 'СОФИЯ (1000)');
  await expect(page.locator('.bgc-allmap-item').first()).toBeVisible({ timeout: 20000 });

  const pick = await page.evaluate(() => {
    const pts = window.BGCouriersAllMap.points();
    const i = pts.findIndex(p => p.available && p.type === 'automat');
    if (i < 0) { return null; }
    document.querySelectorAll('.bgc-allmap-item')[i].click();
    return { courier: pts[i].courier, officeId: pts[i].office.office_id, cityId: pts[i].cityId };
  });
  expect(pick, 'the map must offer at least one locker').not.toBeNull();

  await page.locator('.bgc-allmap-pick').first().click();
  await expect(page.locator('.bgc-allmap-overlay')).toHaveCount(0);
  await page.waitForTimeout(9000);   // long enough for every recalculation this sets off to land

  const fields = page.locator(`.bgc-fields[data-courier="${pick.courier}"]`);
  expect(await fields.getAttribute('data-method')).toBe('automat');
  expect(String(await fields.locator('.bgc-office').inputValue())).toBe(String(pick.officeId));
  expect(String(await fields.locator('.bgc-city').inputValue())).toBe(String(pick.cityId));
  // The address form belongs to the other tab and must not be the one on screen.
  await expect(fields.locator('.bgc-address-rows')).toBeHidden();
});

/**
 * The same dialog on a phone.
 *
 * These exist because the desktop tests above CANNOT catch what went wrong here. They run at
 * 1280x720 and passed the entire time the map on a phone was a strip a few dozen pixels tall - the
 * two panes stacked, each holding a 440px floor, inside a body clipped at 72vh. Every element the
 * desktop tests look for was present and reported visible; there was simply no room to see any of it.
 *
 * So the assertion here is a MEASUREMENT, not a visibility check: how tall is the map actually, in
 * pixels, on a 390x844 screen. That is the number the customer complained about.
 */
test.describe('the combined map on a phone', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  /**
   * The answer, on a phone.
   *
   * It sits in the header under the courier chips rather than in a band of its own, and the whole strip
   * is what you press - which is why it has to be measured, not just found: split into a label and a
   * small button, the thing a finger has to hit would be a 19px line. Two short lines plus its padding
   * are what make it a real target.
   */
  test('combined map: the answer is a proper tap target on a phone @allmap', async ({ page, context }) => {
    await context.grantPermissions(['geolocation']);
    await context.setGeolocation({ latitude: 42.6795, longitude: 23.3242 });
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);
    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1500);
    await page.locator('.bgc-allmap-side .bgc-map-locate').click();
    await page.waitForTimeout(4000);

    const near = page.locator('.bgc-allmap-near');
    await expect(near).toBeVisible();
    const box = await near.boundingBox();
    expect(box.height, `the strip is ${Math.round(box.height)}px tall`).toBeGreaterThanOrEqual(44);

    // In the header, above the map - not floating over it, and not in a band of its own below it.
    const form = await page.locator('.bgc-allmap-form').boundingBox();
    const legend = await page.locator('.bgc-allmap-legend').boundingBox();
    const body = await page.locator('.bgc-allmap-body').boundingBox();
    expect(box.y, 'under the courier chips').toBeGreaterThanOrEqual(legend.y + legend.height - 1);
    expect(box.y + box.height, 'inside the form').toBeLessThanOrEqual(form.y + form.height + 1);
    expect(box.y + box.height, 'above the map').toBeLessThanOrEqual(body.y + 1);

    // And pressing it does the same thing it does on a desktop: the map, on that point, open.
    const closest = (await page.locator('.bgc-allmap-item').first().locator('.n').textContent()).trim();
    await near.click();
    await page.waitForTimeout(1500);
    await expect(page.locator('.leaflet-popup .bgc-allmap-pop-n')).toBeVisible();
    expect((await page.locator('.leaflet-popup .bgc-allmap-pop-n').textContent()).trim())
      .toBe(closest.replace(/^[^\p{L}\d]+/u, '').trim());
  });

  test('combined map: the map gets real height on a phone @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);

    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1200);

    // The measurement. The old layout laid the canvas out at 300px and then clipped it - the list
    // above it claimed 440 of the 607 the body was allowed, and what was left on screen was about
    // 167px of map. Half the screen is the bar it has to clear now.
    const canvas = await page.locator('.bgc-allmap-canvas').boundingBox();
    expect(canvas, 'the map canvas must be laid out').not.toBeNull();
    expect(canvas.height).toBeGreaterThan(340);
    expect(canvas.width).toBeGreaterThan(350);

    // ...and it must all be ON the screen. The bounding box is the box the layout ASKED for; the body
    // has overflow:hidden, so a canvas hanging out of the bottom of it is exactly the sliver the
    // customer reported while every element on the page still reported itself visible.
    const body = await page.locator('.bgc-allmap-body').boundingBox();
    expect(Math.round(canvas.y + canvas.height)).toBeLessThanOrEqual(Math.round(body.y + body.height) + 1);
    expect(Math.round(body.y + body.height)).toBeLessThanOrEqual(845);

    // Tiles, not a grey rectangle: Leaflet caches the size of its container, and a map built or
    // fitted while that container was hidden comes back empty however tall the box now is.
    expect(await page.locator('.leaflet-tile-loaded').count()).toBeGreaterThan(0);

    // Edge to edge, and nothing hanging off the side of the screen.
    const box = await page.locator('.bgc-allmap-box').boundingBox();
    expect(Math.round(box.width)).toBe(390);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  });

  test('combined map: the pill swaps the map for the list and back @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);
    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1200);

    // The pill only exists where there is a choice to make - a desktop shows both panes at once.
    const pill = page.locator('.bgc-allmap-switch');
    await expect(pill).toBeVisible();
    // It says how many offices are behind it, which is the only way to tell that a search found
    // anything while the list itself is hidden under the map.
    await expect(page.locator('.bgc-allmap-n')).toHaveText(/\(\d+\)/);

    await expect(page.locator('.bgc-allmap-canvas')).toBeVisible();
    await pill.locator('button[data-v="list"]').click();
    await expect(page.locator('.bgc-allmap-canvas')).toBeHidden();
    const list = await page.locator('.bgc-allmap-list').boundingBox();
    expect(list.height).toBeGreaterThan(300);

    // Choosing a row means "show me where that is", so it has to bring the map back with it - and the
    // map must be able to draw: this is the path where Leaflet was last measured at zero.
    await page.locator('.bgc-allmap-item:not(.bgc-na)').first().click();
    await expect(page.locator('.bgc-allmap-canvas')).toBeVisible();
    await expect(page.locator('.leaflet-popup')).toBeVisible();
    const canvas = await page.locator('.bgc-allmap-canvas').boundingBox();
    expect(canvas.height).toBeGreaterThan(340);
    expect(await page.locator('.leaflet-tile-loaded').count()).toBeGreaterThan(0);
  });

  /**
   * Choosing a point from a phone, all the way into the checkout.
   *
   * It taps the LOWEST PIN on the map, which is the case that actually broke, and it must be a pin
   * rather than a list row: a row calls setView() and re-centres the map, which parks the popup
   * comfortably in the middle and hides the defect completely. Measured before the fix, tapping the
   * bottom pin put the Choose button 49px underneath the pill.
   *
   * The click on Choose is then deliberately NOT forced. A forced click sails straight through an
   * element painted over another; an ordinary one fails, which is the only reason this test can tell
   * the two situations apart.
   */
  test('combined map: a point can be chosen on a phone, pill and all @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);

    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1500);

    // The bottom-most pin currently on screen - the one whose popup has to be panned clear of the pill.
    await page.evaluate(() => {
      const pins = Array.from(document.querySelectorAll('.leaflet-marker-icon'))
        .map(el => ({ el, y: el.getBoundingClientRect().bottom }))
        .sort((a, b) => b.y - a.y);
      pins[0].el.click();
    });
    await expect(page.locator('.bgc-allmap-pick')).toBeVisible({ timeout: 10000 });
    // The popup appears BEFORE autoPan has finished sliding the map clear of it - measuring here
    // measures the map mid-animation, which reads as obscured whether or not it ends up so.
    await page.waitForTimeout(1200);

    // Clear of the pill, with room to spare - asserted in pixels, because "visible" is exactly what
    // an obscured button reports.
    const clearance = await page.evaluate(() => {
      const b = document.querySelector('.bgc-allmap-pick').getBoundingClientRect();
      const p = document.querySelector('.bgc-allmap-switch').getBoundingClientRect();
      return Math.round(p.top - b.bottom);
    });
    expect(clearance).toBeGreaterThan(0);

    const pick = await page.evaluate(() =>
      window.BGCouriersAllMap.points()[+document.querySelector('.bgc-allmap-pick').dataset.i]);
    expect(pick).toBeTruthy();

    // No force: Playwright refuses an element another one is painted over.
    await page.locator('.bgc-allmap-pick').first().click({ timeout: 10000 });
    await expect(page.locator('.bgc-allmap-overlay')).toHaveCount(0);
    await page.waitForTimeout(9000);

    const fields = page.locator(`.bgc-fields[data-courier="${pick.courier}"]`);
    await expect(fields).toBeVisible();
    expect(await fields.getAttribute('data-method')).toBe(pick.type);
    expect(String(await fields.locator('.bgc-office').inputValue())).toBe(String(pick.office.office_id));
    expect(String(await fields.locator('.bgc-city').inputValue())).toBe(String(pick.cityId));
  });

  /**
   * The zoom buttons must not be printed over what the popup says.
   *
   * Leaflet stacks its controls at z-index 800 and its popups at 700, so a popup opening near the
   * top-left corner had the + and - drawn across its first two lines - the courier's name and the
   * office name, which are the two things the popup exists to tell you.
   */
  test('combined map: the zoom buttons never sit on top of a popup @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);
    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1500);

    // The pin nearest the top-left corner - the one whose popup lands on the zoom control.
    await page.evaluate(() => {
      const c = document.querySelector('.bgc-allmap-canvas').getBoundingClientRect();
      Array.from(document.querySelectorAll('.leaflet-marker-icon'))
        .map(el => { const r = el.getBoundingClientRect();
                     return { el, d: (r.left - c.left) ** 2 + (r.top - c.top) ** 2 }; })
        .sort((a, b) => a.d - b.d)[0].el.click();
    });
    await expect(page.locator('.leaflet-popup')).toBeVisible({ timeout: 10000 });
    await page.waitForTimeout(1200);   // let autoPan settle before measuring anything

    const verdict = await page.evaluate(() => {
      const pop = document.querySelector('.leaflet-popup-content').getBoundingClientRect();
      const zoom = document.querySelector('.leaflet-control-zoom').getBoundingClientRect();
      const ox = Math.min(pop.right, zoom.right) - Math.max(pop.left, zoom.left);
      const oy = Math.min(pop.bottom, zoom.bottom) - Math.max(pop.top, zoom.top);
      if (ox <= 0 || oy <= 0) { return { overlap: false, popOnTop: true }; }
      // They do overlap - then the popup has to be the thing under the finger, or its text is
      // unreadable and its button unpressable.
      const el = document.elementFromPoint(
        Math.max(pop.left, zoom.left) + ox / 2, Math.max(pop.top, zoom.top) + oy / 2);
      return { overlap: true, popOnTop: !!(el && el.closest('.leaflet-popup')),
               on: el ? el.className.toString().slice(0, 40) : null };
    });
    expect(verdict.popOnTop, `zoom control painted over the popup (hit ${verdict.on})`).toBe(true);
  });

  /**
   * A chat bubble, cookie bar or back-to-top button must not be painted over a full-screen map.
   *
   * The shop's own chat widget does exactly that, and it does not load under Playwright at all - no
   * iframe, no high-z element - so it cannot be driven here. What CAN be tested is the rule that
   * decides the outcome: an element with an enormous z-index, added the way those widgets add
   * theirs, must still end up behind this dialog.
   */
  test('combined map: nothing on the page can be painted over the dialog @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);

    // Stand-in for the chat bubble: bottom-right, fixed, and a z-index far above the old 100000.
    await page.evaluate(() => {
      const w = document.createElement('div');
      w.id = 'probe-widget';
      w.style.cssText = 'position:fixed;right:16px;bottom:16px;width:64px;height:64px;'
        + 'border-radius:50%;background:#0a0;z-index:2147483000';
      document.body.appendChild(w);
    });

    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1200);

    // Whatever is painted at the widget's own centre must belong to the dialog, not to the widget.
    const covered = await page.evaluate(() => {
      const r = document.querySelector('#probe-widget').getBoundingClientRect();
      const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return { inDialog: !!(el && el.closest('.bgc-allmap-overlay')),
               hit: el ? (el.id || el.className.toString().slice(0, 40)) : null };
    });
    expect(covered.inDialog, `the dialog was painted under the widget (hit ${covered.hit})`).toBe(true);
  });
});

/**
 * The nearest-office answer.
 *
 * The point of this feature is not a distance, it is a DECISION: is walking to an office worth what the
 * courier charges to bring the parcel to the door. So the test asserts the whole answer - a distance, a
 * price, the address price beside it - and the property that makes it trustworthy: what it recommends
 * must be something the customer can actually order. Switching the winning courier off in the legend has
 * to move the recommendation, not leave a dead one behind.
 *
 * Distances are computed in the browser over points already loaded, so nothing here waits on a request.
 */
test.describe('nearest office', () => {
  test.use({ geolocation: { latitude: 42.6795, longitude: 23.3242 }, permissions: ['geolocation'] });

  const metres = (t) => {
    const m = (t || '').match(/([\d,]+)\s*(м|км|m|km)/);
    if (!m) { return null; }
    const v = parseFloat(m[1].replace(',', '.'));
    return /к/.test(m[2]) || m[2] === 'km' ? v * 1000 : v;
  };

  test('combined map: it says which office is closest and what that saves @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);
    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1500);

    // The switch travels as a STRING, and the difference matters: wp_localize_script() casts a PHP
    // false to '', which is what an absent key also looks like - and this setting defaults to ON, so
    // a falsy test would have switched the feature off for everybody the moment it was sent as a
    // boolean. It was, and it did.
    expect(await page.evaluate(() => window.BGCOURIERS && BGCOURIERS.allmapNearest)).toBe('yes');

    // Nothing claimed before we know where the customer is - a distance from nowhere is a lie.
    expect(await page.locator('.bgc-allmap-near').count()).toBe(0);
    expect(await page.locator('.bgc-allmap-dist').count()).toBe(0);

    await page.locator('.bgc-allmap-side .bgc-map-locate').click();
    await page.waitForTimeout(4000);

    const near = page.locator('.bgc-allmap-near');
    await expect(near).toBeVisible();
    const text = (await near.textContent()).replace(/\s+/g, ' ');
    expect(metres(text), `the answer must carry a distance: ${text}`).not.toBeNull();

    // Rows carry their own distance and the list is ordered by it.
    const order = await page.evaluate(() => Array.from(document.querySelectorAll('.bgc-allmap-item'))
      .map(r => r.querySelector('.bgc-allmap-dist')?.textContent || null).filter(Boolean));
    expect(order.length).toBeGreaterThan(50);
    const nums = order.map(t => { const m = t.match(/([\d,]+)\s*(м|км)/);
      const v = parseFloat(m[1].replace(',', '.')); return m[2] === 'км' ? v * 1000 : v; });
    for (let i = 1; i < nums.length; i++) {
      expect(nums[i], `row ${i} is closer than the one before it`).toBeGreaterThanOrEqual(nums[i - 1] - 1);
    }

    // Every courier's own nearest, on its chip - that is what makes the couriers comparable.
    const chips = await page.locator('.bgc-allmap-chip').allTextContents();
    expect(chips.filter(c => metres(c) !== null).length).toBe(chips.length);

    // The recommendation must follow what is actually orderable.
    const first = (await near.textContent()).replace(/\s+/g, ' ');
    const winner = chips.map(c => c.replace(/[\d,]+\s*(м|км)$/, '').trim())
      .find(name => name && first.includes(name));
    expect(winner, 'the answer names one of the couriers on the map').toBeTruthy();
    await page.locator('.bgc-allmap-chip', { hasText: winner }).first().click();
    await page.waitForTimeout(1200);
    const second = (await near.textContent()).replace(/\s+/g, ' ');
    expect(second, 'switching the winner off must hand the answer to somebody else').not.toContain(winner);
    expect(metres(second)).not.toBeNull();
  });

  /**
   * "Show my location" after the town was changed.
   *
   * It silently did nothing: clearing the town destroys the map, but the reference to the customer's own
   * pin survived it, so the next locate took the "we already have one" branch and moved a marker that
   * belonged to a map no longer on the page. Nothing appeared and nothing complained.
   *
   * The test walks the exact path - locate, clear the town, choose another - and also pins the better
   * behaviour that came with the fix: the position is not forgotten just because the customer looked at
   * a different town, so the distances come back on their own.
   */
  test('combined map: the location pin survives changing the town @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);
    await page.evaluate(() => localStorage.removeItem('bgcouriers_map_pick'));
    await page.locator('.bgc-allmap-btn').click();

    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1500);
    await page.locator('.bgc-allmap-side .bgc-map-locate').click();
    await page.waitForTimeout(3500);
    await expect(page.locator('.bgc-allmap-me')).toHaveCount(1);
    expect(await page.locator('.bgc-allmap-dist').count()).toBeGreaterThan(50);

    // Clearing the town takes the map, the pin and the answer with it - none of them describe anything
    // any more.
    await page.locator('.bgc-allmap-cityclear').click();
    await page.waitForTimeout(900);
    await expect(page.locator('.bgc-allmap-me')).toHaveCount(0);
    await expect(page.locator('.bgc-allmap-near')).toHaveCount(0);

    await chooseCity(page, 'Пловдив', 'ПЛОВДИВ (4000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(2500);

    // The customer has not moved, so the pin and the distances are simply there again.
    await expect(page.locator('.bgc-allmap-me')).toHaveCount(1);
    await expect(page.locator('.bgc-allmap-near')).toBeVisible();
    expect(await page.locator('.bgc-allmap-dist').count()).toBeGreaterThan(20);

    // And pressing it again in the new town still works, which is what was broken.
    await page.locator('.bgc-allmap-side .bgc-map-locate').click();
    await page.waitForTimeout(3500);
    await expect(page.locator('.bgc-allmap-me')).toHaveCount(1);
  });

  /**
   * Switching a courier off must answer on the click.
   *
   * Reported as the legend "getting stuck", and it was: the handler re-measured every point, looked each
   * row up by selector in the whole list, and took a few hundred markers out of Leaflet and put them
   * back - all before the browser was allowed to paint the chip the customer had just pressed. Measured
   * on Sofia's 912 points, that DOM work alone came to 1580 ms.
   *
   * Distances cannot change when a courier is switched off - nobody moved - so the click now only
   * re-reads cached numbers, and the sweep over rows and pins goes to the next frame.
   */
  test('combined map: switching a courier off in the legend answers at once @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);
    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1500);
    await page.locator('.bgc-allmap-side .bgc-map-locate').click();
    await page.waitForTimeout(4000);

    const points = await page.locator('.bgc-allmap-item').count();
    expect(points, 'this only proves anything on a town with a lot of points').toBeGreaterThan(200);

    // Both halves of the answer - the chip's own state and the sentence above the map - measured from
    // the click to the frame that paints them.
    const t = await page.evaluate(async () => {
      const was = document.querySelector('.bgc-allmap-near').textContent;
      // The chip that OWNS the current answer. Switching any other courier off would correctly leave
      // the sentence alone, and would prove nothing about whether it followed.
      const chips = Array.from(document.querySelectorAll('.bgc-allmap-chip.on'));
      const chip = chips.find(c => {
        const name = c.textContent.replace(/[\d,]+\s*(м|км)$/, '').trim();
        return name && was.includes(name);
      }) || chips[0];
      const t0 = performance.now();
      chip.click();
      await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
      return { ms: performance.now() - t0, off: !chip.classList.contains('on'),
               changed: document.querySelector('.bgc-allmap-near').textContent !== was };
    });
    expect(t.off, 'the chip is off on the click').toBe(true);
    expect(t.changed, 'the answer follows the filter').toBe(true);
    // Generous against the 1580 ms this used to cost: this is about the shape, not a stopwatch.
    expect(t.ms, `the legend answered in ${Math.round(t.ms)} ms`).toBeLessThan(400);
  });

  /**
   * "Closest to you: Speedy, locker, 410 m" - and WHICH one is that?
   *
   * Reported exactly that way: the sentence names a courier and a distance but not a place, and there is
   * no way to tell which of two hundred identical dots it means. The sentence is a button, and pressing
   * it has to land on that one point.
   */
  test('combined map: the answer line points at the office it means @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);
    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1500);
    await page.locator('.bgc-allmap-side .bgc-map-locate').click();
    await page.waitForTimeout(4000);

    // Sorted by distance, so the closest office is the first row - and that is what the sentence is
    // about. Its name is nowhere in the sentence, which is the whole complaint.
    const closest = (await page.locator('.bgc-allmap-item').first().locator('.n').textContent()).trim();
    const answer = (await page.locator('.bgc-allmap-near').textContent()).replace(/\s+/g, ' ');
    expect(closest.length).toBeGreaterThan(3);

    await page.locator('.bgc-allmap-near').click();
    await page.waitForTimeout(1200);

    // Its own bubble, open on the map, naming it.
    const popup = page.locator('.leaflet-popup .bgc-allmap-pop-n');
    await expect(popup).toBeVisible();
    const named = (await popup.textContent()).trim();
    expect(named, `the bubble names the office the answer meant (answer: ${answer})`)
      .toBe(closest.replace(/^[^\p{L}\d]+/u, '').trim());
    // ...and the row is marked, so the two halves agree about which one it is.
    await expect(page.locator('.bgc-allmap-item.active')).toHaveCount(1);
    expect((await page.locator('.bgc-allmap-item.active .n').textContent()).trim()).toBe(closest);
  });

  /**
   * A reload must forget where the customer is.
   *
   * It did not: the position rode in localStorage next to the remembered town, so opening the checkout
   * again painted the pin, every distance and the whole answer having asked nobody - and went on doing
   * it after the browser's own permission had been reset. A permission that can be taken back is not a
   * permission if the answer is kept anyway. The town is still remembered; the position is not.
   */
  test('combined map: a reload does not remember where you are @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);
    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1500);
    await page.locator('.bgc-allmap-side .bgc-map-locate').click();
    await page.waitForTimeout(4000);
    await expect(page.locator('.bgc-allmap-me')).toHaveCount(1);
    await expect(page.locator('.bgc-allmap-near')).toBeVisible();

    // Nothing about the customer's position may survive the page, in storage or anywhere else.
    const stored = await page.evaluate(() => localStorage.getItem('bgcouriers_map_pick'));
    expect(stored, 'the town is still remembered').toContain('СОФИЯ');
    expect(stored, `no position in storage: ${stored}`).not.toMatch(/origin|lat|lng/i);

    await page.reload();
    await page.waitForTimeout(3000);
    await page.locator('.bgc-allmap-btn').click();
    // The town comes back on its own, so the map is drawn - and that is exactly the state in which the
    // old behaviour showed the pin.
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 25000 });
    await page.waitForTimeout(2500);
    await expect(page.locator('.bgc-allmap-me')).toHaveCount(0);
    await expect(page.locator('.bgc-allmap-near')).toHaveCount(0);
    expect(await page.locator('.bgc-allmap-dist').count()).toBe(0);
    expect(await page.locator('.bgc-chip-d').count()).toBe(0);

    // ...and it still works when asked.
    await page.locator('.bgc-allmap-side .bgc-map-locate').click();
    await page.waitForTimeout(4000);
    await expect(page.locator('.bgc-allmap-me')).toHaveCount(1);
    await expect(page.locator('.bgc-allmap-near')).toBeVisible();
  });

  /**
   * The answer has to obey the search box too, not only the legend.
   *
   * It did not: after typing a street the sentence still named the closest point in the whole town -
   * a point whose row and whose pin were both hidden by then. Pressing it opened a bubble anchored to
   * a pin nobody could see, which is precisely the confusion the button exists to remove.
   */
  test('combined map: the answer follows the search, not just the legend @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);
    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1500);
    await page.locator('.bgc-allmap-side .bgc-map-locate').click();
    await page.waitForTimeout(4000);
    const all = (await page.locator('.bgc-allmap-near').textContent()).replace(/\s+/g, ' ');

    await page.locator('.bgc-allmap-search').fill('люлин');
    await page.waitForTimeout(900);
    const rows = await page.locator('.bgc-allmap-item:visible').count();
    expect(rows, 'the search has to actually narrow something').toBeGreaterThan(0);

    const narrowed = (await page.locator('.bgc-allmap-near').textContent()).replace(/\s+/g, ' ');
    expect(narrowed, `the answer must change with the search (was: ${all})`).not.toBe(all);

    // Whatever it now names must be a point the customer can see - the first visible row, since the
    // list is sorted by distance.
    const firstVisible = (await page.locator('.bgc-allmap-item:visible').first().locator('.n').textContent()).trim();
    await page.locator('.bgc-allmap-near').click();
    await page.waitForTimeout(1200);
    const named = (await page.locator('.leaflet-popup .bgc-allmap-pop-n').textContent()).trim();
    expect(named).toBe(firstVisible.replace(/^[^\p{L}\d]+/u, '').trim());
    // ...and the pin it opened over is painted, not one the search had hidden.
    await expect(page.locator('.leaflet-popup')).toBeVisible();
    expect(await page.locator('.bgc-allmap-item.active:visible').count()).toBe(1);
  });

  /**
   * The sentence names the closest point; every OTHER pin then has to be worth comparing against it,
   * and it cannot be unless it says how far IT is.
   */
  test('combined map: a point’s bubble says how far it is from you @allmap', async ({ page }) => {
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await page.waitForTimeout(2500);
    await page.locator('.bgc-allmap-btn').click();
    await chooseCity(page, 'София', 'СОФИЯ (1000)');
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 20000 });
    await page.waitForTimeout(1500);

    // Before the customer says where they are, a bubble must not claim a distance from nowhere.
    await page.locator('.bgc-allmap-item:not(.bgc-na)').first().click();
    await page.waitForTimeout(1200);
    await expect(page.locator('.leaflet-popup .bgc-allmap-pop-n')).toBeVisible();
    expect(await page.locator('.leaflet-popup .bgc-allmap-pop-d').count()).toBe(0);

    await page.locator('.bgc-allmap-side .bgc-map-locate').click();
    await page.waitForTimeout(4000);
    // Built when it opens, so a bubble opened AFTER the position is known carries it without a redraw.
    await page.locator('.bgc-allmap-item:not(.bgc-na)').first().click();
    await page.waitForTimeout(1200);
    const d = page.locator('.leaflet-popup .bgc-allmap-pop-d');
    await expect(d).toBeVisible();
    expect(metres(await d.textContent()), 'the bubble carries a real distance').not.toBeNull();

    // ...on the same line as the directions arrow, and level with it. The arrow used to be nudged down
    // by a fixed 5px to look centred against a line of plain text; the distance made that line taller
    // and left the arrow sitting below everything, which is what the owner saw.
    const level = await page.evaluate(() => {
      const mid = (sel) => { const el = document.querySelector('.leaflet-popup ' + sel);
        if (!el) { return null; } const b = el.getBoundingClientRect(); return b.top + b.height / 2; };
      return { dist: mid('.bgc-allmap-pop-d'), dir: mid('.bgc-allmap-dir') };
    });
    expect(level.dir, 'the popup has a directions link').not.toBeNull();
    expect(Math.abs(level.dir - level.dist),
      `the arrow sits level with the distance (${JSON.stringify(level)})`).toBeLessThan(3);
  });
});
