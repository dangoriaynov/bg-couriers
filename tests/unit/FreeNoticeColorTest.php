<?php
use PHPUnit\Framework\TestCase;

/**
 * The free-shipping notice keeps the text colour its theme gave it.
 *
 * The notice is a `.woocommerce-info`, and the theme paints that panel and picks a legible text colour
 * for it - Razzi: blue with white text. One rule of ours was meant to stop the NESTED price span from
 * using its own accent colour on that panel (blue-on-blue) and said `.bgc-free-notice, .bgc-free-notice
 * * { color: inherit !important }`. The first selector is the notice ITSELF: `inherit` there takes the
 * page's body colour, not the theme's, and `!important` beats the theme's white. A shop on Razzi got
 * dark grey on blue and asked whether the colour was a setting. It was this line.
 *
 * So: the descendants inherit, the element does not get told. A rule that names the notice itself and
 * sets its colour is the bug coming back.
 *
 * @group core
 */
final class FreeNoticeColorTest extends TestCase {
    /** The stylesheet without its comments, so a selector is read as written and not as the prose above it. */
    private static function css(): string {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/bgc-checkout.css');
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** Every rule that sets a colour and names the notice must name its DESCENDANTS, never the notice alone. */
    public function test_the_notice_element_itself_is_never_told_its_colour(): void {
        preg_match_all('/([^{}]+)\{([^}]*)\}/', self::css(), $rules, PREG_SET_ORDER);
        $offenders = [];
        foreach ($rules as [, $selectors, $body]) {
            if (!preg_match('/(^|[^-])color\s*:/', $body)) { continue; }
            foreach (explode(',', $selectors) as $sel) {
                // The notice by itself, with nothing after it: no descendant, no child, no pseudo-class.
                if (preg_match('/(^|\s)\.bgc-free-notice\s*$/', trim($sel))) { $offenders[] = trim($sel); }
            }
        }
        $this->assertSame([], $offenders, 'these selectors set a colour on the notice element itself');
    }

    /** What the rule was for still holds: the nested spans take the notice's colour, whatever the theme chose. */
    public function test_the_nested_spans_inherit_the_notice_colour(): void {
        $this->assertMatchesRegularExpression('/\.bgc-free-notice\s+\*\s*\{[^}]*color\s*:\s*inherit\s*!important/', self::css());
    }
}
