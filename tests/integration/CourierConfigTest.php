<?php
// tests/integration/CourierConfigTest.php
final class CourierConfigTest extends WP_UnitTestCase {
    public function test_config_for_registered_enabled_courier(): void {
        update_option('bgc_speedy_enabled', 'yes');
        update_option('bgc_speedy_username', 'u@example.bg');
        $cfg = BGC_Settings::courier_config('speedy');
        $this->assertIsArray($cfg);
        $this->assertSame('u@example.bg', $cfg['username']);
    }
    public function test_disabled_courier_returns_null(): void {
        update_option('bgc_speedy_enabled', 'no');
        $this->assertNull(BGC_Settings::courier_config('speedy'));
    }
    public function test_unregistered_courier_returns_null(): void {
        $this->assertNull(BGC_Settings::courier_config('nope'));
    }
    public function test_couriers_lists_registered(): void {
        $this->assertArrayHasKey('speedy', BGC_Settings::couriers());
    }
}
