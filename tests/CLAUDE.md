# bg-couriers - tests

Lives in `tests/` on purpose: a root CLAUDE.md would ship through `.distignore` and `bin/deploy.sh`, which do not exclude it.

## Tests
- Layout: `tests/unit` (Brain Monkey, no WordPress), `tests/integration` (real WP + WooCommerce in wp-env), `e2e/tests` (Playwright against the dev shop; places real orders). Fixtures are scrubbed live courier answers in `tests/fixtures/<courier>/`; WooCommerce stand-ins are in `tests/stubs/`.
- Run from the repo root: `./vendor/bin/phpunit --testsuite unit` works locally without Docker; integration and e2e go through `bin/test` (see CONTRIBUTING.md). CI runs both PHP suites only on a release tag, so run the unit suite yourself before every PR.
- New unit tests extend `BGCouriers_Unit_TestCase` (`tests/support/`): it wraps Brain Monkey, makes `__`/`_x`/`esc_html` identity, and gives `options([...])` for `get_option()` and `fixture($courier, $file)`. Do not copy that setup into a test.
- Several inputs with one expected outcome each = one test with a `@dataProvider` (public static, string keys as case names), not a test per input or a `foreach` of asserts.
- Name the test after the contract ("a refusal keeps the courier's own message"), not the incident or order number; the docblock is one line, only when the name is not enough.
- Assert on what the code returns or writes (body sent, meta saved, exception message), never on the fixture alone or on the source text of a PHP class. Reading source is reserved for JS/CSS that has no runtime here and for settings-page wiring.
- No network, no real hosts, no wall clock: courier HTTP goes through a subclass overriding `call()`/`http_get()`/`http_post()` or stubbed `wp_remote_*`; static registries (`BGCouriers_Couriers::reset()`) are reset in `tearDown`.
- Every test class keeps its class-level `@group` (`core` or the courier id); `bin/test <courier>` selects by it.
