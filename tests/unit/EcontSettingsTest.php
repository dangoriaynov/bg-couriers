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
 * @group econt
 */
final class EcontSettingsTest extends TestCase {

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
        $rm->setAccessible(true);
        return $rm->invokeArgs($instance, $args);
    }

    // -----------------------------------------------------------------------
    // sections() must contain 'econt'
    // -----------------------------------------------------------------------
    public function test_sections_contains_econt(): void {
        $sections = $this->invoke('sections');
        $this->assertArrayHasKey('econt', $sections,
            'sections() must include the "econt" key');
    }

    public function test_sections_retains_general_and_speedy(): void {
        $sections = $this->invoke('sections');
        $this->assertArrayHasKey('', $sections,    'General section must remain');
        $this->assertArrayHasKey('speedy', $sections, 'Speedy section must remain');
    }

    // -----------------------------------------------------------------------
    // econt_courier_fields() must contain all required field ids
    // -----------------------------------------------------------------------
    public function test_econt_courier_fields_contains_required_ids(): void {
        $fields = $this->invoke('econt_courier_fields');

        $ids = array_column($fields, 'id');

        $required = [
            'bgcouriers_econt_enabled',
            'bgcouriers_econt_username',
            'bgcouriers_econt_password',
            'bgcouriers_econt_label_paper_size',
            'bgcouriers_econt_free_threshold',
        ];

        foreach ($required as $id) {
            $this->assertContains($id, $ids,
                "econt_courier_fields() must contain field id '{$id}'");
        }
    }

    // -----------------------------------------------------------------------
    // get_settings('econt') must return the econt fields merged with per-method fields
    // -----------------------------------------------------------------------
    public function test_get_settings_econt_includes_econt_fields(): void {
        $instance = new BGCouriers_WC_Settings();
        $settings = $instance->get_settings('econt');

        $ids = array_column($settings, 'id');

        $this->assertContains('bgcouriers_econt_enabled', $ids);
        $this->assertContains('bgcouriers_econt_password', $ids);
        // Per-method fields from method_fields('econt', 'office', …)
        $this->assertContains('bgcouriers_econt_office_enabled', $ids);
        $this->assertContains('bgcouriers_econt_address_enabled', $ids);
        $this->assertContains('bgcouriers_econt_automat_enabled', $ids);
    }

    // -----------------------------------------------------------------------
    // get_settings('speedy') must be unchanged (Speedy section not broken)
    // -----------------------------------------------------------------------
    public function test_get_settings_speedy_unchanged(): void {
        $instance = new BGCouriers_WC_Settings();
        $settings = $instance->get_settings('speedy');

        $ids = array_column($settings, 'id');

        $this->assertContains('bgcouriers_speedy_enabled', $ids);
        $this->assertContains('bgcouriers_speedy_password', $ids);
        $this->assertContains('bgcouriers_speedy_office_enabled', $ids);
    }
}
