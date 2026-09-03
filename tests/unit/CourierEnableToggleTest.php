<?php
use PHPUnit\Framework\TestCase;

/**
 * Switching a courier ON has to actually switch it on.
 *
 * The enable toggle on a courier's settings tab guards against a second click while the first is in
 * flight. It did that by disabling the checkbox - and then sent the form. jQuery leaves a disabled
 * control out of `.serialize()` (serializeArray filters on `!is(":disabled")`), so the POST carried no
 * `bgcouriers_<courier>_enabled` field, and WooCommerce reads a checkbox missing from the payload as
 * "no". The shop asked for a courier to be on and the plugin wrote off - for every courier, since the
 * toggle was written.
 *
 * Measured on the live shop 2026-09-03: Express One had credentials, validated, an empty
 * enable_problems() - and no `bgcouriers_expressone_enabled` row at all. Nothing was refusing it.
 * Nothing was ever being asked.
 *
 * This is JavaScript built as a PHP string, so it is pinned the way the ship-in-total defaults are: by
 * reading the source. A browser test would be better and there is no logged-in admin session to run one
 * with; this at least cannot silently come back.
 *
 * @group core
 */
final class CourierEnableToggleTest extends TestCase {
    /** The file with its PHP comments stripped: the fault is quoted in one of them, on purpose. */
    private static function source(): string {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-wc-settings.php');
        return (string) preg_replace('~^\s*//.*$~m', '', $src);
    }

    public function test_the_enable_checkbox_is_never_disabled_while_the_form_is_being_sent(): void {
        // Not "disabled after the post" or "disabled somewhere else" - the property is simply not what
        // guards this control any more, so the whole file may not set it on the toggle.
        $this->assertStringNotContainsString("cb.prop('disabled',true)", self::source(),
            'a disabled checkbox is not serialised, so this saves the courier OFF');
    }

    public function test_the_double_click_guard_is_a_class_on_the_row(): void {
        $src = self::source();
        $this->assertStringContainsString("box.addClass('bgc-busy')", $src);
        $this->assertStringContainsString("box.hasClass('bgc-busy')", $src);
        $this->assertStringContainsString("box.removeClass('bgc-busy')", $src);
    }

    /** A guard that only exists in JavaScript still lets the second click through. */
    public function test_the_busy_row_stops_taking_clicks(): void {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/bgc-settings-admin.css');
        $this->assertMatchesRegularExpression('/\.bgc-enable-toggle\.bgc-busy\s*\{[^}]*pointer-events\s*:\s*none/', $css);
    }

    public function test_a_save_that_failed_is_not_treated_as_one_that_worked(): void {
        $src = self::source();
        // saveForm tells its caller whether the save landed...
        $this->assertStringContainsString('if(cb){cb(false);}', $src, 'no form to send = not saved');
        $this->assertStringContainsString('ok=!!(r&&r.success)', $src);
        $this->assertStringContainsString('if(cb){cb(ok);}', $src);
        // ...the caller acts on it...
        $this->assertStringContainsString('saveForm(function(saved){', $src);
        $this->assertStringContainsString('if(!saved){', $src);
        // ...and the merchant is told, rather than left with a green toggle over an unwritten option.
        $this->assertStringContainsString('window.bgcToast', $src);
    }
}
