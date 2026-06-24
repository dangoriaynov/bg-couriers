const { expect } = require('@playwright/test');

async function addAnyProductToCart(page) {
  await page.goto('/?post_type=product');
  // Click the first "add to cart" on the shop archive.
  const addBtn = page.locator('a.add_to_cart_button, button.add_to_cart_button, a.ajax_add_to_cart').first();
  await addBtn.scrollIntoViewIfNeeded();
  await addBtn.click();
  // Wait for cart to register (ajax) — then go to cart to confirm.
  await page.waitForTimeout(1500);
}

async function gotoCheckout(page) {
  await page.goto('/checkout/');
  await expect(page.locator('form.checkout, form[name="checkout"]')).toBeVisible();
}

async function fillGuestBilling(page, d) {
  const set = async (sel, val) => { const el = page.locator(sel); if (await el.count()) { await el.first().fill(val); } };
  await set('#billing_first_name', d.first);
  await set('#billing_last_name', d.last);
  await set('#billing_email', d.email);
  await set('#billing_phone', d.phone);
  await set('#billing_city', d.city || 'Sofia');
  await set('#billing_address_1', d.address || 'Test 1');
}

module.exports = { addAnyProductToCart, gotoCheckout, fillGuestBilling };
