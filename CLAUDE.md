# CLAUDE.md — bg-couriers

WordPress/WooCommerce plugin "BG Couriers for WooCommerce": seven Bulgarian couriers (Speedy, Econt, Pigeon,
Sameday, Express One, Европът, BOX NOW) at the checkout, labels and tracking in the admin. Published on
WordPress.org; also deployed to the owner's dev and live shops. **This repository is public.**
Product docs: `README.md`. Architecture in depth: `CONTRIBUTING.md`. UI rules: `docs/ui-conventions.md`.

## Map
- `bg-couriers.php` — plugin header (Version), `BGCOURIERS_VERSION`, bootstrap hooks; `uninstall.php` removes all plugin data except order meta.
- `includes/class-bgcouriers-plugin.php` — wiring: registers couriers and hooks, cron list (`clear_cron`).
- `includes/class-bgcouriers-autoloader.php` — `BGCouriers_Foo_Bar` → `class-bgcouriers-foo-bar.php` (`interface-`/`abstract-` prefixes) in `includes/{,Support,Couriers,Cache,Shipping,Checkout,Admin}/`.
- `includes/Couriers/` — registry (`BGCouriers_Couriers`), interface, abstract base (HTTP, retry, PDF fetch), one adapter per courier; parsers are pure static methods.
- `includes/Shipping/` — one `WC_Shipping_Method` per courier, `BGCouriers_Pricing` (live quote → cached reference → configured default), packer.
- `includes/Checkout/` — courier block on the classic checkout, AJAX endpoints, block-checkout bridge (Store API), order persistence, thank-you/tracking for the customer.
- `includes/Admin/` — WooCommerce settings tab, order panel, orders-list column, labels (one waybill per order, claim lock), bulk print, pickup requests.
- `includes/Cache/` — own DB tables (`BGCouriers_Schema`), nomenclature, price zones and reference rates, sync and tracking cron.
- `includes/Support/` — value objects (`Quote`, `Label`, `Tracking`), encryption, logger, label PDF packer.
- `assets/js`, `assets/css` — checkout, all-courier map, admin scripts. **Vendored, never edit:** `includes/lib/pdf/` (FPDF/FPDI), `assets/lib/leaflet/`.
- `languages/` — Bulgarian catalogue: `.po` (source) → `.mo` → `.l10n.php` (read first since WP 6.5). Generated, do not hand-edit `.mo`/`.l10n.php`.
- `bin/` — test, build, translation, deploy and release scripts; `bin/deploy.conf` (gitignored) holds every host/path.
- `.github/workflows/release-wporg.yml` — publishes a `v<version>` tag to WordPress.org.
- `tests/` (PHPUnit unit + integration), `e2e/` (Playwright against the dev shop), `docs/` (courier API notes, changelog).

## Rules
- **Target PHP 7.4+, WP 6.0+, WC 7.0+**; HPOS-compatible (no direct post-table order access).
- **Plugin Check must pass**: escape output, verify nonces, sanitise input with listed sanitisers; a `phpcs:ignore` always names the sniff and the reason.
- **Every user-facing string is translatable** (`__()`, text domain `bg-couriers`, `/* translators: */` on placeholders) and gets its Bulgarian in the same change: `bin/make-pot`, fill the `.po`, `msgfmt`, `bin/make-php`.
- **One version in three places**: plugin header, `BGCOURIERS_VERSION`, readme `Stable tag` (+ changelog entry). A version published on WordPress.org is never re-used: bump.
- `readme.txt` has no spaced or doubled hyphens (WordPress.org turns them into dashes).
- **Nothing private in the repo**: hosts, ports, logins, phone numbers live only in `bin/deploy.conf` / CI secrets. Courier credentials only in WP options, encrypted.
- **Never experiment on prod** (no test orders, waybills or settings changes); read it only through `bin/prod-read`. Real-account label tests create real waybills.
- A courier is resolved by id through the registry; courier-specific behaviour lives in its adapter or a capability flag on the base, not in `if ($courier === 'x')` elsewhere.
- City/office/street ids belong to one courier's nomenclature: never reuse them across couriers.
- Comments: one short "why" for a real gotcha, no history or incident stories (that is git and `docs/CHANGELOG.md`).
- Original code only (clean-room): nothing copied from other plugins.

## Commands
- Unit tests: `composer install && ./vendor/bin/phpunit --testsuite unit`
- Full suite in wp-env: `bin/test` (or `bin/test <courier>|core`); integration needs Docker (`bin/wpenv-start`).
- Release gates: `bin/preflight`; package: `bin/build-zip`.
- Deploy to dev: `bin/deploy.sh dev`; where every copy stands: `bin/release-status`.
- Release: deploy to dev, then tag `v<version>` (CI publishes) or `bin/release-wporg`; live shop: `bin/release-prod`.
