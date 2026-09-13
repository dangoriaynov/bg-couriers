const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, dismissStoreBanner, selectShippingMethod, selectSpeedyTab, selectCity } = require('../helpers/shop');

/**
 * The street the address map hands over is a street off the courier's list.
 *
 * The map reverse-geocodes the pin and used to write whatever came back straight into the street box:
 * "бул. Княз Александър Дондуков" for one point, "Кърниградска" for the next (Nominatim, Sofia, measured
 * 2026-09-13). Speedy's street search answers the typed form with nothing, so such an order reached the
 * packing table with a name Speedy refuses in a town that lists two of it; Express One and Европът refuse
 * any street that is not off their list. Now the map asks the list which street that is (exact=1) and
 * the box holds it with the courier's id and type - or, for a courier that lists its streets, holds
 * nothing rather than a street nobody can label.
 *
 * The geocoder is answered here, not by Nominatim: what is under test is the hand-over, and a live
 * answer for a map pixel is not something a test can assert on.
 */

async function pinAndUse(page, fields, geo) {
  await page.route(/admin-ajax\.php/, async (route) => {
    const url = route.request().url();
    const post = route.request().postData() || '';
    if (/action=bgcouriers_geocode/.test(url) || /action=bgcouriers_geocode/.test(post)) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(geo) });
    }
    return route.fallback();
  });
  await fields.locator('.bgc-addr-map-btn').click();
  await expect(page.locator('.bgc-map-overlay')).toBeVisible();
  await page.waitForTimeout(2500); // the map settles on the town
  const canvas = page.locator('#bgc-map');
  const box = await canvas.boundingBox();
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  const use = page.locator('.bgc-addr-use');
  await expect(use).toBeEnabled({ timeout: 10000 });
  await expect(page.locator('.bgc-addr-preview')).toContainText(geo.street);
  await use.click();
  await expect(page.locator('.bgc-map-overlay')).toBeHidden();
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);
  await page.unroute(/admin-ajax\.php/);
}

async function toAddress(page, courierId) {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await dismissStoreBanner(page);
  await selectShippingMethod(page, courierId);
  const fields = page.locator(`.bgc-fields[data-courier="${courierId}"]`);
  await expect(fields).toBeVisible({ timeout: 15000 });
  await selectSpeedyTab(page, fields, 'address');
  await selectCity(page, fields, 'София');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2000);
  return fields;
}

test('the map hands Speedy a street off its list, with its id and type @speedy', async ({ page, context }) => {
  await context.clearPermissions();
  await page.setViewportSize({ width: 1280, height: 900 });
  const fields = await toAddress(page, 'speedy');
  await pinAndUse(page, fields, { city: 'София', postcode: '1000', street: 'бул. Княз Александър Дондуков', number: '5' });
  const f = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(f.locator('.bgc-street')).toHaveValue('КНЯЗ АЛЕКСАНДЪР ДОНДУКОВ');
  await expect(f.locator('.bgc-street-field .select2-selection__rendered')).toContainText('бул. КНЯЗ АЛЕКСАНДЪР ДОНДУКОВ');
  await expect(f.locator('.bgc-street-type')).toHaveValue('бул.');
  await expect(f.locator('.bgc-street-id')).toHaveValue('64');
  await expect(f.locator('.bgc-street-no')).toHaveValue('5');
  await expect(f.locator('.bgc-city')).toHaveValue('68134');
});

test('a street the courier does not list stays as text for Speedy and is left empty for Express One @speedy @expressone', async ({ page, context }) => {
  await context.clearPermissions();
  await page.setViewportSize({ width: 1280, height: 900 });
  const fields = await toAddress(page, 'speedy');
  await pinAndUse(page, fields, { city: 'София', postcode: '1000', street: 'Несъществуваща', number: '1' });
  const f = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(f.locator('.bgc-street')).toHaveValue('Несъществуваща');
  await expect(f.locator('.bgc-street-id')).toHaveValue('0');

  await selectShippingMethod(page, 'expressone');
  const eo = page.locator('.bgc-fields[data-courier="expressone"]');
  await expect(eo).toBeVisible({ timeout: 15000 });
  await selectSpeedyTab(page, eo, 'address');
  await selectCity(page, eo, 'София');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2000);
  await pinAndUse(page, eo, { city: 'София', postcode: '1000', street: 'Несъществуваща', number: '1' });
  const e2 = page.locator('.bgc-fields[data-courier="expressone"]');
  await expect(e2.locator('.bgc-street')).toHaveValue('');
  await expect(e2.locator('.bgc-street-no')).toHaveValue('1');
  // ...and says so where it happened, with the field painted like a refused one.
  await expect(e2.locator('.bgc-street-note')).toBeVisible();
  await expect(e2.locator('.bgc-street-note')).toContainText('Несъществуваща');
  await expect(e2.locator('.bgc-street-note')).toContainText('Express One');
  await expect(e2.locator('.bgc-street-field')).toHaveClass(/bgc-invalid/);
  // ...and one it does list arrives with Express One's own id.
  await pinAndUse(page, e2, { city: 'София', postcode: '1000', street: 'улица Витоша', number: '10' });
  const e3 = page.locator('.bgc-fields[data-courier="expressone"]');
  await expect(e3.locator('.bgc-street')).toHaveValue('ВИТОША');
  await expect(e3.locator('.bgc-street-note')).toBeHidden();
  await expect(e3.locator('.bgc-street-field')).not.toHaveClass(/bgc-invalid/);
  await expect(e3.locator('.bgc-street-type')).toHaveValue('УЛ.');
  await expect(e3.locator('.bgc-street-id')).toHaveValue('1314');
});
