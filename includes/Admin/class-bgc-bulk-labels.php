<?php
defined('ABSPATH') || exit;

class BGC_Bulk_Labels {
    const ACTION   = 'bgc_generate_labels';
    const REGEN    = 'bgc_regenerate_labels';
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

    /** Our bulk-action values, in the order they are registered (drives the dropdown grouping in JS). */
    public static function actions(): array {
        return [self::PRINT_A4, self::PRINT_A6, self::ACTION, self::REGEN, self::CANCEL];
    }

    public function register(array $actions): array {
        $actions[self::PRINT_A4] = __('Print waybils A4', 'bg-couriers');
        $actions[self::PRINT_A6] = __('Print waybils A6', 'bg-couriers');
        $actions[self::ACTION]   = __('Generate waybils', 'bg-couriers');
        $actions[self::REGEN]    = __('Re-issue waybils', 'bg-couriers');
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
        if ($action === self::REGEN)    { return $this->handle_regen($redirect, $ids); }
        if ($action !== self::ACTION) { return $redirect; }
        $results = [];
        $print_ids = [];
        foreach (array_map('intval', (array) $ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order || !BGC_Labels::order_courier($order)) { $results[] = 'skipped'; continue; }
            // An order that already has a waybill is LEFT ALONE - counted as 'reused' and included in the
            // print set, never re-issued. Re-issuing is the separate 'Re-issue waybils' action, so this
            // one can never void a live shipment. (BGC_Labels::generate() also early-returns on an
            // existing waybill, so a missed guard here still could not double-create.)
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
            // Only the MISSING labels are created; an order that already has a waybill keeps it and is
            // simply printed. Printing never re-issues - that is the 'Re-issue waybils' action.
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

    /**
     * Bulk re-issue: for each selected order that HAS a waybill, void it and issue a fresh one from the
     * order's current delivery details, products and settings - the bulk twin of the panel's re-issue
     * button. Orders WITHOUT a waybill are skipped rather than labelled: nothing to re-issue there, and
     * 'Generate waybils' is the action for those, so this one can never create an unexpected shipment.
     * A cancel that succeeds but whose re-generate then fails leaves the order unlabelled - counted as
     * failed, with the courier's message on the order, and 'Generate waybils' recovers it.
     */
    private function handle_regen($redirect, $ids) {
        $c = ['regenerated' => 0, 'skipped' => 0, 'failed' => 0];
        $print_ids = [];
        foreach (array_map('intval', (array) $ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order || !BGC_Labels::order_courier($order)) { $c['skipped']++; continue; }
            if ((string) $order->get_meta('_bgc_waybill') === '') { $c['skipped']++; continue; }
            try {
                BGC_Labels::cancel($oid);
                BGC_Labels::generate($oid);
                $c['regenerated']++;
                $print_ids[] = $oid;
            } catch (\Exception $e) {
                $c['failed']++;
                /* translators: %s: error message */
                $order->add_order_note(sprintf(__('BG Couriers bulk re-issue failed: %s', 'bg-couriers'), $e->getMessage()));
            }
        }
        if ($print_ids) { set_transient('bgc_print_batch_' . get_current_user_id(), $print_ids, 5 * MINUTE_IN_SECONDS); }
        return add_query_arg(array_merge(['bgc_bulk_regen' => 1, 'bgc_print' => $print_ids ? 1 : 0], $c), $redirect);
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

    /**
     * "Print N on A4 / A6 stickers" buttons for the batch just generated or re-issued (the order ids are
     * in the bgc_print_batch transient). Returns pre-escaped HTML, or '' when there is nothing to print.
     */
    private function print_links(int $total): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect param
        if (empty($_GET['bgc_print']) || $total <= 0) { return ''; }
        $base = admin_url('admin-post.php?action=bgc_print_batch');
        $a4   = esc_url(wp_nonce_url($base . '&paper=a4', 'bgc_print_batch'));
        $a6   = esc_url(wp_nonce_url($base . '&paper=a6', 'bgc_print_batch'));
        return ' <a class="button button-primary" target="_blank" href="' . $a4 . '">'
            /* translators: %d: number of labels */
            . esc_html(sprintf(__('Print %d on A4 (packed)', 'bg-couriers'), $total)) . '</a>'
            . ' <a class="button" target="_blank" href="' . $a6 . '">' . esc_html__('A6 stickers', 'bg-couriers') . '</a>';
    }

    public function notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect param, no state change
        if (!empty($_GET['bgc_bulk_regen'])) {
            $rc = ['regenerated' => 0, 'skipped' => 0, 'failed' => 0];
            foreach ($rc as $k => $_) { $rc[$k] = (int) wp_unslash($_GET[$k] ?? 0); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast read-only redirect param
            $msg = sprintf(
                /* translators: 1: re-issued 2: skipped (no waybill to re-issue) 3: failed */
                esc_html__('Shipping labels: %1$d re-issued, %2$d skipped (no waybill), %3$d failed.', 'bg-couriers'),
                $rc['regenerated'], $rc['skipped'], $rc['failed']
            );
            $cls = $rc['failed'] ? 'notice-warning' : 'notice-success';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $msg is esc_html__()'d, print_links() returns pre-escaped HTML
            echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . $msg . $this->print_links($rc['regenerated']) . '</p></div>';
            return;
        }
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
        $link = $this->print_links($c['generated'] + $c['reused']);
        $cls  = $c['failed'] ? 'notice-warning' : 'notice-success';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $msg is esc_html__()'d, $link is pre-escaped HTML (esc_url/esc_html)
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . $msg . $link . '</p></div>';
    }
}
