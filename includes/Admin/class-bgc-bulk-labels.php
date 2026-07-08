<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Bulk_Labels {
    const ACTION = 'bgc_generate_labels';

    public function __construct() {
        foreach (['woocommerce_page_wc-orders', 'edit-shop_order'] as $screen) {
            add_filter("bulk_actions-{$screen}", [$this, 'register']);
            add_filter("handle_bulk_actions-{$screen}", [$this, 'handle'], 10, 3);
        }
        add_action('admin_notices', [$this, 'notice']);
    }

    public function register(array $actions): array {
        $actions[self::ACTION] = __('Generate Speedy labels', 'bg-couriers');
        return $actions;
    }

    public static function summary(array $results): array {
        $c = ['generated' => 0, 'reused' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($results as $r) { if (isset($c[$r])) { $c[$r]++; } }
        return $c;
    }

    public function handle($redirect, $action, $ids) {
        if ($action !== self::ACTION) { return $redirect; }
        $results = [];
        $print_ids = [];
        foreach (array_map('intval', (array) $ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order || $order->get_meta('_bgc_courier') !== 'speedy') { $results[] = 'skipped'; continue; }
            if ((string) $order->get_meta('_bgc_waybill') !== '') { $results[] = 'reused'; $print_ids[] = $oid; continue; }
            try { BGC_Labels::generate($oid); $results[] = 'generated'; $print_ids[] = $oid; }
            catch (\Exception $e) {
                $results[] = 'failed';
                /* translators: %s: error message */
                $order->add_order_note(sprintf(__('BG Couriers bulk label failed: %s', 'bg-couriers'), $e->getMessage()));
            }
        }
        if ($print_ids) { set_transient('bgc_print_batch_' . get_current_user_id(), $print_ids, 5 * MINUTE_IN_SECONDS); }
        $c = self::summary($results);
        return add_query_arg(array_merge(['bgc_bulk' => 1, 'bgc_print' => $print_ids ? 1 : 0], $c), $redirect);
    }

    public function notice(): void {
        if (empty($_GET['bgc_bulk'])) { return; }
        $c = ['generated' => 0, 'reused' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($c as $k => $_) { $c[$k] = (int) ($_GET[$k] ?? 0); }
        $msg = sprintf(
            /* translators: 1: generated 2: reused 3: skipped 4: failed */
            esc_html__('Speedy labels: %1$d generated, %2$d reused, %3$d skipped, %4$d failed.', 'bg-couriers'),
            $c['generated'], $c['reused'], $c['skipped'], $c['failed']
        );
        $link = '';
        if (!empty($_GET['bgc_print'])) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=bgc_print_batch'), 'bgc_print_batch');
            $total = $c['generated'] + $c['reused'];
            $link = ' <a class="button" target="_blank" href="' . esc_url($url) . '">'
                /* translators: %d: number of labels */
                . esc_html(sprintf(__('Print %d labels', 'bg-couriers'), $total)) . '</a>';
        }
        $cls = $c['failed'] ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . $msg . $link . '</p></div>';
    }
}
