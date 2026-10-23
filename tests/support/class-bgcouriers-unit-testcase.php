<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** Base for unit tests: Brain Monkey around every test, translation/escaping as identity, shared helpers. */
abstract class BGCouriers_Unit_TestCase extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        foreach (['__', '_x', 'esc_html'] as $fn) {
            Functions\when($fn)->returnArg(1);
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** get_option() answers from $stored, falling back to the caller's default. */
    protected function options(array $stored = []): void {
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($stored) {
            return array_key_exists($name, $stored) ? $stored[$name] : $default;
        });
    }

    /** Decoded JSON from tests/fixtures/<courier>/<file>. */
    protected function fixture(string $courier, string $file): array {
        return json_decode((string) file_get_contents(dirname(__DIR__) . "/fixtures/$courier/$file"), true);
    }
}
