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

    /**
     * Sofia has a бул. ВИТОША and a ул. ВИТОША, and Speedy refuses the bare name in a town where it is
     * not unique (measured 2026-09-13 against /validation/address). The street chosen off the list
     * carries Speedy's own id, and that is what goes - the name would only be a second, weaker answer
     * to the same question.
     */
    public function test_a_street_chosen_off_the_list_goes_by_its_id(): void {
        $a = BGCouriers_Speedy::build_address(68134, ['street' => 'ВИТОША', 'street_id' => 1314, 'street_type' => 'ул.', 'street_no' => '10']);
        $this->assertSame(1314, $a['streetId']);
        $this->assertArrayNotHasKey('streetName', $a);
        $this->assertArrayNotHasKey('streetType', $a);
        $this->assertSame('10', $a['streetNo']);
    }

    /** A type without an id (an older order the lookup could not settle) still tells the two apart. */
    public function test_a_type_without_an_id_goes_beside_the_name(): void {
        $a = BGCouriers_Speedy::build_address(68134, ['street' => 'ВИТОША', 'street_type' => 'ул.', 'street_no' => '10']);
        $this->assertSame('ВИТОША', $a['streetName']);
        $this->assertSame('ул.', $a['streetType']);
        $this->assertArrayNotHasKey('streetId', $a);
    }

    /** Nothing invented: no id, no type - the name alone, exactly as every order before this carried it. */
    public function test_neither_id_nor_type_sends_the_name_alone(): void {
        $a = BGCouriers_Speedy::build_address(68134, ['street' => 'ШИПКА', 'street_id' => 0, 'street_type' => '', 'street_no' => '3']);
        $this->assertSame(['countryId' => 100, 'siteId' => 68134, 'streetName' => 'ШИПКА', 'streetNo' => '3'], $a);
    }

    /** A type with no street to attach it to is not sent - Speedy would refuse a streetType alone. */
    public function test_a_type_without_a_street_is_dropped(): void {
        $a = BGCouriers_Speedy::build_address(68134, ['complex' => 'Младост 1', 'street_type' => 'ул.', 'block' => '12']);
        $this->assertArrayNotHasKey('streetType', $a);
        $this->assertArrayNotHasKey('streetName', $a);
        $this->assertSame('Младост 1', $a['complexName']);
    }
}
