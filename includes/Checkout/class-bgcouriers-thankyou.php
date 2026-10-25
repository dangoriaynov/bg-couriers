<?php
defined('ABSPATH') || exit;

/** Delivery card on WC's order-received page, plus the [bgcouriers_order_summary] shortcode for custom thank-you pages. */
class BGCouriers_Thankyou {
    public function __construct() {
        add_action('woocommerce_thankyou', [$this, 'native_card'], 4);
        add_shortcode('bgcouriers_order_summary', [$this, 'shortcode']);
    }

    public function native_card($order_id): void {
        $order = wc_get_order((int) $order_id);
        if (!$order || (string) $order->get_meta('_bgcouriers_courier') === '') { return; }
        self::styles();
        echo wp_kses(self::delivery_card($order), self::TAGS);
    }

    public function shortcode($atts = []): string {
        $a = shortcode_atts(['heading' => 'yes', 'items' => 'yes', 'delivery' => 'yes'], (array) $atts, 'bgcouriers_order_summary');
        $order = self::order_from_request();
        if (!$order) { return ''; }
        self::styles();
        $html = '<div class="bgc-thankyou">';
        if ($a['heading'] === 'number') {
            $date = $order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '';
            /* translators: 1: the order number, 2: the order date */
            $html .= '<h2 class="bgc-ty-heading">' . esc_html(sprintf(__('Order #%1$s · %2$s', 'bg-couriers'), $order->get_order_number(), $date)) . '</h2>';
        } elseif ($a['heading'] !== 'no') {
            /* translators: %s = the order number */
            $html .= '<h2 class="bgc-ty-heading">' . esc_html(sprintf(__('Thank you for your order #%s!', 'bg-couriers'), $order->get_order_number())) . '</h2>';
        }
        if ($a['items'] !== 'no') { $html .= self::items_block($order); }
        if ($a['delivery'] !== 'no' && (string) $order->get_meta('_bgcouriers_courier') !== '') { $html .= self::delivery_card($order); }
        $html .= '</div>';
        return wp_kses($html, self::TAGS);
    }

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

    private static function items_block(\WC_Order $order): string {
        $html = '<div class="bgc-ty-box bgc-ty-items"><div class="bgc-ty-title">' . esc_html__('Your order', 'bg-couriers') . '</div>';
        foreach ($order->get_items() as $item) {
            $html .= '<div class="bgc-ty-row">'
                . '<span class="bgc-ty-name">' . esc_html($item->get_name()) . ' <span class="bgc-ty-qty">× ' . esc_html((string) $item->get_quantity()) . '</span></span>'
                . '<span class="bgc-ty-amount">' . wp_kses_post($order->get_formatted_line_subtotal($item)) . '</span>'
                . '</div>';
        }
        $priced = false;
        foreach ($order->get_order_item_totals() as $key => $total) {
            $cls = $key === 'order_total' ? ' bgc-ty-total' : '';
            $priced = $priced || strpos((string) $total['value'], 'bgc-door-price') !== false;
            $html .= '<div class="bgc-ty-row' . $cls . '">'
                . '<span class="bgc-ty-name">' . esc_html(wp_strip_all_tags((string) $total['label'])) . '</span>'
                . '<span class="bgc-ty-amount">' . wp_kses_post((string) $total['value']) . '</span>'
                . '</div>';
        }
        // The shipping row already says it with the price (BGCouriers_Checkout::order_shipping_row).
        $courier = (string) $order->get_meta('_bgcouriers_courier');
        if (!$priced && $courier !== '' && !BGCouriers_Settings::ship_in_total($courier)) {
            $html .= '<div class="bgc-ty-note">' . esc_html__('The delivery fee is paid to the courier on delivery.', 'bg-couriers') . '</div>';
        }
        return $html . '</div>';
    }

    private static function delivery_card(\WC_Order $order): string {
        $courier = (string) $order->get_meta('_bgcouriers_courier');
        $method  = (string) $order->get_meta('_bgcouriers_method');
        $labels  = BGCouriers_Couriers::all();
        $mlabels = ['office' => __('To office', 'bg-couriers'), 'address' => __('To address', 'bg-couriers'), 'automat' => __('To APS', 'bg-couriers')];
        $logo    = BGCouriers_Couriers::logo_url($courier);
        $lines   = array_filter([
            trim((string) $order->get_shipping_address_1()),
            trim((string) $order->get_shipping_address_2()),
            trim(trim((string) $order->get_shipping_postcode() . ' ' . (string) $order->get_shipping_city())),
        ]);
        $html = '<div class="bgc-ty-box bgc-ty-delivery"><div class="bgc-ty-title">' . esc_html__('Delivery', 'bg-couriers') . '</div>'
            . '<div class="bgc-ty-courier">'
            . ($logo !== '' ? '<img class="bgc-ty-logo" src="' . esc_url($logo) . '" alt="" width="20" height="20"> ' : '')
            . '<strong>' . esc_html((string) ($labels[$courier] ?? ucfirst($courier))) . '</strong>'
            . ($method !== '' && isset($mlabels[$method]) ? ' - ' . esc_html($mlabels[$method]) : '')
            . '</div>';
        foreach ($lines as $line) {
            $html .= '<div class="bgc-ty-line">' . esc_html($line) . '</div>';
        }
        return $html . '</div>';
    }

    private static function styles(): void {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $css = BGCOURIERS_PATH . 'assets/css/bgc-thankyou.css';
        wp_enqueue_style('bgc-thankyou', BGCOURIERS_URL . 'assets/css/bgc-thankyou.css', [], is_file($css) ? (string) filemtime($css) : BGCOURIERS_VERSION);
    }

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
