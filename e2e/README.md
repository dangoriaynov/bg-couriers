# bg-couriers E2E (Playwright)
Runs against a live site - your own dev shop. Its address goes in `bin/deploy.conf` as
`BGC_E2E_BASE_URL` (or `BASE_URL=...` for one run); there is no default, and the suite refuses to
start without one. These specs place COD orders, and a COD order on a site with live courier
credentials books a real shipment.

## Run
    cd e2e && npm install && npx playwright install chromium
    npx playwright test            # all
    npx playwright test --headed   # watch
    npx playwright show-report

## The one spec that books a real shipment

`intl-speedy-ro.spec.js` places an order to **Romania** and then books the waybill for it at Speedy, on
purpose. Speedy's domestic service and its international one are mutually exclusive - each is refused
where the other applies - so a waybill coming back is the only proof that the international one was used.
It is left out of an ordinary run and asked for by name:

    BGC_REAL_WAYBILL=1 npx playwright test intl-speedy-ro

It voids the waybill again in `afterEach` and prints the number either way. Dev must be set up for it
first: Romania switched on in the Speedy settings + **Sync now** run since, and Romania in a WooCommerce
shipping zone that carries the Speedy method. Without those the spec fails and says which one is missing.

## Prerequisites on the target site
Speedy enabled + valid creds + **Sync now** run (cities/offices cached); the
"Speedy" (bgc_speedy) method added to the Bulgaria shipping zone; Cash on Delivery
enabled; ≥1 purchasable product. Login flows (later) read creds from `e2e/.env`.

## The block checkout

`blocks-checkout.spec.js` runs against `/blocks-checkout-test/` on dev - a standalone page carrying the
WooCommerce Checkout **block**, created for this on purpose. The shop's own checkout page stays classic,
so the spec proves the Store API gate without reconfiguring the site under itself.

If that page is ever lost, recreate it:

```bash
wp post create --post_type=page --post_status=publish --post_title='Blocks checkout test' \
  --post_name=blocks-checkout-test \
  --post_content='<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading"></div><!-- /wp:woocommerce/checkout -->'
```
