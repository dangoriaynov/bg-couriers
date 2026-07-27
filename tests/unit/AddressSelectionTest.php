<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-ajax.php';

/**
 * @group speedy
 */
final class AddressSelectionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        // address_fields sanitizes each value (Plugin Check) - trim matches WP's whitespace handling here.
        \Brain\Monkey\Functions\when('sanitize_text_field')->alias(static function ($str) { return trim((string) $str); });
    }
    protected function tearDown(): void { \Brain\Monkey\tearDown(); parent::tearDown(); }

    public function test_maps_and_trims_known_keys_only(): void {
        $out = BGCouriers_Ajax::address_fields([
            'street_name' => '  Витоша ', 'street_no' => '5', 'floor' => '', 'apartment' => '10', 'evil' => 'x',
        ]);
        $this->assertSame('Витоша', $out['street_name']);
        $this->assertSame('10', $out['apartment']);
        $this->assertSame('', $out['floor']);
        $this->assertArrayNotHasKey('evil', $out);
        $this->assertSame(['street_name','street_no','complex','block','entrance','floor','apartment','address_note'], array_keys($out));
    }
}
