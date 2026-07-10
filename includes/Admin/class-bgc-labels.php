<?php
defined('ABSPATH') || exit;

class BGC_Labels {
    /** The registered courier for a BGC order, or null if it isn't one of ours. */
    public static function order_courier($order): ?BGC_Courier_Interface {
        if (!$order instanceof \WC_Order) { return null; }
        $id = (string) $order->get_meta('_bgc_courier');
        return $id !== '' ? BGC_Couriers::get($id) : null;
    }
    private function courier_for(\WC_Order $order): ?BGC_Courier_Interface {
        return self::order_courier($order);
    }

    public function __construct() {
        add_action('admin_post_bgc_generate_label', [$this, 'handle_generate']);
        add_action('admin_post_bgc_track', [$this, 'handle_track']);
        add_action('admin_post_bgc_print_batch', [$this, 'handle_print_batch']);
        add_action('woocommerce_order_status_changed', [$this, 'maybe_auto_generate'], 20, 4);
    }

    /** Auto-generate a label when an order reaches the configured status. */
    public function maybe_auto_generate($order_id, $old_status, $new_status, $order): void {
        $cfg = BGC_Settings::autolabel();
        if (!$cfg['enabled']) { return; }
        if ('wc-' . $new_status !== $cfg['status']) { return; }
        if (!self::order_courier($order)) { return; } // any BGC courier, not just Speedy
        if ($order->get_meta('_bgc_waybill') !== '') { return; }
        try { self::generate((int) $order_id); }
        catch (\Exception $e) {
            /* translators: %s: error message */
            $order->add_order_note(sprintf(__('BG Couriers auto-label failed: %s', 'bg-couriers'), $e->getMessage()));
        }
    }
    public static function generate(int $order_id): BGC_Label {
        $order = wc_get_order($order_id);
        if (!$order) { throw new BGC_Api_Exception('Order not found'); }
        $existing = (string) $order->get_meta('_bgc_waybill');
        if ($existing !== '') { return new BGC_Label($existing, (string) $order->get_meta('_bgc_label_url')); }

        $courier_id = (string) $order->get_meta('_bgc_courier');
        $courier = $courier_id ? BGC_Couriers::get($courier_id) : null;
        if (!$courier) { throw new BGC_Api_Exception(esc_html__('Unknown courier for this order.', 'bg-couriers')); }
        $label = $courier->create_label($order);
        $order->update_meta_data('_bgc_waybill', $label->waybill);

        $pdf = $courier->get_label_pdf($label->waybill);
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'bgc-labels';
        wp_mkdir_p($dir);
        $safe_waybill = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $label->waybill);
        $prefix = preg_replace('/[^a-z0-9]/', '', $courier->id()) ?: 'bgc';
        $file = $dir . '/' . $prefix . '-' . $safe_waybill . '.pdf';
        file_put_contents($file, $pdf);
        $url = trailingslashit($up['baseurl']) . 'bgc-labels/' . $prefix . '-' . $safe_waybill . '.pdf';
        $order->update_meta_data('_bgc_label_url', $url);
        /* translators: 1: courier name, 2: waybill number */
        $order->add_order_note(sprintf(__('%1$s label generated: %2$s', 'bg-couriers'), $courier->label(), $label->waybill));
        $order->save();
        return new BGC_Label($label->waybill, $url);
    }
    public static function batch_parcel_ids(array $order_ids, ?callable $resolver = null): array {
        $resolver = $resolver ?: static function ($id) {
            $o = wc_get_order((int) $id);
            return $o ? (string) $o->get_meta('_bgc_waybill') : '';
        };
        $ids = [];
        foreach ($order_ids as $oid) {
            $w = (string) $resolver($oid);
            if ($w !== '') { $ids[] = $w; }
        }
        return $ids;
    }
    public function handle_generate(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $id = (int) ($_GET['order_id'] ?? 0);
        check_admin_referer('bgc_generate_label_' . $id);
        if (!wc_get_order($id)) { wp_die(esc_html__('Order not found.', 'bg-couriers')); }
        try { self::generate($id); }
        catch (\Exception $e) { set_transient('bgc_admin_error_' . $id, $e->getMessage(), 60); }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }
    public function handle_track(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $id = (int) ($_GET['order_id'] ?? 0);
        check_admin_referer('bgc_track_' . $id);
        $order = wc_get_order($id);
        $waybill = $order ? (string) $order->get_meta('_bgc_waybill') : '';
        if ($waybill === '') { wp_die(esc_html__('No waybill found.', 'bg-couriers')); }
        $courier = $this->courier_for($order);
        if (!$courier) { wp_die(esc_html__('Unknown courier for this order.', 'bg-couriers')); }
        $url  = $courier->tracking_url($waybill);
        $host = wp_parse_url($url, PHP_URL_HOST);
        add_filter('allowed_redirect_hosts', function ($h) use ($host) { if ($host) { $h[] = $host; } return $h; });
        wp_safe_redirect($url);
        exit;
    }
    public function handle_print_batch(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        check_admin_referer('bgc_print_batch');
        $order_ids = isset($_GET['order_id'])
            ? [(int) $_GET['order_id']]
            : (array) get_transient('bgc_print_batch_' . get_current_user_id());
        $order_ids = array_filter(array_map('intval', $order_ids));
        $first_order = $order_ids ? wc_get_order((int) $order_ids[0]) : null;
        $courier = $first_order ? $this->courier_for($first_order) : null;
        if (!$courier) { wp_die(esc_html__('Unknown courier for this order.', 'bg-couriers')); }
        try {
            if (method_exists($courier, 'print_labels')) {
                // Speedy-style multi-parcel combined print.
                $parcels = self::batch_parcel_ids($order_ids);
                if (!$parcels) { wp_die(esc_html__('No labels to print.', 'bg-couriers')); }
                $pdf = $courier->print_labels($parcels, BGC_Settings::label_paper_size((string) $first_order->get_meta('_bgc_courier') ?: 'speedy'));
            } else {
                // Courier exposes a per-waybill PDF (no batch combine) - print the first order's label.
                $wb = (string) $first_order->get_meta('_bgc_waybill');
                if ($wb === '') { wp_die(esc_html__('No label to print.', 'bg-couriers')); }
                $pdf = $courier->get_label_pdf($wb);
            }
        /* translators: %s: error message */
        } catch (\Exception $e) { wp_die(esc_html(sprintf(__('Print failed: %s', 'bg-couriers'), $e->getMessage()))); }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="labels.pdf"');
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF
        exit;
    }
}
