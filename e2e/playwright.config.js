// @ts-check
const { defineConfig, devices } = require('@playwright/test');
module.exports = defineConfig({
  testDir: './tests',
  // Turns dev's auto-labelling off for the run: six of these specs place a COD order, COD lands in
  // `processing`, and dev auto-labels that status against the couriers' LIVE accounts. See the file.
  globalSetup: require.resolve('./global-setup.js'),
  // One spec deliberately books a REAL shipment at a courier (intl-speedy-ro.spec.js) - the only way to
  // prove that a parcel to another country is accepted at all. It is left out of an ordinary run and
  // asked for by name:  BGC_REAL_WAYBILL=1 npx playwright test intl-speedy-ro
  grepInvert: process.env.BGC_REAL_WAYBILL === '1' ? undefined : /@books-real-waybill/,
  timeout: 90000,
  expect: { timeout: 15000 },
  retries: 1,
  workers: 1, // serial: every spec drives the same shared dev site + one Speedy account
  reporter: [['html', { open: 'never' }], ['list']],
  use: {
    baseURL: require('./config').baseURL(),
    headless: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
