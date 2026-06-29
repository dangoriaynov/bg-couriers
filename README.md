# BG Couriers for WooCommerce

WooCommerce shipping plugin for Bulgarian couriers. Ships **to Bulgaria only**. Lets the customer
pick a delivery type (to office / to address / to automat·locker) per courier at checkout, computes
**live** prices from each courier's API, and lets the merchant generate shipping labels + track
shipments from the WordPress admin.

> **Context anchor:** this README + the project memory + `docs/superpowers/{specs,plans}` are the
> source of truth for picking the work back up after a restart. **Keep them updated as work proceeds.**

## Courier status

| Courier | Status | Notes |
|---|---|---|
| **Speedy** | ✅ Done, live-verified, on `main` | checkout (office/address/automat), live quotes, labels, tracking, settings |
| **Econt** | ✅ Done, live-verified, on `main` | checkout (office/address/Econtomat), live quotes (office 4.68 / address 5.77 / automat 4.21 €), labels, tracking, settings; E2E 7/7 with Speedy |
| **Pigeon Express** | 🟡 Scaffolded (code on `main`) | adapter + method + settings + `@group pigeon` tests built against the OpenAPI spec; awaiting credentials for live-verify |
| **Express One** | 📋 Plan ready (gated on creds) | turn-key `plans/2026-06-29-expressone-phase4.md` + fixtures from the official plugin; needs a BG API Key + 1 live confirm (pickup-point→createshipment encoding) |
| **BOX NOW** | 📋 Plan ready (gated on creds) | turn-key `plans/2026-06-29-boxnow-phase5.md` + fixtures from the v1.65 manual; needs OAuth2 creds + a geo locker-picker UI |

## Architecture (multi-courier framework)

A small registry makes couriers pluggable; everything resolves a courier by id.

- **`BGC_Couriers`** (`includes/Couriers/class-bgc-couriers.php`) — registry: `register(id,label,factory)` /
  `get(id)` / `all()`; hooks the `bgc_courier` filter. Couriers are registered in `BGC_Plugin::__construct`.
- **Courier adapters** — `BGC_Speedy`, `BGC_Econt` extend **`BGC_Abstract_Courier`** and implement
  **`BGC_Courier_Interface`** (`id/label/capabilities/check_credentials/fetch_cities/fetch_offices/
  search_streets/quote/create_label/get_label_pdf/track/tracking_url/cancel_label`). Parsers are pure
  static methods, unit-tested against fixtures (`tests/fixtures/<courier>/`).
- **Shipping methods** — `BGC_Method_Speedy`, `BGC_Method_Econt` (`WC_Shipping_Method`, id `bgc_<courier>`),
  registered into the Bulgaria zone via `woocommerce_shipping_methods`. Pricing via `BGC_Pricing` (live
  quote with a configured default-price fallback); method-level free-shipping threshold.
- **Checkout** (`includes/Checkout/`) — `render_fields` emits a courier-aware `.bgc-fields[data-courier]`
  block after each shipping rate (tabs office/address/automat; searchable city/office/street via selectWoo
  + AJAX `bgc_search_cities`/`bgc_offices`/`bgc_streets`, server-limited to `bgc_dropdown_limit`). The JS
  shows only the **chosen** courier's block. Redundant standard WC address fields are removed
  (`woocommerce_checkout_fields` unset); the order address is filled from the plugin selection in
  `persist()`. Free-shipping progress notice via `woocommerce_update_order_review_fragments`.
- **Admin** (`includes/Admin/`) — settings (one section per courier: creds + Validate/Sync, paper size,
  dynamic pricing, per-method enable/price/order, free-shipping threshold), order panel (waybill +
  generate/print/track at the top of the order), orders-list column with a copy-waybill button.
