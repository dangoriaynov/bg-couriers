const { expect } = require('@playwright/test');

async function dismissStoreBanner(page) {
  // The WooCommerce demo store notice overlays the page and blocks clicks.
  await page.evaluate(() => {
    const el = document.querySelector('.woocommerce-store-notice, p.demo_store');
    if (el) { el.style.display = 'none'; el.remove(); }
  });
}

async function addAnyProductToCart(page) {
  // First visit the shop page to discover the product ID, then use a direct
  // add-to-cart URL to reliably add it without fighting overlay widgets.
  await page.goto('/?post_type=product');
  await page.waitForTimeout(1000);

  // Extract the first product's ID from the add-to-cart button data attribute.
  const productId = await page.evaluate(() => {
    const btn = document.querySelector('[data-product_id]');
    return btn ? btn.getAttribute('data-product_id') : null;
  });

  if (productId) {
    // Direct URL add-to-cart — bypasses all click overlays.
    await page.goto(`/?add-to-cart=${productId}`);
    await page.waitForTimeout(1500);
  } else {
    // Fallback: navigate to the known test product.
    await page.goto('/?add-to-cart=66');
    await page.waitForTimeout(1500);
  }
}

async function gotoCheckout(page) {
  // The WooCommerce checkout page on this dev site has slug /porachka-2/
  await page.goto('/porachka-2/');
  await expect(page.locator('form.checkout, form[name="checkout"]')).toBeVisible({ timeout: 20000 });
}

async function fillGuestBilling(page, d) {
  // Only name/phone/email — the WC address fields are hidden + optional when Speedy is active
  // (the plugin's own fields drive the address). Filling visible fields only.
  const set = async (sel, val) => {
    const el = page.locator(sel).first();
    if (await el.count() && await el.isVisible().catch(() => false)) { await el.fill(val); }
  };
  await set('#billing_first_name', d.first);
  await set('#billing_last_name', d.last);
  await set('#billing_email', d.email);
  await set('#billing_phone', d.phone);
}

// Select a bgc_<courier> shipping method radio (so its .bgc-fields block becomes the active one).
// WC re-renders the rate radios on every recalc, so a plain .check() races the re-render; we let
// the initial review settle, skip if already selected, and force-click to bypass the stability wait.
async function selectShippingMethod(page, courierId) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2000); // let the initial order-review render settle
  const sel = `input[name^="shipping_method"][value^="bgc_${courierId}"]`;
  try {
    const radio = page.locator(sel).first();
    await radio.waitFor({ state: 'attached', timeout: 15000 });
    if (await radio.isChecked()) { return; } // already the chosen method
    await radio.check({ force: true });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(2000);
  } catch (e) { /* re-render race — best effort; the assert on .bgc-fields visibility follows */ }
}

// Click a delivery-type tab (office | address | automat) within a courier's fields block.
async function selectSpeedyTab(page, fields, methodName) {
  await fields.locator(`.bgc-tab[data-method="${methodName}"]`).click();
  await expect(fields.locator(`.bgc-tab[data-method="${methodName}"].active`)).toBeVisible({ timeout: 10000 });
}

// Open the city selectWoo, type a term, pick the first result.
async function selectCity(page, fields, term) {
  await fields.locator('.bgc-city-field .select2-selection').click();
  const search = page.locator('.select2-search__field');
  await expect(search).toBeVisible({ timeout: 10000 });
  await search.fill(term);
  const first = page.locator('.select2-results__option[role="option"]').first();
  await expect(first).toBeVisible({ timeout: 15000 });
  await first.click();
}

// Open the office/automat selectWoo (AJAX, live per-city) and pick the first office.
async function pickFirstOffice(page, fields) {
  await fields.locator('.bgc-office-row .select2-selection').click();
  const opt = page.locator('.select2-results__option[role="option"]').first();
  await expect(opt).toBeVisible({ timeout: 20000 }); // waits past the "Searching…" transient
  await page.waitForTimeout(600);
  await opt.click();
}

// Open the street autocomplete (selectWoo), type a term, pick the first suggestion.
async function fillStreet(page, fields, term) {
  await fields.locator('.bgc-street-field .select2-selection').click();
  const search = page.locator('.select2-search__field');
  await expect(search).toBeVisible({ timeout: 10000 });
  await search.fill(term);
  const opt = page.locator('.select2-results__option[role="option"]').first();
  await expect(opt).toBeVisible({ timeout: 15000 });
  await opt.click();
}

module.exports = { addAnyProductToCart, gotoCheckout, fillGuestBilling, dismissStoreBanner, selectShippingMethod, selectSpeedyTab, selectCity, pickFirstOffice, fillStreet };
