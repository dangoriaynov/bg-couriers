<?php
defined('ABSPATH') || exit;

class BGC_Bulk_Labels {
    const ACTION   = 'bgc_generate_labels';
    const CANCEL   = 'bgc_cancel_labels';
    const PRINT_A4 = 'bgc_print_a4';
    const PRINT_A6 = 'bgc_print_a6';

    public function __construct() {
        foreach (['woocommerce_page_wc-orders', 'edit-shop_order'] as $screen) {
            add_filter("bulk_actions-{$screen}", [$this, 'register']);
            add_filter("handle_bulk_actions-{$screen}", [$this, 'handle'], 10, 3);
        }
        add_action('admin_notices', [$this, 'notice']);
    }

    public function register(array $actions): array {
        $actions[self::PRINT_A4] = __('Print waybils A4', 'bg-couriers');
        $actions[self::PRINT_A6] = __('Print waybils A6', 'bg-couriers');
        $actions[self::ACTION]   = __('Generate waybils', 'bg-couriers');
        $actions[self::CANCEL]   = __('Cancel waybils', 'bg-couriers');
        return $actions;
    }

    // The bulk "Cancel waybils" confirmation lives in assets/js/bgc-orders-list.js (enqueued with
    // its config by BGC_Order_Columns::enqueue_assets on the same screens).

    public static function summary(array $results): array {
        $c = ['generated' => 0, 'reused' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($results as $r) { if (isset($c[$r])) { $c[$r]++; } }
        return $c;
    }

    public function handle($redirect, $action, $ids) {
        if ($action === self::PRINT_A4) { $this->handle_print('A4', $ids); }
        if ($action === self::PRINT_A6) { $this->handle_print('A6', $ids); }
        if ($action === self::CANCEL)   { return $this->handle_cancel($redirect, $ids); }
        if ($action !== self::ACTION) { return $redirect; }
        $results = [];
        $print_ids = [];
        foreach (array_map('intval', (array) $ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order || !BGC_Labels::order_courier($order)) { $results[] = 'skipped'; continue; }
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

    /**
     * Bulk print: generate any missing labels for the selected orders, then pack them onto A4 (many per
     * sheet) or A6 (one per page) and stream the PDF directly. WooCommerce runs the bulk handler before any
     * output, so we can emit the PDF and exit instead of redirecting.
     */
    private function handle_print(string $paper, $ids): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $ok = [];
        foreach (array_map('intval', (array) $ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order || !BGC_Labels::order_courier($order)) { continue; }
            if ((string) $order->get_meta('_bgc_waybill') === '') {
                try { BGC_Labels::generate($oid); }
                catch (\Exception $e) {
                    /* translators: %s: error message */
                    $order->add_order_note(sprintf(__('BG Couriers bulk print - generate failed: %s', 'bg-couriers'), $e->getMessage()));
                    continue;
                }
            }
            $ok[] = $oid;
        }
        // Each courier lays out its own sheet the way it prints 1-by-1 (Speedy = its native A4 landscape
        // layout), then the sheets are concatenated - no re-packing or scaling on our side.
        $out = BGC_Labels::batch_pdf($ok, $paper);
        if ($out === '') { wp_die(esc_html__('No labels to print (none could be generated).', 'bg-couriers')); }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="labels-' . strtolower($paper) . '.pdf"');
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF
        exit;
    }

    /** Bulk-cancel each selected order's waybill (via BGC_Labels::cancel, which voids + clears it). */
    private function handle_cancel($redirect, $ids) {
        $c = ['cancelled' => 0, 'skipped' => 0, 'failed' => 0];
        foreach (array_map('intval', (array) $ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order || (string) $order->get_meta('_bgc_waybill') === '') { $c['skipped']++; continue; }
            try { BGC_Labels::cancel($oid); $c['cancelled']++; }
            catch (\Exception $e) {
                $c['failed']++;
                /* translators: %s: error message */
                $order->add_order_note(sprintf(__('BG Couriers bulk cancel failed: %s', 'bg-couriers'), $e->getMessage()));
            }
        }
        return add_query_arg(array_merge(['bgc_bulk_cancel' => 1], $c), $redirect);
    }

    public function notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect param, no state change
        if (!empty($_GET['bgc_bulk_cancel'])) {
            $cc = ['cancelled' => 0, 'skipped' => 0, 'failed' => 0];
            foreach ($cc as $k => $_) { $cc[$k] = (int) wp_unslash($_GET[$k] ?? 0); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast read-only redirect param
            $msg = sprintf(
                /* translators: 1: cancelled 2: skipped 3: failed */
                esc_html__('Shipping labels: %1$d cancelled, %2$d skipped, %3$d failed.', 'bg-couriers'),
                $cc['cancelled'], $cc['skipped'], $cc['failed']
            );
            echo '<div class="notice ' . ($cc['failed'] ? 'notice-warning' : 'notice-success') . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            return;
        }
        if (empty($_GET['bgc_bulk'])) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect param
        $c = ['generated' => 0, 'reused' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($c as $k => $_) { $c[$k] = (int) wp_unslash($_GET[$k] ?? 0); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast read-only redirect param
        $msg = sprintf(
            /* translators: 1: generated 2: reused 3: skipped 4: failed */
            esc_html__('Shipping labels: %1$d generated, %2$d reused, %3$d skipped, %4$d failed.', 'bg-couriers'),
            $c['generated'], $c['reused'], $c['skipped'], $c['failed']
        );
        $link = '';
        if (!empty($_GET['bgc_print'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect param
            $base  = admin_url('admin-post.php?action=bgc_print_batch');
            $total = $c['generated'] + $c['reused'];
            $a4    = esc_url(wp_nonce_url($base . '&paper=a4', 'bgc_print_batch'));
            $a6    = esc_url(wp_nonce_url($base . '&paper=a6', 'bgc_print_batch'));
            $link  = ' <a class="button button-primary" target="_blank" href="' . $a4 . '">'
                /* translators: %d: number of labels */
                . esc_html(sprintf(__('Print %d on A4 (packed)', 'bg-couriers'), $total)) . '</a>'
                . ' <a class="button" target="_blank" href="' . $a6 . '">' . esc_html__('A6 stickers', 'bg-couriers') . '</a>';
        }
        $cls = $c['failed'] ? 'notice-warning' : 'notice-success';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $msg is esc_html__()'d, $link is pre-escaped HTML (esc_url/esc_html)
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . $msg . $link . '</p></div>';
    }
}
