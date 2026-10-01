# Working on BG Couriers

Everything here is for whoever works on the plugin. What the plugin does, and how a shop sets it up,
is in [`README.md`](README.md).

## Where things live

| Directory | What is in it |
|---|---|
| `includes/Couriers/` | The registry, the courier interface, the abstract base, and one adapter per courier |
| `includes/Shipping/` | One `WC_Shipping_Method` per courier, plus pricing and the parcel packer |
| `includes/Checkout/` | The courier block on the checkout, its AJAX, the block-checkout bridge, order persistence |
| `includes/Admin/` | Settings screens, the order panel, the orders-list column, labels and bulk actions |
| `includes/Cache/` | Nomenclature tables, price zones, cached reference rates, the sync and tracking cron |
| `includes/Support/` | Value objects (`Quote`, `Label`, `Tracking`), encryption, logging, the API exception |
| `assets/js/` | Checkout, blocks, the all-courier map, settings and orders-list scripts |
| `tests/`, `e2e/` | PHPUnit (unit + integration) and Playwright |
| `bin/` | Test, build, translation, deploy and release scripts |

## Architecture

### The registry

`BGCouriers_Couriers` (`includes/Couriers/class-bgcouriers-couriers.php`) makes couriers pluggable:
`register(id, label, factory)` / `get(id)` / `all()`, plus the `bgcouriers_courier` filter. All seven
are registered in `BGCouriers_Plugin::__construct`. Everything else resolves a courier by id.

### Courier adapters

`BGCouriers_Speedy`, `BGCouriers_Econt`, `BGCouriers_Pigeon`, `BGCouriers_Boxnow`,
`BGCouriers_Sameday`, `BGCouriers_Expressone` and `BGCouriers_Evropat` extend
`BGCouriers_Abstract_Courier` and implement `BGCouriers_Courier_Interface`:

- identity and capability: `id`, `label`, `capabilities`, `check_credentials`
- nomenclature: `fetch_cities`, `fetch_offices`
- money: `quote`
- paperwork: `create_label`, `label_formats`, `get_label_pdf`, `cancel_label`
- after the parcel leaves: `track`, `tracking_url`

`request_pickup()` and `pickup_terms()` are on the abstract base and overridden only by the couriers
that have a collection request (Speedy, Econt, Express One, Европът); the base refuses for the rest.

Parsers are pure static methods, unit-tested against fixtures in `tests/fixtures/<courier>/`.

### Shipping methods and pricing

- `BGCouriers_Method_<Courier>` (a `WC_Shipping_Method`, id `bgcouriers_<courier>`) is offered to
  WooCommerce through `woocommerce_shipping_methods`; the merchant adds it to a zone.
- `BGCouriers_Pricing` resolves a price in that order: live quote, then the cached reference, then the
  configured default. BOX NOW is a flat rate and never quoted.
- The cached reference is **per price zone** (inside Sofia, and the rest of the country, see
  `BGCouriers_Zones`), because every courier here charges two different prices for the same parcel.
  The country figure is what a checkout shows before a town is named.
- Free-shipping thresholds are per courier and per method.
- **Abroad there is no fallback.** Every cached or configured price is a Bulgarian one, so a foreign
  destination is priced live or not offered. Delivery abroad is switched off entirely for now:
  [`docs/international-shipping.md`](docs/international-shipping.md).

### Checkout

- `render_fields` emits a courier-aware `.bgc-fields[data-courier]` block after each shipping rate:
  tabs for office / address / APS, with searchable city, office and street pickers (selectWoo).
- BOX NOW instead renders a button that opens **its own map widget**, which is the only way to pick
  one of its lockers.
- Only the **chosen** courier's block is shown. An option the town has no points for is greyed out,
  and the office dropdown stays disabled until a town is chosen, then offices are preloaded per
  courier + town + type and cached in the browser.
- The selection is **tagged with its courier** (`bgcouriers_selection_courier`), so switching couriers
  never shows a stale pick, and `validate()` blocks the order without a destination that courier can
  take.
- **The all-courier map** (`assets/js/bgc-allmap.js`, bundled Leaflet, no CDN) shows every enabled
  courier's offices and lockers for a town at once, each priced for the way it is collected, with
  distances worked out in the browser. Choosing a point sets courier, type, town and office together.
- **Where the block sits** is a setting (`bgcouriers_delivery_position`): in the order-review table as
  WooCommerce renders it, or moved by script under the customer's details. The move is scripted, never
  styled, and undoes itself if the host never appears.
- Redundant standard WC address fields are removed; the order's address is filled in `persist()`.
- The block checkout is served by `includes/Checkout/class-bgcouriers-blocks.php` and the Store API.

### Admin

- Settings: one section per courier, showing only the fields that courier uses. Enable is a top
  toggle; courier and per-method tabs are pills tinted green/red; credentials carry a validated state.
  Saving is AJAX with a toast.
- General holds the default courier, the drag-sortable courier order
  (`BGCouriers_Checkout::sort_rates`), hide-country and the auto-label trigger.
- Orders: a shipment panel on the order screen (waybill, generate, print, track, cancel), a waybill
  column on the orders list, bulk label printing into one PDF, and **Request a courier**.

### Nomenclature cache and sync

`bgcouriers_cities` and `bgcouriers_offices` (`BGCouriers_Schema`) are read through
`BGCouriers_Nomenclature`. `BGCouriers_Sync` runs for every registered and enabled courier: a weekly
full nomenclature sync, a daily reference-price refresh (`seed_rates` into `BGCouriers_Rates`, two
zones per method), and a one-off sync the moment a courier is switched on.

### Settings and credentials

