<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Order_Columns {
    public function __construct() {
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'col']);
        add_filter('manage_edit-shop_order_columns', [$this, 'col']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render'], 10, 2);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_legacy'], 10, 2);
        add_action('admin_footer', [$this, 'copy_script']);
    }
    public function col($cols) { $cols['bgc_shipping'] = __('Speedy', 'bg-couriers'); return $cols; }

    public static function cell_html(string $waybill, string $print_url, string $track_url, string $generate_url): string {
        if ($waybill === '') {
            return '<a class="button button-small" href="' . esc_url($generate_url) . '">' . esc_html__('Generate', 'bg-couriers') . '</a>';
        }
        // The copy link reads the waybill from the adjacent .bgc-wb-num so no data-* attribute is
        // needed (which wp_kses_post would strip); it stops propagation so the row click is suppressed.
        return '<strong class="bgc-wb-num">' . esc_html($waybill) . '</strong> '
            . '<a href="#" class="bgc-copy" title="' . esc_attr__('Copy waybill', 'bg-couriers') . '" aria-label="' . esc_attr__('Copy waybill', 'bg-couriers') . '">⧉</a><br>'
            . '<a target="_blank" href="' . esc_url($print_url) . '">' . esc_html__('Print', 'bg-couriers') . '</a> | '
            . '<a target="_blank" href="' . esc_url($track_url) . '">' . esc_html__('Track', 'bg-couriers') . '</a>';
    }

    /** Clipboard copy for the waybill button on the Orders list (avoids opening the order on click). */
    public function copy_script(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['woocommerce_page_wc-orders', 'edit-shop_order'], true)) { return; }
        ?>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('.bgc-copy'); if (!b) return;
  e.preventDefault(); e.stopPropagation();
  var num = b.parentNode.querySelector('.bgc-wb-num');
  var wb = num ? num.textContent.trim() : '';
  if (wb && navigator.clipboard) {
    navigator.clipboard.writeText(wb).then(function () { var o = b.textContent; b.textContent = '✓'; setTimeout(function () { b.textContent = o; }, 1200); });
  }
});
</script>
        <?php
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
