# Running tests

- All tests (unit + integration + e2e): `bin/test`
- One courier (PHP `@group` + E2E `@tag`): `bin/test speedy`
- Framework only (no e2e): `bin/test core`

PHPUnit runs inside `@wordpress/env` and needs nothing else. The e2e specs drive a **live dev shop with
the couriers' real accounts behind it**: its address goes in `bin/deploy.conf` as `BGC_E2E_BASE_URL`,
there is no default, and the suite refuses to start without one. It also turns dev's auto-labelling off
for the length of a run over SSH, so `bin/deploy.conf` has to reach dev too - see
[`e2e/README.md`](../e2e/README.md) for what that protects against.

## The specs that book a real shipment

Two tests are left out of every ordinary run, including `bin/test` and `bin/test speedy` - the ones
tagged `@books-real-waybill`. Each books a waybill at Speedy and voids it again, because a waybill
coming back is the only proof the courier accepts what the order carries:

- the parcel to **Romania** (`e2e/tests/intl-speedy-ro.spec.js`): Speedy's domestic and international
  services are mutually exclusive, so the waybill proves the international one was used. Dev has to be
  set up for it first - Romania in Speedy's "Also deliver to" with a **Sync now** since, and Romania in
  a shipping zone that carries the Speedy method. [`e2e/README.md`](../e2e/README.md) has the full recipe
  and what to check afterwards.
- the parcel to **ул. ВИТОША** (the second test in `e2e/tests/speedy-street-type.spec.js`): Sofia has a
  бул. ВИТОША too, and Speedy refuses the bare name in a town where it is not unique - the waybill
  proves the order's street id is what reaches Speedy. The first test in that file runs every time and
  books nothing.

    cd e2e && BGC_REAL_WAYBILL=1 npx playwright test intl-speedy-ro speedy-street-type

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
