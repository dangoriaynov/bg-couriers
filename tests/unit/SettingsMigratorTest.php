<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey; use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-settings-migrator.php';

final class SettingsMigratorTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_migrate_sets_version_when_absent(): void {
        Functions\when('get_option')->justReturn(false);
        $saved = null;
        Functions\when('update_option')->alias(function ($k, $v) use (&$saved) { if ($k === 'bgc_settings_version') { $saved = $v; } return true; });
        BGC_Settings_Migrator::migrate();
        $this->assertSame(BGC_Settings_Migrator::VERSION, $saved);
    }
}
