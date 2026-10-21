<?php
defined('ABSPATH') || exit;

class BGCouriers_Labels {
    const RETRY_HOOK = 'bgcouriers_retry_autolabel';

    public static function order_courier($order): ?BGCouriers_Courier_Interface {
        if (!$order instanceof \WC_Order) { return null; }
        $id = (string) $order->get_meta('_bgcouriers_courier');
        return $id !== '' ? BGCouriers_Couriers::get($id) : null;
    }
    private function courier_for(\WC_Order $order): ?BGCouriers_Courier_Interface {
        return self::order_courier($order);
    }

    public function __construct() {
        add_action('admin_post_bgcouriers_generate_label', [$this, 'handle_generate']);
        add_action('admin_post_bgcouriers_cancel_label', [$this, 'handle_cancel_label']);
        add_action('admin_post_bgcouriers_regenerate', [$this, 'handle_regenerate']);
        add_action('admin_post_bgcouriers_cancel_order', [$this, 'handle_cancel_order']);
        add_action('admin_post_bgcouriers_track', [$this, 'handle_track']);
        add_action('admin_post_bgcouriers_print_batch', [$this, 'handle_print_batch']);
        add_action('wp_ajax_bgcouriers_order_save_delivery', [$this, 'handle_save_delivery']);
        add_action('wp_ajax_bgcouriers_ajax_cancel_label', [$this, 'ajax_cancel_label']);
        add_action('woocommerce_order_status_changed', [$this, 'maybe_auto_generate'], 20, 4);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_status_changed'], 20, 4);
        add_action(self::RETRY_HOOK, [__CLASS__, 'attempt_auto_label'], 10, 1);
        add_action('woocommerce_order_refunded', [$this, 'maybe_cancel_on_refund'], 20, 2);
        // Both hooks: the Update button and the AJAX line-item editor are separate paths.
        add_action('woocommerce_process_shop_order_meta', [$this, 'maybe_regenerate_on_change'], 90, 1);
        add_action('woocommerce_saved_order_items', [$this, 'maybe_regenerate_on_change'], 90, 1);
        // The WooCommerce mobile app edits orders through the REST API, which fires neither of the two above.
        add_action('woocommerce_rest_insert_shop_order_object', [$this, 'maybe_regenerate_after_rest'], 90, 3);
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'maybe_move_dispatch'], 91, 1);
    }

    /** The dispatch-day appointment follows an edited ship day. */
    public static function maybe_move_dispatch($order_id): void {
        $order = wc_get_order((int) $order_id);
        if (!$order || (string) $order->get_meta('_bgcouriers_waybill') !== '') { return; }
        if ((string) $order->get_meta('_bgcouriers_autolabel_at') === '') { return; }
        self::attempt_auto_label((int) $order_id);
    }

    public function maybe_auto_generate($order_id, $old_status, $new_status, $order): void {
        $cfg = BGCouriers_Settings::autolabel();
        if ('wc-' . $new_status !== $cfg['status']) { return; }
        if (!self::order_courier($order)) { return; }
        if (!BGCouriers_Settings::autolabel_for((string) $order->get_meta('_bgcouriers_courier'))) { return; }
        if ($order->get_meta('_bgcouriers_waybill') !== '') { return; }
        self::attempt_auto_label((int) $order_id);
    }

    const AUTOLABEL_RETRIES = [5, 20, 60, 180];

    /** Hour of the dispatch day (site zone) at which the automatic waybill is issued. */
    const DISPATCH_HOUR = 7;

    /** When this order's parcel is meant to go out, or 0 when the order does not say (filter: bgcouriers_dispatch_time). */
    public static function dispatch_time(\WC_Order $order): int {
        $ts    = 0;
        $orddd = (int) $order->get_meta('_orddd_timestamp');
        if ($orddd > 0) {
            // The orddd stamp is a midnight in some zone: round to the nearest UTC day, then set the hour in the site's zone.
            $day  = gmdate('Y-m-d', (int) round($orddd / DAY_IN_SECONDS) * DAY_IN_SECONDS);
            $hour = max(0, min(23, (int) apply_filters('bgcouriers_dispatch_hour', self::DISPATCH_HOUR)));
            $at   = date_create_immutable($day . ' ' . sprintf('%02d:00:00', $hour), wp_timezone());
            if ($at) { $ts = $at->getTimestamp(); }
        }
        return max(0, (int) apply_filters('bgcouriers_dispatch_time', $ts, $order));
    }

    private static function wait_for_dispatch(\WC_Order $order, int $at): void {
        $id   = $order->get_id();
        $next = wp_next_scheduled(self::RETRY_HOOK, [$id]);
        if ($next !== false && (int) $next !== $at) { wp_unschedule_event($next, self::RETRY_HOOK, [$id]); $next = false; }
        if ($next === false) { wp_schedule_single_event($at, self::RETRY_HOOK, [$id]); }
        if ((int) $order->get_meta('_bgcouriers_autolabel_at') !== $at) {
            $order->update_meta_data('_bgcouriers_autolabel_at', $at);
            /* translators: %s: the day and hour the waybill will be issued */
            $order->add_order_note(sprintf(__('The waybill will be issued on %s, the day this order ships.', 'bg-couriers'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), $at)));
            $order->save();
        }
    }

    /** Statuses in which nothing will ship: the dispatch-day appointment must not book a shipment for them. */
    const NO_SHIP_STATUSES = ['cancelled', 'refunded', 'failed', 'trash', 'completed', 'pending', 'on-hold', 'checkout-draft'];

    public static function attempt_auto_label(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order || (string) $order->get_meta('_bgcouriers_waybill') !== '') { return; }
        $trigger = preg_replace('/^wc-/', '', (string) BGCouriers_Settings::autolabel()['status']);
        if (!$order->has_status($trigger) && $order->has_status(self::NO_SHIP_STATUSES)) {
            if ((string) $order->get_meta('_bgcouriers_autolabel_at') !== '') {
                $order->delete_meta_data('_bgcouriers_autolabel_at');
                $order->save();
            }
            return;
        }
        if (BGCouriers_Settings::autolabel_wait()) {
            $at = self::dispatch_time($order);
            if ($at > time()) { self::wait_for_dispatch($order, $at); return; }
        }
        try {
            self::generate($order_id);
            $order = wc_get_order($order_id);
            $order->delete_meta_data('_bgcouriers_autolabel_try');
            $order->delete_meta_data('_bgcouriers_autolabel_at');
            $order->save();
            return;
        } catch (BGCouriers_Claimed_Exception $e) {
            // Another request is issuing it right now: not a failure, no retry.
            return;
        } catch (\Exception $e) {
            $try  = (int) $order->get_meta('_bgcouriers_autolabel_try');
            $wait = self::AUTOLABEL_RETRIES[$try] ?? null;
            if ($wait === null) {
                /* translators: 1: number of attempts, 2: error message */
                $order->add_order_note(sprintf(__('BG Couriers auto-label gave up after %1$d attempts: %2$s', 'bg-couriers'),
                    $try + 1, $e->getMessage()));
                $order->delete_meta_data('_bgcouriers_autolabel_try');
                $order->save();
                return;
            }
            $order->update_meta_data('_bgcouriers_autolabel_try', $try + 1);
            $order->save();
            wp_schedule_single_event(time() + $wait * MINUTE_IN_SECONDS, self::RETRY_HOOK, [$order_id]);
            /* translators: 1: error message, 2: minutes until the next attempt */
            $order->add_order_note(sprintf(__('BG Couriers auto-label failed: %1$s - retrying in %2$d min.', 'bg-couriers'),
                $e->getMessage(), $wait));
        }
    }
    /** Re-quote what the courier collects at the door (delivery not in the order total only); failure leaves the old figure. */
    public static function refresh_door_price(\WC_Order $order): void {
        $courier_id = (string) $order->get_meta('_bgcouriers_courier');
        if ($courier_id === '' || BGCouriers_Settings::ship_in_total($courier_id)) { return; }
        $courier = BGCouriers_Couriers::get($courier_id);
        $shipment = BGCouriers_Pricing::order_shipment($order);
        if (!$courier || !$shipment) { return; }
        try {
            $quote = BGCouriers_Pricing::quote($courier, $shipment);
        } catch (\Throwable $e) {
            BGCouriers_Logger::debug('door price: re-quote failed, keeping the old one', [
                'order' => $order->get_id(), 'courier' => $courier_id, 'err' => $e->getMessage()]);
            return;
        }
        // Only a live answer: quote() never refuses, so a fallback price would overwrite a real figure with a placeholder.
        if ($quote->source !== 'live') {
            BGCouriers_Logger::debug('door price: not a live quote, keeping the old one', [
                'order' => $order->get_id(), 'courier' => $courier_id, 'source' => $quote->source]);
            return;
        }
        $door = BGCouriers_Pricing::door_price($quote);
        if ($door <= 0) { return; }
        foreach ($order->get_items('shipping') as $item) {
            if (!$item instanceof \WC_Order_Item_Shipping) { continue; }
            if (strpos((string) $item->get_method_id(), 'bgcouriers_') !== 0) { continue; }
            $item->update_meta_data('_bgcouriers_info_price', $door);
            $item->save();
        }
    }

    /** The one place a waybill is made: at most one per order, guarded by claim(). */
    public static function generate(int $order_id): BGCouriers_Label {
        $order = wc_get_order($order_id);
        if (!$order) { throw new BGCouriers_Api_Exception(esc_html__('Order not found.', 'bg-couriers')); }
        $existing = (string) $order->get_meta('_bgcouriers_waybill');
        if ($existing !== '') { return new BGCouriers_Label($existing, (string) $order->get_meta('_bgcouriers_label_url')); }

        if (!self::claim($order_id)) {
            throw new BGCouriers_Claimed_Exception(esc_html__('A waybill for this order is being issued right now. Give it a moment.', 'bg-couriers'));
        }
        try {
            $order    = wc_get_order($order_id);   // fresh: the previous holder may have written one
            $existing = (string) $order->get_meta('_bgcouriers_waybill');
            if ($existing !== '') { return new BGCouriers_Label($existing, (string) $order->get_meta('_bgcouriers_label_url')); }
            return self::issue($order);
        } finally {
            self::release($order_id);
        }
    }

    /** @var array<int,true> In-request claims; across requests the claim is a MySQL named lock, re-granted to the same connection. */
    private static array $issuing = [];
    private static function claim(int $order_id): bool {
        if (isset(self::$issuing[$order_id])) { return false; }
        global $wpdb;
        // A named MySQL lock is the query, not data: there is nothing to cache and no API for it.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', 'bgcouriers_label_' . $order_id)) !== '1') { return false; }
        self::$issuing[$order_id] = true;
        return true;
    }
    private static function release(int $order_id): void {
        global $wpdb;
        unset(self::$issuing[$order_id]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'bgcouriers_label_' . $order_id));
    }

    private static function issue(\WC_Order $order): BGCouriers_Label {
        $courier_id = (string) $order->get_meta('_bgcouriers_courier');
        $courier = $courier_id ? BGCouriers_Couriers::get($courier_id) : null;
        if (!$courier) { throw new BGCouriers_Api_Exception(esc_html__('Unknown courier for this order.', 'bg-couriers')); }
        // Refused once here for every courier: e.g. Express One takes a COD field for an EXOBOX locker but cannot collect there.
        $ship_method = (string) $order->get_meta('_bgcouriers_method');
        if ($order->get_payment_method() === 'cod'
            && in_array($ship_method, BGCouriers_Settings::no_cod_methods($courier_id), true)) {
            throw new BGCouriers_Api_Exception(esc_html(sprintf(
                /* translators: 1: courier name, 2: the delivery option, e.g. "To APS" */
                __('%1$s does not collect cash on delivery for this delivery option (%2$s). Change the delivery, or mark the order paid, before booking the waybill.', 'bg-couriers'),
                $courier->label(), BGCouriers_Icons::method_label($ship_method))));
        }
        $label = $courier->create_label($order);
        // A "success" with no shipment id created no shipment.
        if ((string) $label->waybill === '') {
            throw new BGCouriers_Api_Exception(esc_html__('The courier returned no waybill number.', 'bg-couriers'));
        }
        $order->update_meta_data('_bgcouriers_waybill', $label->waybill);
        self::reset_shipment_state($order);
        $order->update_meta_data('_bgcouriers_track_stage', 'registered');
        $order->update_meta_data('_bgcouriers_track_updated', time());
        // Saved before the PDF is fetched: a PDF failure must not leave a created shipment off the order (the retry would book a second).
        $order->save();

        self::refresh_door_price($order);

        // A courier can accept a shipment and quietly drop part of it (Speedy's COD): record it loudly.
        if ($label->problems) {
            $order->update_meta_data('_bgcouriers_label_warning', implode(' ', $label->problems));
            $order->add_order_note(sprintf(
                /* translators: 1: courier name, 2: what the courier did not apply */
                __('⚠ %1$s: the waybill was created but NOT everything was applied. %2$s Check the shipment before handing it over.', 'bg-couriers'),
                $courier->label(),
                implode(' ', $label->problems)
            ));
        } else {
            $order->delete_meta_data('_bgcouriers_label_warning');
        }

        $primary = self::courier_primary_format($courier);
        // URL from the create response (Econt), inline PDF (Pigeon), else fetched by waybill in the configured size.
        $pdf = '';
        try {
            if ($label->pdf !== '' && strpos($label->pdf, 'http') === 0) {
                $pdf = self::download_pdf($label->pdf);
            } elseif ($label->pdf !== '') {
                $pdf = $label->pdf;
            } else {
                $pdf = $courier->get_label_pdf($label->waybill, $primary);
            }
        } catch (\Exception $e) {
            // Not a failed label: the shipment is booked; printing fetches the PDF again.
            BGCouriers_Logger::debug('label: the PDF could not be fetched after the shipment was created', [
                'courier' => $courier_id, 'waybill' => $label->waybill, 'err' => $e->getMessage()]);
            /* translators: 1: courier name, 2: waybill number, 3: error message */
            $order->add_order_note(sprintf(__('%1$s issued waybill %2$s, but the label PDF could not be fetched yet: %3$s. Print will fetch it again.', 'bg-couriers'),
                $courier->label(), $label->waybill, $e->getMessage()));
        }
        $url = '';
        if (strncmp($pdf, '%PDF', 4) === 0) {
            $url = self::store_label_file($courier->id(), (string) $label->waybill, $pdf);
            $order->update_meta_data('_bgcouriers_label_url', $url);
        }
        $order->update_meta_data('_bgcouriers_label_paper_size', $primary);
        $order->update_meta_data('_bgcouriers_label_fp', self::label_fingerprint($order));
        // Keyed on the waybill, not the fingerprint: a re-issue of an unchanged order is a new, unprinted document.
        $order->update_meta_data('_bgcouriers_label_needs_print', '1');
        if (class_exists('BGCouriers_Tracking_Poller')) {
            BGCouriers_Tracking_Poller::remember_stage_time($order, 'registered');
        }
        /* translators: 1: courier name, 2: waybill number */
        $order->add_order_note(sprintf(__('%1$s label generated: %2$s', 'bg-couriers'), $courier->label(), $label->waybill));
        $order->save();
        return new BGCouriers_Label($label->waybill, $url);
    }

    private static function courier_primary_format(BGCouriers_Courier_Interface $courier): string {
        $fmts = $courier->label_formats();
        if (!$fmts) { return ''; }
        $set = BGCouriers_Settings::label_paper_size($courier->id());
        return in_array($set, $fmts, true) ? $set : (string) $fmts[0];
    }

    /** Random token in the file name: the PDF carries the recipient's PII, so the path must not be guessable. */
    private static function store_label_file(string $courier_id, string $waybill, string $pdf): string {
        $up  = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'bgc-labels';
        wp_mkdir_p($dir);
        self::protect_label_dir($dir);
        $safe_waybill = preg_replace('/[^A-Za-z0-9\-]/', '', $waybill);
        $prefix = preg_replace('/[^a-z0-9]/', '', $courier_id) ?: 'bgc';
        $token  = wp_generate_password(24, false);
        $name   = $prefix . '-' . $safe_waybill . '-' . $token . '.pdf';
        file_put_contents($dir . '/' . $name, $pdf);
        return trailingslashit($up['baseurl']) . 'bgc-labels/' . $name;
    }
    private static function download_pdf(string $url): string {
        $r = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($r)) {
            /* translators: %s: the error the download reported. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('Downloading the label PDF failed: %s', 'bg-couriers'), $r->get_error_message())));
        }
        return (string) wp_remote_retrieve_body($r);
    }

    private static function protect_label_dir(string $dir): void {
        $index = $dir . '/index.html';
        if (!file_exists($index)) { @file_put_contents($index, ''); }
    }

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
            return $o ? (string) $o->get_meta('_bgcouriers_waybill') : '';
        };
        $ids = [];
        foreach ($order_ids as $oid) {
            $w = (string) $resolver($oid);
            if ($w !== '') { $ids[] = $w; }
        }
        return $ids;
    }
    public function handle_generate(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do that.', 'bg-couriers')); }
        $id = absint(wp_unslash($_GET['order_id'] ?? 0));
        check_admin_referer('bgcouriers_generate_label_' . $id);
        if (!wc_get_order($id)) { wp_die(esc_html__('Order not found.', 'bg-couriers')); }
        try { self::generate($id); }
        catch (\Exception $e) {
            /* translators: %s: error message from the courier */
            set_transient('bgcouriers_admin_error_' . $id, sprintf(__('Label generation failed: %s', 'bg-couriers'), $e->getMessage()), 60);
            if ($o = wc_get_order($id)) {
                /* translators: %s: error message from the courier */
                $o->add_order_note(sprintf(__('Label generation failed: %s', 'bg-couriers'), $e->getMessage()));
            }
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }
    /** Everything a waybill depends on, as one md5; status, notes and dates are deliberately not in it. */
    public static function label_fingerprint(\WC_Order $order): string {
        $parts = [];
        // boxnow_name / boxnow_addr are retired but stay: dropping a key would change the md5 on every live waybill.
        foreach (['courier', 'method', 'site_id', 'office_id', 'post_code', 'street_name', 'street_no',
                  'complex', 'block', 'entrance', 'floor', 'apartment', 'address_note',
                  'boxnow_name', 'boxnow_addr', 'weight_kg', 'parcels', 'insurance'] as $k) {
            $parts[] = $k . '=' . (string) $order->get_meta('_bgcouriers_' . $k);
        }
        $parts[] = 'name=' . $order->get_formatted_billing_full_name();
        $parts[] = 'phone=' . $order->get_billing_phone();
        $parts[] = 'pay=' . $order->get_payment_method();
        $parts[] = 'total=' . wc_format_decimal($order->get_total(), 2);
        $parts[] = 'ship=' . wc_format_decimal($order->get_shipping_total(), 2);
        $parts[] = 'weight=' . BGCouriers_Abstract_Courier::order_weight_kg($order);
        foreach ($order->get_items() as $item) {
            $parts[] = 'i:' . $item->get_product_id() . 'x' . $item->get_quantity() . '@' . wc_format_decimal($item->get_total(), 2);
        }
        return md5(implode('|', $parts));
    }

    public const LABEL_STATE_OK = '';
    public const LABEL_STATE_STALE = 'stale';
    public const LABEL_STATE_UNPRINTED = 'unprinted';

    /** 'stale' (red): the order's fingerprint no longer matches the waybill's; 'unprinted' (green): no print route has run yet. */
    public static function label_state(\WC_Order $order): string {
        if ((string) $order->get_meta('_bgcouriers_waybill') === '') { return self::LABEL_STATE_OK; }
        // '' means the waybill predates the fingerprint: nothing to compare, so not red.
        $issued = (string) $order->get_meta('_bgcouriers_label_fp');
        if ($issued !== '' && $issued !== self::label_fingerprint($order)) { return self::LABEL_STATE_STALE; }
        if ((string) $order->get_meta('_bgcouriers_label_needs_print') === '1') { return self::LABEL_STATE_UNPRINTED; }
        return self::LABEL_STATE_OK;
    }

    /** Clears the "not printed yet" flag from every print route; a stale waybill stays flagged. @param int[] $order_ids */
    public static function mark_printed(array $order_ids): void {
        foreach ($order_ids as $oid) {
            $o = wc_get_order((int) $oid);
            if (!$o || (string) $o->get_meta('_bgcouriers_waybill') === '') { continue; }
            $o->update_meta_data('_bgcouriers_label_needs_print', '');
            $o->update_meta_data('_bgcouriers_label_printed_at', time());
            $o->save();
        }
    }

    public static function print_tip(bool $unprinted): string {
        return $unprinted ? __('Print label (not printed yet)', 'bg-couriers') : __('Print label', 'bg-couriers');
    }

    public static function label_state_message(\WC_Order $order, ?string $state = null): string {
        switch ($state ?? self::label_state($order)) {
            case self::LABEL_STATE_STALE:
                return self::is_locked($order)
                    ? __('The order changed after this waybill was issued, and the courier already holds the parcel - arrange the change with the courier.', 'bg-couriers')
                    : __('The order changed after this waybill was issued - re-issue the waybill so it matches.', 'bg-couriers');
            case self::LABEL_STATE_UNPRINTED:
                return __('This waybill has not been printed yet.', 'bg-couriers');
            default:
                return '';
        }
    }

    public function maybe_regenerate_after_rest($order, $request = null, $creating = false): void {
        if ($creating || !$order instanceof \WC_Order) { return; }
        $this->maybe_regenerate_on_change($order->get_id());
    }

    /** Re-issue the waybill when an edit changed something the courier needs (fingerprint, not "saved"). */
    public function maybe_regenerate_on_change($order_id): void {
        static $running = false;
        if ($running) { return; }
        if (get_option('bgcouriers_autoregen_on_change', 'yes') !== 'yes') { return; }
        $order = wc_get_order((int) $order_id);
        if (!$order || (string) $order->get_meta('_bgcouriers_waybill') === '') { return; }
        if (!self::order_courier($order)) { return; }
        // Once the courier holds the parcel, re-issuing would void a travelling waybill: say so instead.
        if (self::is_locked($order)) {
            $fp = self::label_fingerprint($order);
            if ((string) $order->get_meta('_bgcouriers_label_fp') !== $fp
                && (string) $order->get_meta('_bgcouriers_change_noted_fp') !== $fp) {
                $order->add_order_note(__('⚠ The order changed, but the waybill was NOT re-issued: the courier already collected this shipment. Arrange the change with the courier.', 'bg-couriers'));
                // Noted once per change without moving the issued fingerprint, so the red flag stays.
                $order->update_meta_data('_bgcouriers_change_noted_fp', $fp);
                $order->save();
            }
            return;
        }

        // Reopened by hand after collection: the waybill may be on the parcel, re-issuing is the merchant's call.
        if ((string) $order->get_meta('_bgcouriers_reopened') === 'yes') { return; }

        $now  = self::label_fingerprint($order);
        $were = (string) $order->get_meta('_bgcouriers_label_fp');
        if ($were === '' || $were === $now) {
            // No stored fingerprint: the label predates this feature, record it and change nothing.
            if ($were === '') { $order->update_meta_data('_bgcouriers_label_fp', $now); $order->save(); }
            return;
        }

        $running = true;
        $old = (string) $order->get_meta('_bgcouriers_waybill');
        try {
            self::cancel((int) $order_id);
            self::generate((int) $order_id);
            $fresh = wc_get_order((int) $order_id);
            $fresh->update_meta_data('_bgcouriers_label_fp', self::label_fingerprint($fresh));
            $fresh->save();
            /* translators: %s: the previous waybill number */
            $fresh->add_order_note(sprintf(__('The order changed, so waybill %s was voided and a new one issued automatically.', 'bg-couriers'), $old));
        } catch (\Exception $e) {
            /* translators: %s: error message from the courier */
            $order->add_order_note(sprintf(__('Automatic re-issue after an order change failed: %s', 'bg-couriers'), $e->getMessage()));
        }
        $running = false;
    }

    public static function cancel(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) { throw new BGCouriers_Api_Exception(esc_html__('Order not found.', 'bg-couriers')); }
        $waybill = (string) $order->get_meta('_bgcouriers_waybill');
        if ($waybill === '') { return; }
        $courier = self::order_courier($order);
        if (!$courier) { throw new BGCouriers_Api_Exception(esc_html__('Unknown courier for this order.', 'bg-couriers')); }
        $already = false;
        $why = '';
        try { $ok = $courier->cancel_label($waybill); }
        catch (BGCouriers_Api_Exception $e) { $ok = false; $why = $e->getMessage(); }
        if (!$ok) {
            // A refused cancel is a failure only while the shipment is alive: the courier's answer first, then the recorded stage.
            $stage = (string) $order->get_meta('_bgcouriers_track_stage');
            if (!$courier->is_cancelled($waybill) && $stage !== 'cancelled') {
                throw new BGCouriers_Api_Exception($why !== ''
                    /* translators: %s: what the courier answered */
                    ? esc_html(sprintf(__('The courier did not cancel the waybill: %s', 'bg-couriers'), $why))
                    : esc_html__('The courier did not cancel the waybill.', 'bg-couriers'));
            }
            $already = true;
        }
        // Every alternate-size variant too, or a later print would serve the old waybill from cache.
        self::delete_label_file((string) $order->get_meta('_bgcouriers_label_url'));
        foreach ($courier->label_formats() as $fmt) {
            $key = '_bgcouriers_label_url_' . $fmt;
            self::delete_label_file((string) $order->get_meta($key));
            $order->delete_meta_data($key);
        }
        $order->delete_meta_data('_bgcouriers_waybill');
        $order->delete_meta_data('_bgcouriers_label_url');
        // The stage would otherwise stay behind and the list would keep showing "registered".
        self::reset_shipment_state($order);
        $order->delete_meta_data('_bgcouriers_track_stage');
        $order->delete_meta_data('_bgcouriers_track_updated');
        $order->add_order_note($already
            /* translators: %s: waybill number */
            ? sprintf(__('Shipment label %s was already cancelled at the courier; removed from the order.', 'bg-couriers'), $waybill)
            /* translators: %s: waybill number */
            : sprintf(__('Shipment label %s cancelled.', 'bg-couriers'), $waybill));
        $order->save();
    }

    public function maybe_cancel_on_refund($order_id, $refund_id): void {
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        if ((string) $order->get_meta('_bgcouriers_waybill') === '') { return; }
        if ((float) $order->get_remaining_refund_amount() > 0) { return; }
        try { self::cancel((int) $order_id); }
        catch (\Exception $e) {
            /* translators: %s: error message from the courier */
            $order->add_order_note(sprintf(__('Auto-cancel of the waybill after a full refund failed: %s', 'bg-couriers'), $e->getMessage()));
        }
    }

    private function fail_note(int $id, string $msg, string $context): void {
        set_transient('bgcouriers_admin_error_' . $id, $context . ': ' . $msg, 60);
        if ($o = wc_get_order($id)) { $o->add_order_note($context . ': ' . $msg); }
    }

    /** True once the courier has the parcel: voiding, re-issuing or editing would change only our copy. Cancelled is not locked. */
    public static function is_locked(\WC_Order $order): bool {
        if ((string) $order->get_meta('_bgcouriers_reopened') === 'yes') { return false; }
        return self::shipment_locked($order);
    }

    private static function shipment_locked(\WC_Order $order): bool {
        $stage = (string) $order->get_meta('_bgcouriers_track_stage');
        // Before the handover flag, which stays set for good: a returned or voided parcel needs a fresh waybill.
        if (in_array($stage, ['returned', 'cancelled'], true)) { return false; }
        if ((string) $order->get_meta('_bgcouriers_handover') === 'yes') { return true; }
        return in_array($stage, ['delivered', 'returning'], true);
    }

    private const REOPEN_STATUSES = ['processing', 'pending'];

    /** Reopens a locked order put back to Processing or Pending; keyed on the transition, since paid orders already sit in Processing. */
    public static function maybe_reopen(\WC_Order $order, string $from, string $to): void {
        if (!in_array($to, self::REOPEN_STATUSES, true)) { return; }
        // Nothing locked yet: the ordinary payment -> Processing transition must not leave a flag behind.
        if (!self::shipment_locked($order)) { return; }
        $order->update_meta_data('_bgcouriers_reopened', 'yes');
        $order->add_order_note(__('BG Couriers: the order was put back in hand, so the waybill can be cancelled, re-issued and edited again - even though the courier has the parcel. Arrange the change with the courier as well.', 'bg-couriers'));
        $order->save();
    }

    public static function on_status_changed($order_id, $from, $to, $order = null): void {
        if (!$order instanceof \WC_Order) { $order = wc_get_order((int) $order_id); }
        if ($order instanceof \WC_Order) { self::maybe_reopen($order, (string) $from, (string) $to); }
    }

    /** Forget the previous parcel (a stale handover flag would lock the fresh waybill); _bgcouriers_shipped_marked stays. */
    public static function reset_shipment_state(\WC_Order $order): void {
        foreach (['_bgcouriers_reopened', '_bgcouriers_handover', '_bgcouriers_track_done',
                  '_bgcouriers_track_first', '_bgcouriers_track_status', '_bgcouriers_track_text'] as $k) {
            $order->delete_meta_data($k);
        }
    }

    public static function locked_message(): string {
        return __('The courier has already collected this shipment - the waybill can no longer be cancelled, re-issued or edited. To work on this order again, set it back to Processing or Pending payment.', 'bg-couriers');
    }

    private static function unlocked_order(int $id): ?\WC_Order {
        $order = wc_get_order($id);
        return ($order && !self::is_locked($order)) ? $order : null;
    }

    public function handle_cancel_label(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do that.', 'bg-couriers')); }
        $id = absint(wp_unslash($_GET['order_id'] ?? 0));
        check_admin_referer('bgcouriers_cancel_label_' . $id);
        if (!self::unlocked_order($id)) {
            $this->fail_note($id, self::locked_message(), __('Label cancellation refused', 'bg-couriers'));
        } else {
            try { self::cancel($id); }
            catch (\Exception $e) { $this->fail_note($id, $e->getMessage(), __('Label cancellation failed', 'bg-couriers')); }
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    public function handle_regenerate(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do that.', 'bg-couriers')); }
        $id = absint(wp_unslash($_GET['order_id'] ?? 0));
        check_admin_referer('bgcouriers_regenerate_' . $id);
        if (!self::unlocked_order($id)) {
            $this->fail_note($id, self::locked_message(), __('Re-issue refused', 'bg-couriers'));
        } else {
            try { self::cancel($id); self::generate($id); }
            catch (\Exception $e) { $this->fail_note($id, $e->getMessage(), __('Label re-generation failed', 'bg-couriers')); }
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    public function handle_cancel_order(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do that.', 'bg-couriers')); }
        $id = absint(wp_unslash($_GET['order_id'] ?? 0));
        check_admin_referer('bgcouriers_cancel_order_' . $id);
        $order = wc_get_order($id);
        if ($order) {
            if ((string) $order->get_meta('_bgcouriers_waybill') !== '') {
                try { self::cancel($id); } catch (\Exception $e) { $this->fail_note($id, $e->getMessage(), __('Label cancellation failed', 'bg-couriers')); }
            }
            $order = wc_get_order($id);
            $order->update_status('cancelled', __('Cancelled from the BG Couriers panel.', 'bg-couriers'));
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    public function handle_save_delivery(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => __('You are not allowed to do that.', 'bg-couriers')]); }
        check_ajax_referer('bgcouriers_order_delivery', 'nonce');
        $id = absint(wp_unslash($_POST['order_id'] ?? 0));
        $order = wc_get_order($id);
        if (!$order) { wp_send_json_error(['msg' => __('Order not found.', 'bg-couriers')]); }
        if (self::is_locked($order)) { wp_send_json_error(['msg' => self::locked_message()]); }
        $courier = sanitize_key(wp_unslash($_POST['courier'] ?? ''));
        if ($courier === '' || !BGCouriers_Couriers::get($courier)) { wp_send_json_error(['msg' => __('Choose a courier.', 'bg-couriers')]); }

        // Void FIRST, while the order still carries its original courier: cancel() resolves the courier from the order.
        $had_waybill = (string) $order->get_meta('_bgcouriers_waybill') !== '';
        if ($had_waybill) {
            try { self::cancel($id); }
            /* translators: %s: error message */
            catch (\Exception $e) { wp_send_json_error(['msg' => sprintf(__('Could not cancel the current waybill: %s', 'bg-couriers'), $e->getMessage())]); }
            $order = wc_get_order($id);
        }

        $t = static function ($k) { return isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : ''; }; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        BGCouriers_Checkout::apply_delivery($order, [
            'courier' => $courier, 'method' => sanitize_key(wp_unslash($_POST['method'] ?? '')),
            'site_id' => absint(wp_unslash($_POST['site_id'] ?? 0)), 'office_id' => absint(wp_unslash($_POST['office_id'] ?? 0)),
            'post_code' => $t('post_code'), 'street_name' => $t('street_name'), 'street_no' => $t('street_no'),
            'street_id' => absint(wp_unslash($_POST['street_id'] ?? 0)), 'street_type' => $t('street_type'),
            'complex' => $t('complex'), 'block' => $t('block'), 'entrance' => $t('entrance'),
            'floor' => $t('floor'), 'apartment' => $t('apartment'), 'address_note' => $t('address_note'),
        ]);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer above
        $order->update_meta_data('_bgcouriers_parcels', max(1, min(99, absint(wp_unslash($_POST['parcels'] ?? 1)))));
        // floatval(), not (float): Plugin Check only recognises listed sanitisers.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer above
        $order->update_meta_data('_bgcouriers_insurance', max(0, round(floatval(wp_unslash($_POST['insurance'] ?? 0)), 2)));
        $order->save();

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

    public function ajax_cancel_label(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => __('You are not allowed to do that.', 'bg-couriers')]); }
        $id = absint(wp_unslash($_POST['order_id'] ?? 0));
        check_ajax_referer('bgcouriers_cancel_label_' . $id, 'nonce');
        if (!self::unlocked_order($id)) { wp_send_json_error(['msg' => self::locked_message()]); }
        try { self::cancel($id); }
        catch (\Exception $e) { wp_send_json_error(['msg' => $e->getMessage()]); }
        wp_send_json_success(['msg' => __('Waybill cancelled.', 'bg-couriers')]);
    }

    public function handle_track(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do that.', 'bg-couriers')); }
        $id = absint(wp_unslash($_GET['order_id'] ?? 0));
        check_admin_referer('bgcouriers_track_' . $id);
        $order = wc_get_order($id);
        $waybill = $order ? (string) $order->get_meta('_bgcouriers_waybill') : '';
        if ($waybill === '') { wp_die(esc_html__('No waybill found.', 'bg-couriers')); }
        $courier = $this->courier_for($order);
        if (!$courier) { wp_die(esc_html__('Unknown courier for this order.', 'bg-couriers')); }
        $url  = $courier->tracking_url($waybill);
        // Some couriers publish no tracking page; wp_safe_redirect('') would show a blank screen.
        if ($url === '') {
            wp_die(esc_html(sprintf(
                /* translators: 1: courier name, 2: the waybill number */
                __('%1$s has no public tracking page. The waybill number is %2$s.', 'bg-couriers'),
                $courier->label(), $waybill)));
        }
        $host = wp_parse_url($url, PHP_URL_HOST);
        add_filter('allowed_redirect_hosts', function ($h) use ($host) { if ($host) { $h[] = $host; } return $h; });
        wp_safe_redirect($url);
        exit;
    }
    public function handle_print_batch(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do that.', 'bg-couriers')); }
        check_admin_referer('bgcouriers_print_batch');
        $order_ids = isset($_GET['order_id'])
            ? [(int) wp_unslash($_GET['order_id'])] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, nonce verified above
            : (array) get_transient('bgcouriers_print_batch_' . get_current_user_id());
        $order_ids = array_filter(array_map('intval', $order_ids));
        if (!$order_ids) { wp_die(esc_html__('No labels to print.', 'bg-couriers')); }
        $paper = (isset($_GET['paper']) && strtoupper(sanitize_text_field(wp_unslash($_GET['paper']))) === 'A6') ? 'A6' : 'A4';
        try {
            if (count($order_ids) === 1) {
                $pdfs = self::collect_label_pdfs($order_ids, $paper);
                $out  = $pdfs[0] ?? '';
            } else {
                $out = self::batch_pdf($order_ids, $paper);
            }
        }
        /* translators: %s: error message */
        catch (\Exception $e) { wp_die(esc_html(sprintf(__('Print failed: %s', 'bg-couriers'), $e->getMessage()))); }
        if ($out === '') { wp_die(esc_html__('No labels to print.', 'bg-couriers')); }
        // Streamed unescaped below, so verify it IS a PDF first.
        if (strncmp($out, '%PDF', 4) !== 0) { wp_die(esc_html__('The generated file is not a valid PDF.', 'bg-couriers')); }

        self::mark_printed($order_ids);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="labels-' . strtolower($paper) . '.pdf"');
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- raw PDF bytes, verified above to start with %PDF; escaping would corrupt the file
        exit;
    }

    public static function batch_pdf(array $order_ids, string $format = ''): string {
        $groups = []; // courier_id => [ order_id => waybill ]
        foreach ($order_ids as $oid) {
            $o = wc_get_order((int) $oid);
            if (!$o) { continue; }
            $wb  = (string) $o->get_meta('_bgcouriers_waybill');
            $cid = (string) $o->get_meta('_bgcouriers_courier');
            if ($wb === '' || $cid === '') { continue; }
            $groups[$cid][(int) $oid] = $wb;
        }
        $a4 = strtoupper($format) === 'A4' && BGCouriers_Label_Packer::available();
        $sheets = []; $halfs = []; $smalls = [];
        foreach ($groups as $cid => $map) {
            $courier = BGCouriers_Couriers::get($cid);
            if (!$courier) { continue; }
            if ($courier->has_native_batch()) {
                // On A4, Speedy's raw half-sheet pages are composed together with other couriers' stickers.
                if ($a4 && $courier instanceof BGCouriers_Speedy) {
                    try { $halfs[] = $courier->print_labels(array_values($map), 'A4'); continue; }
                    catch (\Exception $e) { /* fall through to the courier's own batch */ }
                }
                try { $b = $courier->batch_label_pdf(array_values($map), $format); if ($b !== '') { $sheets[] = $b; } }
                catch (\Exception $e) { /* skip this courier */ }
            } else {
                // Stored files (Pigeon cannot re-fetch); on A4 placed on real A4 pages at native size, on A6 passed through.
                $per = self::collect_label_pdfs(array_keys($map), $format);
                if (!$per) { continue; }
                if ($a4) { foreach ($per as $p) { $smalls[] = $p; } continue; }
                $sheets[] = count($per) === 1 ? $per[0] : BGCouriers_Label_Packer::concat($per);
            }
        }
        if ($a4 && ($halfs || $smalls)) {
            $res = BGCouriers_Label_Packer::compose_a4($halfs, $smalls);
            if ($res['pdf'] !== '') { $sheets[] = $res['pdf']; }
            if ($res['leftover']) {
                $packed = BGCouriers_Label_Packer::pack($res['leftover'], 'A4');
                if ($packed !== '') { $sheets[] = $packed; }
                elseif (count($res['leftover']) === 1) { $sheets[] = $res['leftover'][0]; }
                else { $sheets[] = BGCouriers_Label_Packer::concat($res['leftover']); }
            }
        }
        if (!$sheets) { return ''; }
        return count($sheets) === 1 ? $sheets[0] : BGCouriers_Label_Packer::concat($sheets);
    }

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

    /** Stored primary when it matches; a different courier-supported size is fetched once and cached per format, never scaled. */
    private static function label_pdf_for_print(\WC_Order $o, string $format): string {
        $courier = self::order_courier($o);
        $wb      = (string) $o->get_meta('_bgcouriers_waybill');
        $primary = (string) $o->get_meta('_bgcouriers_label_paper_size');
        $fmts    = $courier ? $courier->label_formats() : [];
        $want = ($format !== '' && in_array($format, $fmts, true)) ? $format : $primary;

        if ($want === '' || $want === $primary) {
            $b = self::read_stored_url($o, (string) $o->get_meta('_bgcouriers_label_url'));
            if ($b !== '') { return $b; }
            if ($courier && $wb !== '') {
                try { return $courier->get_label_pdf($wb, $primary); } catch (\Exception $e) { return ''; }
            }
            return '';
        }

        $meta_key = '_bgcouriers_label_url_' . $want;
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
        return self::read_stored_url($o, (string) $o->get_meta('_bgcouriers_label_url'));
    }

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
