<?php
/**
 * The free-shipping progress notice math: how much is left to reach the threshold.
 *
 * @group speedy
 */
final class FreeNoticeTest extends WP_UnitTestCase {
    public function test_free_remaining(): void {
        $cfg = ['enabled' => true, 'threshold' => 50.0];
        $this->assertSame(20.0, BGCouriers_Checkout::free_remaining(30.0, $cfg)); // 20 left
        $this->assertSame(0.0, BGCouriers_Checkout::free_remaining(50.0, $cfg));  // exactly met
        $this->assertSame(0.0, BGCouriers_Checkout::free_remaining(60.0, $cfg));  // over → never negative
        $this->assertSame(0.0, BGCouriers_Checkout::free_remaining(10.0, ['enabled' => false, 'threshold' => 50.0])); // disabled
        $this->assertSame(0.0, BGCouriers_Checkout::free_remaining(10.0, ['enabled' => true, 'threshold' => 0.0]));   // no threshold
    }
}
