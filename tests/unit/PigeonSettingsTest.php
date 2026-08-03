<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Minimal stub for WC_Settings_Page so that class-bgcouriers-wc-settings.php can be loaded
 * in the unit suite without a running WordPress/WooCommerce environment.
 */
if (!class_exists('WC_Settings_Page')) {
    class WC_Settings_Page {
        protected $id    = '';
        protected $label = '';
        public function __construct() {}
    }
}

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-wc-settings.php';

/**
 * @group pigeon
 */
final class PigeonSettingsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Stub WordPress translation and option functions used during construction.
        Functions\when('__')->returnArg(1);
        Functions\when('apply_filters')->returnArg(2);
        // Field building reads options (creds_present gating, defaults) - default-return unless a test overrides.
        Functions\when('get_option')->alias(static function ($name, $default = false) { return $default; });
        Functions\when('get_woocommerce_currency')->justReturn('EUR');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helper: invoke a private method on a BGCouriers_WC_Settings instance.
    // -----------------------------------------------------------------------
    private function invoke(string $method, array $args = []) {
        $instance = new BGCouriers_WC_Settings();
        $rm = new ReflectionMethod(BGCouriers_WC_Settings::class, $method);
        // A no-op since PHP 8.1 (private methods are reachable without it) and deprecated in 8.5,
        // where convertDeprecationsToExceptions turned every test in this file into an error.
        // Still required on the 7.4 the plugin supports, so it is version-gated rather than dropped.
        if (PHP_VERSION_ID < 80100) { $rm->setAccessible(true); }
        return $rm->invokeArgs($instance, $args);
    }

    // -----------------------------------------------------------------------
    // sections() must contain 'pigeon'
    // -----------------------------------------------------------------------
    public function test_sections_contains_pigeon(): void {
        $sections = $this->invoke('sections');
        $this->assertArrayHasKey('pigeon', $sections,
            'sections() must include the "pigeon" key');
    }

    public function test_sections_retains_general_speedy_econt(): void {
        $sections = $this->invoke('sections');
        $this->assertArrayHasKey('', $sections,      'General section must remain');
        $this->assertArrayHasKey('speedy', $sections, 'Speedy section must remain');
        $this->assertArrayHasKey('econt', $sections,  'Econt section must remain');
    }

    // -----------------------------------------------------------------------
    // pigeon_courier_fields() must contain all required field ids
    // -----------------------------------------------------------------------
    public function test_pigeon_courier_fields_contains_required_ids(): void {
        $fields = $this->invoke('pigeon_courier_fields');

        $ids = array_column($fields, 'id');

        $required = [
            'bgcouriers_pigeon_enabled',
            'bgcouriers_pigeon_username',
            'bgcouriers_pigeon_password',
            'bgcouriers_pigeon_live',
            'bgcouriers_pigeon_pickup_office_id',
            'bgcouriers_pigeon_free_threshold',
        ];

        foreach ($required as $id) {
            $this->assertContains($id, $ids,
                "pigeon_courier_fields() must contain field id '{$id}'");
        }
    }

    // -----------------------------------------------------------------------
    // get_settings('pigeon') must return the pigeon fields merged with per-method fields
    // -----------------------------------------------------------------------
    public function test_get_settings_pigeon_includes_pigeon_fields(): void {
        $instance = new BGCouriers_WC_Settings();
        $settings = $instance->get_settings('pigeon');

        $ids = array_column($settings, 'id');

        $this->assertContains('bgcouriers_pigeon_enabled', $ids);
        $this->assertContains('bgcouriers_pigeon_password', $ids);
        $this->assertContains('bgcouriers_pigeon_live', $ids);
        $this->assertContains('bgcouriers_pigeon_pickup_office_id', $ids);
        // Per-method fields from method_fields('pigeon', 'office', …)
        $this->assertContains('bgcouriers_pigeon_office_enabled', $ids);
        $this->assertContains('bgcouriers_pigeon_address_enabled', $ids);
        $this->assertContains('bgcouriers_pigeon_automat_enabled', $ids);
    }

    // -----------------------------------------------------------------------
    // get_settings('speedy') and get_settings('econt') must be unchanged
    // -----------------------------------------------------------------------
    public function test_get_settings_speedy_unchanged(): void {
        $instance = new BGCouriers_WC_Settings();
        $settings = $instance->get_settings('speedy');

        $ids = array_column($settings, 'id');

        $this->assertContains('bgcouriers_speedy_enabled', $ids);
        $this->assertContains('bgcouriers_speedy_password', $ids);
        $this->assertContains('bgcouriers_speedy_office_enabled', $ids);
    }

    public function test_get_settings_econt_unchanged(): void {
        $instance = new BGCouriers_WC_Settings();
        $settings = $instance->get_settings('econt');

        $ids = array_column($settings, 'id');

        $this->assertContains('bgcouriers_econt_enabled', $ids);
        $this->assertContains('bgcouriers_econt_password', $ids);
        $this->assertContains('bgcouriers_econt_office_enabled', $ids);
    }
}
