const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, dismissStoreBanner, selectShippingMethod } = require('../helpers/shop');

// What the town dropdown lists FIRST. The preloaded list is searched in the browser, and it used to be
// shown in the order it was stored: for "Ст" a customer saw Костенец, Костинброд, Кюстендил, Силистра
// and Стамболийски - towns that merely contain "ст" - and Стара Загора was not in the first five;
// Sameday opened with "Алеко Константиново" (measured on dev, 2026-09-13). A town that begins with what
// was typed comes first now, then one with a word that begins with it, then the rest.
async function firstResults(page, fields, term, n = 5) {
  await fields.locator('.bgc-city').locator('..').locator('.select2-selection').first().click();
  const input = page.locator('.select2-container--open .select2-search__field').first();
  await input.fill(term);
  await page.waitForTimeout(700);
  const opts = await page.locator('.select2-container--open .select2-results__option').evaluateAll((els) => els.map((e) => e.textContent.trim()));
  await page.keyboard.press('Escape');
  return opts.slice(0, n);
}

test('a town that begins with what was typed is listed before one that merely contains it @core', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await dismissStoreBanner(page);
  for (const courier of ['speedy', 'sameday']) {
    await selectShippingMethod(page, courier);
    const fields = page.locator(`.bgc-fields[data-courier="${courier}"]`);
    await expect(fields).toBeVisible({ timeout: 15000 });

    const st = await firstResults(page, fields, 'Ст', 12);
    expect(st.length, `${courier}: results for Ст`).toBeGreaterThan(2);
    // Every town that begins with "Ст" comes before the first that merely contains it (a courier may
    // have only two or three such towns among the ones it serves; the rest follow).
    const starts = st.map((t) => /^ст/.test(t.toLowerCase()));
    const firstInside = starts.indexOf(false);
    expect(firstInside, `${courier}: at least one town beginning with Ст: ${st.join(' | ')}`).not.toBe(0);
    if (firstInside !== -1) { expect(starts.slice(firstInside).some(Boolean), `${courier}: a town beginning with Ст listed after one that only contains it: ${st.join(' | ')}`).toBe(false); }
    expect(st.slice(0, 3).map((t) => t.toLowerCase()).some((t) => t.startsWith('стара загора')), `${courier}: Стара Загора is in the first three: ${st.join(' | ')}`).toBe(true);

    // A word inside the name outranks a match inside a word: "Загора" finds Стара Загора before anything
    // that only happens to contain those letters.
    const zag = await firstResults(page, fields, 'Загора', 1);
    expect(zag[0].toLowerCase(), `${courier}: first for "Загора"`).toMatch(/^стара загора|^нова загора/);

    // Control: a prefix that is also the whole answer still comes first.
    const sof = await firstResults(page, fields, 'Соф', 1);
    expect(sof[0].toLowerCase(), `${courier}: first for "Соф"`).toMatch(/^софия/);
  }
});
