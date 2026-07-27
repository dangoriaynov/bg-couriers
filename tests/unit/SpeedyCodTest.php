<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';

/**
 * @group speedy
 */
final class SpeedyCodTest extends TestCase {
    public function test_cod_is_total_minus_shipping(): void {
        // total 25.20 = goods 19.00 + shipping 6.00 + shipping tax 0.20
        $this->assertEqualsWithDelta(19.00, BGCouriers_Speedy::cod_amount(25.20, 6.00, 0.20), 0.001);
    }
    public function test_cod_never_negative(): void {
        $this->assertSame(0.0, BGCouriers_Speedy::cod_amount(5.0, 6.0, 0.0));
    }
}
