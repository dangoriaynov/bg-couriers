<?php
defined('ABSPATH') || exit;

/** "Request a courier": the confirm screen that books collection of the selected waybills. */
class BGCouriers_Pickup {
    const PAGE   = 'bgcouriers-pickup';
    const ACTION = 'bgcouriers_pickup';
    const META   = '_bgcouriers_pickup_id';
    /** Not "_wpnonce": the page nonce travels in the URL under that name and the POST would replace it. */
    const CONFIRM_NONCE = 'bgcouriers_confirm_nonce';
    const TICKET_TTL = HOUR_IN_SECONDS;

    public function __construct() {
        add_action('admin_menu', [$this, 'register_page']);
    }

    public function register_page(): void {
        $hook = add_submenu_page(null, __('Request a courier', 'bg-couriers'), __('Request a courier', 'bg-couriers'),
            'manage_woocommerce', self::PAGE, [$this, 'render']);
        if ($hook) { add_action('load-' . $hook, [$this, 'load']); }
    }

    public function load(): void {
        // A page with no menu parent has no title and the header strips tags from null; set the global.
        $GLOBALS['title'] = __('Request a courier', 'bg-couriers');
        $this->confirm();
    }

    public static function url(array $order_ids): string {
        return add_query_arg([
            'page'      => self::PAGE,
            'orders'    => implode(',', array_map('intval', $order_ids)),
            '_wpnonce'  => wp_create_nonce(self::ACTION),
        ], admin_url('admin.php'));
    }

    /** @return array{groups:array<string,array>, no_waybill:int[], unsupported:array<string,int[]>, handed_in:array<string,int[]>, unresolved:int[]} every id lands in exactly one bucket */
    public static function group(array $order_ids): array {
        $groups = []; $no_waybill = []; $unsupported = []; $handed_in = []; $unresolved = [];
        foreach (array_map('intval', $order_ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order) { $unresolved[] = $oid; continue; }
            $waybill = (string) $order->get_meta('_bgcouriers_waybill');
            if ($waybill === '') { $no_waybill[] = $oid; continue; }
            $cid = (string) $order->get_meta('_bgcouriers_courier');
            $c   = $cid !== '' ? BGCouriers_Couriers::get($cid) : null;
            if (!$c) { $unresolved[] = $oid; continue; }
            if (!in_array('pickup', $c->capabilities(), true)) { $unsupported[$cid][] = $oid; continue; }
            if ($c->hands_in_at_office()) { $handed_in[$cid][] = $oid; continue; }
            $groups[$cid][] = ['order_id' => $oid, 'waybill' => $waybill,
                               'weight_kg' => BGCouriers_Abstract_Courier::order_weight_kg($order)];
        }
        return ['groups' => $groups, 'no_waybill' => $no_waybill,
                'unsupported' => $unsupported, 'handed_in' => $handed_in, 'unresolved' => $unresolved];
    }

    /** Cut-offs still ahead, soonest first, compared as real moments (current_time('timestamp') is offset-shifted). */
    public static function upcoming(array $cutoffs, ?int $now = null): array {
        $now = $now ?? time();
        $at  = [];
        foreach ($cutoffs as $cutoff) {
            $ts = strtotime((string) $cutoff);
            if ($ts && $ts > $now) { $at[(string) $cutoff] = $ts; }
        }
        asort($at);
        return array_keys($at);
    }

    /** The site calendar day of the next cut-off, else tomorrow. */
    public static function default_date(array $cutoffs, ?int $now = null): string {
        $now    = $now ?? time();
        $offset = (int) (get_option('gmt_offset') * HOUR_IN_SECONDS);
        $next   = self::upcoming($cutoffs, $now);
        if ($next) { return gmdate('Y-m-d', strtotime($next[0]) + $offset); }
        return gmdate('Y-m-d', $now + $offset + DAY_IN_SECONDS);
    }

