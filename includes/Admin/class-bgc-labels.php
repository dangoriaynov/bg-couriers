<?php
defined('ABSPATH') || exit;

class BGC_Labels {
    public function __construct() {
        add_action('admin_post_bgc_generate_label', [$this, 'handle_generate']);
        add_action('admin_post_bgc_track', [$this, 'handle_track']);
    }
    public static function generate(int $order_id): BGC_Label {
        $order = wc_get_order($order_id);
        if (!$order) { throw new BGC_Api_Exception('Order not found'); }
        $existing = (string) $order->get_meta('_bgc_waybill');
        if ($existing !== '') { return new BGC_Label($existing, (string) $order->get_meta('_bgc_label_url')); }

        $courier = apply_filters('bgc_courier', null, 'speedy') ?: new BGC_Speedy(BGC_Settings::courier_config('speedy') ?: ['env' => 'demo']);
        $label = $courier->create_label($order);
        $order->update_meta_data('_bgc_waybill', $label->waybill);

        $pdf = $courier->get_label_pdf($label->waybill);
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'bgc-labels';
        wp_mkdir_p($dir);
        $file = $dir . '/speedy-' . $label->waybill . '.pdf';
        file_put_contents($file, $pdf);
        $url = trailingslashit($up['baseurl']) . 'bgc-labels/speedy-' . $label->waybill . '.pdf';
        $order->update_meta_data('_bgc_label_url', $url);
        $order->add_order_note(sprintf(__('Speedy label generated: %s', 'bg-couriers'), $label->waybill));
        $order->save();
        return new BGC_Label($label->waybill, $url);
    }
    public function handle_generate(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        check_admin_referer('bgc_generate_label');
        $id = (int) ($_GET['order_id'] ?? 0);
        try { self::generate($id); }
        catch (\Exception $e) { set_transient('bgc_admin_error_' . $id, $e->getMessage(), 60); }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }
    public function handle_track(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        check_admin_referer('bgc_track');
        $order = wc_get_order((int) ($_GET['order_id'] ?? 0));
        $waybill = $order ? (string) $order->get_meta('_bgc_waybill') : '';
        $courier = new BGC_Speedy(['env' => 'demo']);
        wp_redirect($courier->tracking_url($waybill));
        exit;
    }
}
