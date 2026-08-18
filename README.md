# BG Couriers for WooCommerce

Ship with Bulgaria's couriers straight from WooCommerce - **Speedy, Econt, Pigeon Express, Sameday
and BOX NOW**. Your customer picks where the parcel goes and sees what it costs; you print the label
and follow the parcel without leaving WordPress.

**Install it from WordPress.org:** https://wordpress.org/plugins/bg-couriers/
Free, GPL, and staying that way - every courier, every feature, no paid tier. Deliveries are
Bulgaria-only.

![Every courier's offices and lockers for one town, each with its own price](.wordpress-org/screenshot-3.jpg)

### What your customer sees

Every courier you switch on shows its own price for the basket, live from that courier's API. The
customer picks how the parcel is delivered - **to an office, to an address, or to a locker/APS** -
and finds the office by typing the town, or by pointing at it on one map that carries every courier's
offices and lockers at once, each with its own price. The map can also say which pickup point is
closest to them, and what collecting from it saves against delivery to the door.

### What you get in the admin

One click issues the waybill and the label - one order, or fifty of them into a single PDF (A6 labels
or an A4 sheet). Each shipment's status is then kept up to date on its own, and your customer sees
the waybill and a tracking link on their order and in the e-mails you already send them. Cash on
delivery, several parcels per shipment, insurance and free-shipping thresholds are all settings, not
code.

### Setting it up

You need your own account with each courier you want to offer - the prices your customers see and
the labels you print are your own contract's, and nothing is resold through this plugin. Then, per
courier:

1. Paste the API credentials on that courier's tab and switch the courier on. It checks them with the
   courier there and then, and says so plainly if they are refused.
2. Sync its towns and offices - one button.
3. Add it to your **Bulgaria** shipping zone.

Everything else already has a working default. Getting the credentials is the slow part, and
[`docs/getting-api-credentials.md`](docs/getting-api-credentials.md) says who to ask, per courier.

Bugs and ideas: https://github.com/dangoriaynov/bg-couriers/issues

---

## Courier status

| Courier | Status | Notes |
|---|---|---|
| **Speedy** | ✅ Live on `main` | checkout (office/address/APS), live quotes, labels, tracking, settings |
| **Econt** | ✅ Live on `main` | + **наложен платеж (COD)** with itemised packing list (опис) & ППП money-transfer agreement - live-verified; E2E 7/7 with Speedy |
| **Pigeon Express** | ✅ Live on `main` | checkout (office/address), live quotes, labels; tracking live-verified against real shipments |
| **BOX NOW** | ✅ Live on `main` | locker-only, flat-rate, OAuth2, **map-widget** locker picker; full create → label → track → cancel cycle verified. Needs a prepaid gateway to be offered at checkout: it cannot do наложен платеж |
| **Sameday** | ✅ Live on `main` | checkout (address/APS), live quotes, labels, tracking; create → PDF → track → cancel verified against a live account (easyBox-only on it) |
| **Express One** | 📋 Planned | needs a **BG API key** + one live confirm (how a pickup-point selection encodes into `/createshipment`) |
| **Европът (Evropat-2000)** | 📋 Planned | API key is **self-service** from online.evropat.com; owner wrote to them 2026-08-17. No public API docs - they ship with the key. Their **online-module manual is read** and the domain is mapped (`docs/courier-api-notes.md`): **no lockers** (ОФ-ОФ/ОФ-ВР/ВР-ОФ/ВР-ВР only), НП + ППП both present, tariff weight is volumetric `w*l*h/6000` |
| **Български пощи** | ❌ Not planned | no public API - integration only under contract, and *no* Bulgarian integrator carries them (Izprati, CloudCart, SELITON, PRIM.IO all omit them). Dropped 2026-08-17 |

## Features in detail

- **Delivery types** per courier - to office / to address / to APS (locker) - as searchable checkout tabs
  (BoxNow is locker-only via its map widget).
- **Live pricing** from each courier's API, with a **daily reference baseline** (shown before a destination
  is picked) and a configured per-method fallback when the API is down. BoxNow is a flat configured rate
  (no rate API). Prices are net; WooCommerce adds 20% VAT once (no double-VAT).
- **Labels & tracking** - per-order + bulk "Generate labels" → one combined PDF (A6 label / A4 office),
  waybill + track link at the top of the order, copy-waybill in the orders list.
- **Econt COD (наложен платеж)** - itemised опис (seq / name / weight / qty / price), the ППП postal-money-
  transfer agreement, sum(price×count) reconciled to the collected amount; live-verified with a real waybill.
