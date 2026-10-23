<?php
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * @group speedy
 */
final class SettingsPaperSizeTest extends BGCouriers_Unit_TestCase {

    public function test_default_is_a6_and_validates(): void {
        // get_option is stubbed by the unit bootstrap to return the default arg.
        Functions\when('get_option')->alias(function($option, $default = '') { return $default; });
        $this->assertSame('A6', BGCouriers_Settings::label_paper_size());
    }
}
