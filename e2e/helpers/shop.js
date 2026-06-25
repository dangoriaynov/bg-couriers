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
  const set = async (sel, val) => { const el = page.locator(sel); if (await el.count()) { await el.first().fill(val); } };
  await set('#billing_first_name', d.first);
  await set('#billing_last_name', d.last);
  await set('#billing_email', d.email);
  await set('#billing_phone', d.phone);
  await set('#billing_city', d.city || 'София');
  await set('#billing_address_1', d.address || 'ул. Тест 1');
  await set('#billing_postcode', d.postcode || '1000');
}

// Click a Speedy delivery-type tab (office | address | automat).
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

module.exports = { addAnyProductToCart, gotoCheckout, fillGuestBilling, dismissStoreBanner, selectSpeedyTab, selectCity, pickFirstOffice };
