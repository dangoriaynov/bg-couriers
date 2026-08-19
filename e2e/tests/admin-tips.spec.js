const { test, expect } = require('@playwright/test');

/**
 * The admin hover hints (assets/js/bgc-tip.js).
 *
 * These run against a harness page rather than wp-admin, because the e2e suite has no admin login and
 * the thing under test does not need one: the bubble is plain DOM behaviour driven by [data-tip]. The
 * harness reproduces the two shapes that were broken - a DIMMED control, and a control at the very edge
 * of the window - and asserts in pixels, since every one of these bugs rendered something that was
 * technically "visible".
 *
 * The stylesheet and script are pulled from the deployed dev site, so this tests what is actually
 * being served rather than a local copy.
 */
const BASE = process.env.BGC_BASE || require('../config').baseURL();
const ASSETS = BASE + '/wp-content/plugins/bg-couriers/assets';

/** A stand-in for the orders-list cell: one dimmed action, one ordinary button, one anchor. */
async function harness(page, extraStyle = '') {
  await page.setContent(`<!doctype html><html><body style="margin:0;height:2000px">
    <div class="bgc-cell" style="position:absolute;top:300px;left:0;width:100%;
         display:flex;justify-content:space-between">
      <a class="bgc-ico bgc-off" data-tip="Пратката е предадена на куриера - редакцията е спряна."
         aria-label="Пратката е предадена на куриера - редакцията е спряна."
         style="opacity:.4">edit</a>
      <button class="bgc-act" data-tip="Обнови статуса">refresh</button>
      <a class="bgc-ico" id="edge" data-tip="Този надпис е достатъчно дълъг, за да излезе извън екрана ако не бъде преместен обратно.">x</a>
    </div>
    <div class="bgc-cell" style="position:absolute;top:2px;left:50%">
      <button id="top" data-tip="Близо до горния ръб">t</button>
    </div>
    <style>${extraStyle}</style></body></html>`);
  await page.addStyleTag({ url: `${ASSETS}/css/bgc-tip.css` });
  await page.addScriptTag({ url: `${ASSETS}/js/bgc-tip.js` });
  await page.waitForTimeout(300);
}

/** Where the one bubble is, and what it looks like, right now. */
async function bubble(page) {
  return page.evaluate(() => {
    const b = document.querySelector('.bgc-tipbox');
    if (!b) { return null; }
    const r = b.getBoundingClientRect();
    const cs = getComputedStyle(b);
    return { on: b.classList.contains('bgc-tip-on'), below: b.classList.contains('bgc-tip-below'),
             text: b.textContent, opacity: Number(cs.opacity), align: cs.textAlign,
             left: Math.round(r.left), right: Math.round(r.right),
             top: Math.round(r.top), bottom: Math.round(r.bottom),
             parent: b.parentElement.tagName.toLowerCase() };
  });
}

test('admin hints: the explanation of a BLOCKED action is not dimmed with it @tips', async ({ page }) => {
  await harness(page);
  // This is the bug that mattered. The hint used to be an ::after ON the control, so a control dimmed
  // to opacity .4 *because it is blocked* printed the reason it was blocked at .4 as well - grey on
  // grey, exactly where the merchant needs to read it.
  await page.locator('.bgc-off').hover();
  await page.waitForTimeout(250);
  const b = await bubble(page);
  expect(b, 'a bubble must be drawn').not.toBeNull();
  expect(b.on).toBe(true);
  expect(b.text).toContain('предадена на куриера');
  expect(b.opacity).toBe(1);
  // It is on <body>, not inside the dimmed control - which is what makes the opacity above possible.
  expect(b.parent).toBe('body');
});

test('admin hints: a hint on the last control stays inside the window @tips', async ({ page }) => {
  await page.setViewportSize({ width: 900, height: 700 });
  await harness(page);
  // The waybill column sits at the right edge of the orders table, so a bubble centred on its last
  // tile used to run off the screen and get cut off.
  await page.locator('#edge').hover();
  await page.waitForTimeout(250);
  const b = await bubble(page);
  expect(b.on).toBe(true);
  expect(b.left).toBeGreaterThanOrEqual(0);
  expect(b.right).toBeLessThanOrEqual(900);
});

test('admin hints: a hint near the top of the window flips below the control @tips', async ({ page }) => {
  await harness(page);
  await page.locator('#top').hover();
  await page.waitForTimeout(250);
  const b = await bubble(page);
  expect(b.on).toBe(true);
  expect(b.below, 'no room above, so it must open downwards').toBe(true);
  expect(b.top).toBeGreaterThanOrEqual(0);
});

test('admin hints: one bubble, reused, and it reads the same on a button as on a link @tips', async ({ page }) => {
  await harness(page);
  await page.locator('.bgc-act').hover();
  await page.waitForTimeout(250);
  const onButton = await bubble(page);
  await page.locator('.bgc-off').hover();
  await page.waitForTimeout(250);
  const onAnchor = await bubble(page);

  // A <button> centres its text and an <a> does not, so as an ::after the same sentence was laid out
  // two different ways in one row. The bubble sets its own alignment now.
  expect(onButton.align).toBe('left');
  expect(onAnchor.align).toBe(onButton.align);
  // One element on the page, moved and refilled - not one per control.
  expect(await page.locator('.bgc-tipbox').count()).toBe(1);
  expect(onAnchor.text).not.toBe(onButton.text);

  // And it goes away again.
  await page.mouse.move(5, 5);
  await page.waitForTimeout(250);
  expect((await bubble(page)).on).toBe(false);
});
