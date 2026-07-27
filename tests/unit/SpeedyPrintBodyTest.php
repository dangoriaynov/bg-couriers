<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';

/**
 * @group speedy
 */
final class SpeedyPrintBodyTest extends TestCase {
    public function test_build_print_body_multi_parcel(): void {
        $b = BGCouriers_Speedy::build_print_body(['111', '222'], 'A4');
        $this->assertSame('A4', $b['paperSize']);
        $this->assertSame([['parcel' => ['id' => '111']], ['parcel' => ['id' => '222']]], $b['parcels']);
        $this->assertSame('NONE', $b['additionalWaybillSenderCopy']);
    }
    public function test_build_print_body_defaults_invalid_size_to_a6(): void {
        $this->assertSame('A6', BGCouriers_Speedy::build_print_body(['1'], 'A3')['paperSize']);
    }
    public function test_build_print_body_accepts_the_multi_label_a4_grid(): void {
        // Speedy's own batch layout: an A4 page carrying four A6 labels (used by batch_label_pdf for A4).
        $this->assertSame('A4_4xA6', BGCouriers_Speedy::build_print_body(['1', '2'], 'A4_4xA6')['paperSize']);
    }
}
