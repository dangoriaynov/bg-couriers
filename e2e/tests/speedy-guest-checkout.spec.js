const { test, expect } = require('@playwright/test');
const { addAnyProductToCart, gotoCheckout, fillGuestBilling } = require('../helpers/shop');

test('speedy guest checkout to office, COD', async ({ page }) => {
  await addAnyProductToCart(page);
  await gotoCheckout(page);

  // On this dev site Speedy is the ONLY shipping method and is auto-selected
  // (rendered as hidden input, not a visible radio). No click needed.
  // Confirm the bgc-fields block is present (proves Speedy is active).
  const fields = page.locator('.bgc-fields');
  await expect(fields).toBeVisible({ timeout: 15000 });

  // "To office" is pre-checked by default; ensure it's checked.
  const officeRadio = fields.locator('input[name="bgc_method"][value="office"]');
  await expect(officeRadio).toBeChecked({ timeout: 5000 });

  // City selectWoo: the visible trigger is the .select2-selection inside
  // the .bgc-row that wraps .bgc-city.
  const cityRow = fields.locator('.bgc-row').filter({ has: page.locator('.bgc-city') });
  const citySelect2Trigger = cityRow.locator('.select2-selection');
  await citySelect2Trigger.click();

  // The search input appears in the dropdown.
  const search = page.locator('.select2-search__field');
  await expect(search).toBeVisible({ timeout: 10000 });
  await search.fill('София');

  // Wait for results and click the first one.
  const firstOpt = page.locator('.select2-results__option').first();
  await expect(firstOpt).toBeVisible({ timeout: 15000 });
  await firstOpt.click();

  // After the city is selected, offices auto-load and the first office is auto-selected.
  // (The fields re-render statefully on each update_checkout recalc, preserving the choice.)
  const officeRow = fields.locator('.bgc-office-row');
  await expect(officeRow).toBeVisible({ timeout: 20000 });

  // The office <select> must hold a real (auto-selected) office id before continuing —
  // a web-first poll instead of a fixed sleep (the manual select2 open was timing-flaky).
  await expect
    .poll(async () => await fields.locator('.bgc-office').inputValue(), { timeout: 20000 })
    .not.toBe('');

  // Let update_checkout recalculate shipping with the chosen office.
  await page.waitForTimeout(2000);

  // Assert shipping row shows a monetary amount.
  const shippingRow = page.locator('tr.shipping, .woocommerce-shipping-totals');
  await expect(shippingRow).toBeVisible({ timeout: 15000 });
  const shippingText = await shippingRow.innerText();
  expect(shippingText).toMatch(/\d/);

  // ── Step 3: Strengthen total assertion ──────────────────────────────────────
  // Parse amounts from the order review table. This dev site has VAT enabled so:
  //   total = subtotal + shipping + vat  (exact)
  //   total >= subtotal + shipping       (always true, our assertion)
  const parseAmount = async (selector) => {
    const el = page.locator(selector).first();
    await expect(el).toBeVisible({ timeout: 10000 });
    const text = await el.innerText();
    // Match first decimal number (comma or dot separator), e.g. "5,00" or "4.00".
    const match = text.match(/[\d]+[,.][\d]+/);
    return match ? parseFloat(match[0].replace(',', '.')) : 0;
  };

  const subtotal = await parseAmount('.cart-subtotal .woocommerce-Price-amount');
  const shipping = await parseAmount('.woocommerce-shipping-totals .woocommerce-Price-amount');
  const total    = await parseAmount('.order-total .woocommerce-Price-amount');

  // Optional VAT row (may not be present on all sites).
  let vat = 0;
  const vatEl = page.locator('tr.tax-rate .woocommerce-Price-amount, tr.tax_total .woocommerce-Price-amount').first();
  if (await vatEl.count() > 0) {
    const vatText = await vatEl.innerText();
    const vm = vatText.match(/[\d]+[,.][\d]+/);
    vat = vm ? parseFloat(vm[0].replace(',', '.')) : 0;
  }

  console.log(`Subtotal: ${subtotal}, Shipping: ${shipping}, VAT: ${vat}, Total: ${total}`);
  expect(subtotal).toBeGreaterThan(0);
  expect(shipping).toBeGreaterThan(0);
  expect(total).toBeGreaterThanOrEqual(subtotal + shipping - 0.05);
  // If VAT is present verify it is accounted for in the total.
  if (vat > 0) {
    expect(total).toBeCloseTo(subtotal + shipping + vat, 1);
  } else {
    expect(total).toBeCloseTo(subtotal + shipping, 1);
  }

  // ── Fill billing ─────────────────────────────────────────────────────────────
  await fillGuestBilling(page, {
    first: 'Тест',
    last: 'Клиент',
    email: 'e2e@example.com',
    phone: '0888123456',
  });

  // COD is the only gateway and is auto-selected. Place the order.
  await page.locator('#place_order').click();

  // ── Step 4: Order-received assertions ────────────────────────────────────────
  // WC submits via AJAX then redirects; toHaveURL auto-waits for the redirect.
  // (Match only the order-received segment — /porachka-2/ alone is the checkout page.)
  await expect(page).toHaveURL(/order-received/i, { timeout: 30000 });

  // The thank-you order block shows the order number, the Speedy shipping line and the total.
  const order = page.locator('.woocommerce-order').first();
  await expect(order).toBeVisible({ timeout: 15000 });
  await expect(order).toContainText(/\d/);       // order number / amounts present
  await expect(order).toContainText(/Speedy/i);  // shipping method carried through to the order
});
