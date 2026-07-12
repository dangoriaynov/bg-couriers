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
        add_action('admin_post_bgc_cancel_label', [$this, 'handle_cancel_label']);
        add_action('admin_post_bgc_regenerate', [$this, 'handle_regenerate']);
        add_action('admin_post_bgc_cancel_order', [$this, 'handle_cancel_order']);
        add_action('admin_post_bgc_track', [$this, 'handle_track']);
        add_action('admin_post_bgc_print_batch', [$this, 'handle_print_batch']);
        add_action('wp_ajax_bgc_order_save_delivery', [$this, 'handle_save_delivery']);
        add_action('wp_ajax_bgc_ajax_cancel_label', [$this, 'ajax_cancel_label']);
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

        // Prefer the PDF URL the courier returned in the create response (Econt does this); otherwise
        // fetch the label by waybill.
        $pdf = ($label->pdf !== '' && strpos($label->pdf, 'http') === 0)
            ? self::download_pdf($label->pdf)
            : $courier->get_label_pdf($label->waybill);
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
    private static function download_pdf(string $url): string {
        $r = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($r)) { throw new BGC_Api_Exception(esc_html('Label PDF download failed: ' . $r->get_error_message())); }
        return (string) wp_remote_retrieve_body($r);
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
        $id = (int) wp_unslash($_GET['order_id'] ?? 0);
        check_admin_referer('bgc_generate_label_' . $id);
        if (!wc_get_order($id)) { wp_die(esc_html__('Order not found.', 'bg-couriers')); }
        try { self::generate($id); }
        catch (\Exception $e) {
            set_transient('bgc_admin_error_' . $id, $e->getMessage(), 60);
            if ($o = wc_get_order($id)) {
                /* translators: %s: error message from the courier */
                $o->add_order_note(sprintf(__('Label generation failed: %s', 'bg-couriers'), $e->getMessage()));
            }
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }
    /** Void the courier waybill and clear it from the order (throws on courier failure). */
    public static function cancel(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) { throw new BGC_Api_Exception('Order not found'); }
        $waybill = (string) $order->get_meta('_bgc_waybill');
        if ($waybill === '') { return; } // nothing to cancel
        $courier = self::order_courier($order);
        if (!$courier) { throw new BGC_Api_Exception(esc_html__('Unknown courier for this order.', 'bg-couriers')); }
        if (!$courier->cancel_label($waybill)) { throw new BGC_Api_Exception(esc_html__('The courier did not cancel the waybill.', 'bg-couriers')); }
        $order->delete_meta_data('_bgc_waybill');
        $order->delete_meta_data('_bgc_label_url');
        /* translators: %s: waybill number */
        $order->add_order_note(sprintf(__('Shipment label %s cancelled.', 'bg-couriers'), $waybill));
        $order->save();
    }

    private function fail_note(int $id, string $msg, string $context): void {
        set_transient('bgc_admin_error_' . $id, $msg, 60);
        if ($o = wc_get_order($id)) { $o->add_order_note($context . ': ' . $msg); }
    }

    public function handle_cancel_label(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $id = (int) wp_unslash($_GET['order_id'] ?? 0);
        check_admin_referer('bgc_cancel_label_' . $id);
        try { self::cancel($id); }
        catch (\Exception $e) { $this->fail_note($id, $e->getMessage(), __('Label cancellation failed', 'bg-couriers')); }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    /** Void the existing waybill and issue a fresh one from the order's current delivery details. */
    public function handle_regenerate(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $id = (int) wp_unslash($_GET['order_id'] ?? 0);
        check_admin_referer('bgc_regenerate_' . $id);
        try { self::cancel($id); self::generate($id); }
        catch (\Exception $e) { $this->fail_note($id, $e->getMessage(), __('Label re-generation failed', 'bg-couriers')); }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    /** Cancel the order (and void its label first, best effort). */
    public function handle_cancel_order(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $id = (int) wp_unslash($_GET['order_id'] ?? 0);
        check_admin_referer('bgc_cancel_order_' . $id);
        $order = wc_get_order($id);
        if ($order) {
            if ((string) $order->get_meta('_bgc_waybill') !== '') {
                try { self::cancel($id); } catch (\Exception $e) { $this->fail_note($id, $e->getMessage(), __('Label cancellation failed', 'bg-couriers')); }
            }
            $order = wc_get_order($id);
            $order->update_status('cancelled', __('Cancelled from the BG Couriers panel.', 'bg-couriers'));
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    /** Save edited delivery details onto an order; void the old waybill and issue a fresh matching one. */
    public function handle_save_delivery(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgc_order_delivery', 'nonce');
        $id = (int) wp_unslash($_POST['order_id'] ?? 0);
        $order = wc_get_order($id);
        if (!$order) { wp_send_json_error(['msg' => __('Order not found.', 'bg-couriers')]); }
        $courier = sanitize_key(wp_unslash($_POST['courier'] ?? ''));
        if ($courier === '' || !BGC_Couriers::get($courier)) { wp_send_json_error(['msg' => __('Choose a courier.', 'bg-couriers')]); }

        // A pre-existing waybill must be voided FIRST, while the order still carries its ORIGINAL courier -
        // cancel() resolves the courier from the order, so after an Econt->Speedy switch it would otherwise
        // try to void the Econt waybill through Speedy's API. Reload afterwards so apply_delivery/save don't
        // resurrect the cleared waybill meta.
        $had_waybill = (string) $order->get_meta('_bgc_waybill') !== '';
        if ($had_waybill) {
            try { self::cancel($id); }
            /* translators: %s: error message */
            catch (\Exception $e) { wp_send_json_error(['msg' => sprintf(__('Could not cancel the current waybill: %s', 'bg-couriers'), $e->getMessage())]); }
            $order = wc_get_order($id);
        }

        $t = static function ($k) { return isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : ''; };
        BGC_Checkout::apply_delivery($order, [
            'courier' => $courier, 'method' => sanitize_key(wp_unslash($_POST['method'] ?? '')),
            'site_id' => (int) wp_unslash($_POST['site_id'] ?? 0), 'office_id' => (int) wp_unslash($_POST['office_id'] ?? 0),
            'post_code' => $t('post_code'), 'street_name' => $t('street_name'), 'street_no' => $t('street_no'),
            'complex' => $t('complex'), 'block' => $t('block'), 'entrance' => $t('entrance'),
            'floor' => $t('floor'), 'apartment' => $t('apartment'), 'address_note' => $t('address_note'),
            'boxnow_name' => $t('boxnow_name'), 'boxnow_addr' => $t('boxnow_addr'),
        ]);
        $order->save();

        // Issue a fresh waybill from the new details (only if one existed before - a plain save on an order
        // without a waybill just stores the details).
        $regenerated = false;
        if ($had_waybill) {
            try { self::generate($id); $regenerated = true; }
            /* translators: %s: error message */
            catch (\Exception $e) { wp_send_json_error(['msg' => sprintf(__('Saved, but issuing the new waybill failed: %s', 'bg-couriers'), $e->getMessage())]); }
        }
        wp_send_json_success([
            'msg' => $regenerated ? __('Delivery updated and a new waybill issued.', 'bg-couriers') : __('Delivery details saved.', 'bg-couriers'),
        ]);
    }

    /** AJAX: void a waybill from the Orders list without reloading the page. */
    public function ajax_cancel_label(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        $id = (int) wp_unslash($_POST['order_id'] ?? 0);
        check_ajax_referer('bgc_cancel_label_' . $id, 'nonce');
        try { self::cancel($id); }
        catch (\Exception $e) { wp_send_json_error(['msg' => $e->getMessage()]); }
        wp_send_json_success(['msg' => __('Waybill cancelled.', 'bg-couriers')]);
    }

    public function handle_track(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $id = (int) wp_unslash($_GET['order_id'] ?? 0);
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
        if (!$order_ids) { wp_die(esc_html__('No labels to print.', 'bg-couriers')); }
        $paper = (isset($_GET['paper']) && strtoupper(sanitize_text_field(wp_unslash($_GET['paper']))) === 'A6') ? 'A6' : 'A4';
        try { $pdfs = self::collect_label_pdfs($order_ids); }
        /* translators: %s: error message */
        catch (\Exception $e) { wp_die(esc_html(sprintf(__('Print failed: %s', 'bg-couriers'), $e->getMessage()))); }
        if (!$pdfs) { wp_die(esc_html__('No labels to print.', 'bg-couriers')); }

        // A single label always prints at its NATIVE size (packing it onto A6 would shrink an A4-landscape
        // Econt label to a tiny corner). Packing only kicks in when combining several labels onto a sheet.
        $out = (count($pdfs) === 1) ? $pdfs[0] : BGC_Label_Packer::pack($pdfs, $paper);
        if ($out === '') { $out = $pdfs[0]; }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="labels-' . strtolower($paper) . '.pdf"');
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF
        exit;
    }

    /** Collect each order's label PDF bytes: the saved file first, else re-fetch from the courier. */
    public static function collect_label_pdfs(array $order_ids): array {
        $up = wp_upload_dir();
        $pdfs = [];
        foreach ($order_ids as $oid) {
            $o = wc_get_order((int) $oid);
            if (!$o) { continue; }
            $url = (string) $o->get_meta('_bgc_label_url');
            if ($url !== '' && !empty($up['baseurl']) && strpos($url, $up['baseurl']) === 0) {
                $file = $up['basedir'] . substr($url, strlen($up['baseurl']));
                if (is_file($file)) { $bytes = @file_get_contents($file); if ($bytes !== false && $bytes !== '') { $pdfs[] = $bytes; continue; } }
            }
            $courier = self::order_courier($o);
            $wb = (string) $o->get_meta('_bgc_waybill');
            if ($courier && $wb !== '' && method_exists($courier, 'get_label_pdf')) {
                try { $b = $courier->get_label_pdf($wb); if ($b !== '') { $pdfs[] = $b; } } catch (\Exception $e) {}
            }
        }
        return $pdfs;
    }
}