    /** One courier per form; the ticket is spent atomically so a repeated POST sends nothing, then redirect. */
    public function confirm(): void {
        if (!isset($_POST['bgcouriers_confirm'])) { return; }
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do this.', 'bg-couriers')); }
        check_admin_referer(self::ACTION);
        check_admin_referer(self::ACTION . '_confirm', self::CONFIRM_NONCE);

        $ticket  = sanitize_key(wp_unslash($_POST['bgcouriers_ticket'] ?? ''));
        $notices = [];
        // delete_transient() says whether there was one, so of two requests spending the ticket exactly one gets yes.
        if ($ticket === '' || !delete_transient('bgcouriers_pickup_ticket_' . $ticket)) {
            $notices[] = ['warning', __('This confirmation was already used, or is more than an hour old - nothing was sent. If the courier has not been requested, select the orders again.', 'bg-couriers')];
        } else {
            $ids = array_filter(array_map('intval', explode(',', sanitize_text_field(wp_unslash($_REQUEST['orders'] ?? '')))));
            $notices = $this->book(self::group($ids)['groups'], [
                'date' => sanitize_text_field(wp_unslash($_POST['bgcouriers_date'] ?? '')),
                'from' => sanitize_text_field(wp_unslash($_POST['bgcouriers_from'] ?? '')),
                'to'   => sanitize_text_field(wp_unslash($_POST['bgcouriers_to'] ?? '')),
            ]);
        }
        $result = $ticket !== '' ? $ticket : sanitize_key(wp_generate_password(12, false));
        set_transient('bgcouriers_pickup_result_' . $result, $notices, 5 * MINUTE_IN_SECONDS);
        $to = add_query_arg(['page' => self::PAGE, 'done' => $result, '_wpnonce' => wp_create_nonce(self::ACTION)], admin_url('admin.php'));
        if (wp_safe_redirect($to)) { exit; }
    }

    public function render(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do this.', 'bg-couriers')); }
        check_admin_referer(self::ACTION);

        $done = sanitize_key(wp_unslash($_GET['done'] ?? ''));
        if ($done !== '') {
            $notices = get_transient('bgcouriers_pickup_result_' . $done);
            $this->render_result(is_array($notices) ? $notices : []);
            return;
        }

        $ids = array_filter(array_map('intval', explode(',', sanitize_text_field(wp_unslash($_REQUEST['orders'] ?? '')))));
        $g   = self::group($ids);
        $cutoffs = [];
        foreach (array_keys($g['groups']) as $cid) {
            $c = BGCouriers_Couriers::get($cid);
            if ($c) { $cutoffs = array_merge($cutoffs, $c->pickup_terms(gmdate('Y-m-d', current_time('timestamp')))); }
        }
        $this->render_form($g, self::upcoming($cutoffs), $ids);
    }

