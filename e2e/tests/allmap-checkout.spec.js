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
