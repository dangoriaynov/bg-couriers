const { test, expect, devices } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, dismissStoreBanner, selectShippingMethod, selectSpeedyTab, selectCity, pickFirstOffice, fillStreet } = require('../helpers/shop');

/**
 * The checkout on a phone, measured rather than eyeballed: an emulated iPhone 13 (390px, touch, so
 * `(pointer: coarse)` matches and the touch sizes apply). Every state the customer passes through is
 * checked for the two things a phone gets wrong that a desktop never shows - something reaching past
 * the edge of the screen, and something too small to tap.
 *
 * Found on 2026-09-11 by walking exactly this path with screenshots: the (i) bubble cut off at the
 * left edge, the address picker's "Use this address" button off the right edge, a 30px map opener and a
 * 40px BOX NOW button under a thumb, and a chosen office name cut off mid-word after three words.
 */
// The descriptor asks for WebKit; only Chromium is installed here, and its touch emulation is what the
// touch sizes key on, so the device's screen and pointer are taken and its browser is not.
const { defaultBrowserType, ...iphone } = devices['iPhone 13']; // eslint-disable-line no-unused-vars
test.use(iphone);

async function noSidewaysScroll(page) {
  const [sw, iw] = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  expect(sw, 'the page must not scroll sideways').toBeLessThanOrEqual(iw);
}

/** Every visible element in the courier box, and any dialog of ours, stays inside the screen. */
async function nothingPastTheEdge(page, scope) {
  const out = await page.evaluate((sel) => {
    const iw = window.innerWidth, bad = [];
    document.querySelectorAll(sel).forEach((el) => {
      const r = el.getBoundingClientRect();
      if (!r.width || !r.height) return;
      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden') return;
      // A strip that scrolls sideways on purpose (the courier chips over the map) is allowed to.
      if (el.closest('.bgc-allmap-legend, .leaflet-container')) return;
      if (r.right > iw + 1 || r.left < -1) bad.push(`${el.className || el.tagName} ${Math.round(r.left)}..${Math.round(r.right)}`);
    });
    return bad;
  }, scope);
  expect(out, 'past the edge of the screen').toEqual([]);
}

async function tapTargets(page, sel, min = 44) {
  const small = await page.evaluate(([s, m]) => {
    const out = [];
    document.querySelectorAll(s).forEach((el) => {
      const r = el.getBoundingClientRect();
      if (!r.width || !r.height) return;
      if (r.height < m - 1 || r.width < m - 1) out.push(`${el.className || el.tagName} ${Math.round(r.width)}x${Math.round(r.height)}`);
    });
    return out;
  }, [sel, min]);
  expect(small, `smaller than ${min}px under a thumb`).toEqual([]);
}

test('the courier box, its dialogs and the (i) fit an iPhone @phone', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await dismissStoreBanner(page);
  await selectShippingMethod(page, 'speedy');
  const fields = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(fields).toBeVisible({ timeout: 15000 });
  await page.waitForTimeout(600);

  // Office tab, town chosen, office chosen: the chosen office is readable and the row fits.
  await selectSpeedyTab(page, fields, 'office');
  await selectCity(page, fields, 'София');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1500);
  await pickFirstOffice(page, fields);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);
  await noSidewaysScroll(page);
  await nothingPastTheEdge(page, '#order_review *');
  await tapTargets(page, '.bgc-fields[data-courier="speedy"] .bgc-tab, .bgc-fields[data-courier="speedy"] .select2-selection, .bgc-fields[data-courier="speedy"] .bgc-map-btn, .bgc-allmap-btn');
  // The office's name wraps onto a second line instead of being cut to an ellipsis. Whichever office
  // Speedy lists first, and however long its name is today: the text is allowed to wrap, nothing runs
  // off sideways, and the box is one or two lines tall - two being where a long name is clamped.
  const office = await fields.locator('.bgc-office-row .select2-selection__rendered').evaluate((el) => {
    const cs = getComputedStyle(el);
    return { wrap: cs.whiteSpace, sideways: el.scrollWidth > el.clientWidth + 1,
      lines: Math.round(el.clientHeight / parseFloat(cs.lineHeight)), text: el.textContent.trim() };
  });
  expect(office.wrap).toBe('normal');
  expect(office.sideways, 'cut to an ellipsis: ' + office.text).toBe(false);
  expect(office.lines, office.text).toBeGreaterThanOrEqual(1);
  expect(office.lines, office.text).toBeLessThanOrEqual(2);

  // The (i): a tap shows the whole sentence, inside the screen, and it stays shown.
  const tip = page.locator('ul#shipping_method > li:has(input:checked) .bgc-info-tip').first();
  await tip.scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollBy(0, -120));
  await tip.tap();
  await page.waitForTimeout(400);
  const box = page.locator('.bgc-tipbox.bgc-tip-on');
  await expect(box).toBeVisible();
  const b = await box.boundingBox();
  const iw = await page.evaluate(() => window.innerWidth);
  expect(b.x).toBeGreaterThanOrEqual(0);
  expect(b.x + b.width).toBeLessThanOrEqual(iw);
  await expect(box).toHaveText(await tip.getAttribute('data-tip'));
  await page.mouse.click(5, 5); // anywhere else closes it
  await expect(box).toBeHidden();

  // Address tab: street with the map pin beside it, then the number; the page still fits.
  await selectSpeedyTab(page, fields, 'address');
  await fillStreet(page, fields, 'Витоша');
  await noSidewaysScroll(page);
  await nothingPastTheEdge(page, '#order_review *');
  const street = await fields.locator('.bgc-street-field').boundingBox();
  const pin = await fields.locator('.bgc-addr-map-cell').boundingBox();
  const no = await fields.locator('.bgc-streetno-field').boundingBox();
  expect(Math.abs(street.y - pin.y), 'the pin shares the street\'s row').toBeLessThan(4);
  expect(no.y, 'the number goes under the street').toBeGreaterThanOrEqual(street.y + street.height - 1);
  await tapTargets(page, '.bgc-fields[data-courier="speedy"] input:not([type=hidden]), .bgc-fields[data-courier="speedy"] .bgc-addr-map-icon');

  // The address picker: every button inside the screen, big enough to tap.
  await fields.locator('.bgc-addr-map-btn').click();
  await expect(page.locator('.bgc-map-overlay')).toBeVisible();
  await page.waitForTimeout(800);
  await nothingPastTheEdge(page, '.bgc-map-box, .bgc-map-box *');
  await tapTargets(page, '.bgc-map-actions .button');
  await page.locator('.bgc-map-close').click();
  await expect(page.locator('.bgc-map-overlay')).toBeHidden();

  // BOX NOW's own button is drawn by the theme; it still has to be a target.
  await selectShippingMethod(page, 'boxnow');
  await expect(page.locator('.bgc-fields[data-courier="boxnow"]')).toBeVisible({ timeout: 15000 });
  await page.waitForTimeout(600);
  await tapTargets(page, '.bgc-fields[data-courier="boxnow"] .bgc-boxnow-pick');
  await noSidewaysScroll(page);
});
