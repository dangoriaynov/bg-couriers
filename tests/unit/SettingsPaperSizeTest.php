<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-settings.php';

final class SettingsPaperSizeTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_default_is_a6_and_validates(): void {
        // get_option is stubbed by the unit bootstrap to return the default arg.
        Functions\when('get_option')->alias(function($option, $default = '') { return $default; });
        $this->assertSame('A6', BGC_Settings::label_paper_size());
    }
}
