<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

if (!class_exists('WC_Settings_Page')) {
    class WC_Settings_Page {
        protected $id    = '';
        protected $label = '';
        public function __construct() {}
    }
}

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-wc-settings.php';

/**
 * No courier credential field may be offered to the browser's password manager.
 *
 * This is not theoretical: on the live shop the BOX NOW "Client ID" was rendered blank and editable,
 * Chrome filled it with the merchant's own e-mail address and the site password, and a Save wrote both
 * over the real credentials. Locking already-saved credentials behind the ✕ covers a configured
 * courier; these attributes cover the rest - a courier being set up for the first time, and any courier
 * added to the plugin later, since the rule is applied to the finished field list rather than repeated
 * in each declaration.
 *
 * @group core
 */
final class CredentialAutofillTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('get_option')->alias(static function ($name, $default = false) { return $default; });
        Functions\when('get_woocommerce_currency')->justReturn('EUR');
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('admin_url')->returnArg(1);
        Functions\when('wc_get_weight')->returnArg(1);
    }

    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @return array<int,array<string,mixed>> every credential field across every courier section */
    private function credential_fields(): array {
        $page = new BGCouriers_WC_Settings();
        $out  = [];
        foreach (['general', 'speedy', 'econt', 'pigeon', 'boxnow', 'sameday'] as $section) {
            foreach ($page->get_settings($section) as $f) {
                if (is_array($f) && preg_match('/^bgcouriers_[a-z0-9]+_(username|password)$/', (string) ($f['id'] ?? ''))) {
                    $out[] = $f;
                }
            }
        }
        return $out;
    }

    public function test_every_courier_has_its_credential_fields_found(): void {
        $ids = array_column($this->credential_fields(), 'id');
        foreach (['speedy', 'econt', 'pigeon', 'boxnow', 'sameday'] as $c) {
            $this->assertContains('bgcouriers_' . $c . '_username', $ids, "$c username field");
            $this->assertContains('bgcouriers_' . $c . '_password', $ids, "$c password field");
        }
    }

    public function test_no_credential_field_accepts_autofill(): void {
        $fields = $this->credential_fields();
        $this->assertNotEmpty($fields);
        foreach ($fields as $f) {
            $attrs = $f['custom_attributes'] ?? [];
            $this->assertIsArray($attrs, $f['id'] . ' must carry custom attributes');
            // 'off' is not enough - Chrome ignores it on anything it reads as a login field.
            $this->assertSame('new-password', $attrs['autocomplete'] ?? null, $f['id'] . ' autocomplete');
            // Autofill lands before any of our JS runs; a readonly field is skipped.
            $this->assertSame('readonly', $attrs['readonly'] ?? null, $f['id'] . ' readonly');
            $this->assertSame('1', $attrs['data-bgc-nofill'] ?? null, $f['id'] . ' focus-release hook');
        }
    }

    /** The guard must not have eaten the placeholder and other attributes already on those fields. */
    public function test_existing_attributes_survive(): void {
        $page = new BGCouriers_WC_Settings();
        foreach ($page->get_settings('speedy') as $f) {
            if (($f['id'] ?? '') === 'bgcouriers_speedy_password') {
                $this->assertArrayHasKey('placeholder', $f['custom_attributes']);
                return;
            }
        }
        $this->fail('the Speedy password field was not rendered at all');
    }

    /** Ordinary settings must be left alone - only credentials get the treatment. */
    public function test_non_credential_fields_are_untouched(): void {
        $page = new BGCouriers_WC_Settings();
        foreach ($page->get_settings('boxnow') as $f) {
            if (($f['id'] ?? '') === 'bgcouriers_boxnow_partner_id') {
                $this->assertArrayNotHasKey('readonly', $f['custom_attributes'] ?? [],
                    'a Partner ID is not a credential and must stay normally editable');
                return;
            }
        }
        $this->fail('the BOX NOW Partner ID field was not rendered at all');
    }
}
