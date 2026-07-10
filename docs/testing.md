# Running tests

- All tests (unit + integration + e2e): `bin/test`
- One courier (PHP `@group` + E2E `@tag`): `bin/test speedy`
- Framework only (no e2e): `bin/test core`

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
