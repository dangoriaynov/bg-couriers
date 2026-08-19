# Running tests

- All tests (unit + integration + e2e): `bin/test`
- One courier (PHP `@group` + E2E `@tag`): `bin/test speedy`
- Framework only (no e2e): `bin/test core`

PHPUnit runs inside `@wordpress/env` and needs nothing else. The e2e specs drive a **live dev shop with
the couriers' real accounts behind it**: its address goes in `bin/deploy.conf` as `BGC_E2E_BASE_URL`,
there is no default, and the suite refuses to start without one. It also turns dev's auto-labelling off
for the length of a run over SSH, so `bin/deploy.conf` has to reach dev too - see
[`e2e/README.md`](../e2e/README.md) for what that protects against.

## The spec that books a real shipment

One spec is left out of every ordinary run, including `bin/test` and `bin/test speedy`: the parcel to
**Romania** (`e2e/tests/intl-speedy-ro.spec.js`, tagged `@books-real-waybill`). It books a waybill at
Speedy and voids it again, because Speedy's domestic and international services are mutually exclusive
and a waybill coming back is the only proof the international one was used.

    cd e2e && BGC_REAL_WAYBILL=1 npx playwright test intl-speedy-ro

Dev has to be set up for it first - Romania in Speedy's "Also deliver to" with a **Sync now** since, and
Romania in a shipping zone that carries the Speedy method. [`e2e/README.md`](../e2e/README.md) has the
full recipe and what to check afterwards.

## Adding a new courier

Tag the courier's PHP tests with `@group <id>` at the class level:

```php
/**
 * @group econt
 */
final class EcontQuoteTest extends TestCase {
```

Tag the courier's E2E specs by appending `@<id>` to each `test(...)` title:

```js
test('econt guest checkout to office @econt', async ({ page }) => {
```

Then `bin/test econt` picks them up automatically - PHPUnit runs `--group econt`
(unit + integration) and Playwright runs `--grep "@econt"` in the `e2e/` directory.
