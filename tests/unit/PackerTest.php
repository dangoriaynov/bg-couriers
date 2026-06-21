<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgc-packer.php';

final class PackerTest extends TestCase {
    public function test_standard_is_10_10_10_2kg(): void {
        $s = BGC_Packer::standard();
        $this->assertSame(2.0, $s['weight_kg']);
        $this->assertSame(10, $s['length_cm']);
    }
    public function test_from_weight_floors_tiny(): void {
        $this->assertSame(0.1, BGC_Packer::from_weight(0.0)['weight_kg']);
    }
}
