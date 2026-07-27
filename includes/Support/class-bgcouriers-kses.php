<?php
defined('ABSPATH') || exit;

/**
 * Allowed-tag sets for wp_kses(), so every block of markup this plugin builds is escaped AT THE POINT
 * IT IS ECHOED rather than trusted because the pieces were escaped earlier.
 *
 * The individual fields are still escaped where they are inserted (esc_html / esc_attr / esc_url) - that
 * is what keeps the values correct. This is the second gate: whatever the string turns out to contain, only
 * these tags and attributes can reach the page. It also means a future edit to one of these builders cannot
 * quietly introduce an XSS hole.
 */
class BGCouriers_Kses {
    /** Inline glyphs shared by both sets - our icons are inline SVG, plus courier logos as <img>. */
    private static function glyphs(): array {
        return [
            'img'    => ['class' => true, 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'data-tip' => true],
            'svg'    => ['class' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true,
                         'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true,
                         'aria-hidden' => true, 'xmlns' => true],
            'path'   => ['d' => true, 'fill' => true, 'stroke' => true],
            'rect'   => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true],
            'line'   => ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true],
            'circle' => ['cx' => true, 'cy' => true, 'r' => true],
            'polyline' => ['points' => true],
        ];
    }

    /**
     * Admin icon-only action tiles and settings nav pills: links and buttons whose behaviour is carried on
     * data-* attributes (the waybill to copy, the URL to confirm before following, the courier of a tab).
     */
    public static function admin_actions(): array {
        return self::glyphs() + [
            'span'   => ['class' => true, 'data-tip' => true, 'aria-label' => true, 'role' => true, 'tabindex' => true],
            'strong' => ['class' => true],
            'b'      => [],
            'a'      => ['class' => true, 'href' => true, 'target' => true, 'rel' => true, 'title' => true,
                         'aria-label' => true, 'data-tip' => true, 'data-courier' => true, 'data-id' => true,
                         'data-nonce' => true, 'data-gennonce' => true],
            'button' => ['type' => true, 'class' => true, 'aria-label' => true, 'title' => true, 'data-tip' => true,
                         'data-wb' => true, 'data-cancel-url' => true, 'data-regen-url' => true, 'data-method' => true],
            'div'    => ['class' => true, 'style' => true],
            'p'      => ['class' => true, 'style' => true],
        ];
    }

    /** The checkout delivery form: the courier wrapper plus its inputs, dropdowns and loader. */
    public static function checkout_fields(): array {
        return self::glyphs() + [
            'div'    => ['class' => true, 'style' => true, 'aria-hidden' => true, 'data-courier' => true,
                         'data-method' => true, 'data-methods' => true, 'data-order' => true, 'data-locker' => true],
            'span'   => ['class' => true, 'aria-hidden' => true, 'data-tip' => true, 'aria-label' => true],
            'label'  => ['class' => true, 'for' => true],
            'strong' => ['class' => true],
            'br'     => [],
            'select' => ['class' => true, 'style' => true, 'data-current' => true, 'name' => true, 'id' => true],
            'option' => ['value' => true, 'selected' => true],
            'input'  => ['type' => true, 'class' => true, 'value' => true, 'name' => true, 'id' => true,
                         'style' => true, 'placeholder' => true, 'autocomplete' => true, 'readonly' => true],
            'button' => ['type' => true, 'class' => true, 'title' => true, 'aria-label' => true, 'data-method' => true],
            'a'      => ['class' => true, 'href' => true, 'target' => true, 'rel' => true],
        ];
    }
}
