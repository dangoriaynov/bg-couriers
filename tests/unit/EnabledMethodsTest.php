<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * @group speedy
 */
final class EnabledMethodsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        // Answered here rather than left to whether some other test file happened to leave a courier in
        // the registry: with one there, enabled_methods() prunes against the synced office counts.
        Functions\when('get_transient')->alias(static function ($k) {
            // "Nothing synced", answered from the cache so nothing reaches for a database that is not
            // there. total = 0 is what makes available_methods() fall back to declared capabilities.
            return strpos((string) $k, 'bgcouriers_typecnt_') === 0
                ? ['office' => 0, 'automat' => 0, 'total' => 0] : false;
        });
        Functions\when('set_transient')->justReturn(true);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_disabled_method_is_excluded(): void {
        // office disabled, the rest default to 'yes'
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $name === 'bgcouriers_speedy_office_enabled' ? 'no' : 'yes';
        });
        $this->assertSame(['address', 'automat'], BGCouriers_Settings::enabled_methods('speedy'));
    }

    public function test_all_enabled_by_default(): void {
        Functions\when('get_option')->justReturn('yes');
        $this->assertSame(['office', 'address', 'automat'], BGCouriers_Settings::enabled_methods('speedy'));
    }
}
