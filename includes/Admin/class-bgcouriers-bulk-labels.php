<?php
defined('ABSPATH') || exit;

class BGCouriers_Bulk_Labels {
    const ACTION   = 'bgcouriers_generate_labels';
    const REGEN    = 'bgcouriers_regenerate_labels';
    const CANCEL   = 'bgcouriers_cancel_labels';
    const PRINT_A4 = 'bgcouriers_print_a4';
    const PRINT_A6 = 'bgcouriers_print_a6';

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
    // its config by BGCouriers_Order_Columns::enqueue_assets on the same screens).

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
            if (!$order || !BGCouriers_Labels::order_courier($order)) { $results[] = 'skipped'; continue; }
            // An order that already has a waybill is LEFT ALONE - counted as 'reused' and included in the
            // print set, never re-issued. Re-issuing is the separate 'Re-issue waybils' action, so this
            // one can never void a live shipment. (BGCouriers_Labels::generate() also early-returns on an
            // existing waybill, so a missed guard here still could not double-create.)
            if ((string) $order->get_meta('_bgcouriers_waybill') !== '') { $results[] = 'reused'; $print_ids[] = $oid; continue; }
            try { BGCouriers_Labels::generate($oid); $results[] = 'generated'; $print_ids[] = $oid; }
            catch (\Exception $e) {
                $results[] = 'failed';
                /* translators: %s: error message */
                $order->add_order_note(sprintf(__('BG Couriers bulk label failed: %s', 'bg-couriers'), $e->getMessage()));
            }
        }
        if ($print_ids) { set_transient('bgcouriers_print_batch_' . get_current_user_id(), $print_ids, 5 * MINUTE_IN_SECONDS); }
        self::stash_notice(['kind' => 'generate', 'print' => (bool) $print_ids] + self::summary($results));
        return $redirect;
    }

    /**
     * Hand the bulk result to the admin notice through a ONE-SHOT transient rather than query args.
     * Query args survive a page refresh and get copied into the list table's pagination / filter links, so
     * the same "9 re-issued" notice kept reappearing and read as if the action had run again - it had not,
     * because the action only ever runs on the POSTed form (WooCommerce redirects to the plain referer,
     * which carries neither `action` nor `id[]`). Read once, then deleted.
     */
    private static function stash_notice(array $payload): void {
        set_transient('bgcouriers_bulk_notice_' . get_current_user_id(), $payload, 5 * MINUTE_IN_SECONDS);
    }

    /** @return array|null the pending bulk result, consumed so it can never render twice */
    private static function take_notice(): ?array {
        $key     = 'bgcouriers_bulk_notice_' . get_current_user_id();
        $payload = get_transient($key);
        if (!is_array($payload) || empty($payload['kind'])) { return null; }
        delete_transient($key);
        return $payload;
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
            if (!$order || !BGCouriers_Labels::order_courier($order)) { continue; }
            // Only the MISSING labels are created; an order that already has a waybill keeps it and is
            // simply printed. Printing never re-issues - that is the 'Re-issue waybils' action.
            if ((string) $order->get_meta('_bgcouriers_waybill') === '') {
                try { BGCouriers_Labels::generate($oid); }
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
        $out = BGCouriers_Labels::batch_pdf($ok, $paper);
        if ($out === '') { wp_die(esc_html__('No labels to print (none could be generated).', 'bg-couriers')); }
        // A PDF byte stream is the one thing that must NOT be escaped - escaping it corrupts the file. So
        // instead of trusting it, refuse to stream anything that is not actually a PDF: that makes the raw
        // echo below safe by construction rather than by convention.
        if (strncmp($out, '%PDF', 4) !== 0) { wp_die(esc_html__('The generated file is not a valid PDF.', 'bg-couriers')); }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="labels-' . strtolower($paper) . '.pdf"');
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- raw PDF bytes, verified above to start with %PDF; escaping would corrupt the file
        exit;
    }

    /**
     * Bulk re-issue: EVERY selected order ends up with a fresh waybill. Where one already exists it is
     * voided with the courier first, then a new one is issued from the order's current delivery details,
     * products and settings; an order with no waybill simply gets one. Only orders that are not ours (no
     * BGCOURIERS courier) are skipped, since there is nothing to issue them with.
     *
     * A cancel that succeeds but whose generate then fails leaves that order unlabelled - counted as
     * failed, with the courier's message on the order, and running this (or Generate) again recovers it.
     */
    private function handle_regen($redirect, $ids) {
        $c = ['regenerated' => 0, 'generated' => 0, 'skipped' => 0, 'failed' => 0];
        $print_ids = [];
        foreach (array_map('intval', (array) $ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order || !BGCouriers_Labels::order_courier($order)) { $c['skipped']++; continue; }
            $had = (string) $order->get_meta('_bgcouriers_waybill') !== '';
            try {
                if ($had) { BGCouriers_Labels::cancel($oid); } // void the old one before issuing its replacement
                BGCouriers_Labels::generate($oid);
                $c[$had ? 'regenerated' : 'generated']++;
                $print_ids[] = $oid;
            } catch (\Exception $e) {
                $c['failed']++;
                /* translators: %s: error message */
                $order->add_order_note(sprintf(__('BG Couriers bulk re-issue failed: %s', 'bg-couriers'), $e->getMessage()));
            }
        }
        if ($print_ids) { set_transient('bgcouriers_print_batch_' . get_current_user_id(), $print_ids, 5 * MINUTE_IN_SECONDS); }
        self::stash_notice(['kind' => 'regen', 'print' => (bool) $print_ids] + $c);
        return $redirect;
    }

    /** Bulk-cancel each selected order's waybill (via BGCouriers_Labels::cancel, which voids + clears it). */
    private function handle_cancel($redirect, $ids) {
        $c = ['cancelled' => 0, 'skipped' => 0, 'failed' => 0];
        foreach (array_map('intval', (array) $ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order || (string) $order->get_meta('_bgcouriers_waybill') === '') { $c['skipped']++; continue; }
            try { BGCouriers_Labels::cancel($oid); $c['cancelled']++; }
            catch (\Exception $e) {
                $c['failed']++;
                /* translators: %s: error message */
                $order->add_order_note(sprintf(__('BG Couriers bulk cancel failed: %s', 'bg-couriers'), $e->getMessage()));
            }
        }
        self::stash_notice(['kind' => 'cancel'] + $c);
        return $redirect;
    }

    /**
     * "Print N on A4 / A6 stickers" buttons for the batch just generated or re-issued (the order ids are
     * in the bgcouriers_print_batch transient). Returns pre-escaped HTML, or '' when there is nothing to print.
     */
    private function print_links(bool $offer, int $total): string {
        if (!$offer || $total <= 0) { return ''; }
        $base = admin_url('admin-post.php?action=bgcouriers_print_batch');
        $a4   = esc_url(wp_nonce_url($base . '&paper=a4', 'bgcouriers_print_batch'));
        $a6   = esc_url(wp_nonce_url($base . '&paper=a6', 'bgcouriers_print_batch'));
        return ' <a class="button button-primary" target="_blank" href="' . $a4 . '">'
            /* translators: %d: number of labels */
            . esc_html(sprintf(__('Print %d on A4 (packed)', 'bg-couriers'), $total)) . '</a>'
            . ' <a class="button" target="_blank" href="' . $a6 . '">' . esc_html__('A6 stickers', 'bg-couriers') . '</a>';
    }

    /**
     * Render the result of the last bulk action, exactly once. The payload comes from a transient that is
     * consumed on read, so refreshing the page (or paging / filtering, which used to carry the counters
     * along in the URL) no longer replays a notice that reads like the action ran again.
     */
    public function notice(): void {
        $p = self::take_notice();
        if ($p === null) { return; }
        $n = static function (string $k) use ($p): int { return (int) ($p[$k] ?? 0); };
        $link = '';

        if ($p['kind'] === 'regen') {
            $msg = sprintf(
                /* translators: 1: re-issued (had a waybill) 2: newly generated (had none) 3: skipped 4: failed */
                esc_html__('Shipping labels: %1$d re-issued, %2$d newly generated, %3$d skipped, %4$d failed.', 'bg-couriers'),
                $n('regenerated'), $n('generated'), $n('skipped'), $n('failed')
            );
            $link = $this->print_links(!empty($p['print']), $n('regenerated') + $n('generated'));
        } elseif ($p['kind'] === 'cancel') {
            $msg = sprintf(
                /* translators: 1: cancelled 2: skipped 3: failed */
                esc_html__('Shipping labels: %1$d cancelled, %2$d skipped, %3$d failed.', 'bg-couriers'),
                $n('cancelled'), $n('skipped'), $n('failed')
            );
        } else {
            $msg = sprintf(
                /* translators: 1: generated 2: reused 3: skipped 4: failed */
                esc_html__('Shipping labels: %1$d generated, %2$d reused, %3$d skipped, %4$d failed.', 'bg-couriers'),
                $n('generated'), $n('reused'), $n('skipped'), $n('failed')
            );
            $link = $this->print_links(!empty($p['print']), $n('generated') + $n('reused'));
        }

        $cls = $n('failed') ? 'notice-warning' : 'notice-success';
        echo wp_kses('<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . $msg . $link . '</p></div>',
            BGCouriers_Kses::admin_actions());
    }
}
