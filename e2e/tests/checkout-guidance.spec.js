const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, dismissStoreBanner, fillGuestBilling, selectShippingMethod, selectSpeedyTab, selectCity, choosePayment } = require('../helpers/shop');

/**
 * Three things the map and the checkout do to guide a customer, each measured rather than eyeballed.
 *
 * 1. The combined map folds pins that stand on top of each other into count bubbles. The invariant
 *    that catches every bucketing mistake: at any zoom, the bubbles' counts plus the pins still painted
 *    equal the points the filter lets through - nothing is shown twice, nothing disappears. At street
 *    level nothing is folded at all.
 * 2. A refusal ("choose an office for Speedy") names its field: the notice carries the field's id,
 *    the field is painted red - and stays red across the re-render WooCommerce does right after.
 * 3. The address picker opens on the town the customer named, not on the whole country, when the
 *    browser gives no position (a headless one never does - exactly the path this covers).
 * 4. Nothing stands alone beside a bubble. The pins are bucketed on a grid, and a grid has edges: a
 *    point ten pixels from a thousand others can fall the other side of one and stay a lone pin against
 *    the count. Measured before the fix: six of them at zoom 11, one at zoom 10.
 * 5. The map remembers the last town this browser looked at. A remembered town with no pickup point
 *    left in it is dropped instead of shown - the memory has no expiry, so it outlives a town the
 *    couriers stop listing and every town of a country the shop has stopped delivering to.
 *
 * Places no order: the one submit is made without a pickup point, which is what is being tested.
 */
