<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-settings.php';

final class EnabledMethodsTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_disabled_method_is_excluded(): void {
        // office disabled, the rest default to 'yes'
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $name === 'bgc_speedy_office_enabled' ? 'no' : 'yes';
        });
        $this->assertSame(['address', 'automat'], BGC_Settings::enabled_methods('speedy'));
    }

    public function test_all_enabled_by_default(): void {
        Functions\when('get_option')->justReturn('yes');
        $this->assertSame(['office', 'address', 'automat'], BGC_Settings::enabled_methods('speedy'));
    }
}
