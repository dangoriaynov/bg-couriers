# bg-couriers E2E (Playwright)
Runs against a live site (default https://dev.dobavki.club).

## Run
    cd e2e && npm install && npx playwright install chromium
    npx playwright test            # all
    npx playwright test --headed   # watch
    npx playwright show-report

## Prerequisites on the target site
Speedy enabled + valid creds + **Sync now** run (cities/offices cached); the
"Speedy" (bgc_speedy) method added to the Bulgaria shipping zone; Cash on Delivery
enabled; ≥1 purchasable product. Login flows (later) read creds from `e2e/.env`.
