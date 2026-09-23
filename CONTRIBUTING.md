# Working on BG Couriers

Everything here is for whoever works on the plugin. What the plugin does, and how a shop sets it up,
is in [`README.md`](README.md).

## Architecture (multi-courier framework)

A small registry makes couriers pluggable; everything resolves a courier by id.

- **`BGCouriers_Couriers`** (`includes/Couriers/class-bgcouriers-couriers.php`) - registry: `register(id,label,factory)` /
  `get(id)` / `all()`; hooks the `bgcouriers_courier` filter. Couriers are registered in `BGCouriers_Plugin::__construct`.
- **Courier adapters** - `BGCouriers_Speedy`, `BGCouriers_Econt`, `BGCouriers_Pigeon`, `BGCouriers_Boxnow`, `BGCouriers_Sameday` extend
  **`BGCouriers_Abstract_Courier`** and implement **`BGCouriers_Courier_Interface`** (`id/label/capabilities/
  check_credentials/fetch_cities/fetch_offices/quote/create_label/get_label_pdf/track/tracking_url/
  cancel_label`). Parsers are pure static methods, unit-tested against fixtures (`tests/fixtures/<courier>/`).
- **Shipping methods** - `BGCouriers_Method_<Courier>` (`WC_Shipping_Method`, id `bgcouriers_<courier>`), offered
  to WooCommerce via `woocommerce_shipping_methods` and added by the merchant to whichever zone the parcels
  go to. Pricing via `BGCouriers_Pricing` (live quote → cached reference → configured default); method-level
  free-shipping threshold. BoxNow is a flat rate. **Abroad there is no fallback:** every cached or configured
  price is a Bulgarian one, so a destination outside the home country is priced live or not offered at all -
  and delivery abroad is switched off entirely for now
  ([`docs/international-shipping.md`](docs/international-shipping.md)).
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
- **E2E:** Playwright (`e2e/`), plain JS, `workers:1`, against the live dev site - whose address goes in
  `bin/deploy.conf` (no default) and which the run reaches over SSH to turn auto-labelling off for its
  length, so that a suite paying by cash on delivery does not book six real parcels. The three `intl-*`
  specs are skipped while delivery abroad is off - one of them books a real shipment on purpose and was
  held out of every ordinary run besides (`cd e2e && BGC_REAL_WAYBILL=1 npx playwright test
  intl-speedy-ro`). Setup recipe and what to check afterwards: [`e2e/README.md`](e2e/README.md).
- **Releasing.** The gate is DEV, not the shop. A build goes to WordPress.org once it is running on the
  dev site and has passed Plugin Check and both suites there; what the shop is running is a separate
  decision that no longer holds the directory up (owner, 2026-09-23). The old rule tied every release to
  one particular server being reachable, and the live shop is not even on the machine the scripts assumed.
  ```bash
  bin/deploy.sh dev           # the build lands on dev; that is what the release is then gated on
  git tag -a v0.4.18 -m "0.4.18" && git push origin v0.4.18   # CI releases it to WordPress.org
  bin/release-prod            # the SHOP, separately; asks before it, and runs wp.org after if not done
  bin/release-status          # where every copy stands: checkout, dev, prod, wp.org served + tagged
  ```
  **From CI** (`.github/workflows/release-wporg.yml`, triggered by the `v<version>` tag): version agrees
  with the tag, Bulgarian complete and compiled, unit + integration suites, Plugin Check, dev is on this
  version, nothing private in the package, then SVN trunk + assets + tag, then a look at whether the
  directory serves it. The WordPress.org credentials live in the repository's secrets
  (`SVN_USERNAME`, `SVN_PASSWORD`), so a release no longer needs one particular laptop with an SVN
  working copy on it; `BGC_LEAK_PATTERNS` is a secret too, and `BGC_DEV_URL` a variable.
  **From a machine that has `bin/deploy.conf`**, `bin/release-wporg` does the same locally, and
  `bin/release-prod` runs `bin/preflight` (one version in all 3 places, changelog entry, clean+pushed
  tree, Bulgarian complete AND compiled, unit + integration tests, nothing test-shaped tracked), Plugin
  Check on dev, backup (named for the version being REPLACED), deploy, verify, purge, smoke - and then
  `bin/release-wporg` if the version is not published yet. Its two prompts are its two decisions;
  `--yes` answers both in advance, and `--detach` is `--yes` in a session of its own, logged to
  `tmp/release-<version>.log` - for a release that has ALREADY been agreed and must outlast the
  terminal: 0.4.13 was left on the shop alone when the session that started its second half was killed.
  `bin/deploy.sh dev` prints that state whenever it recurs, and `release-status` is how to look on purpose.
  Every check in `bin/preflight` stands in for something that has gone wrong here at least once; the
  comment above each says which.
- **Deploy to dev:** `bash bin/deploy.sh dev` then chown to the site user, activate via wp-admin (wp-cli /
  php-exec are blocked over SSH). The dev site URL/host + credentials + the server-side "probe" technique for
  live API checks are kept **privately, outside this repository** - never in it.

## Conventions & rules

- **Credentials never in chat / memory / VCS.** Courier API creds are entered server-side (encrypted in WP
  options) only. Use a courier sandbox where one exists. Real-account label tests create **real waybills** -
  logged **privately, outside this repository** for the **owner** to cancel.
- **Clean-room:** original code only (no code copied from other plugins).
