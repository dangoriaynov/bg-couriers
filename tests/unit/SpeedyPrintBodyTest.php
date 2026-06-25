<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-speedy.php';

final class SpeedyPrintBodyTest extends TestCase {
    public function test_build_print_body_multi_parcel(): void {
        $b = BGC_Speedy::build_print_body(['111', '222'], 'A4');
        $this->assertSame('A4', $b['paperSize']);
        $this->assertSame([['parcel' => ['id' => '111']], ['parcel' => ['id' => '222']]], $b['parcels']);
        $this->assertSame('NONE', $b['additionalWaybillSenderCopy']);
    }
    public function test_build_print_body_defaults_invalid_size_to_a6(): void {
        $this->assertSame('A6', BGC_Speedy::build_print_body(['1'], 'A3')['paperSize']);
    }
}
