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
        $this->assertSame(['street_name','street_type','street_no','complex','block','entrance','floor','apartment','address_note','street_id'], array_keys($out));
    }

    /** Which street of that name: the courier's id is a whole number or nothing, the type is text like the rest. */
    public function test_the_street_id_is_a_number_and_the_type_is_text(): void {
        $out = BGCouriers_Ajax::address_fields(['street_name' => 'ВИТОША', 'street_id' => '1314', 'street_type' => ' ул. ']);
        $this->assertSame(1314, $out['street_id']);
        $this->assertSame('ул.', $out['street_type']);
        $out = BGCouriers_Ajax::address_fields(['street_name' => 'Шипка']);
        $this->assertSame(0, $out['street_id'], 'a typed street has no id');
        $this->assertSame('', $out['street_type']);
        $this->assertSame(0, BGCouriers_Ajax::address_fields(['street_id' => '-3'])['street_id']);
        $this->assertSame(0, BGCouriers_Ajax::address_fields(['street_id' => 'abc'])['street_id']);
    }
}
