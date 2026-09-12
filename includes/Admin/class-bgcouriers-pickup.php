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
    /**
     * The confirm form's nonce field. NOT the default "_wpnonce": the page's own nonce travels in the
     * URL under that name, the form posts back to that same URL, and PHP builds $_REQUEST with the POST
     * over the GET - so a confirm nonce posted as "_wpnonce" replaced the page nonce, the page check read
     * the wrong one, and every click on "Request the courier" answered "The link you followed has
     * expired". Two nonces on one request need two names.
     */
    const CONFIRM_NONCE = 'bgcouriers_confirm_nonce';
    /** How long a rendered confirm form stays confirmable. */
    const TICKET_TTL = HOUR_IN_SECONDS;

    public function __construct() {
        add_action('admin_menu', [$this, 'register_page']);
    }

    /**
     * Hidden: it is reached from the orders list, never from the menu. The confirmation is handled on
     * the page's load hook, before any output, so the browser can be sent on to a result page - a
     * result rendered in place of a POST is re-sent by a refresh, and that is a second courier.
     */
    public function register_page(): void {
        $hook = add_submenu_page(null, __('Request a courier', 'bg-couriers'), __('Request a courier', 'bg-couriers'),
            'manage_woocommerce', self::PAGE, [$this, 'render']);
        if ($hook) { add_action('load-' . $hook, [$this, 'load']); }
    }

    /** Before the admin header: the page names itself, then a confirmation, if that is what arrived. */
    public function load(): void {
        // A page with no menu parent has no title for WordPress to find, and the header then strips
        // tags from null - a deprecation on every render of this screen, and a tab that reads
        // "WordPress" alone. get_admin_page_title() takes the global first.
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

    /**
     * The cut-offs still ahead, earliest first.
     *
     * A cut-off is a moment the courier names in its own zone ("2026-09-14T17:00:00+0300"), so whether
     * it has passed is a comparison of two REAL moments. current_time('timestamp') is not one - WordPress
     * hands back the epoch shifted by the site's offset, and against a real cut-off that runs the offset
     * fast: three hours in a Bulgarian summer, so from 14:00 the screen offered tomorrow while the
     * courier still came today until 17:00. Ordered as moments too, not as text: two couriers writing
     * their zones differently ("+0300" and "Z") would otherwise sort by spelling.
     *
     * @param string[] $cutoffs as the couriers gave them
     * @param int|null $now     the moment to judge by, as a real epoch; null = now
     * @return string[] the same strings, only those still to come, soonest first
     */
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

    /**
     * The day to offer: the site's calendar day of the next cut-off, so today while the courier will
     * still come. With no cut-off ahead - a courier that names none - it is simply tomorrow, which on a
     * Saturday is a Sunday; the couriers that do name their cut-offs name working days, and that is where
     * the knowledge of which days they work belongs.
     *
     * @param int|null $now the moment to judge by, as a real epoch; null = now
     */
    public static function default_date(array $cutoffs, ?int $now = null): string {
        $now    = $now ?? time();
        $offset = (int) (get_option('gmt_offset') * HOUR_IN_SECONDS);
        $next   = self::upcoming($cutoffs, $now);
        if ($next) { return gmdate('Y-m-d', strtotime($next[0]) + $offset); }
        return gmdate('Y-m-d', $now + $offset + DAY_IN_SECONDS);
    }

    /**
     * The confirmation, on the page's load hook. One courier per rendered form: the form carries a
     * ticket that is spent here, atomically, so the same POST arriving twice - a refresh of the result,
     * the back button, a second click while the courier's API takes its seconds - finds it spent and
     * sends nothing. Then a redirect to the result, so what the browser holds is a GET.
     */
    public function confirm(): void {
        if (!isset($_POST['bgcouriers_confirm'])) { return; }
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You are not allowed to do this.', 'bg-couriers')); }
        check_admin_referer(self::ACTION);
        check_admin_referer(self::ACTION . '_confirm', self::CONFIRM_NONCE);

        $ticket  = sanitize_key(wp_unslash($_POST['bgcouriers_ticket'] ?? ''));
        $notices = [];
        // delete_transient() says whether there was one to delete: in the database that is the affected
        // row count of one DELETE, under an object cache the store's own atomic delete - so of two
        // requests spending the same ticket exactly one is told yes.
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
        // Kept, not consumed: the result page is a GET, and reading it twice is harmless.
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
        // Only what is still ahead reaches the screen: the line "accepts requests up to 17:00" printed
        // at 18:00 next to a date field saying tomorrow read as two screens disagreeing.
        $this->render_form($g, self::upcoming($cutoffs), $ids);
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

    /**
     * One request per courier: the APIs take a list, so ten orders with one courier are one call.
     *
     * @return array<int,array{0:string,1:string}> what to tell the merchant: [kind, text] per courier
     */
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
                    // A courier that accepts the request without giving it a number - Express One does -
                    // still had the request accepted. Printing "(request )" would read as a fault, and
                    // leaving the note out would lose the fact that a courier is coming at all.
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

    /** The result page: what each courier answered, and the way back. */
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
