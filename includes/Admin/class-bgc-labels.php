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
        add_action('woocommerce_order_refunded', [$this, 'maybe_cancel_on_refund'], 20, 2);
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
        // Never store an empty waybill: a courier API that "succeeded" without returning a shipment id
        // did NOT create a shipment (guards every courier against 200-with-error-body responses).
        if ((string) $label->waybill === '') {
            throw new BGC_Api_Exception(esc_html__('The courier returned no waybill number.', 'bg-couriers'));
        }
        $order->update_meta_data('_bgc_waybill', $label->waybill);

        // The format the PRIMARY stored label should be in - the merchant's per-courier size setting for
        // size-aware couriers (Speedy/Sameday), else the courier's single native format, else '' (no choice).
        $primary = self::courier_primary_format($courier);
        // Get the label PDF bytes. Three cases, in order:
        //  - the create response returned a URL  (Econt) -> download it;
        //  - the create response returned the PDF inline (Pigeon has no separate label endpoint) -> use as-is;
        //  - otherwise fetch the label by waybill IN THE CONFIGURED SIZE (Speedy/Sameday request it, so the
        //    courier returns a correctly-sized native PDF and we never scale it).
        if ($label->pdf !== '' && strpos($label->pdf, 'http') === 0) {
            $pdf = self::download_pdf($label->pdf);
        } elseif ($label->pdf !== '') {
            $pdf = $label->pdf;
        } else {
            $pdf = $courier->get_label_pdf($label->waybill, $primary);
        }
        // Only store real PDF bytes. The shipment already exists at the courier, so on a bad/empty body
        // we keep the waybill and leave the URL empty - printing re-fetches the label on demand.
        $url = '';
        if (strncmp($pdf, '%PDF', 4) === 0) {
            $url = self::store_label_file($courier->id(), (string) $label->waybill, $pdf);
            $order->update_meta_data('_bgc_label_url', $url);
        }
        $order->update_meta_data('_bgc_label_paper_size', $primary);
        /* translators: 1: courier name, 2: waybill number */
        $order->add_order_note(sprintf(__('%1$s label generated: %2$s', 'bg-couriers'), $courier->label(), $label->waybill));
        $order->save();
        return new BGC_Label($label->waybill, $url);
    }

    /**
     * The paper format a courier's PRIMARY label is stored in: the merchant's per-courier size setting when
     * the courier supports choosing (label_formats), else its single native format, else '' (no size concept).
     */
    private static function courier_primary_format(BGC_Courier_Interface $courier): string {
        $fmts = $courier->label_formats();
        if (!$fmts) { return ''; }
        $set = BGC_Settings::label_paper_size($courier->id());
        return in_array($set, $fmts, true) ? $set : (string) $fmts[0];
    }

    /**
     * Write label PDF bytes to a non-guessable file in uploads/bgc-labels and return its public URL. A random
     * token keeps the URL unguessable (the PDF carries the recipient's name/address/phone - a predictable path
     * would leak PII). Reused for the primary label and for cached alternate-size variants.
     */
    private static function store_label_file(string $courier_id, string $waybill, string $pdf): string {
        $up  = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'bgc-labels';
        wp_mkdir_p($dir);
        self::protect_label_dir($dir);
        $safe_waybill = preg_replace('/[^A-Za-z0-9\-]/', '', $waybill);
        $prefix = preg_replace('/[^a-z0-9]/', '', $courier_id) ?: 'bgc';
        $token  = wp_generate_password(24, false); // [A-Za-z0-9] only
        $name   = $prefix . '-' . $safe_waybill . '-' . $token . '.pdf';
        file_put_contents($dir . '/' . $name, $pdf);
        return trailingslashit($up['baseurl']) . 'bgc-labels/' . $name;
    }
    private static function download_pdf(string $url): string {
        $r = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($r)) { throw new BGC_Api_Exception(esc_html('Label PDF download failed: ' . $r->get_error_message())); }
        return (string) wp_remote_retrieve_body($r);
    }

    /** Drop an empty index.html into the label dir so the folder can't be directory-listed. */
    private static function protect_label_dir(string $dir): void {
        $index = $dir . '/index.html';
        if (!file_exists($index)) { @file_put_contents($index, ''); }
    }

    /** Delete the on-disk label PDF for a saved _bgc_label_url (only inside uploads/bgc-labels). */
    private static function delete_label_file(string $url): void {
        if ($url === '') { return; }
        $up = wp_upload_dir();
        if (empty($up['baseurl']) || strpos($url, trailingslashit($up['baseurl']) . 'bgc-labels/') !== 0) { return; }
        $file = $up['basedir'] . substr($url, strlen($up['baseurl']));
        if (is_file($file)) { wp_delete_file($file); }
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
        $id = absint(wp_unslash($_GET['order_id'] ?? 0));
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
        // If the courier refuses the cancel, it may be because the waybill is ALREADY cancelled/gone there -
        // in which case the desired end state is reached, so clear our record. Only surface a failure when
        // the shipment is still live (never silently drop an active shipment).
        $already = false;
        if (!$courier->cancel_label($waybill)) {
            if (!$courier->is_cancelled($waybill)) {
                throw new BGC_Api_Exception(esc_html__('The courier did not cancel the waybill.', 'bg-couriers'));
            }
            $already = true;
        }
        self::delete_label_file((string) $order->get_meta('_bgc_label_url')); // remove the PII PDF for the voided shipment
        $order->delete_meta_data('_bgc_waybill');
        $order->delete_meta_data('_bgc_label_url');
        $order->add_order_note($already
            /* translators: %s: waybill number */
            ? sprintf(__('Shipment label %s was already cancelled at the courier; removed from the order.', 'bg-couriers'), $waybill)
            /* translators: %s: waybill number */
            : sprintf(__('Shipment label %s cancelled.', 'bg-couriers'), $waybill));
        $order->save();
    }

    /**
     * When an order is FULLY refunded, void its courier waybill (best effort) so a refunded shipment is not
     * still delivered + billed. Partial refunds are left alone. Fires on woocommerce_order_refunded.
     */
    public function maybe_cancel_on_refund($order_id, $refund_id): void {
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        if ((string) $order->get_meta('_bgc_waybill') === '') { return; }        // nothing to void
        if ((float) $order->get_remaining_refund_amount() > 0) { return; }        // partial refund -> keep the waybill
        try { self::cancel((int) $order_id); }
        catch (\Exception $e) {
            /* translators: %s: error message from the courier */
            $order->add_order_note(sprintf(__('Auto-cancel of the waybill after a full refund failed: %s', 'bg-couriers'), $e->getMessage()));
        }
    }

    private function fail_note(int $id, string $msg, string $context): void {
        set_transient('bgc_admin_error_' . $id, $msg, 60);
        if ($o = wc_get_order($id)) { $o->add_order_note($context . ': ' . $msg); }
    }

    public function handle_cancel_label(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $id = absint(wp_unslash($_GET['order_id'] ?? 0));
        check_admin_referer('bgc_cancel_label_' . $id);
        try { self::cancel($id); }
        catch (\Exception $e) { $this->fail_note($id, $e->getMessage(), __('Label cancellation failed', 'bg-couriers')); }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    /** Void the existing waybill and issue a fresh one from the order's current delivery details. */
    public function handle_regenerate(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $id = absint(wp_unslash($_GET['order_id'] ?? 0));
        check_admin_referer('bgc_regenerate_' . $id);
        try { self::cancel($id); self::generate($id); }
        catch (\Exception $e) { $this->fail_note($id, $e->getMessage(), __('Label re-generation failed', 'bg-couriers')); }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    /** Cancel the order (and void its label first, best effort). */
    public function handle_cancel_order(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $id = absint(wp_unslash($_GET['order_id'] ?? 0));
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
        $id = absint(wp_unslash($_POST['order_id'] ?? 0));
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

        // Nonce is verified at the top of this handler (check_ajax_referer); the reads inside this closure
        // can't be traced back to it by the linter, so ignore the false-positive nonce warning here.
        $t = static function ($k) { return isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : ''; }; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        BGC_Checkout::apply_delivery($order, [
            'courier' => $courier, 'method' => sanitize_key(wp_unslash($_POST['method'] ?? '')),
            'site_id' => absint(wp_unslash($_POST['site_id'] ?? 0)), 'office_id' => absint(wp_unslash($_POST['office_id'] ?? 0)),
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
        $id = absint(wp_unslash($_POST['order_id'] ?? 0));
        check_ajax_referer('bgc_cancel_label_' . $id, 'nonce');
        try { self::cancel($id); }
        catch (\Exception $e) { wp_send_json_error(['msg' => $e->getMessage()]); }
        wp_send_json_success(['msg' => __('Waybill cancelled.', 'bg-couriers')]);
    }

    public function handle_track(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        $id = absint(wp_unslash($_GET['order_id'] ?? 0));
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
            ? [(int) wp_unslash($_GET['order_id'])] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, nonce verified above
            : (array) get_transient('bgc_print_batch_' . get_current_user_id());
        $order_ids = array_filter(array_map('intval', $order_ids));
        if (!$order_ids) { wp_die(esc_html__('No labels to print.', 'bg-couriers')); }
        $paper = (isset($_GET['paper']) && strtoupper(sanitize_text_field(wp_unslash($_GET['paper']))) === 'A6') ? 'A6' : 'A4';
        try {
            if (count($order_ids) === 1) {
                // Individual print in the courier's configured size ($paper is that setting, from the metabox).
                $pdfs = self::collect_label_pdfs($order_ids, $paper);
                $out  = $pdfs[0] ?? '';
            } else {
                // Batch: each courier lays out its own sheet natively, then the sheets are concatenated.
                $out = self::batch_pdf($order_ids, $paper);
            }
        }
        /* translators: %s: error message */
        catch (\Exception $e) { wp_die(esc_html(sprintf(__('Print failed: %s', 'bg-couriers'), $e->getMessage()))); }
        if ($out === '') { wp_die(esc_html__('No labels to print.', 'bg-couriers')); }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="labels-' . strtolower($paper) . '.pdf"');
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF
        exit;
    }

    /**
     * Combined PDF for printing MANY orders' labels, grouped by courier so each courier lays out its OWN sheet
     * the way it prints 1-by-1: a courier with a native multi-label endpoint (Speedy - landscape, its own
     * per-A4 layout) produces one native sheet; the rest use their stored labels. Courier sheets are then
     * concatenated at native size (no re-packing, no scaling). $format = the requested paper size (A4/A6).
     */
    public static function batch_pdf(array $order_ids, string $format = ''): string {
        $groups = []; // courier_id => [ order_id => waybill ]
        foreach ($order_ids as $oid) {
            $o = wc_get_order((int) $oid);
            if (!$o) { continue; }
            $wb  = (string) $o->get_meta('_bgc_waybill');
            $cid = (string) $o->get_meta('_bgc_courier');
            if ($wb === '' || $cid === '') { continue; }
            $groups[$cid][(int) $oid] = $wb;
        }
        $a4 = strtoupper($format) === 'A4' && BGC_Label_Packer::available();
        $sheets = []; $halfs = []; $smalls = [];
        foreach ($groups as $cid => $map) {
            $courier = BGC_Couriers::get($cid);
            if (!$courier) { continue; }
            if ($courier->has_native_batch()) {
                // On A4 fetch Speedy's RAW half-sheet pages so they can be composed below TOGETHER
                // with other couriers' sticker labels (a leftover half column gets filled instead
                // of wasting a nearly-empty sheet on one sticker).
                if ($a4 && $courier instanceof BGC_Speedy) {
                    try { $halfs[] = $courier->print_labels(array_values($map), 'A4'); continue; }
                    catch (\Exception $e) { /* fall through to the courier's own batch */ }
                }
                // The courier lays out the whole batch itself.
                try { $b = $courier->batch_label_pdf(array_values($map), $format); if ($b !== '') { $sheets[] = $b; } }
                catch (\Exception $e) { /* skip this courier */ }
            } else {
                // Stored per-label files (no re-fetch - Pigeon can't re-fetch). On A4 the labels are
                // PLACED on real A4 pages at native size (a sticker-sized PDF page would otherwise be
                // blown up to the paper by the print dialog); labels larger than A4 keep their own
                // native page. On A6 (sticker roll) the native pages pass through unchanged.
                $per = self::collect_label_pdfs(array_keys($map), $format);
                if (!$per) { continue; }
                if ($a4) { foreach ($per as $p) { $smalls[] = $p; } continue; }
                $sheets[] = count($per) === 1 ? $per[0] : BGC_Label_Packer::concat($per);
            }
        }
        if ($a4 && ($halfs || $smalls)) {
            $res = BGC_Label_Packer::compose_a4($halfs, $smalls);
            if ($res['pdf'] !== '') { $sheets[] = $res['pdf']; }
            if ($res['leftover']) {
                $packed = BGC_Label_Packer::pack($res['leftover'], 'A4');
                if ($packed !== '') { $sheets[] = $packed; }
                elseif (count($res['leftover']) === 1) { $sheets[] = $res['leftover'][0]; }
                else { $sheets[] = BGC_Label_Packer::concat($res['leftover']); }
            }
        }
        if (!$sheets) { return ''; }
        return count($sheets) === 1 ? $sheets[0] : BGC_Label_Packer::concat($sheets);
    }

    /**
     * Collect each order's label PDF bytes in the desired paper $format ('' = each label's stored/native
     * format). The couriers' own PDFs are returned unscaled; the packer arranges them at native size.
     */
    public static function collect_label_pdfs(array $order_ids, string $format = ''): array {
        $pdfs = [];
        foreach ($order_ids as $oid) {
            $o = wc_get_order((int) $oid);
            if (!$o) { continue; }
            $bytes = self::label_pdf_for_print($o, $format);
            if ($bytes !== '') { $pdfs[] = $bytes; }
        }
        return $pdfs;
    }

    /**
     * The label PDF bytes for one order in the desired paper $format. Serves the stored primary file when it
     * already matches (or the courier has a single fixed format). Only when the courier can actually PRODUCE a
     * different requested size do we fetch it from the courier once and CACHE it to a per-format file - so the
     * next print of that size is instant and we never scale a label to fake a size it wasn't made in.
     */
    private static function label_pdf_for_print(\WC_Order $o, string $format): string {
        $courier = self::order_courier($o);
        $wb      = (string) $o->get_meta('_bgc_waybill');
        $primary = (string) $o->get_meta('_bgc_label_paper_size');
        $fmts    = $courier ? $courier->label_formats() : [];
        // Only switch away from the stored primary when the courier can truly make the requested (different)
        // size; otherwise serve what we already have.
        $want = ($format !== '' && in_array($format, $fmts, true)) ? $format : $primary;

        if ($want === '' || $want === $primary) {
            $b = self::read_stored_url($o, (string) $o->get_meta('_bgc_label_url'));
            if ($b !== '') { return $b; }
            if ($courier && $wb !== '') {
                try { return $courier->get_label_pdf($wb, $primary); } catch (\Exception $e) { return ''; }
            }
            return '';
        }

        // A different, courier-supported size: cached variant file first, else fetch + cache it.
        $meta_key = '_bgc_label_url_' . $want;
        $cached   = self::read_stored_url($o, (string) $o->get_meta($meta_key));
        if ($cached !== '') { return $cached; }
        if ($courier && $wb !== '') {
            try {
                $pdf = $courier->get_label_pdf($wb, $want);
                if ($pdf !== '') {
                    $url = self::store_label_file($courier->id(), $wb . '-' . strtolower($want), $pdf);
                    $o->update_meta_data($meta_key, $url);
                    $o->save();
                    return $pdf;
                }
            } catch (\Exception $e) {}
        }
        // Variant unavailable - fall back to the stored primary rather than fail the print.
        return self::read_stored_url($o, (string) $o->get_meta('_bgc_label_url'));
    }

    /** Read the bytes of a stored label file from its uploads URL, or '' if it isn't a readable local file. */
    private static function read_stored_url(\WC_Order $o, string $url): string {
        if ($url === '') { return ''; }
        $up = wp_upload_dir();
        if (empty($up['baseurl']) || strpos($url, $up['baseurl']) !== 0) { return ''; }
        $file = $up['basedir'] . substr($url, strlen($up['baseurl']));
        if (!is_file($file)) { return ''; }
        $b = @file_get_contents($file);
        return ($b !== false) ? (string) $b : '';
    }
}
