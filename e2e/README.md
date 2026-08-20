# bg-couriers E2E (Playwright)

Runs against a live site - your own dev shop, with the couriers' real accounts behind it. Its address
goes in `bin/deploy.conf` as `BGC_E2E_BASE_URL` (or `BASE_URL=...` for one run); there is no default,
and the suite refuses to start without one.

**What a run does to that site.** It places real orders, and it leaves them there. What it no longer
does is book shipments: `global-setup.js` turns dev's `bgcouriers_autolabel_enabled` off for the length
of the run and puts it back afterwards. Six of these specs pay by cash on delivery, WooCommerce puts a
COD order straight into `processing`, and dev auto-labels that status - so a full run used to book six
real parcels every time, with nobody meaning to. The setup needs SSH to dev to do that (same
`bin/deploy.conf`) and **refuses to start without it** rather than running blind; `BGC_ALLOW_AUTOLABEL=1`
overrides that, and is only honest if you have turned the setting off yourself. A run pointed at anything
other than that dev address - a local wp-env, your own copy - says so and leaves its settings alone: only
the shared dev site has live courier accounts behind it.

Afterwards it sweeps dev for waybills and prints any it finds, even when every line says CANCELLED - a
cancel that reported success and did not take looks exactly like one that worked, so the numbers belong
on screen where someone can re-check them at the courier.

## Run
    cd e2e && npm install && npx playwright install chromium
    npx playwright test            # everything except the real-waybill spec
    npx playwright test --headed   # watch
    npx playwright show-report

From the repo root, `bin/test` runs PHPUnit and then this suite, and `bin/test speedy` narrows both to
one courier. Neither of them runs the real-waybill spec: `grepInvert` in `playwright.config.js` holds it
back until it is asked for by name.

## Prerequisites on the target site
- Speedy enabled with valid credentials, and **Sync now** run since (cities/offices cached).
- The **Speedy** method (`bgcouriers_speedy`) added to the shipping zone that covers Bulgaria.
- Cash on delivery enabled, and at least one purchasable product.
- SSH to dev configured in `bin/deploy.conf` - `global-setup.js` and `dev-option.sh` both need it.

## Delivery to another country: the one spec that books a real shipment

`intl-speedy-ro.spec.js` places an order to **Romania** and then books the waybill for it at Speedy, on
purpose. Speedy's domestic service (505) and its international one (202) are mutually exclusive - each is
refused outright where the other applies - so a waybill coming back is the only proof that the
international one was used. A mistake here does not produce a wrong label, it produces no label at all.

Dev needs three things first, and none of them is something the plugin can do for itself:

1. **Speedy → "Also deliver to" includes Romania** (`bgcouriers_speedy_intl_countries`), saved, and
   **Sync now** pressed afterwards. A courier's towns and offices are cached per country; without the
   sync the Romanian ones are simply not there.
2. **Romania in a WooCommerce shipping zone that carries the Speedy method.** Without it Speedy is not
   offered for the address at all and the order cannot be placed.
3. At least one purchasable product.

Each is asserted in the spec with the reason rather than skipped past: it is only ever run on purpose,
and a silent skip would answer "does it work?" with "nothing happened".

    cd e2e && BGC_REAL_WAYBILL=1 npx playwright test intl-speedy-ro

The order is **prepaid**, and that is the feature rather than a convenience: cash on delivery here is
receipted through Speedy's ППП, no courier's ППП crosses the border, and Speedy refuses the whole quote
when asked for one abroad - so the plugin takes COD off the checkout for a foreign address, and the spec
asserts that too. The spec switches dev's bank transfer on for its own run and afterwards puts it back
the way it FOUND it (`dev-option.sh gateway bacs [yes|no]`, which reads with no value given) - a payment
method left enabled changes what every other spec sees, and one left disabled is worse: it is how dev
came to show the next person who picked a foreign country an empty delivery box (below).

It prints, and you should read, three lines:

    [intl] order total with Romanian delivery: …
    [intl] WAYBILL 63712538954 speedy
    [intl] order 394: CANCELLED 63712538954

The waybill is voided in `afterEach` and the number is printed whether the cancel worked or not. Re-check
it at Speedy a few minutes later anyway - the suite's own sweep is the second net, not a guarantee.

## A country the shop cannot deliver to

`intl-no-delivery.spec.js` drives the other side of the same feature, and books nothing: with no prepaid
gateway on the shop, a ППП checkout has no way at all to be paid abroad, so every rate for the foreign
address is refused on purpose - and the country picker, which is drawn underneath a rate, goes with them.
What the customer was left with was WooCommerce's stock "please ensure that your address has been entered
correctly", about an address with nothing wrong with it, and nothing on the page to change it back with.

The spec asserts the message names the country and carries the way back out, follows that link, and
checks the delivery options return. It also asserts the **payment box** below it: with cash on delivery
correctly removed and nothing prepaid to replace it, WooCommerce fills that box with its own "Sorry, it
seems that there are no available payment methods" - a stock English sentence on a Bulgarian shop, naming
neither the cause nor a way out. The plugin replaces it (`.bgc-no-payment`) with the same reason and the
same link, so the screen ends in an exit rather than a dead stop. It forces bank transfer **off** for its own length (and back to what
it found), because with a prepaid method there is no dead end to test, and it asserts dev is in ППП mode
first rather than failing later for the wrong reason. It runs with every other spec.

## Which payment method a spec uses

Every COD spec calls `choosePayment(page, 'cod')` before placing its order. It used to rely on the shop
having exactly one gateway enabled, which is a promise nobody made: switch bank transfer on - and the
international spec does that on every run - and those six specs would place bank-transfer orders instead
and keep passing, testing the wrong thing in silence.

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
