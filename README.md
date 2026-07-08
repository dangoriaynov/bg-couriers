# BG Couriers for WooCommerce

WooCommerce shipping plugin for Bulgarian couriers. Ships **to Bulgaria only**. Lets the customer
pick a delivery type (to office / to address / to APS·locker) per courier at checkout, computes
**live** prices from each courier's API, and lets the merchant generate shipping labels + track
shipments from the WordPress admin.

> **Context anchor:** this README + the project memory + `docs/superpowers/{specs,plans}` are the
> source of truth for picking the work back up after a restart. **Keep them updated as work proceeds.**

## Courier status

| Courier | Status | Notes |
|---|---|---|
| **Speedy** | ✅ Live on `main` | checkout (office/address/APS), live quotes, labels, tracking, settings |
| **Econt** | ✅ Live on `main` | + **наложен платеж (COD)** with itemised packing list (опис) & ППП money-transfer agreement — live-verified; E2E 7/7 with Speedy |
| **Pigeon Express** | ✅ Built on `main` | adapter + method + settings + `@group pigeon` tests (OpenAPI-derived); live-verify pending credentials |
| **BOX NOW** | ✅ Built on `main` | locker-only, flat-rate, OAuth2, **map-widget** locker picker; stage-verified (test parcels to APM 8009) |
| **Sameday** | 🟡 Built on `feat/sameday` — **unverified** | adapter + method + settings + tests (SDK-derived). **Auth live-confirmed** against the demo host; the rest of the JSON shapes need a demo login to verify, then merge to `main` |
| **Express One** | 📋 Planned | turn-key `plans/2026-06-29-expressone-phase4.md` + fixtures; needs a **BG API key** + one live confirm (pickup-point → createshipment encoding) |

## Features (what's built)

- **Delivery types** per courier — to office / to address / to APS (locker) — as searchable checkout tabs
  (BoxNow is locker-only via its map widget).
- **Live pricing** from each courier's API, with a **daily reference baseline** (shown before a destination
  is picked) and a configured per-method fallback when the API is down. BoxNow is a flat configured rate
  (no rate API). Prices are net; WooCommerce adds 20% VAT once (no double-VAT).
- **Labels & tracking** — per-order + bulk "Generate labels" → one combined PDF (A6 label / A4 office),
  waybill + track link at the top of the order, copy-waybill in the orders list.
- **Econt COD (наложен платеж)** — itemised опис (seq / name / weight / qty / price), the ППП postal-money-
  transfer agreement, sum(price×count) reconciled to the collected amount; live-verified with a real waybill.
- **Dual currency** — optional BGN + EUR (fixed peg 1.95583) on shipping labels & the cart estimate;
  price/threshold settings show the store-currency unit.