    private function render_form(array $g, array $cutoffs, array $ids): void {
        $date = self::default_date($cutoffs);
        echo '<div class="wrap bgc-pickup"><h1>' . esc_html__('Request a courier', 'bg-couriers') . '</h1>';

        if (empty($g['groups'])) {
            echo '<div class="notice notice-warning"><p>'
               . esc_html__('None of the selected orders can be collected: a courier is called for waybills that already exist, and for couriers that offer the service.', 'bg-couriers')
               . '</p></div>';
            $this->render_exclusions($g);
            echo '</div>';
            return;
        }
        if ($cutoffs) {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: the courier's own cut-off moment, e.g. 2026-08-19T17:00:00+0300 */
                __('The courier accepts requests up to %s.', 'bg-couriers'), (string) $cutoffs[0])) . '</p>';
        }

        $ticket = sanitize_key(wp_generate_password(12, false));
        set_transient('bgcouriers_pickup_ticket_' . $ticket, get_current_user_id(), self::TICKET_TTL);
        echo '<form method="post">';
        wp_nonce_field(self::ACTION . '_confirm', self::CONFIRM_NONCE);
        echo '<input type="hidden" name="bgcouriers_ticket" value="' . esc_attr($ticket) . '" />';
        echo '<input type="hidden" name="orders" value="' . esc_attr(implode(',', $ids)) . '" />';

        foreach ($g['groups'] as $cid => $rows) {
            $c = BGCouriers_Couriers::get($cid);
            echo '<h2>' . esc_html($c ? $c->label() : $cid) . ' <span class="count">('
               . esc_html((string) count($rows)) . ')</span></h2><ul>';
            foreach ($rows as $r) {
                echo '<li>' . esc_html(sprintf('#%d - %s (%s kg)', $r['order_id'], $r['waybill'], $r['weight_kg'])) . '</li>';
            }
            echo '</ul>';
        }

        $this->render_exclusions($g);

        echo '<table class="form-table"><tbody>'
           . '<tr><th scope="row"><label for="bgcouriers_date">' . esc_html__('Day', 'bg-couriers') . '</label></th>'
           . '<td><input type="date" id="bgcouriers_date" name="bgcouriers_date" value="' . esc_attr($date) . '" required /></td></tr>'
           . '<tr><th scope="row"><label for="bgcouriers_from">' . esc_html__('Between', 'bg-couriers') . '</label></th>'
           . '<td><input type="time" id="bgcouriers_from" name="bgcouriers_from" value="14:00" required /> - '
           . '<input type="time" name="bgcouriers_to" value="17:00" required /></td></tr>'
           . '</tbody></table>';
        submit_button(__('Request the courier', 'bg-couriers'), 'primary', 'bgcouriers_confirm');
        echo '</form></div>';
    }

    private function render_exclusions(array $g): void {
        foreach ($g['unsupported'] as $cid => $oids) {
            $c = BGCouriers_Couriers::get($cid);
            echo '<p class="description" style="opacity:.7">' . esc_html(sprintf(
                /* translators: 1: courier name, 2: how many orders */
                __('%1$s does not offer a pickup request - %2$d order(s) left out. Its parcels are collected under your contract with them.', 'bg-couriers'),
                $c ? $c->label() : $cid, count($oids))) . '</p>';
        }
        foreach ($g['handed_in'] as $cid => $oids) {
            $c = BGCouriers_Couriers::get($cid);
            echo '<p class="description" style="opacity:.7">' . esc_html(sprintf(
                /* translators: 1: courier name, 2: how many orders */
                __('%1$s is set to "Send parcels from" an office - %2$d order(s) left out. Hand those parcels in at the office; no courier comes for them.', 'bg-couriers'),
                $c ? $c->label() : $cid, count($oids))) . '</p>';
        }
        if ($g['no_waybill']) {
            echo '<p class="description" style="opacity:.7">' . esc_html(sprintf(
                /* translators: %d: how many orders */
                __('%d order(s) have no waybill yet - generate one first, then call the courier.', 'bg-couriers'),
                count($g['no_waybill']))) . '</p>';
        }
        if (!empty($g['unresolved'])) {
            echo '<p class="description" style="opacity:.7">' . esc_html(sprintf(
                /* translators: %d: how many orders */
                __('%d order(s) could not be read - no courier is recorded on them, or the order is gone. Nothing is requested for those.', 'bg-couriers'),
                count($g['unresolved']))) . '</p>';
        }
    }

    /** @return array<int,array{0:string,1:string}> [kind, text] per courier, one request per courier */
    private function book(array $groups, array $opts): array {
        $notices = [];
        foreach ($groups as $cid => $rows) {
            $c = BGCouriers_Couriers::get($cid);
            if (!$c) { continue; }
            $waybills = array_column($rows, 'waybill');
            $args = array_merge($opts, [
                'contact'   => get_bloginfo('name'),
                'phone'     => (string) get_option('bgcouriers_' . $cid . '_sender_phone', ''),
                'packs'     => count($waybills),
                'weight_kg' => array_sum(array_column($rows, 'weight_kg')),
            ]);
            try {
                $id = $c->request_pickup($waybills, $args);
                foreach ($rows as $r) {
                    $order = wc_get_order($r['order_id']);
                    if (!$order) { continue; }
                    $order->update_meta_data(self::META, $id);
                    // Express One accepts a request without giving it a number.
                    $order->add_order_note($id !== '' ? sprintf(
                        /* translators: 1: courier name, 2: the courier's request id, 3: day, 4: from time, 5: to time */
                        __('%1$s courier requested (request %2$s) for %3$s, %4$s-%5$s.', 'bg-couriers'),
                        $c->label(), $id, $opts['date'], $opts['from'], $opts['to']
                    ) : sprintf(
                        /* translators: 1: courier name, 2: day, 3: from time, 4: to time */
                        __('%1$s courier requested for %2$s, %3$s-%4$s. This courier issues no separate request number - the waybills themselves are the request.', 'bg-couriers'),
                        $c->label(), $opts['date'], $opts['from'], $opts['to']));
                    $order->save();
                }
                $notices[] = ['success', $id !== '' ? sprintf(
                    /* translators: 1: courier name, 2: how many parcels, 3: request id */
                    __('%1$s will collect %2$d parcel(s). Request %3$s.', 'bg-couriers'),
                    $c->label(), count($waybills), $id
                ) : sprintf(
                    /* translators: 1: courier name, 2: how many parcels */
                    __('%1$s will collect %2$d parcel(s). This courier issues no separate request number.', 'bg-couriers'),
                    $c->label(), count($waybills))];
            } catch (\Exception $e) {
                $notices[] = ['error', sprintf(
                    /* translators: 1: courier name, 2: the courier's own error */
                    __('%1$s refused the request: %2$s', 'bg-couriers'), $c->label(), $e->getMessage())];
            }
        }
        return $notices;
    }

    private function render_result(array $notices): void {
        echo '<div class="wrap"><h1>' . esc_html__('Request a courier', 'bg-couriers') . '</h1>';
        if (!$notices) {
            echo '<p>' . esc_html__('There is no result to show for this confirmation any more.', 'bg-couriers') . '</p>';
        }
        foreach ($notices as $n) {
            $kind = in_array($n[0] ?? '', ['success', 'warning', 'error'], true) ? $n[0] : 'info';
            echo '<div class="notice notice-' . esc_attr($kind) . '"><p>' . esc_html((string) ($n[1] ?? '')) . '</p></div>';
        }
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=wc-orders')) . '">'
           . esc_html__('Back to orders', 'bg-couriers') . '</a></p></div>';
    }
}
