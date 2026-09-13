<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings-admin.php';

/**
 * The "Checkout dropdown results" field: a cleared or nonsense value falls back to the SAME default the
 * reader and the field itself use. It fell back to 5 for two months after the default became 20.
 *
 * @group core
 */
final class DropdownLimitSanitizeTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function sanitizer(): BGCouriers_Settings_Admin {
        // The constructor registers two dozen hooks; the sanitizer needs none of them.
        return (new ReflectionClass(BGCouriers_Settings_Admin::class))->newInstanceWithoutConstructor();
    }

    public function test_a_cleared_field_resets_to_the_readers_default(): void {
        $s = $this->sanitizer();
        $this->assertSame((string) BGCouriers_Settings::DROPDOWN_LIMIT, $s->sanitize_dropdown_limit('', 'bgcouriers_dropdown_limit', ''));
        $this->assertSame((string) BGCouriers_Settings::DROPDOWN_LIMIT, $s->sanitize_dropdown_limit('0', 'bgcouriers_dropdown_limit', '0'));
        $this->assertSame((string) BGCouriers_Settings::DROPDOWN_LIMIT, $s->sanitize_dropdown_limit('-3', 'bgcouriers_dropdown_limit', '-3'));
        $this->assertSame('20', (string) BGCouriers_Settings::DROPDOWN_LIMIT, 'and that default is 20, what the field shows');
    }

    public function test_a_real_number_is_kept(): void {
        $this->assertSame('7', $this->sanitizer()->sanitize_dropdown_limit('7', 'bgcouriers_dropdown_limit', '7'));
        $this->assertSame('12', $this->sanitizer()->sanitize_dropdown_limit('12.9', 'bgcouriers_dropdown_limit', '12.9'), 'whole numbers only');
    }
}
