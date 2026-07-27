<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';

/**
 * @group speedy
 */
final class SpeedyAddressTest extends TestCase {
    public function test_required_only(): void {
        $a = BGCouriers_Speedy::build_address(68134, ['street' => 'Витоша', 'street_no' => '5']);
        $this->assertSame(['countryId' => 100, 'siteId' => 68134, 'streetName' => 'Витоша', 'streetNo' => '5'], $a);
    }
    public function test_full_set_and_skips_blanks(): void {
        $a = BGCouriers_Speedy::build_address(68134, [
            'complex' => 'Кръстова вада', 'street' => 'Витоша', 'street_no' => '5',
            'block' => '1', 'entrance' => '4', 'floor' => '', 'apartment' => '10', 'note' => 'до входа',
        ]);
        $this->assertSame('Кръстова вада', $a['complexName']);
        $this->assertSame('4', $a['entranceNo']);
        $this->assertArrayNotHasKey('floorNo', $a);   // blank skipped
        $this->assertSame('до входа', $a['addressNote']);
    }
}
