const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout } = require('../helpers/shop');

/**
 * The combined map is the one place a customer can compare pickup points ACROSS couriers. What matters
 * is not that a map appears - it is that the dialog refuses to plot anything until it knows where, that
 * it really does carry more than one courier, and that choosing a point leaves the checkout in exactly
 * the state a manual courier + city + office selection would.
 *
 * Most of these set the city's value directly instead of clicking through select2, which keeps them
 * quick and stable. That shortcut has a cost, and it was paid: it sailed straight past a bug the very
 * first person to open the dialog hit. So ONE test picks the city with the mouse, and it is the one
 * that guards everything the shortcut cannot see.
 */

/**
 * Pick a city THROUGH select2, the way a customer does: open it, type, click a result.
 *
 * This test exists because the programmatic helper below hid a bug that the very first person to open
 * the dialog hit - select2 hung its dropdown off <body>, far below the overlay's z-index, so the list
 * opened underneath the dialog and no city could be chosen at all. Setting the value in JavaScript
 * sailed straight past it. Anything a customer must click, a test has to click.
 */
async function chooseCityByClicking(page, term, expectText) {
  const dlg = page.locator('.bgc-allmap-overlay');
  await dlg.locator('.select2-selection').click();
  await page.locator('input.select2-search__field').fill(term);
  // Wait for a REAL result, not select2's own "Searching…" row, which is also a
  // .select2-results__option and clicking it does nothing.
  const option = page.locator('.select2-results__option', { hasText: expectText }).first();
  await option.waitFor({ state: 'visible', timeout: 15000 });
  // Visible to Playwright is not enough: a dropdown painted under the overlay is still "visible".
  // Clicking it is the assertion - if anything covers it, this throws.
  await option.click({ timeout: 10000 });
  await page.waitForTimeout(400);
  return dlg.locator('.select2-selection').innerText();
}

/** Put a place into the dialog's city select the way its own handler expects. */
async function chooseCity(page, term, postCode) {
  return page.evaluate(async ([t, pc]) => {
    const $ = window.jQuery;
    const rows = await $.get(BGCOURIERS.ajax, { action: 'bgcouriers_allmap_cities', term: t });
    const r = rows.find(x => x.post_code === pc) || rows[0];
    if (!r) { return null; }
    $('.bgc-allmap-city')
      .append(new Option(r.name + ' (' + r.post_code + ')', r.name + '|' + r.post_code, true, true))
      .trigger('change');
    return r.name + ' (' + r.post_code + ')';
  }, [term, postCode]);
}

test('combined map: nothing is plotted until a place and a destination are chosen @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);

  await page.locator('.bgc-allmap-btn').click();
  const dlg = page.locator('.bgc-allmap-overlay');
  await expect(dlg).toBeVisible();

  // This is the rule the owner asked for: no city, no map.
  await expect(dlg.locator('.bgc-allmap-show')).toBeDisabled();
  await expect(dlg.locator('.bgc-allmap-body')).toBeHidden();

  const label = await chooseCity(page, 'София', '1000');
  expect(label).toBeTruthy();
  await dlg.locator('.bgc-allmap-type[data-m="office"]').click();
  await expect(dlg.locator('.bgc-allmap-show')).toBeEnabled();
});

test('combined map: the city can actually be picked with the mouse @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);
  await page.locator('.bgc-allmap-btn').click();

  const chosen = await chooseCityByClicking(page, 'София', 'СОФИЯ');
  expect(chosen).toContain('СОФИЯ');
  await expect(page.locator('.bgc-allmap-show')).toBeEnabled();
});

test('combined map: it carries several couriers at once, each with its own price @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);
  await page.locator('.bgc-allmap-btn').click();
  await chooseCity(page, 'София', '1000');
  await page.locator('.bgc-allmap-type[data-m="office"]').click();
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
});

test('combined map: choosing a point sets the courier, the city and the office @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);
  const before = await page.locator('input[name^="shipping_method"]:checked').inputValue();

  await page.locator('.bgc-allmap-btn').click();
  await chooseCity(page, 'София', '1000');
  await page.locator('.bgc-allmap-type[data-m="office"]').click();
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
    return { i, courier: pts[i].courier, officeId: pts[i].office.office_id };
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
  expect(await fields.getAttribute('data-method')).toBe('office');
  expect(String(await fields.locator('.bgc-office').inputValue())).toBe(String(pick.officeId));
});

/** The dialog is meant to be easier the second time: it remembers where you were looking. */
test('combined map: the place and destination survive a reload @allmap', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);
  await page.waitForTimeout(2500);
  await page.locator('.bgc-allmap-btn').click();
  await chooseCity(page, 'София', '1000');
  await page.locator('.bgc-allmap-type[data-m="automat"]').click();
  await page.locator('.bgc-allmap-close').click();

  await page.reload();
  await page.waitForTimeout(2500);
  await page.locator('.bgc-allmap-btn').click();
  const dlg = page.locator('.bgc-allmap-overlay');
  await expect(dlg.locator('.bgc-allmap-type[data-m="automat"]')).toHaveClass(/active/);
  await expect(dlg.locator('.bgc-allmap-show')).toBeEnabled();
});
