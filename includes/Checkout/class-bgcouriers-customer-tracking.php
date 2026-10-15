<?php
defined('ABSPATH') || exit;

/** Waybill and tracking link for the customer: on the order page and in customer emails. */
class BGCouriers_Customer_Tracking {
    public function __construct() {
        add_action('woocommerce_order_details_after_order_table', [$this, 'on_order_page'], 20);
        add_action('woocommerce_email_after_order_table', [$this, 'in_email'], 20, 4);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void {
        if (!function_exists('is_order_received_page')) { return; }
        if (!is_order_received_page() && !is_view_order_page() && !is_account_page()) { return; }
        $css = BGCOURIERS_PATH . 'assets/css/bgc-track.css';
        wp_enqueue_style('bgc-track', BGCOURIERS_URL . 'assets/css/bgc-track.css', [],
            is_file($css) ? (string) filemtime($css) : BGCOURIERS_VERSION);
    }

    public function on_order_page($order): void {
        if (!$order instanceof \WC_Order) { return; }
        $html = self::panel($order, false);
        if ($html === '') { return; }
        echo wp_kses_post($html);
    }

    public function in_email($order, $sent_to_admin = false, $plain_text = false, $email = null): void {
        if (!$order instanceof \WC_Order || $sent_to_admin) { return; }
        if ($plain_text) {
            $line = self::plain($order);
            if ($line !== '') { echo "\n" . esc_html($line) . "\n"; }
            return;
        }
        $html = self::panel($order, true);
        if ($html === '') { return; }
        echo wp_kses_post($html);
    }

    private static function courier_of(\WC_Order $order) {
        $cid = (string) $order->get_meta('_bgcouriers_courier');
        return $cid !== '' ? BGCouriers_Couriers::get($cid) : null;
    }

    /** @param bool $for_email inline styles, since mail clients strip <style> blocks */
    private static function panel(\WC_Order $order, bool $for_email): string {
        $waybill = (string) $order->get_meta('_bgcouriers_waybill');
        if ($waybill === '') { return ''; }
        $courier = self::courier_of($order);
        $name    = $courier ? $courier->label() : (string) $order->get_meta('_bgcouriers_courier');
        $url     = $courier ? $courier->tracking_url($waybill) : '';

        $box  = $for_email
            ? 'style="margin:16px 0;padding:14px 16px;border:1px solid #e0e4ea;border-radius:6px;background:#f7f9fb;"'
            : 'class="bgc-track-box"';
        $ttl  = $for_email ? 'style="font-weight:700;margin:0 0 6px;"' : 'class="bgc-track-title"';
        $row  = $for_email ? 'style="margin:0 0 4px;color:#3c434a;"' : 'class="bgc-track-row"';

        $html  = '<div ' . $box . '>';
        $html .= '<p ' . $ttl . '>' . esc_html__('Your parcel', 'bg-couriers') . '</p>';
        /* translators: %s: courier name, e.g. Speedy */
        $html .= '<p ' . $row . '>' . esc_html(sprintf(__('Courier: %s', 'bg-couriers'), $name)) . '</p>';
        /* translators: %s: the courier's waybill (tracking) number */
        $html .= '<p ' . $row . '>' . esc_html(sprintf(__('Waybill: %s', 'bg-couriers'), $waybill)) . '</p>';
        if ($url !== '') {
            $link = $for_email
                ? 'style="color:#2271b1;font-weight:700;"'
                : 'class="bgc-track-link"';
            $html .= '<p ' . $row . '><a ' . $link . ' href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__('Track this parcel', 'bg-couriers') . '</a></p>';
        }
        $html .= '</div>';
        return $html;
    }

    private static function plain(\WC_Order $order): string {
        $waybill = (string) $order->get_meta('_bgcouriers_waybill');
        if ($waybill === '') { return ''; }
        $courier = self::courier_of($order);
        $name    = $courier ? $courier->label() : (string) $order->get_meta('_bgcouriers_courier');
        $url     = $courier ? $courier->tracking_url($waybill) : '';
        /* translators: 1: courier name, 2: waybill number */
        $out = sprintf(__('Your parcel - courier: %1$s, waybill: %2$s', 'bg-couriers'), $name, $waybill);
        if ($url !== '') { $out .= ' - ' . $url; }
        return $out;
    }
}
