<?php
defined('ABSPATH') || exit;

class BGC_Order_Metabox {
    public function __construct() { add_action('add_meta_boxes', [$this, 'add']); }
    public function add(): void {
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('bgc_shipping', __('BG Couriers', 'bg-couriers'), [$this, 'render'], $screen, 'side');
    }
    public function render($post_or_order): void {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order || $order->get_meta('_bgc_courier') !== 'speedy') { echo esc_html__('No Speedy shipment.', 'bg-couriers'); return; }
        $waybill = (string) $order->get_meta('_bgc_waybill');
        $base = admin_url('admin-post.php');
        echo '<p><strong>' . esc_html__('Method', 'bg-couriers') . ':</strong> ' . esc_html($order->get_meta('_bgc_method')) . '</p>';
        echo '<p><strong>' . esc_html__('Office', 'bg-couriers') . ':</strong> ' . esc_html($order->get_meta('_bgc_office_id')) . '</p>';
        $qp = $order->get_meta('_bgc_quote_price');
        if ($qp !== '' && $qp !== null) {
            echo '<p><strong>' . esc_html__('Quoted price', 'bg-couriers') . ':</strong> ' . esc_html(number_format((float) $qp, 2) . ' ' . $order->get_currency()) . ' <em>(' . esc_html((string) $order->get_meta('_bgc_quote_source')) . ')</em></p>';
        }
        if ($waybill === '') {
            $url = wp_nonce_url($base . '?action=bgc_generate_label&order_id=' . $order->get_id(), 'bgc_generate_label');
            echo '<a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Generate label', 'bg-couriers') . '</a>';
        } else {
            echo '<p><strong>' . esc_html__('Waybill', 'bg-couriers') . ':</strong> ' . esc_html($waybill) . '</p>';
            if ($order->get_meta('_bgc_label_url')) {
                echo '<a class="button" target="_blank" href="' . esc_url($order->get_meta('_bgc_label_url')) . '">' . esc_html__('Print', 'bg-couriers') . '</a> ';
            }
            $track = wp_nonce_url($base . '?action=bgc_track&order_id=' . $order->get_id(), 'bgc_track');
            echo '<a class="button" target="_blank" href="' . esc_url($track) . '">' . esc_html__('Track', 'bg-couriers') . '</a>';
        }
    }
}
