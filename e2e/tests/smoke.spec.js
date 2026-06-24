const { test, expect } = require('@playwright/test');

test('dev site loads', async ({ page }) => {
  const resp = await page.goto('/');
  expect(resp && resp.status()).toBeLessThan(400);
  await expect(page).toHaveTitle(/.+/);
});
