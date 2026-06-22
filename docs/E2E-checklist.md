# Speedy slice — manual E2E on dev.dobavki.club
1. `./bin/deploy.sh dev` → activate "BG Couriers" in wp-admin.
2. WooCommerce → BG Couriers: enable Speedy, set env, paste creds (copied server-side from prod, never via chat), Save → "Validate credentials" shows OK.
3. "Sync now" → cities/offices/standard_rates tables populate (verify via mysql: `SELECT COUNT(*) FROM wp_bgc_cities;`).
4. WooCommerce → Settings → Shipping → add "Speedy" to the Bulgaria zone.
5. Front-end: add product → checkout → choose Speedy → type postcode (e.g. 9300) → city auto-fills → pick office → price (BGN+EUR) and order total update.
6. Disable network to Speedy (set bogus creds) → reload checkout → price still shows from cache, order can still be placed.
7. Place order → wp-admin order → "Generate label" → waybill + Print (PDF opens) → "Track" opens Speedy tracking.
8. Toggle dual currency OFF → only one currency shows; order totals + WooCommerce → Analytics unchanged.
