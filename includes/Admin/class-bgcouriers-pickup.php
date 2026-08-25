<?php
defined('ABSPATH') || exit;

/**
 * "Request a courier" - the step that actually gets the parcels collected.
 *
 * A waybill only says a parcel exists. The courier comes for it on a request that NAMES those waybills
 * for a day: Speedy's scope for it is EXPLICIT_SHIPMENT_ID_LIST, Econt attaches the numbers the same way.
 *
 * It is deliberately NOT a one-click bulk action. Booking sends a real courier to a real address at a
 * real hour, and the merchant has to choose that hour - so the bulk action carries the selection to this
 * screen, which shows what will be requested from whom, and books only on confirm.
 */
class BGCouriers_Pickup {
    const PAGE   = 'bgcouriers-pickup';
    const ACTION = 'bgcouriers_pickup';
    const META   = '_bgcouriers_pickup_id';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_page']);
    }

    /** Hidden: it is reached from the orders list, never from the menu. */
    public function register_page(): void {
        add_submenu_page(null, __('Request a courier', 'bg-couriers'), __('Request a courier', 'bg-couriers'),
            'manage_woocommerce', self::PAGE, [$this, 'render']);
    }

    public static function url(array $order_ids): string {
        return add_query_arg([
            'page'      => self::PAGE,
            'orders'    => implode(',', array_map('intval', $order_ids)),
            '_wpnonce'  => wp_create_nonce(self::ACTION),
        ], admin_url('admin.php'));
    }

    /**
     * The selected orders, grouped by courier, with the waybill each one already has.
     *
     * An order with no waybill cannot be collected - there is nothing for the courier to take - and a
     * courier with no pickup service must not be offered one. Both are reported rather than dropped
     * silently, so the merchant learns why an order is not in the list.
     *
     * Every selected id leaves this method in exactly one of the four: nothing is skipped without a
     * reason the merchant can read. The fourth is the one that should never happen - an order that no
     * longer loads, or a waybill whose courier the plugin cannot name - and it is counted precisely
     * because a silent disappearance there would be indistinguishable from a request that went out.
     *
     * @return array{groups:array<string,array>, no_waybill:int[], unsupported:array<string,int[]>, unresolved:int[]}
     */
    public static function group(array $order_ids): array {
        $groups = []; $no_waybill = []; $unsupported = []; $unresolved = [];
        foreach (array_map('intval', $order_ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order) { $unresolved[] = $oid; continue; }
            $waybill = (string) $order->get_meta('_bgcouriers_waybill');
            if ($waybill === '') { $no_waybill[] = $oid; continue; }
            $cid = (string) $order->get_meta('_bgcouriers_courier');
            $c   = $cid !== '' ? BGCouriers_Couriers::get($cid) : null;
            if (!$c) { $unresolved[] = $oid; continue; }
            if (!in_array('pickup', $c->capabilities(), true)) { $unsupported[$cid][] = $oid; continue; }
            $groups[$cid][] = ['order_id' => $oid, 'waybill' => $waybill,
                               'weight_kg' => BGCouriers_Abstract_Courier::order_weight_kg($order)];
        }
        return ['groups' => $groups, 'no_waybill' => $no_waybill,
                'unsupported' => $unsupported, 'unresolved' => $unresolved];
    }

    /** The day to offer: today while the courier will still come, otherwise the next working day. */
    public static function default_date(array $cutoffs): string {
        $now = current_time('timestamp');
        foreach ($cutoffs as $cutoff) {
            $ts = strtotime((string) $cutoff);
            if ($ts && $ts > $now) { return gmdate('Y-m-d', $ts + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS)); }
        }
        return gmdate('Y-m-d', $now + DAY_IN_SECONDS);
    }

    public function render(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do this.', 'bg-couriers')); }
        check_admin_referer(self::ACTION);
        $ids = array_filter(array_map('intval', explode(',', sanitize_text_field(wp_unslash($_REQUEST['orders'] ?? '')))));
        $g   = self::group($ids);

        if (isset($_POST['bgcouriers_confirm'])) {
            check_admin_referer(self::ACTION . '_confirm');
            $this->book($g['groups'], [
                'date' => sanitize_text_field(wp_unslash($_POST['bgcouriers_date'] ?? '')),
                'from' => sanitize_text_field(wp_unslash($_POST['bgcouriers_from'] ?? '')),
                'to'   => sanitize_text_field(wp_unslash($_POST['bgcouriers_to'] ?? '')),
            ]);
            return;
        }

        $cutoffs = [];
        foreach (array_keys($g['groups']) as $cid) {
            $c = BGCouriers_Couriers::get($cid);
            if ($c) { $cutoffs = array_merge($cutoffs, $c->pickup_terms(gmdate('Y-m-d', current_time('timestamp')))); }
        }
        sort($cutoffs);
        $this->render_form($g, $cutoffs, $ids);
    }

    private function render_form(array $g, array $cutoffs, array $ids): void {
        $date = self::default_date($cutoffs);
        echo '<div class="wrap bgc-pickup"><h1>' . esc_html__('Request a courier', 'bg-couriers') . '</h1>';

        if (empty($g['groups'])) {
            // Still say WHY, one line per reason: "none of these can be collected" without the reason
            // is the same dead end as dropping them silently.
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

        echo '<form method="post">';
        wp_nonce_field(self::ACTION . '_confirm');
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
           . '<td><input type="time" id="bgcouriers_from" name="bgcouriers_from" value="14:00" required /> &ndash; '
           . '<input type="time" name="bgcouriers_to" value="17:00" required /></td></tr>'
           . '</tbody></table>';
        submit_button(__('Request the courier', 'bg-couriers'), 'primary', 'bgcouriers_confirm');
        echo '</form></div>';
    }

    /**
     * Convention 11: what cannot be requested is shown with its reason, not hidden.
     *
     * Printed whether or not anything is left to request - a selection that yields nothing still has to
     * say what happened to it.
     */
    private function render_exclusions(array $g): void {
        foreach ($g['unsupported'] as $cid => $oids) {
            $c = BGCouriers_Couriers::get($cid);
            echo '<p class="description" style="opacity:.7">' . esc_html(sprintf(
                /* translators: 1: courier name, 2: how many orders */
                __('%1$s does not offer a pickup request - %2$d order(s) left out. Its parcels are collected under your contract with them.', 'bg-couriers'),
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

    /** One request per courier: the APIs take a list, so ten orders with one courier are one call. */
    private function book(array $groups, array $opts): void {
        echo '<div class="wrap"><h1>' . esc_html__('Request a courier', 'bg-couriers') . '</h1>';
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
                    // A courier that accepts the request without giving it a number - Express One does -
                    // still had the request accepted. Printing "(request )" would read as a fault, and
                    // leaving the note out would lose the fact that a courier is coming at all.
                    $order->add_order_note($id !== '' ? sprintf(
                        /* translators: 1: courier name, 2: the courier's request id, 3: day, 4: from time, 5: to time */
                        __('%1$s courier requested (request %2$s) for %3$s, %4$s-%5$s.', 'bg-couriers'),
                        $c->label(), $id, $opts['date'], $opts['from'], $opts['to']
                    ) : sprintf(
                        /* translators: 1: courier name, 2: day, 3: from time, 4: to time */
                        __('%1$s courier requested for %2$s, %3$s-%4$s. The courier gave no request number.', 'bg-couriers'),
                        $c->label(), $opts['date'], $opts['from'], $opts['to']));
                    $order->save();
                }
                echo '<div class="notice notice-success"><p>' . esc_html($id !== '' ? sprintf(
                    /* translators: 1: courier name, 2: how many parcels, 3: request id */
                    __('%1$s will collect %2$d parcel(s). Request %3$s.', 'bg-couriers'),
                    $c->label(), count($waybills), $id
                ) : sprintf(
                    /* translators: 1: courier name, 2: how many parcels */
                    __('%1$s will collect %2$d parcel(s). It gave no request number.', 'bg-couriers'),
                    $c->label(), count($waybills))) . '</p></div>';
            } catch (\Exception $e) {
                echo '<div class="notice notice-error"><p>' . esc_html(sprintf(
                    /* translators: 1: courier name, 2: the courier's own error */
                    __('%1$s refused the request: %2$s', 'bg-couriers'), $c->label(), $e->getMessage())) . '</p></div>';
            }
        }
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=wc-orders')) . '">'
           . esc_html__('Back to orders', 'bg-couriers') . '</a></p></div>';
    }
}