`BGCouriers_Settings::courier_config(id)` reads the `bgcouriers_<id>_*` options; passwords and keys are
encrypted at rest by `BGCouriers_Encryption`.

**There is no global sender setting.** Each courier ships from its own registered account address:
Econt profile, BOX NOW warehouse, Pigeon pickup office, Sameday pickup point, Express One sender
object (`bgcouriers_expressone_sender_object`), Европът sender file
(`bgcouriers_evropat_sender_file`, which end the parcel leaves from in
`bgcouriers_evropat_sender_end`). Speedy
defaults to the account's own address and can be pointed at a drop-off office instead
(`bgcouriers_speedy_dropoff_office`).

## Running tests

PHPUnit runs inside `@wordpress/env` (Docker: PHP + WP + WooCommerce + MySQL).

```bash
bin/test                # unit + integration + the whole E2E suite
bin/test core           # framework PHP tests only, no E2E
bin/test <courier>      # speedy | econt | pigeon | boxnow | sameday | expressone | evropat
```

- Every PHP test carries a class-level `@group`, one per courier plus `core`; E2E specs carry the
  matching `@<courier>` tag.
- **wp-env gotcha:** the wrapper swallows the PHPUnit summary when piped. Judge by **exit code 0**.
- Raw invocation, when `bin/test` is in the way:
  ```bash
  npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/bg-couriers -- ./vendor/bin/phpunit --testsuite unit
  BGC_SUITE=integration npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/bg-couriers -- ./vendor/bin/phpunit --testsuite integration
  ```

### E2E

Playwright (`e2e/`), plain JS, `workers:1`, driven against the **live dev shop** whose couriers are
real accounts. Its address goes in `bin/deploy.conf` as `BGC_E2E_BASE_URL`; there is no default and
the suite refuses to start without one.

- `global-setup.js` reaches dev over SSH and **turns auto-labelling off for the length of a run**, so
  a suite that pays by cash on delivery does not book real parcels. Do not bypass it.
- The three `intl-*` specs are skipped while delivery abroad is off. One of them books a real shipment
  on purpose and is held out of every ordinary run: `cd e2e && BGC_REAL_WAYBILL=1 npx playwright test
  intl-speedy-ro`.
- Setup recipe and what to check afterwards: [`e2e/README.md`](e2e/README.md).

## Translations

The Bulgarian catalogue lives in **three files that must agree**, and WordPress reads them in this
order since 6.5: `languages/bg-couriers-bg_BG.l10n.php`, then the `.mo`, then nothing. The `.po` is the
source.

```bash
bin/make-pot                                            # refresh the template from the code
msgfmt -o languages/bg-couriers-bg_BG.mo languages/bg-couriers-bg_BG.po
bin/make-php                                            # build the .l10n.php from that .mo
bin/make-php --check                                    # touch nothing; fail if they disagree
```

A string added without its Bulgarian lands on a Bulgarian shop in English, so `bin/preflight` gates on
completeness, and the release workflow runs `bin/make-php --check` as well: a stale `.l10n.php` would
answer **before** the `.mo` and say something else.

## Releasing

**The gate is DEV, not the shop.** A build goes to WordPress.org once it is running on the dev site and
has passed Plugin Check and both suites there. What the live shop is running is a separate decision and
no longer holds the directory up (owner, 2026-09-23).

```bash
bin/deploy.sh dev                                       # the build lands on dev, which is the gate
git tag -a v<version> -m "<version>" && git push origin v<version>   # CI publishes to WordPress.org
bin/release-prod                                        # the SHOP, separately
bin/release-status                                      # where every copy stands
```

**From CI** (`.github/workflows/release-wporg.yml`, on a `v<version>` tag): the version agrees with the
tag, Bulgarian is complete and all three catalogue files agree, unit + integration pass, Plugin Check
passes, dev is on this version, nothing private is in the package, then SVN trunk + assets + tag, then
a look at whether the directory serves it. `SVN_USERNAME`, `SVN_PASSWORD` and `BGC_LEAK_PATTERNS` are
repository secrets and `BGC_DEV_URL` a variable, so a release no longer needs one particular laptop.

**From a machine with `bin/deploy.conf`**, `bin/release-wporg` does the same locally and
`bin/release-prod` runs the shop half: `bin/preflight`, Plugin Check on dev, a backup named for the
version being replaced, deploy, verify, purge, smoke, and then `bin/release-wporg` if the version is
not published yet. Its two prompts are its two decisions: `--yes` answers both in advance, and
`--detach` is `--yes` in a session of its own logged to `tmp/release-<version>.log`, for a release that
has already been agreed and must outlast the terminal.

`bin/preflight` is the list of everything that must be true first: one version in all three places, a
changelog entry, a clean and pushed tree, translations complete and compiled, the test suites, nothing
test-shaped tracked, and no dashes on the plugin page. **Every check stands in for something that has
gone wrong here at least once**, and the comment above each says which.

## Deploying to dev

`bash bin/deploy.sh dev`, then chown to the site user. wp-cli is available on the site over the same
SSH route (`BGC_WP_BIN` in `bin/deploy.conf`; `bin/release-prod`, `bin/release-wporg` and
`bin/settings-snapshot` all use it), so activation and option reads do not need wp-admin.

The dev site's URL, host and credentials, and the server-side "probe" technique for live API checks,
are kept **privately, outside this repository**. Never in it.

## Rules

- **Credentials never in chat, memory or version control.** Courier API credentials are entered
  server-side only, encrypted in WP options. Use a courier sandbox where one exists.
- **Real-account label tests create real waybills.** They are logged privately, outside this
  repository, for the owner to cancel.
- **Clean-room:** original code only. Nothing is copied from another plugin.
