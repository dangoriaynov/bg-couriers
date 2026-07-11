<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Minimal stub for WC_Settings_Page so that class-bgc-wc-settings.php can be loaded
 * in the unit suite without a running WordPress/WooCommerce environment.
 */
if (!class_exists('WC_Settings_Page')) {
    class WC_Settings_Page {
        protected $id    = '';
        protected $label = '';
        public function __construct() {}
    }
}

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-wc-settings.php';

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
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helper: invoke a private method on a BGC_WC_Settings instance.
    // -----------------------------------------------------------------------
    private function invoke(string $method, array $args = []) {
        $instance = new BGC_WC_Settings();
        $rm = new ReflectionMethod(BGC_WC_Settings::class, $method);
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
            'bgc_econt_enabled',
            'bgc_econt_username',
            'bgc_econt_password',
            'bgc_econt_paper_size',
            'bgc_econt_free_threshold',
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
        $instance = new BGC_WC_Settings();
        $settings = $instance->get_settings('econt');

        $ids = array_column($settings, 'id');

        $this->assertContains('bgc_econt_enabled', $ids);
        $this->assertContains('bgc_econt_password', $ids);
        // Per-method fields from method_fields('econt', 'office', …)
        $this->assertContains('bgc_econt_office_enabled', $ids);
        $this->assertContains('bgc_econt_address_enabled', $ids);
        $this->assertContains('bgc_econt_automat_enabled', $ids);
    }

    // -----------------------------------------------------------------------
    // get_settings('speedy') must be unchanged (Speedy section not broken)
    // -----------------------------------------------------------------------
    public function test_get_settings_speedy_unchanged(): void {
        $instance = new BGC_WC_Settings();
        $settings = $instance->get_settings('speedy');

        $ids = array_column($settings, 'id');

        $this->assertContains('bgc_speedy_enabled', $ids);
        $this->assertContains('bgc_speedy_password', $ids);
        $this->assertContains('bgc_speedy_office_enabled', $ids);
    }
}