- **Cart shipping estimate** - optional per-courier + delivery-type estimate on the cart page.
- **Courier-aware checkout validation** - an order can't be placed without a valid, specified destination
  for **any** courier (BoxNow needs a locker; a selection made for one courier can't satisfy another),
  with clear per-courier error messages.
- **Emergency help** - a configurable help phone + message shown after repeated checkout failures.
- **One interactive map for every courier** - a bundled-Leaflet (no CDN) map showing every enabled
  courier's offices AND lockers for a town at once, each point priced for the way it is collected. The
  legend names and colours the couriers and doubles as a filter; there is a searchable list beside the
  map, "show my location", and a directions link per point. **Closest to you** (on by default, one
  General setting to switch off) sorts the list by distance, puts each courier's own nearest point on
  its legend badge, and answers the actual question in one line - which point is closest, what it
  costs, and what it saves against delivery to the door. The answer line is a button that goes to the
  point it means. Distances are haversine, computed in the browser over points the page already has. Choosing a point sets the courier, delivery
  type, town and office in one go. A courier's own "Map" button opens this same map filtered to it - the
  separate per-courier map was removed, because keeping two meant every fix had to land twice. BoxNow
  keeps its own GPS map widget, which is the only way to pick one of its lockers.
- **Settings** - one tab per courier (only the fields each courier actually uses), toggles tinted green/red,
  AJAX save with a toast, default courier, drag-to-order couriers + delivery options, hide-country,
  per-method free-shipping thresholds.

## Missing / roadmap

- **Courier pickup requests** - NOT built. A waybill says a parcel exists; the courier collects it only on a
  request naming those waybills for a day. **Speedy** (`POST /v1/pickup`, scope `EXPLICIT_SHIPMENT_ID_LIST`,
  plus `/pickup/terms` for the allowed windows) and **Econt** (`requestCourier` + `getRequestCourierStatus`)
  both support it, Европът has it in its web module, and Sameday/Pigeon/BOX NOW do not. Drusoft's plugins
  and ShipBG already do this. Field-level notes in `docs/courier-api-notes.md`.

- **Европът** - build once the API key arrives; it is generated by the merchant at online.evropat.com
  (or via `sales@evropat.com`), and the key is what unlocks their documentation and their own
  WooCommerce plugin. Whether they do lockers at all is still unconfirmed.
- **Not gaps, checked:** **DPD Bulgaria** is Speedy (GeoPost owns it; `dpd.com/bg` redirects to
  speedy.bg), **Лео Експрес** has ceased operating, and **PostOne** shows no sign of an API. With Еконт
  and Speedy the plugin already covers ~73% of the Bulgarian courier market by revenue.
- **Express One** - build once a **BG API key** arrives (`international@expressone.bg`); the one open shape is
  how a pickup-point selection encodes into `/createshipment`.
- **WordPress.org** - published; releases go out over SVN (`trunk` + `tags/<version>` + `assets/` for the
  banner, icon and screenshots). Background on how it got there is in
  `docs/wordpress-org-readiness-audit.md` and `docs/wporg-submission.md`.
- **Advanced courier parity** (post-launch) - Sameday per-service rows / 3-way pricing / open-package;
  BOX NOW home-delivery + any-APM + returns.

---

*Everything below is for whoever works on the plugin.*

## Architecture (multi-courier framework)

A small registry makes couriers pluggable; everything resolves a courier by id.

- **`BGCouriers_Couriers`** (`includes/Couriers/class-bgcouriers-couriers.php`) - registry: `register(id,label,factory)` /
  `get(id)` / `all()`; hooks the `bgcouriers_courier` filter. Couriers are registered in `BGCouriers_Plugin::__construct`.
- **Courier adapters** - `BGCouriers_Speedy`, `BGCouriers_Econt`, `BGCouriers_Pigeon`, `BGCouriers_Boxnow`, `BGCouriers_Sameday` extend
  **`BGCouriers_Abstract_Courier`** and implement **`BGCouriers_Courier_Interface`** (`id/label/capabilities/
  check_credentials/fetch_cities/fetch_offices/quote/create_label/get_label_pdf/track/tracking_url/
  cancel_label`). Parsers are pure static methods, unit-tested against fixtures (`tests/fixtures/<courier>/`).
- **Shipping methods** - `BGCouriers_Method_<Courier>` (`WC_Shipping_Method`, id `bgcouriers_<courier>`), registered into
  the Bulgaria zone via `woocommerce_shipping_methods`. Pricing via `BGCouriers_Pricing` (live quote → cached
  reference → configured default); method-level free-shipping threshold. BoxNow is a flat rate.
- **Checkout** (`includes/Checkout/`) - `render_fields` emits a courier-aware `.bgc-fields[data-courier]`
  block after each shipping rate (tabs office/address/**APS**; searchable city/office/street via selectWoo);
  BoxNow instead renders a "Choose a BOX NOW locker" button that opens the **map widget**. The JS shows only
  the **chosen** courier's block. Per-city availability greys + disables an option the city lacks. The
  office/APS dropdown is disabled until a city is chosen, then offices are preloaded per courier+city+type and
  cached client-side. The **selection is tagged with its courier** (`bgcouriers_selection_courier`) so switching
  couriers never shows a stale pick, and `validate()` blocks checkout without a valid destination. Pickers do
  not render on the cart page. Redundant standard WC address fields are removed; order address filled in
  `persist()`. Free-shipping notice follows the chosen courier.
- **Admin** (`includes/Admin/`) - settings, one section per courier (each shows only the fields it uses).
  Enable is a **top toggle**; courier + per-method tabs are pills tinted green/red; per-method enable lives on
  its sub-tab (method sub-tabs are hidden for single-method/flat-rate couriers like BoxNow). Credentials show a
  validated state. **AJAX Save** with a top-right toast. General has Default courier, Courier order
  (drag-sortable → `BGCouriers_Checkout::sort_rates`) and Hide-country. Order panel
  (waybill + generate/print/track), orders-list column, auto-generate on a trigger status.
- **Cache / pricing** (`includes/Cache/`) - `bgcouriers_cities` / `bgcouriers_offices` tables (`BGCouriers_Schema`),
  `BGCouriers_Nomenclature` repo. `BGCouriers_Sync` runs for all registered+enabled couriers: weekly full nomenclature
  sync + a daily reference-price refresh (`seed_rates`) cached in `BGCouriers_Rates`.
- **Support** (`includes/Support/`) - `BGCouriers_Quote` / `BGCouriers_Label` /
  `BGCouriers_Tracking` value objects, `BGCouriers_Encryption` (password at rest), `BGCouriers_Api_Exception`.
- **Settings/config** - `BGCouriers_Settings::courier_config(id)` reads `bgcouriers_<id>_*` options (password encrypted).
  Each courier uses its **own registered account sender** (Econt profile, Speedy account, BoxNow warehouse,
  Pigeon pickup office, Sameday pickup point) - there is no global sender setting.

## Development

Tests run inside `@wordpress/env` (Docker: PHP + WP + WooCommerce + MySQL).

```bash
bin/test                 # all PHPUnit (unit + integration) + E2E
bin/test speedy          # only @group speedy PHP tests + @speedy E2E specs
bin/test econt|pigeon|core  # per-courier / framework groups
# raw: npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/bg-couriers -- ./vendor/bin/phpunit --testsuite unit
#      BGC_SUITE=integration ... --testsuite integration      (--group sameday for one courier)
```

- **Per-courier test split:** every PHP test has a class-level `@group speedy|econt|pigeon|boxnow|sameday|core`.
- **wp-env gotcha:** the wrapper swallows the PHPUnit summary when piped - judge by **exit code 0**.
- **E2E:** Playwright (`e2e/`), plain JS, `workers:1`, against the live dev site.
- **Releasing.** Three scripts, run in this order; each refuses rather than half-doing the job.
  ```bash
  bin/preflight        # one version in all 3 places, changelog entry, clean+pushed tree,
                       # Bulgarian complete AND compiled, unit tests, nothing test-shaped tracked
  bin/release-prod     # backup (named for the version being REPLACED) → deploy → verify → purge → smoke
  bin/release-wporg    # refuses unless prod already runs this build and the tag is unpublished;
                       # Plugin Check on dev, audit the zip, then SVN trunk + tag, then confirm the
                       # directory really serves it
  ```
  Every check in `bin/preflight` stands in for something that has gone wrong here at least once; the
  comment above each says which.
- **Deploy to dev:** `bash bin/deploy.sh dev` then chown to the site user, activate via wp-admin (wp-cli /
  php-exec are blocked over SSH). The dev site URL/host + credentials + the server-side "probe" technique for
  live API checks are kept **privately, outside this repository** - never in it.

## Conventions & rules

- **Credentials never in chat / memory / VCS.** Courier API creds are entered server-side (encrypted in WP
  options) only. Use a courier sandbox where one exists. Real-account label tests create **real waybills** -
  logged in `docs/test-*-waybills.md` for the **owner** to cancel.
- **Clean-room:** original code only (no code copied from other plugins).

## Docs index

- `docs/wordpress-org-readiness-audit.md` - **pre-publish audit**: i18n / readme.txt / external-services gaps, findings, path to submission
- `docs/wporg-submission.md` - what was submitted to WordPress.org and the review correspondence
- `docs/getting-api-credentials.md` - **merchant-facing** guide to getting API access per courier
- `docs/courier-api-access.md` - which credentials each courier issues, and how to ask for them
- `docs/courier-api-notes.md` - technical API analysis / framework fit / divergences
- `docs/ui-conventions.md` - **read before adding any control**: why a field outside a section breaks four things at once, why descriptions must fit an (i), and what defaults to OFF
- `docs/testing.md` - running tests per courier · `docs/boxnow-testing.md` - BOX NOW stage-testing guide
- `docs/E2E-checklist.md` - the end-to-end pass to run against a real shop before a release
- `docs/test-{speedy,econt,boxnow,sameday}-waybills.md` - real/stage test waybills for the owner to cancel

## License

GPLv2-or-later - intended for a free **WordPress.org** release (see the readiness audit for what's left).
© Dan Goriaynov.