- **Cache / pricing** (`includes/Cache/`) — `bgc_cities` / `bgc_offices` tables (`BGC_Schema`),
  `BGC_Nomenclature` repo. `BGC_Sync` runs for **all registered+enabled couriers**: weekly full
  nomenclature sync (mark-and-sweep; lifts memory/time — Econt's getCities is ~130MB) + a **daily
  reference-price refresh** (`seed_rates`) that quotes a baseline per courier+method — address from
  the first alphabetical city, office/automat from the first city that has that office type
  (`first_office`) — cached in `BGC_Rates`. At checkout, before the customer picks a destination,
  `BGC_Pricing` shows this reference (live → cached reference → configured default fallback).
  Offices carry a `code` column (Econt/Pigeon address offices by string code).
- **Settings/config** — `BGC_Settings::courier_config(id)` reads `bgc_<id>_*` options (password encrypted
  via `BGC_Encryption`). `bgc_dropdown_limit` is a single global setting.

## Development

Tests run inside `@wordpress/env` (Docker: PHP + WP + WooCommerce + MySQL).

```bash
bin/test                 # all PHPUnit (unit + integration) + E2E
bin/test speedy          # only @group speedy PHP tests + @speedy E2E specs
bin/test econt           # only @group econt PHP tests + @econt E2E specs
bin/test core            # only framework (@group core) PHP tests
# raw: npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/bg-couriers -- ./vendor/bin/phpunit --testsuite unit
#      BGC_SUITE=integration ... --testsuite integration
```

- **Per-courier test split:** every PHP test has a class-level `@group speedy|econt|core`; E2E test titles
  carry `@speedy`/`@econt`. Changing one courier → run only its suite. (`docs/testing.md`)
- **wp-env gotcha:** the wrapper swallows the PHPUnit summary when piped — judge by **exit code 0**.
- **E2E:** Playwright (`e2e/`), plain JS, `workers:1` (serial, shared dev site), runs against the live dev site.
- **Deploy to dev:** `bash bin/deploy.sh dev` then chown to the site user. The dev site URL/host, its
  credentials, and the server-side "probe" technique for live API checks live in the **private project
  memory** — never in this repo.

## Conventions & rules

- **Credentials never in chat / memory / VCS.** Courier API creds are entered server-side (encrypted in
  WP options) only. Use a courier sandbox where one exists. Real-account label tests create **real
  waybills** — logged in `docs/test-*-waybills.md` for the **owner** to cancel (Claude never cancels).
- **Clean-room:** original code only (no code copied from other plugins).
- **Workflow:** features go brainstorm → spec (`docs/superpowers/specs/`) → plan
  (`docs/superpowers/plans/`) → subagent-driven execution with per-task review.

## Docs index

- `docs/superpowers/specs/2026-06-26-multi-courier-design.md` — multi-courier design
- `docs/superpowers/plans/2026-06-26-multi-courier-phase1.md` — framework refactor (done, on `main`)
- `docs/superpowers/plans/2026-06-27-econt-phase2.md` — Econt adapter (done, merged)
- `docs/superpowers/plans/2026-06-28-pigeon-phase3.md` — Pigeon adapter (scaffolded, awaiting creds)
- `docs/superpowers/plans/2026-06-29-expressone-phase4.md` — Express One (ready-to-execute, gated on BG creds + live shape confirm)
- `docs/superpowers/plans/2026-06-29-boxnow-phase5.md` — BOX NOW (ready-to-execute, gated on OAuth2 creds; locker-picker UI)
- `docs/getting-api-credentials.md` — **merchant-facing** guide: who to contact + steps to get API access for every courier (Speedy/Econt/BoxNow/Pigeon/Express One)
- `docs/courier-api-access.md` — how to get API credentials for BoxNow / Pigeon / Express One (dev reference)
- `docs/courier-api-notes.md` — technical API analysis of those 3 (framework fit, divergences, blockers; build order Pigeon → BoxNow → Express One)
- `docs/testing.md` — running tests per courier
- `docs/test-speedy-waybills.md`, `docs/test-econt-waybills.md` — real test waybills for the owner to cancel

## License

Proprietary — © Dan Goriaynov. All rights reserved (publishing model TBD).
