<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * The "hidden checkout fields" setting is printed straight into a stylesheet, so this is its escaping
 * gate: the danger is a value that closes the selector and opens a rule of its own.
 *
 * @group core
 */
final class HiddenFieldSelectorsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('wp_strip_all_tags')->alias(static function ($s) { return strip_tags((string) $s); });
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function out(string $stored): string {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($stored) {
            return $n === 'bgcouriers_hidden_fields' ? $stored : $d;
        });
        return BGCouriers_Settings::hidden_field_selectors();
    }

    public function test_ordinary_selectors_pass_through(): void {
        $this->assertSame('#billing_company_field,.cart-subtotal',
            $this->out(' #billing_company_field , .cart-subtotal '));
        $this->assertSame('input[type="tel"]', $this->out('input[type="tel"]'));
        $this->assertSame('.woocommerce-checkout .form-row > label', $this->out('.woocommerce-checkout .form-row > label'));
        $this->assertSame('li:not(.first)', $this->out('li:not(.first)'));
    }

    /**
     * The property that matters: whatever is stored, the stylesheet we print must stay ONE rule. An entry
     * that could close the selector and open its own is dropped. (Something like "<style>x</style>" reduces
     * to the tag selector "x" - useless, but inert, which is fine.)
     */
    public function test_no_stored_value_can_escape_the_selector(): void {
        foreach ([
            '#a{display:none} body{display:none',
            '#a}html{background:url(evil)',
            '@import "http://evil"',
            '#a;color:red',
            '#a\\3c script',
            '#a/*x*/',
            '<style>x</style>',
            '#a{}*',
        ] as $bad) {
            $css = $this->out($bad) . '{display:none !important;}';
            $this->assertSame(1, substr_count($css, '{'), 'opened a second rule: ' . $bad);
            $this->assertSame(1, substr_count($css, '}'), 'closed a rule early: ' . $bad);
            foreach (['@', ';', '<', '/*'] as $ch) {
                $this->assertStringNotContainsString($ch, $this->out($bad), 'value: ' . $bad);
            }
        }
    }

    /** The outright dangerous forms produce nothing at all. */
    public function test_rule_breaking_entries_are_dropped_entirely(): void {
        foreach (['#a{display:none} body{display:none', '@import "http://evil"', '#a;color:red', '#a/*x*/'] as $bad) {
            $this->assertSame('', $this->out($bad), 'value: ' . $bad);
        }
    }

    /** A bad entry must not take the good ones with it, nor be half-kept. */
    public function test_only_the_offending_entry_is_dropped(): void {
        $this->assertSame('#keep_me,.also-keep', $this->out('#keep_me, #bad{}, .also-keep'));
    }

    public function test_empty_and_absurd_input(): void {
        $this->assertSame('', $this->out(''));
        $this->assertSame('', $this->out('   ,  , '));
        $this->assertSame('', $this->out('#' . str_repeat('a', 250)), 'absurdly long entries are dropped');
    }
}
