<?php
defined('ABSPATH') || exit;

/** Renders the shipment panel (waybill + generate/print/track) at the TOP of a BG Couriers order. */
class BGC_Order_Metabox {
    public function __construct() {
        // The order-data panel (after the shipping address) — visible at the top, both HPOS + legacy.
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'render'], 10, 1);
    }

    public function render($order): void {
        $courier = BGC_Labels::order_courier($order); // any BG Couriers order, not just Speedy
        if (!$courier) { return; }
        $id      = $order->get_id();
        $waybill = (string) $order->get_meta('_bgc_waybill');
        $method  = (string) $order->get_meta('_bgc_method');
        $base    = admin_url('admin-post.php');

        echo '<div class="bgc-order-panel" style="margin-top:12px;padding:12px 14px;border:1px solid #e2e6ea;border-radius:8px;background:#fff;">';
        echo '<p style="margin:0 0 8px;"><strong>' . esc_html($courier->label()) . '</strong> — ' . esc_html(ucfirst($method ?: 'office')) . '</p>';

        if ($waybill === '') {
            $gen = wp_nonce_url($base . '?action=bgc_generate_label&order_id=' . $id, 'bgc_generate_label_' . $id);
            echo '<a class="button button-primary" href="' . esc_url($gen) . '">' . esc_html__('Generate label', 'bg-couriers') . '</a>';
        } else {
            $print = wp_nonce_url($base . '?action=bgc_print_batch&order_id=' . $id, 'bgc_print_batch');
            $track = wp_nonce_url($base . '?action=bgc_track&order_id=' . $id, 'bgc_track_' . $id);
            echo '<p style="margin:0 0 8px;"><strong>' . esc_html__('Waybill', 'bg-couriers') . ':</strong> <code>' . esc_html($waybill) . '</code></p>';
            echo '<a class="button button-primary" target="_blank" href="' . esc_url($print) . '">' . esc_html__('Print label', 'bg-couriers') . '</a> ';
            echo '<a class="button" target="_blank" href="' . esc_url($track) . '">' . esc_html__('Track', 'bg-couriers') . '</a>';
        }
        echo '</div>';
    }
}
