<?php
defined('ABSPATH') || exit;

class BGCouriers_Bulk_Labels {
    const ACTION   = 'bgcouriers_generate_labels';
    const REGEN    = 'bgcouriers_regenerate_labels';
    const CANCEL   = 'bgcouriers_cancel_labels';
    const PRINT_A4 = 'bgcouriers_print_a4';
    const PRINT_A6 = 'bgcouriers_print_a6';
    const PICKUP   = 'bgcouriers_pickup';

    public function __construct() {
        foreach (['woocommerce_page_wc-orders', 'edit-shop_order'] as $screen) {
            add_filter("bulk_actions-{$screen}", [$this, 'register']);
            add_filter("handle_bulk_actions-{$screen}", [$this, 'handle'], 10, 3);
        }
        add_action('admin_notices', [$this, 'notice']);
    }

    public static function print_actions(): array { return [self::PRINT_A4, self::PRINT_A6]; }

    public static function actions(): array {
        return [self::PRINT_A4, self::PRINT_A6, self::ACTION, self::REGEN, self::CANCEL, self::PICKUP];
    }

    public function register(array $actions): array {
        $actions[self::PRINT_A4] = __('Print waybils A4', 'bg-couriers');
        $actions[self::PRINT_A6] = __('Print waybils A6', 'bg-couriers');
        $actions[self::ACTION]   = __('Generate waybils', 'bg-couriers');
        $actions[self::REGEN]    = __('Re-issue waybils', 'bg-couriers');
        $actions[self::CANCEL]   = __('Cancel waybils', 'bg-couriers');
        $actions[self::PICKUP]   = __('Request a courier', 'bg-couriers');
        return $actions;
    }

    public static function summary(array $results): array {
        $c = ['generated' => 0, 'reused' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($results as $r) { if (isset($c[$r])) { $c[$r]++; } }
        return $c;
    }

    public function handle($redirect, $action, $ids) {
        if ($action === self::PICKUP) { return BGCouriers_Pickup::url(array_map('intval', (array) $ids)); }
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
            // An order with a waybill is reused, never re-issued: this action can never void a live shipment.
            if ((string) $order->get_meta('_bgcouriers_waybill') !== '') { $results[] = 'reused'; $print_ids[] = $oid; continue; }
            try { BGCouriers_Labels::generate_or_wait($oid); $results[] = 'generated'; $print_ids[] = $oid; }
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

    /** One-shot transient, not query args: those survive a refresh and get copied into pagination links. */
    private static function stash_notice(array $payload): void {
        set_transient('bgcouriers_bulk_notice_' . get_current_user_id(), $payload, 5 * MINUTE_IN_SECONDS);
    }

    private static function take_notice(): ?array {
        $key     = 'bgcouriers_bulk_notice_' . get_current_user_id();
        $payload = get_transient($key);
        if (!is_array($payload) || empty($payload['kind'])) { return null; }
        delete_transient($key);
        return $payload;
    }

    /** Bulk print: generate missing labels, pack onto A4/A6 and stream the PDF (WC runs this before output). */
    private function handle_print(string $paper, $ids): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do that.', 'bg-couriers')); }
        [$ok, $errors] = self::printable((array) $ids);
        $out = BGCouriers_Labels::batch_pdf($ok, $paper);
        self::note_print_errors(BGCouriers_Labels::print_errors());
        if ($out === '') { wp_die(wp_kses_post(self::nothing_printed($errors + BGCouriers_Labels::print_errors()))); }
        // Refuse anything that is not a PDF: that makes the raw echo below safe by construction.
        if (strncmp($out, '%PDF', 4) !== 0) { wp_die(esc_html__('The generated file is not a valid PDF.', 'bg-couriers')); }
        BGCouriers_Labels::mark_printed($ok);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="labels-' . strtolower($paper) . '.pdf"');
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- raw PDF bytes, verified above to start with %PDF; escaping would corrupt the file
        exit;
    }

    /**
     * Orders that have a waybill or just got one; the rest with the reason, by order id.
     * @return array{0:int[],1:array<int,string>}
     */
    public static function printable(array $ids): array {
        $ok = []; $errors = [];
        foreach (array_map('intval', $ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order) { continue; }
            if (!BGCouriers_Labels::order_courier($order)) {
                $errors[$oid] = __('This order is not shipped with a BG Couriers courier.', 'bg-couriers');
                continue;
            }
            if ((string) $order->get_meta('_bgcouriers_waybill') === '') {
                try { BGCouriers_Labels::generate_or_wait($oid); }
                catch (\Exception $e) {
                    $errors[$oid] = $e->getMessage();
                    /* translators: %s: error message */
                    $order->add_order_note(sprintf(__('BG Couriers bulk print - generate failed: %s', 'bg-couriers'), $e->getMessage()));
                    continue;
                }
            }
            $ok[] = $oid;
        }
        return [$ok, $errors];
    }

    /** @param array<int,string> $errors order id => why its label PDF did not come back */
    public static function note_print_errors(array $errors): void {
        foreach ($errors as $oid => $why) {
            $order = wc_get_order((int) $oid);
            /* translators: %s: error message */
            if ($order) { $order->add_order_note(sprintf(__('BG Couriers bulk print - the label PDF did not come back: %s', 'bg-couriers'), $why)); }
        }
    }

    /** @param array<int,string> $errors order id => reason */
    public static function nothing_printed(array $errors): string {
        $html = '<p>' . esc_html__('No labels to print (none could be generated).', 'bg-couriers') . '</p>';
        if (!$errors) { return $html; }
        $html .= '<ul>';
        foreach ($errors as $oid => $why) {
            $order = wc_get_order((int) $oid);
            $num   = $order ? (string) $order->get_order_number() : (string) $oid;
            $html .= '<li>#' . esc_html($num) . ': ' . esc_html($why) . '</li>';
        }
        return $html . '</ul>';
    }

    /** Bulk re-issue: every selected order ends up with a fresh waybill; orders without a BG Couriers courier are skipped. */
    private function handle_regen($redirect, $ids) {
        $c = ['regenerated' => 0, 'generated' => 0, 'skipped' => 0, 'failed' => 0];
        $print_ids = [];
        foreach (array_map('intval', (array) $ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order || !BGCouriers_Labels::order_courier($order)) { $c['skipped']++; continue; }
            $had = (string) $order->get_meta('_bgcouriers_waybill') !== '';
            try {
                if ($had) { BGCouriers_Labels::cancel($oid); }
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

    private function print_links(bool $offer, int $total): string {
        if (!$offer || $total <= 0) { return ''; }
        $base = admin_url('admin-post.php?action=bgcouriers_print_batch');
        $a4   = esc_url(wp_nonce_url($base . '&paper=a4', 'bgcouriers_print_batch'));
        $a6   = esc_url(wp_nonce_url($base . '&paper=a6', 'bgcouriers_print_batch'));
        // The batch ids ride on the links so the list script can reset those rows' print tiles; data-ids is kses-allowed.
        $ids  = implode(',', array_filter(array_map('intval', (array) get_transient('bgcouriers_print_batch_' . get_current_user_id()))));
        $data = $ids !== '' ? ' data-ids="' . esc_attr($ids) . '"' : '';
        return ' <a class="button button-primary" target="_blank" href="' . $a4 . '"' . $data . '>'
            /* translators: %d: number of labels */
            . esc_html(sprintf(__('Print %d on A4 (packed)', 'bg-couriers'), $total)) . '</a>'
            . ' <a class="button" target="_blank" href="' . $a6 . '"' . $data . '>' . esc_html__('A6 stickers', 'bg-couriers') . '</a>';
    }

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
