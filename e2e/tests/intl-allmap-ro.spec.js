const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { addAnyProductToCart, gotoCheckout, selectShippingMethod } = require('../helpers/shop');

/**
 * The combined map, with the parcel going ABROAD.
 *
 * The dialog carries a list of towns the page was given at load, which is why its town box answers
 * instantly and asks the server nothing - see "the city list comes from the page, not the server". That
 * index is built for ONE country. Abroad it does not merely lack a few names: it holds none of that
 * country at all, so every lookup in it comes back "no such place", which is indistinguishable from an
 * answer. Left to it, a customer buying to Romania would be offered Bulgarian towns and nothing else.
 *
 * So this spec is the mirror image of that one: at home the box must NOT call the server, and abroad it
 * MUST - and what comes back has to be the destination's towns, with a real pickup point at the end of
 * it. Every other @allmap test runs at home, so without this one the whole foreign path is unwatched.
 *
 * It books nothing and places no order. It does need the prepaid gateway on for its own length: dev's
 * cash on delivery is receipted through the courier's ППП, which stops at the border, so with no
 * prepaid method every rate abroad is correctly refused - and the map button renders above the rates,
 * which means there would be no dialog to open at all. Put back the way it was FOUND afterwards.
 *
 * SKIPPED while international delivery is unfinished and switched off in the plugin: no shop is
 * offered a foreign rate at all, so there is nothing here to open. Turn it back on with
 * add_filter('bgcouriers_intl_enabled', '__return_true') on the site under test and drop the .skip -
 * the spec is kept as it is because it is the only watch on the foreign path.
 * See docs/international-shipping.md.
 */

const SH = path.join(__dirname, '..', 'dev-option.sh');
const dev = (...args) => execFileSync('bash', [SH, ...args], { encoding: 'utf8' }).trim();

// Bulgarian town names are Cyrillic and Romanian ones are Latin, which is what makes "whose list is
// this?" a thing a test can decide by looking. Nothing else here can tell the two apart.
const CYRILLIC = /[Ѐ-ӿ]/;

let prepaidWas = 'no';
test.beforeAll(() => {
  prepaidWas = dev('gateway', 'bacs');
  console.log(`[intl-map] bank transfer was ${prepaidWas}, on for this spec: ${dev('gateway', 'bacs', 'yes')}`);
});
test.afterAll(() => { console.log(`[intl-map] bank transfer back to ${dev('gateway', 'bacs', prepaidWas)}`); });

test.skip('combined map: abroad the towns are the DESTINATION\'s, not the shop\'s @allmap @speedy', async ({ page }) => {
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

  const asked = [];
  page.on('request', r => {
    if (r.url().includes('action=bgcouriers_allmap_cities')
        || (r.postData() || '').includes('bgcouriers_allmap_cities')) { asked.push(r.url()); }
  });

  // .first(): abroad the shortcut above the rates and the chosen courier's own button can both be on
  // screen, and either opens the same dialog on the same destination.
  await page.locator('.bgc-allmap-btn').first().click();
  await expect(page.locator('.bgc-allmap-overlay')).toBeVisible();

  const input = page.locator('.bgc-allmap-cityinput');
  const opts = page.locator('.bgc-allmap-cityopt');
  // Pressing the field with nothing typed is the dropdown being opened, and abroad that is a round trip
  // - generous, because it costs a whole WordPress boot on a dev site under a test run.
  await input.click();
  await expect(opts.first(), 'the town list stayed empty for a Romanian address').toBeVisible({ timeout: 25000 });

  expect(asked.length,
    'the box answered out of the page index, which holds no Romanian town at all - so what it offered was Bulgarian')
    .toBeGreaterThan(0);

  const names = await opts.allInnerTexts();
  expect(names.length, 'one town is not a list').toBeGreaterThan(1);
  const bg = names.filter(n => CYRILLIC.test(n));
  expect(bg, `Bulgarian towns offered for a Romanian address: ${bg.slice(0, 3).join(', ')}`).toHaveLength(0);

  // A named town rather than the first on the list: the first Romanian one alphabetically (1 DECEMBRIE)
  // has no Speedy office at all, so nothing would plot and the failure would point at the wrong thing.
  // Bucharest has 32, and is the only town whose name is exactly this.
  await input.fill('BUCURESTI');
  const buc = page.locator('.bgc-allmap-cityopt', { hasText: 'BUCURESTI' }).first();
  await buc.waitFor({ state: 'visible', timeout: 25000 });
  await buc.click({ timeout: 10000 });
  expect(await input.inputValue()).toContain('BUCURESTI');

  // And the points themselves come from Romania. A town name is only unique inside a country - "1000"
  // is both Sofia and Bucharest - so plotting the right pins is a separate claim from listing the right
  // towns, and it is the one a customer would actually be sent to.
  const item = page.locator('.bgc-allmap-item').first();
  await expect(item, 'no pickup point plotted in Bucharest - has dev synced Romania?')
    .toBeVisible({ timeout: 30000 });
  const addr = await item.innerText();
  expect(CYRILLIC.test(addr), `a Bulgarian pickup point was plotted for a Romanian town: ${addr}`).toBe(false);
});
