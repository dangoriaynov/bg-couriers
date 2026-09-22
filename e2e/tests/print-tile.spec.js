const { test, expect } = require('@playwright/test');

/**
 * The green "not printed yet" print tile (assets/js/bgc-orders-list.js) turning plain on the click.
 *
 * A harness page rather than wp-admin, like admin-tips.spec.js: the suite has no admin login, and the
 * behaviour under test is DOM-only. The script is pulled from the deployed dev site, so this tests what
 * is actually served. Three doors lead to a print and all three used to leave the tile green until a
 * reload (the PDF opens elsewhere; the list stays): the row's own tile, the "Print N on A4 / A6" links
 * after a bulk generate (they carry data-ids), and the bulk print action's Apply button.
 */
const BASE = process.env.BGC_BASE || require('../config').baseURL();
const ASSETS = BASE + '/wp-content/plugins/bg-couriers/assets';

const tile = (id) => `<a class="bgc-ico bgc-primary bgc-unprinted" target="_blank"
  href="http://x/admin-post.php?action=bgcouriers_print_batch&order_id=${id}&_wpnonce=n"
  data-tip="Print label (not printed yet)" aria-label="Print label (not printed yet)" id="t${id}">p</a>`;

async function harness(page) {
  await page.setContent(`<!doctype html><html><body>
    <form id="posts-filter" onsubmit="return false">
      <select id="bulk-action-selector-top"><option value="-1">-</option><option value="bgcouriers_print_a4">A4</option></select>
      <input type="submit" id="doaction" value="Apply">
      <table><tbody>
        <tr><th class="check-column"><input type="checkbox" id="cb1" checked></th><td>${tile(1)}</td></tr>
        <tr><th class="check-column"><input type="checkbox" id="cb2"></th><td>${tile(2)}</td></tr>
        <tr><th class="check-column"><input type="checkbox" id="cb3"></th><td>${tile(3)}</td></tr>
        <tr><th class="check-column"><input type="checkbox" id="cb4"></th><td>${tile(4)}</td></tr>
      </tbody></table>
    </form>
    <div class="notice"><a class="button" id="batch" href="javascript:void(0)" data-ids="3,4">Print 2 on A4</a></div>
  </body></html>`);
  await page.evaluate(() => {
    window.BGCOURIERS_LIST = { i18n: { print: 'Print label' }, printActions: ['bgcouriers_print_a4', 'bgcouriers_print_a6'] };
    // The tiles would open a tab; the harness only wants the click to reach the script.
    document.querySelectorAll('a.bgc-primary').forEach((a) => a.addEventListener('click', (e) => e.preventDefault()));
  });
  await page.addScriptTag({ url: `${ASSETS}/js/bgc-orders-list.js` });
}

const green = (page, id) => page.locator(`#t${id}`).evaluate((el) => el.classList.contains('bgc-unprinted'));
const tip = (page, id) => page.locator(`#t${id}`).getAttribute('data-tip');

test('the row tile turns plain on its own click, and only that one', async ({ page }) => {
  await harness(page);
  await page.click('#t2');
  expect(await green(page, 2)).toBe(false);
  expect(await tip(page, 2)).toBe('Print label');
  expect(await green(page, 1)).toBe(true);
  expect(await tip(page, 1)).toBe('Print label (not printed yet)');
});

test('the batch print links turn their own orders plain', async ({ page }) => {
  await harness(page);
  await page.click('#batch');
  expect(await green(page, 3)).toBe(false);
  expect(await green(page, 4)).toBe(false);
  expect(await green(page, 1)).toBe(true);
  expect(await green(page, 2)).toBe(true);
});

test('the bulk print action turns the checked rows plain as the form goes', async ({ page }) => {
  await harness(page);
  await page.selectOption('#bulk-action-selector-top', 'bgcouriers_print_a4');
  await page.click('#doaction');
  expect(await green(page, 1)).toBe(false);
  expect(await green(page, 2)).toBe(true);
});

test('a bulk action that does not print leaves the tiles alone', async ({ page }) => {
  await harness(page);
  await page.click('#doaction'); // "-1" selected
  expect(await green(page, 1)).toBe(true);
});