test.describe('checkout guidance @guidance', () => {
  test('the map folds crowded pins into bubbles, and the count always adds up', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await dismissStoreBanner(page);
    await selectShippingMethod(page, 'speedy');
    const fields = page.locator('.bgc-fields[data-courier="speedy"]');
    await expect(fields).toBeVisible({ timeout: 15000 });
    await selectCity(page, fields, 'София');
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1500);

    await page.locator('.bgc-allmap-btn').click();
    const dlg = page.locator('.bgc-allmap-box');
    await expect(dlg).toBeVisible();
    await expect(page.locator('.bgc-allmap-pin').first()).toBeAttached({ timeout: 30000 });
    await page.waitForTimeout(1500); // the box widens, frame() runs its last pass

    async function tally() {
      return page.evaluate(() => {
        const painted = [...document.querySelectorAll('.bgc-allmap-pin')].filter((el) => el.style.display !== 'none').length;
        const bubbles = [...document.querySelectorAll('.bgc-allmap-cluster b')].map((b) => parseInt(b.textContent, 10));
        const inBubbles = bubbles.reduce((a, b) => a + b, 0);
        const offered = window.BGCouriersAllMap.points().filter((p) => Number(p.office.lat) && Number(p.office.lng)).length;
        const shownByFilter = parseInt(document.querySelector('.bgc-allmap-n').textContent.replace(/\D/g, ''), 10);
        return { painted, bubbles: bubbles.length, inBubbles, offered, shownByFilter };
      });
    }
    // A whole city at its fitting zoom: hundreds of points, folded.
    let t = await tally();
    expect(t.offered).toBeGreaterThan(50);
    expect(t.bubbles).toBeGreaterThan(0);
    expect(t.painted + t.inBubbles, 'every located point is either painted or counted in a bubble').toBe(t.offered);
    // Every bubble stands for at least two points.
    const smallest = await page.evaluate(() => Math.min(...[...document.querySelectorAll('.bgc-allmap-cluster b')].map((b) => parseInt(b.textContent, 10))));
    expect(smallest).toBeGreaterThanOrEqual(2);

    // Switching a courier off in the legend: the count still adds up against what is left.
    const chips = page.locator('.bgc-allmap-chip');
    if (await chips.count() > 1) {
      await chips.nth(1).click();
      await page.waitForTimeout(500);
      const afterToggle = await tally();
      const stillOn = await page.evaluate(() => {
        const off = [...document.querySelectorAll('.bgc-allmap-chip:not(.on)')].map((c) => c.getAttribute('data-c'));
        return window.BGCouriersAllMap.points().filter((p) => Number(p.office.lat) && Number(p.office.lng) && off.indexOf(p.courier) === -1).length;
      });
      expect(afterToggle.painted + afterToggle.inBubbles).toBe(stillOn);
      await chips.nth(1).click();
      await page.waitForTimeout(500);
    }

    // Tapping a bubble zooms in; at street level nothing is folded any more.
    await page.locator('.bgc-allmap-cluster').first().click();
    await page.waitForTimeout(800);
    // Drive the zoom to street level through the + control until the bubbles are gone.
    for (let i = 0; i < 8; i++) {
      if (await page.locator('.bgc-allmap-cluster').count() === 0) { break; }
      await page.locator('.leaflet-control-zoom-in').click();
      await page.waitForTimeout(400);
    }
    t = await tally();
    expect(t.bubbles).toBe(0);
    expect(t.painted).toBe(t.offered);
    await page.locator('.bgc-allmap-close').click();
  });

  test('a refusal names its field, and the field stays marked through a re-render', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await dismissStoreBanner(page);
    await selectShippingMethod(page, 'speedy');
    const fields = page.locator('.bgc-fields[data-courier="speedy"]');
    await expect(fields).toBeVisible({ timeout: 15000 });
    await selectSpeedyTab(page, fields, 'office');
    await selectCity(page, fields, 'София');
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1500);
    // Town chosen, office not: the one refusal that is the plugin's own.
    await fillGuestBilling(page, { first: 'Тест', last: 'Насока', email: 'e2e-guidance@example.com', phone: '0888123456' });
    await choosePayment(page, 'cod');
    await page.locator('#place_order').click();

    const notice = page.locator('.woocommerce-error li[data-id="bgcouriers-office-speedy"]');
    await expect(notice).toBeVisible({ timeout: 30000 });
    await expect(notice.locator('a')).toHaveAttribute('href', '#bgcouriers-office-speedy'); // WooCommerce's own link to the field
    const office = page.locator('#bgcouriers-office-speedy');
    await expect(office).toHaveClass(/bgc-invalid/);
    // Painted, not just classed: the box's border went red.
    const border = await office.locator('.select2-selection').evaluate((el) => getComputedStyle(el).borderTopColor);
    expect(border).toBe('rgb(179, 45, 46)');

    // WooCommerce re-renders the order table; the mark must survive it.
    await page.evaluate(() => jQuery(document.body).trigger('update_checkout'));
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(2500);
    await expect(page.locator('#bgcouriers-office-speedy')).toHaveClass(/bgc-invalid/);

    // Filling the field takes the mark off - through the MAP, which writes the office into the select
    // without a change event and lets the server re-render the block: the route most likely to leave
    // a stale mark behind.
    await page.locator('#bgcouriers-office-speedy .bgc-map-btn').click();
    await expect(page.locator('.bgc-allmap-box')).toBeVisible();
    await expect(page.locator('.bgc-allmap-item').first()).toBeAttached({ timeout: 30000 });
    const item = page.locator('.bgc-allmap-item:not(.bgc-na)').first(); // the list is beside the map at this width
    await item.click();
    await page.locator('.bgc-allmap-pick').first().click();
    await expect(page.locator('.bgc-allmap-box')).toBeHidden({ timeout: 15000 });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(4000); // the pick's own round of recalculations
    const officeVal = await page.locator('#bgcouriers-office-speedy .bgc-office').inputValue();
    expect(officeVal).not.toBe('');
    await expect(page.locator('#bgcouriers-office-speedy')).not.toHaveClass(/bgc-invalid/);
    // ...and it stays off through the next re-render.
    await page.evaluate(() => jQuery(document.body).trigger('update_checkout'));
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(2500);
    await expect(page.locator('#bgcouriers-office-speedy')).not.toHaveClass(/bgc-invalid/);
  });

  test('the office already chosen is never folded into a bubble', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await dismissStoreBanner(page);
    await selectShippingMethod(page, 'speedy');
    const fields = page.locator('.bgc-fields[data-courier="speedy"]');
    await expect(fields).toBeVisible({ timeout: 15000 });
    await selectSpeedyTab(page, fields, 'office');
    await selectCity(page, fields, 'София');
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1500);
    await fields.locator('.bgc-office-row .select2-selection').click();
    const opt = page.locator('.select2-results__option[role="option"]').first();
    await expect(opt).toBeVisible({ timeout: 20000 });
    await page.waitForTimeout(600);
    await opt.click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(2500);
    // Opened from this courier's own button, the map lands on the pick at street level; zoom out
    // three steps into bubble territory and the pulsing pin must still be painted.
    await fields.locator('.bgc-office-pick .bgc-map-btn').click();
    await expect(page.locator('.bgc-allmap-pin.bgc-chosen')).toBeAttached({ timeout: 30000 });
    await page.waitForTimeout(1500);
    for (let i = 0; i < 3; i++) { await page.locator('.leaflet-control-zoom-out').click(); await page.waitForTimeout(400); }
    expect(await page.locator('.bgc-allmap-cluster').count()).toBeGreaterThan(0);
    const chosen = await page.locator('.bgc-allmap-pin.bgc-chosen').evaluate((el) => el.style.display !== 'none');
    expect(chosen, 'the chosen pin is painted, not swallowed').toBe(true);
    await page.locator('.bgc-allmap-close').click();
  });

  test('the address picker opens on the named town when the browser gives no position', async ({ page, context }) => {
    await context.clearPermissions(); // no geolocation: the map has only the town to go on
    await page.setViewportSize({ width: 1280, height: 900 });
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await dismissStoreBanner(page);
    await selectShippingMethod(page, 'speedy');
    const fields = page.locator('.bgc-fields[data-courier="speedy"]');
    await expect(fields).toBeVisible({ timeout: 15000 });
    await selectSpeedyTab(page, fields, 'address');
    await selectCity(page, fields, 'Пловдив');
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1500);
    await fields.locator('.bgc-addr-map-btn').click();
    await expect(page.locator('.bgc-map-overlay')).toBeVisible();
    // The town's centre is one fetch away (none, when the combined map already had the town).
    await page.waitForTimeout(4000);
    // The map object is private to the plugin's script; its view is read off the tiles it asked for.
    // The most-requested zoom is the current one, and the mean of that zoom's tile rows and columns
    // is the centre to within a tile - a few kilometres at these zooms, which is all the check needs.
    const centre = await page.evaluate(() => {
      const tiles = [...document.querySelectorAll('#bgc-map img.leaflet-tile')].map((i) => i.src.match(/\/(\d+)\/(\d+)\/(\d+)\.png/)).filter(Boolean);
      const count = {};
      tiles.forEach((m) => { count[m[1]] = (count[m[1]] || 0) + 1; });
      const z = parseInt(Object.keys(count).sort((a, b) => count[a] - count[b]).pop(), 10);
      const at = tiles.filter((m) => parseInt(m[1], 10) === z).map((m) => ({ x: parseInt(m[2], 10) + 0.5, y: parseInt(m[3], 10) + 0.5 }));
      const mx = at.reduce((a, t) => a + t.x, 0) / at.length, my = at.reduce((a, t) => a + t.y, 0) / at.length;
      const n = Math.pow(2, z);
      const lng = mx / n * 360 - 180;
      const lat = Math.atan(Math.sinh(Math.PI * (1 - 2 * my / n))) * 180 / Math.PI;
      return { z, lat, lng, tiles: tiles.length };
    });
    expect(centre.tiles).toBeGreaterThan(0);
    expect(centre.z).toBeGreaterThanOrEqual(12);
    // Plovdiv is at 42.15N 24.75E; within a few kilometres of it.
    expect(Math.abs(centre.lat - 42.15)).toBeLessThan(0.08);
    expect(Math.abs(centre.lng - 24.75)).toBeLessThan(0.12);
    await page.locator('.bgc-map-close').click();
  });
  test('a remembered town with nothing left in it is dropped, not shown', async ({ context, page }) => {
    // A browser that looked at a Romanian town while international delivery was still switched on.
    // Nothing on this checkout is chosen, and the customer never named this place in this session.
    await context.addInitScript(() => {
      try {
        window.localStorage.setItem('bgcouriers_map_pick',
          JSON.stringify({ cityName: 'ANINA', cityCode: '325100', cityLabel: 'ANINA (325100)' }));
      } catch (e) { /* private mode - the test below then passes trivially */ }
    });
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await dismissStoreBanner(page);
    await page.locator('.bgc-allmap-btn').click();
    const city = page.locator('.bgc-allmap-cityinput');
    await expect(city).toBeVisible({ timeout: 15000 });
    // The answer arrives over admin-ajax; give it the same room the dialog does.
    await expect(city).toHaveValue('', { timeout: 30000 });
    await expect(page.locator('.bgc-allmap-item')).toHaveCount(0);
    const stored = await page.evaluate(() => {
      try { return JSON.parse(window.localStorage.getItem('bgcouriers_map_pick') || '{}').cityName || ''; }
      catch (e) { return ''; }
    });
    expect(stored).toBe('');
  });

  test('a remembered town that is still served opens on it', async ({ context, page }) => {
    await context.addInitScript(() => {
      try {
        window.localStorage.setItem('bgcouriers_map_pick',
          JSON.stringify({ cityName: 'СОФИЯ', cityCode: '1000', cityLabel: 'СОФИЯ (1000)' }));
      } catch (e) {}
    });
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await dismissStoreBanner(page);
    await page.locator('.bgc-allmap-btn').click();
    await expect(page.locator('.bgc-allmap-cityinput')).toHaveValue('СОФИЯ (1000)', { timeout: 15000 });
    await expect(page.locator('.bgc-allmap-item').first()).toBeVisible({ timeout: 30000 });
  });
  test('nothing stands alone next to a bubble', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await dismissStoreBanner(page);
    await selectShippingMethod(page, 'speedy');
    const fields = page.locator('.bgc-fields[data-courier="speedy"]');
    await expect(fields).toBeVisible({ timeout: 15000 });
    await selectCity(page, fields, 'София');
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1500);

    await page.locator('.bgc-allmap-btn').click();
    await expect(page.locator('.bgc-allmap-pin').first()).toBeAttached({ timeout: 30000 });
    await page.waitForTimeout(1500);

    // The distance is the bucket size: a pin closer to a bubble than one cell is a pin that belongs in
    // it. Walked down the zoom levels, because the stragglers appear at the top of the range - by the
    // time a whole country fits, every point is in one cell anyway and there is nothing left to catch.
    const lonely = () => page.evaluate(() => {
      const vis = (el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && el.style.display !== 'none'; };
      const mid = (el) => { const r = el.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; };
      const bubbles = [...document.querySelectorAll('.bgc-allmap-cluster')].filter(vis).map(mid);
      if (!bubbles.length) { return { checked: false, n: 0 }; }
      const n = [...document.querySelectorAll('.bgc-allmap-pin')].filter(vis).map(mid)
        .filter((p) => Math.min(...bubbles.map((b) => Math.hypot(p.x - b.x, p.y - b.y))) < 56).length;
      return { checked: true, n };
    });

    let checkedAny = false;
    for (let i = 0; i < 4; i++) {
      const r = await lonely();
      if (r.checked) { checkedAny = true; }
      expect(r.n, `a pin is sitting inside a bubble's own cell (zoom step ${i})`).toBe(0);
      await page.locator('.leaflet-control-zoom-out').click({ force: true });
      await page.waitForTimeout(700);
    }
    expect(checkedAny, 'no bubbles at all - this test measured nothing').toBe(true);
  });
});
