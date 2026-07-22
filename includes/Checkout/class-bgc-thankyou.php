<?php
defined('ABSPATH') || exit;

/**
 * Order summary for the "thank you" step.
 *
 * Two surfaces share the same renderers:
 *  - WC's native order-received page gets a delivery card (courier logo, delivery option, exact
 *    office/APS/address) via the woocommerce_thankyou hook - WC already prints the order number,
 *    items and totals there, so only the card is added.
 *  - The [bgc_order_summary] shortcode renders the FULL summary (thank-you heading with the order
 *    number, items, totals, delivery card) for shops whose thank-you page is a custom WP page.
 *    It reads the order id + key from the URL WC passes along (?order=<id>&key=wc_order_...) and
 *    shows nothing unless the key matches the order, so a guessed order id reveals nothing.
 *    Attributes: heading="no" drops the heading, items="no" drops the items/totals block.
 */
class BGC_Thankyou {
    public function __construct() {
        add_action('woocommerce_thankyou', [$this, 'native_card'], 4); // before WC's own details table
        add_shortcode('bgc_order_summary', [$this, 'shortcode']);
    }

    public function native_card($order_id): void {
        $order = wc_get_order((int) $order_id);
        if (!$order || (string) $order->get_meta('_bgc_courier') === '') { return; }
        self::styles();
        echo wp_kses(self::delivery_card($order), self::TAGS);
    }

    public function shortcode($atts = []): string {
        $a = shortcode_atts(['heading' => 'yes', 'items' => 'yes'], (array) $atts, 'bgc_order_summary');
        $order = self::order_from_request();
        if (!$order) { return ''; }
        self::styles();
        $html = '<div class="bgc-thankyou">';
        if ($a['heading'] !== 'no') {
            /* translators: %s = the order number */
            $html .= '<h2 class="bgc-ty-heading">' . esc_html(sprintf(__('Thank you for your order #%s!', 'bg-couriers'), $order->get_order_number())) . '</h2>';
        }
        if ($a['items'] !== 'no') { $html .= self::items_block($order); }
        if ((string) $order->get_meta('_bgc_courier') !== '') { $html .= self::delivery_card($order); }
        $html .= '</div>';
        return wp_kses($html, self::TAGS);
    }

    /** The order WC linked to: ?order=<id>&key=wc_order_... (also WC's own order-received query vars). */
    private static function order_from_request(): ?\WC_Order {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only render; the order key IS the credential.
        $id  = absint(wp_unslash($_GET['order'] ?? ($_GET['order-received'] ?? 0)));
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        // phpcs:enable
        if (!$id && $key !== '' && function_exists('wc_get_order_id_by_order_key')) {
            $id = (int) wc_get_order_id_by_order_key($key);
        }
        if (!$id || $key === '') { return null; }
        $order = wc_get_order($id);
        if (!$order || !hash_equals($order->get_order_key(), $key)) { return null; }
        return $order;
    }

    /** Items + WC's canonical totals rows (subtotal / shipping / payment method / total). */
    private static function items_block(\WC_Order $order): string {
        $html = '<div class="bgc-ty-box bgc-ty-items"><div class="bgc-ty-title">' . esc_html__('Your order', 'bg-couriers') . '</div>';
        foreach ($order->get_items() as $item) {
            $html .= '<div class="bgc-ty-row">'
                . '<span class="bgc-ty-name">' . esc_html($item->get_name()) . ' <span class="bgc-ty-qty">× ' . esc_html((string) $item->get_quantity()) . '</span></span>'
                . '<span class="bgc-ty-amount">' . wp_kses_post($order->get_formatted_line_subtotal($item)) . '</span>'
                . '</div>';
        }
        foreach ($order->get_order_item_totals() as $key => $total) {
            $cls = $key === 'order_total' ? ' bgc-ty-total' : '';
            $html .= '<div class="bgc-ty-row' . $cls . '">'
                . '<span class="bgc-ty-name">' . esc_html(wp_strip_all_tags((string) $total['label'])) . '</span>'
                . '<span class="bgc-ty-amount">' . wp_kses_post((string) $total['value']) . '</span>'
                . '</div>';
        }
        $courier = (string) $order->get_meta('_bgc_courier');
        if ($courier !== '' && !BGC_Settings::ship_in_total($courier)) {
            $html .= '<div class="bgc-ty-note">' . esc_html__('The delivery fee is paid to the courier on delivery.', 'bg-couriers') . '</div>';
        }
        return $html . '</div>';
    }

