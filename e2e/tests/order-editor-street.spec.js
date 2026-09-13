const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { addAnyProductToCart, gotoCheckout, fillGuestBilling, selectShippingMethod, selectSpeedyTab, selectCity, fillStreet, choosePayment } = require('../helpers/shop');

/**
 * The order editor's street box, in a browser.
 *
 * The editor lives on the wp-admin order screen, which needs a login this suite does not have - so its
 * real markup and the config its script is given are fetched for one order (dev-option.sh editor) and put
 * on a page of their own with dev's jQuery, selectWoo and Leaflet, and the plugin's own admin script.
 * The script does not care where its markup came from; what it does with the street box is what is
 * under test: two streets of one name are two rows, either can be picked, the box shows the label and
 * keeps the bare name as its value, the courier's id and the type ride in the hidden fields, a typed
 * street carries neither, and the save posts all of it. Until 2026-09-13 the editor listed both ВИТОША
 * rows as "ВИТОША" and "ВИТОША" and posted the bare name for either.
 */

const SH = path.join(__dirname, '..', 'dev-option.sh');
const dev = (...args) => execFileSync('bash', [SH, ...args], { encoding: 'utf8' }).trim();
const BASE = process.env.BGC_BASE || require('../config').baseURL();

async function placeSpeedyAddressOrder(page) {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await selectShippingMethod(page, 'speedy');
  const fields = page.locator('.bgc-fields[data-courier="speedy"]');
  await expect(fields).toBeVisible({ timeout: 15000 });
  await selectSpeedyTab(page, fields, 'address');
  await selectCity(page, fields, 'София');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2000);
  await fillStreet(page, fields, 'Шипка');
  await fields.locator('.bgc-street-no').fill('7');
  await page.waitForTimeout(1200);
  await fillGuestBilling(page, { first: 'Тест', last: 'Редактор', email: 'e2e-editor@example.com', phone: '0888123456' });
  await choosePayment(page, 'cod');
  await page.locator('#place_order').click();
  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });
  const id = (page.url().match(/order-received\/(\d+)/) || [])[1] || '';
  expect(id, 'could not read the order number off the thank-you page').toBeTruthy();
  return id;
}

test('the order editor lists two streets of one name as two rows and saves the one picked, with its id @speedy', async ({ page }) => {
  const orderId = await placeSpeedyAddressOrder(page);
  const ed = JSON.parse(dev('editor', orderId));
  expect(ed.html, 'the editor markup for the order').toContain('bgc-ed-street');

  const saves = [];
  await page.route(/admin-ajax\.php/, async (route) => {
    const post = route.request().postData() || '';
    if (/action=bgcouriers_order_save_delivery/.test(post)) {
      saves.push(Object.fromEntries(new URLSearchParams(post)));
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { msg: 'saved' } }) });
    }
    return route.fallback(); // the street lookups reach the real endpoint
  });
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' }); // same origin for the lookups
  await page.setContent(`<!doctype html><html><head>
    <link rel="stylesheet" href="${BASE}/wp-content/plugins/woocommerce/assets/css/select2.css">
    <link rel="stylesheet" href="${BASE}/wp-content/plugins/bg-couriers/assets/css/bgc-admin.css">
    </head><body style="width:900px;margin:20px">${ed.html}
    <script src="${BASE}/wp-includes/js/jquery/jquery.min.js"></script>
    <script src="${BASE}/wp-content/plugins/woocommerce/assets/js/selectWoo/selectWoo.full.min.js"></script>
    <script src="${BASE}/wp-content/plugins/bg-couriers/assets/lib/leaflet/leaflet.js"></script>
    <script>${ed.config}</script>
    <script src="${BASE}/wp-content/plugins/bg-couriers/assets/js/bgc-order-admin.js?v=${Date.now()}"></script>
    </body></html>`, { waitUntil: 'load' });
  await page.waitForTimeout(1200);
  const panel = page.locator('.bgc-order-panel');
  await panel.locator('.bgc-ed-toggle').click();
  const street = panel.locator('.bgc-ed-street');
  await expect(street).toHaveValue('Шипка');

  async function pick(term, label) {
    await panel.locator('.bgc-ed-street-row .select2-selection').click();
    await page.locator('.select2-search__field').fill(term);
    const rows = page.locator('.select2-results__option[role="option"]');
    await expect(rows.first()).toBeVisible({ timeout: 15000 });
    await page.waitForTimeout(500);
    return { rows, click: () => rows.filter({ hasText: label }).first().click() };
  }
  const first = await pick('Витоша', /^ул\. ВИТОША$/);
  await expect(first.rows.filter({ hasText: /^(бул\.|ул\.) ВИТОША$/ }), 'two rows for the two streets').toHaveCount(2);
  await first.click();
  await expect(street).toHaveValue('ВИТОША');
  await expect(panel.locator('.bgc-ed-street-row .select2-selection__rendered')).toContainText('ул. ВИТОША');
  await expect(panel.locator('.bgc-ed-street-type')).toHaveValue('ул.');
  const ulId = await panel.locator('.bgc-ed-street-id').inputValue();
  expect(Number(ulId)).toBeGreaterThan(0);

  const second = await pick('Витоша', /^бул\. ВИТОША$/);
  await second.click();
  await expect(panel.locator('.bgc-ed-street-row .select2-selection__rendered')).toContainText('бул. ВИТОША');
  await expect(panel.locator('.bgc-ed-street-type')).toHaveValue('бул.');
  expect(await panel.locator('.bgc-ed-street-id').inputValue()).not.toBe(ulId);

  const typed = await pick('Моя Улица', /^Моя Улица$/);
  await typed.click();
  await expect(street).toHaveValue('Моя Улица');
  await expect(panel.locator('.bgc-ed-street-id')).toHaveValue('0');
  await expect(panel.locator('.bgc-ed-street-type')).toHaveValue('');

  const again = await pick('Витоша', /^ул\. ВИТОША$/);
  await again.click();
  await panel.locator('.bgc-ed-save').click();
  await expect.poll(() => saves.length, { timeout: 10000 }).toBe(1);
  expect(saves[0]).toMatchObject({ street_name: 'ВИТОША', street_id: ulId, street_type: 'ул.', street_no: '7', site_id: '68134', method: 'address' });
});
