<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Order_Columns {
    public function __construct() {
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'col']);
        add_filter('manage_edit-shop_order_columns', [$this, 'col']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render'], 10, 2);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_legacy'], 10, 2);
    }
    public function col($cols) { $cols['bgc_shipping'] = __('Speedy', 'bg-couriers'); return $cols; }

    public static function cell_html(string $waybill, string $print_url, string $track_url, string $generate_url): string {
        if ($waybill === '') {
            return '<a class="button button-small" href="' . esc_url($generate_url) . '">' . esc_html__('Generate', 'bg-couriers') . '</a>';
        }
        return '<strong>' . esc_html($waybill) . '</strong><br>'
            . '<a target="_blank" href="' . esc_url($print_url) . '">' . esc_html__('Print', 'bg-couriers') . '</a> | '
            . '<a target="_blank" href="' . esc_url($track_url) . '">' . esc_html__('Track', 'bg-couriers') . '</a>';
    }

    public function render($column, $order): void {
        if ($column !== 'bgc_shipping' || !$order) { return; }
        if ($order->get_meta('_bgc_courier') !== 'speedy') { echo '—'; return; }
        $id = $order->get_id();
        $base = admin_url('admin-post.php');
        $print = wp_nonce_url($base . '?action=bgc_print_batch&order_id=' . $id, 'bgc_print_batch');
        $track = wp_nonce_url($base . '?action=bgc_track&order_id=' . $id, 'bgc_track_' . $id);
        $gen   = wp_nonce_url($base . '?action=bgc_generate_label&order_id=' . $id, 'bgc_generate_label_' . $id);
        echo wp_kses_post(self::cell_html((string) $order->get_meta('_bgc_waybill'), $print, $track, $gen));
    }
    public function render_legacy($column, $post_id): void {
        if ($column !== 'bgc_shipping') { return; }
        $this->render('bgc_shipping', wc_get_order($post_id));
    }
}