    /** Where the parcel goes: courier logo + delivery option + the exact point (from the order address). */
    private static function delivery_card(\WC_Order $order): string {
        $courier = (string) $order->get_meta('_bgc_courier');
        $method  = (string) $order->get_meta('_bgc_method');
        $labels  = BGC_Couriers::all();
        $mlabels = ['office' => __('To office', 'bg-couriers'), 'address' => __('To address', 'bg-couriers'), 'automat' => __('To APS', 'bg-couriers')];
        $logo    = BGC_Couriers::logo_url($courier);
        $lines   = array_filter([
            trim((string) $order->get_shipping_address_1()),
            trim((string) $order->get_shipping_address_2()),
            trim(trim((string) $order->get_shipping_postcode() . ' ' . (string) $order->get_shipping_city())),
        ]);
        $html = '<div class="bgc-ty-box bgc-ty-delivery"><div class="bgc-ty-title">' . esc_html__('Delivery', 'bg-couriers') . '</div>'
            . '<div class="bgc-ty-courier">'
            . ($logo !== '' ? '<img class="bgc-ty-logo" src="' . esc_url($logo) . '" alt="" width="20" height="20"> ' : '')
            . '<strong>' . esc_html((string) ($labels[$courier] ?? ucfirst($courier))) . '</strong>'
            . ($method !== '' && isset($mlabels[$method]) ? ' — ' . esc_html($mlabels[$method]) : '')
            . '</div>';
        foreach ($lines as $line) {
            $html .= '<div class="bgc-ty-line">' . esc_html($line) . '</div>';
        }
        return $html . '</div>';
    }

    /** Card styles, printed once next to the first rendered block (static CSS, theme-neutral). */
    private static function styles(): void {
        static $done = false;
        if ($done) { return; }
        $done = true;
        echo '<style>
        .bgc-thankyou{margin:1.2em 0}
        .bgc-ty-heading{margin:0 0 .8em;text-align:center}
        .bgc-ty-box{border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin:0 auto 14px;max-width:560px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04);text-align:left}
        .bgc-ty-title{font-weight:600;margin-bottom:8px;font-size:1.05em}
        .bgc-ty-row{display:flex;justify-content:space-between;gap:12px;padding:4px 0;border-bottom:1px dashed #eef0f3}
        .bgc-ty-row:last-child{border-bottom:0}
        .bgc-ty-row.bgc-ty-total{border-top:1px solid #e5e7eb;border-bottom:0;margin-top:4px;padding-top:8px;font-weight:700}
        .bgc-ty-qty{color:#6b7280;font-size:.92em}
        .bgc-ty-amount{white-space:nowrap}
        .bgc-ty-note{color:#6b7280;font-size:.9em;margin-top:6px}
        .bgc-ty-courier{display:flex;align-items:center;gap:6px;margin-bottom:6px}
        .bgc-ty-logo{width:20px;height:20px;object-fit:contain}
        .bgc-ty-line{color:#374151;line-height:1.45}
        </style>';
    }

    /** Allowed markup for the summary (incl. wc_price spans/bdi and the courier logo img). */
    private const TAGS = [
        'div'    => ['class' => true],
        'h2'     => ['class' => true],
        'span'   => ['class' => true],
        'strong' => [],
        'bdi'    => [],
        'del'    => ['aria-hidden' => true],
        'ins'    => [],
        'small'  => ['class' => true],
        'img'    => ['class' => true, 'src' => true, 'alt' => true, 'width' => true, 'height' => true],
    ];
}
