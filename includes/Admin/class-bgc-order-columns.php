<?php
defined('ABSPATH') || exit;

class BGC_Order_Columns {
    public function __construct() {
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'col']);     // HPOS
        add_filter('manage_edit-shop_order_columns', [$this, 'col']);                 // legacy
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render'], 10, 2);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_legacy'], 10, 2);
    }
    public function col($cols) { $cols['bgc_shipping'] = __('Speedy', 'bg-couriers'); return $cols; }
    public function render($column, $order): void {
        if ($column !== 'bgc_shipping') { return; }
        $waybill = (string) $order->get_meta('_bgc_waybill');
        echo $waybill ? esc_html($waybill) : '—';
    }
    public function render_legacy($column, $post_id): void {
        if ($column !== 'bgc_shipping') { return; }
        $this->render('bgc_shipping', wc_get_order($post_id));
    }
}
