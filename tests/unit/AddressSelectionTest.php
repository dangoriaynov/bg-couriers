<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgc-ajax.php';

/**
 * @group speedy
 */
final class AddressSelectionTest extends TestCase {
    public function test_maps_and_trims_known_keys_only(): void {
        $out = BGC_Ajax::address_fields([
            'street_name' => '  Витоша ', 'street_no' => '5', 'floor' => '', 'apartment' => '10', 'evil' => 'x',
        ]);
        $this->assertSame('Витоша', $out['street_name']);
        $this->assertSame('10', $out['apartment']);
        $this->assertSame('', $out['floor']);
        $this->assertArrayNotHasKey('evil', $out);
        $this->assertSame(['street_name','street_no','complex','block','entrance','floor','apartment','address_note'], array_keys($out));
    }
}
