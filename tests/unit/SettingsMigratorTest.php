<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey; use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings-migrator.php';

/**
 * @group core
 */
final class SettingsMigratorTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_migrate_sets_version_when_absent(): void {
        Functions\when('get_option')->justReturn(false);
        Functions\when('delete_option')->justReturn(true);
        $saved = null;
        Functions\when('update_option')->alias(function ($k, $v) use (&$saved) { if ($k === 'bgcouriers_settings_version') { $saved = $v; } return true; });
        BGCouriers_Settings_Migrator::migrate();
        $this->assertSame(BGCouriers_Settings_Migrator::VERSION, $saved);
    }

    /**
     * The regression this pins: dropping bgcouriers_dual_currency was first done inside the $current < 2
     * step, so an install already at version 3 - i.e. every existing one - never ran it and kept the
     * option forever. The step has to be reachable from the version the field actually sits at.
     */
    public function test_migrate_drops_dual_currency_on_an_install_already_at_v3(): void {
        Functions\when('get_option')->alias(function ($k, $d = false) {
            return $k === 'bgcouriers_settings_version' ? 3 : $d;
        });
        Functions\when('update_option')->justReturn(true);
        $deleted = [];
        Functions\when('delete_option')->alias(function ($k) use (&$deleted) { $deleted[] = $k; return true; });
        BGCouriers_Settings_Migrator::migrate();
        $this->assertContains('bgcouriers_dual_currency', $deleted);
    }

    public function test_migrate_skips_when_version_current(): void {
        Functions\when('get_option')->justReturn(BGCouriers_Settings_Migrator::VERSION);
        Functions\expect('update_option')->never();
        BGCouriers_Settings_Migrator::migrate();
        $this->assertTrue(true); // Brain Monkey expectation enforced above; explicit assertion avoids risky flag.
    }
}