- **Cart shipping estimate** — optional per-courier + delivery-type estimate on the cart page.
- **Courier-aware checkout validation** — an order can't be placed without a valid, specified destination
  for **any** courier (BoxNow needs a locker; a selection made for one courier can't satisfy another),
  with clear per-courier error messages.
- **Emergency help** — a configurable help phone + message shown after repeated checkout failures.
- **Interactive map locker picker** — BoxNow's GPS map widget today (a unified GPS map for the other
  couriers' offices is planned).
- **Settings** — one tab per courier (only the fields each courier actually uses), toggles tinted green/red,
  AJAX save with a toast, default courier, drag-to-order couriers + delivery options, hide-country,
  per-method free-shipping thresholds, dual-currency switch.

## Missing / roadmap

- **Sameday → production** — needs a Sameday **demo login** (request from Sameday; no public demo account —
  the SDK default `test-sameday-username`/`…password` returns 403). Then tick *Sandbox mode*, verify the
  cities / lockers / ooh / estimate / AWB field names, run a `@sameday` E2E, and merge `feat/sameday` → `main`.
- **Express One** — build once a **BG API key** arrives (`international@expressone.bg`); the one open shape is
  how a pickup-point selection encodes into `/createshipment`.
- **WordPress.org readiness** — see `docs/wordpress-org-readiness-audit.md`. Blockers before publishing:
  no `load_plugin_textdomain` / `Domain Path` / `languages/` / `.pot` (translations don't load yet), no
  `readme.txt`, and an **external-services disclosure** is required (we call 4 courier APIs + embed the
  BoxNow map iframe). Then a complete, reviewed **bg_BG (Bulgarian) translation** and an escaping/capability sweep.
- **Advanced courier parity** — per `docs/superpowers/specs/2026-07-04-courier-competitive-settings.md`:
  Sameday per-service rows / 3-way pricing / open-package; BoxNow home-delivery + any-APM + returns; a
  unified interactive GPS map for Speedy/Econt/Pigeon offices.

## Architecture (multi-courier framework)

A small registry makes couriers pluggable; everything resolves a courier by id.

- **`BGC_Couriers`** (`includes/Couriers/class-bgc-couriers.php`) — registry: `register(id,label,factory)` /
  `get(id)` / `all()`; hooks the `bgc_courier` filter. Couriers are registered in `BGC_Plugin::__construct`.
- **Courier adapters** — `BGC_Speedy`, `BGC_Econt`, `BGC_Pigeon`, `BGC_Boxnow`, `BGC_Sameday` extend
  **`BGC_Abstract_Courier`** and implement **`BGC_Courier_Interface`** (`id/label/capabilities/
  check_credentials/fetch_cities/fetch_offices/quote/create_label/get_label_pdf/track/tracking_url/
  cancel_label`). Parsers are pure static methods, unit-tested against fixtures (`tests/fixtures/<courier>/`).
- **Shipping methods** — `BGC_Method_<Courier>` (`WC_Shipping_Method`, id `bgc_<courier>`), registered into
  the Bulgaria zone via `woocommerce_shipping_methods`. Pricing via `BGC_Pricing` (live quote → cached
  reference → configured default); method-level free-shipping threshold. BoxNow is a flat rate.
- **Checkout** (`includes/Checkout/`) — `render_fields` emits a courier-aware `.bgc-fields[data-courier]`
  block after each shipping rate (tabs office/address/**APS**; searchable city/office/street via selectWoo);
  BoxNow instead renders a "Choose a BOX NOW locker" button that opens the **map widget**. The JS shows only
  the **chosen** courier's block. Per-city availability greys + disables an option the city lacks. The
  office/APS dropdown is disabled until a city is chosen, then offices are preloaded per courier+city+type and
  cached client-side. The **selection is tagged with its courier** (`bgc_selection_courier`) so switching
  couriers never shows a stale pick, and `validate()` blocks checkout without a valid destination. Pickers do
  not render on the cart page. Redundant standard WC address fields are removed; order address filled in
  `persist()`. Free-shipping notice follows the chosen courier.
- **Admin** (`includes/Admin/`) — settings, one section per courier (each shows only the fields it uses).
  Enable is a **top toggle**; courier + per-method tabs are pills tinted green/red; per-method enable lives on
  its sub-tab (method sub-tabs are hidden for single-method/flat-rate couriers like BoxNow). Credentials show a
  validated state. **AJAX Save** with a top-right toast. General has Default courier, Courier order
  (drag-sortable → `BGC_Checkout::sort_rates`), Hide-country, and the dual-currency switch. Order panel
  (waybill + generate/print/track), orders-list column, auto-generate on a trigger status.
- **Cache / pricing** (`includes/Cache/`) — `bgc_cities` / `bgc_offices` tables (`BGC_Schema`),
  `BGC_Nomenclature` repo. `BGC_Sync` runs for all registered+enabled couriers: weekly full nomenclature
  sync + a daily reference-price refresh (`seed_rates`) cached in `BGC_Rates`.
- **Support** (`includes/Support/`) — `BGC_Currency` (peg 1.95583, dual BGN/EUR), `BGC_Quote` / `BGC_Label` /
  `BGC_Tracking` value objects, `BGC_Encryption` (password at rest), `BGC_Api_Exception`.
- **Settings/config** — `BGC_Settings::courier_config(id)` reads `bgc_<id>_*` options (password encrypted).
  Each courier uses its **own registered account sender** (Econt profile, Speedy account, BoxNow warehouse,
  Pigeon pickup office, Sameday pickup point) — there is no global sender setting.

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
- **wp-env gotcha:** the wrapper swallows the PHPUnit summary when piped — judge by **exit code 0**.
- **E2E:** Playwright (`e2e/`), plain JS, `workers:1`, against the live dev site.
- **Deploy to dev:** `bash bin/deploy.sh dev` then chown to the site user, activate via wp-admin (wp-cli /
  php-exec are blocked over SSH). The dev site URL/host + credentials + the server-side "probe" technique for
  live API checks live in the **private project memory** — never in this repo.

## Conventions & rules

- **Credentials never in chat / memory / VCS.** Courier API creds are entered server-side (encrypted in WP
  options) only. Use a courier sandbox where one exists. Real-account label tests create **real waybills** —
  logged in `docs/test-*-waybills.md` for the **owner** to cancel (Claude never cancels).
- **Clean-room:** original code only (no code copied from other plugins).
- **Workflow:** features go brainstorm → spec (`docs/superpowers/specs/`) → plan (`docs/superpowers/plans/`)
  → subagent-driven execution with per-task review.

## Docs index

- `docs/wordpress-org-readiness-audit.md` — **pre-publish audit**: i18n / readme.txt / external-services gaps, findings, path to submission
- `docs/superpowers/specs/2026-06-26-multi-courier-design.md` — multi-courier design
- `docs/superpowers/specs/2026-07-03-sameday-design.md` + `plans/2026-07-03-sameday.md` — Sameday (built on `feat/sameday`, unverified)
- `docs/superpowers/specs/2026-07-04-courier-competitive-settings.md` — advanced per-courier parity (post-launch)
- `docs/superpowers/plans/2026-06-27-econt-phase2.md` — Econt (done, merged)
- `docs/superpowers/plans/2026-06-28-pigeon-phase3.md` — Pigeon (built, awaiting creds)
- `docs/superpowers/plans/2026-06-29-expressone-phase4.md` — Express One (planned, gated on BG creds)
- `docs/superpowers/plans/2026-06-29-boxnow-phase5.md` — BOX NOW (built, merged)
- `docs/getting-api-credentials.md` — **merchant-facing** guide to getting API access per courier
- `docs/courier-api-notes.md` — technical API analysis / framework fit / divergences
- `docs/testing.md` — running tests per courier · `docs/boxnow-testing.md` — BOX NOW stage-testing guide
- `docs/test-{speedy,econt,boxnow}-waybills.md` — real/stage test waybills for the owner to cancel

## License

GPLv2-or-later — intended for a free **WordPress.org** release (see the readiness audit for what's left).
© Dan Goriaynov.
