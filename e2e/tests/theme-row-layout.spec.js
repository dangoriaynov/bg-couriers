const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, selectShippingMethod } = require('../helpers/shop');

/**
 * The chosen courier's delivery fields sit UNDER its name, whatever the theme does to the row.
 *
 * Reported with a screenshot on 2026-09-11: the courier's name and price floating in the left half of
 * the card, the tabs and the town/office fields squeezed into the right half, "5,12 €Град" run
 * together. That is what a theme gets by laying each shipping <li> out as a flex row so the radio and
 * the label share a line - a common trick - while this plugin's fields only said `display:block;
 * width:100%` and HOPED the row was a block. In a flex row the label and the fields become neighbours
 * and split the width between them.
 *
 * The hostile rule below is written the way a theme writes it - scoped under a class, so it outranks
 * the plugin's own `ul#shipping_method > li` on specificity, not merely on load order. Measured by
 * geometry, not by eye: the fields start below the label's bottom edge and span the card.
 */
const HOSTILE = {
  flex: [
    '.woocommerce-checkout #shipping_method > li { display: flex; align-items: center; }',
    '.woocommerce-checkout #shipping_method > li > label { flex: 1 1 auto; }',
  ].join('\n'),
  // A theme that wins the display back outright: the column direction is inert on a grid, so the
  // children have to say for themselves that each takes the whole row.
  grid: '.woocommerce-checkout #shipping_method > li { display: grid !important; grid-template-columns: 1fr auto; align-items: center; }',
};

async function boxes(page) {
  const li = page.locator('ul#shipping_method > li:has(input[value^="bgcouriers_econt"])').first();
  const label = li.locator('> label');
  const fields = li.locator('> .bgc-fields');
  return { li: await li.boundingBox(), label: await label.boundingBox(), fields: await fields.boundingBox() };
}

function expectFieldsUnderLabel({ li, label, fields }) {
  expect(li && label && fields, 'the chosen courier row, its label and its fields are all rendered').toBeTruthy();
  // Below, not beside: the top of the fields is at or under the bottom of the label.
  expect(fields.y).toBeGreaterThanOrEqual(label.y + label.height - 1);
  // And the full width of the card, give or take the card's own padding.
  expect(fields.width).toBeGreaterThan(li.width * 0.9);
  expect(label.width).toBeGreaterThan(li.width * 0.9);
}

test.describe('courier fields under the courier name @layout', () => {
  for (const [name, width, kind] of [['phone', 390, 'flex'], ['tablet', 768, 'flex'], ['desktop', 1280, 'flex'], ['phone', 390, 'grid']]) {
    test(`${name} ${width}px, with a theme that makes the row a ${kind}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 1000 });
      await addAnyProductToCart(page);
      await gotoCheckout(page);
      await page.addStyleTag({ content: HOSTILE[kind] });
      await selectShippingMethod(page, 'econt');
      const fields = page.locator('.bgc-fields[data-courier="econt"]');
      await expect(fields).toBeVisible({ timeout: 15000 });
      await page.waitForTimeout(600); // the fields slide in (.bgc-ready transition)
      expectFieldsUnderLabel(await boxes(page));
      // The page itself never scrolls sideways.
      const w = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
      expect(w[0]).toBeLessThanOrEqual(w[1]);
    });
  }

  test('phone 390px, the plain theme', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 1000 });
    await addAnyProductToCart(page);
    await gotoCheckout(page);
    await selectShippingMethod(page, 'econt');
    await expect(page.locator('.bgc-fields[data-courier="econt"]')).toBeVisible({ timeout: 15000 });
    await page.waitForTimeout(600);
    expectFieldsUnderLabel(await boxes(page));
  });
});
