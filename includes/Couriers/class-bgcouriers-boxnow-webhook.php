<?php
defined('ABSPATH') || exit;

/**
 * BOX NOW webhook receiver - real-time parcel tracking.
 *
 * BoxNow posts a WebhookMessage to a URL the merchant registers in their BoxNow account on every parcel
 * event. The message's `data` object is authenticated by `datasignature` = HMAC-SHA256 of the data, keyed
 * by the shared "Webhook secret" (bgcouriers_boxnow_webhook_secret). We verify that, then record the state on the
 * matching order. Auth is the HMAC, not a WP capability, so the route is public.
 */
class BGCouriers_Boxnow_Webhook {
    const NS   = 'bgc/v1';
    const PATH = '/boxnow-webhook';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register']);
    }

    public function register(): void {
        register_rest_route(self::NS, self::PATH, [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** The public URL to paste into the BoxNow account. */
    public static function url(): string {
        return function_exists('rest_url') ? rest_url(self::NS . self::PATH) : '';
    }

    public function handle($request) {
        $raw    = (string) $request->get_body();
        $secret = (string) get_option('bgcouriers_boxnow_webhook_secret', '');
        if ($secret === '' || !self::verify($raw, $secret)) {
            return new WP_REST_Response(['ok' => false], 401);
        }
        $msg  = json_decode($raw, true);
        $data = (is_array($msg) && isset($msg['data']) && is_array($msg['data'])) ? $msg['data'] : [];
        self::apply($data);
        return new WP_REST_Response(['ok' => true], 200);
    }

    /** Verify the HMAC-SHA256 signature over the message's `data`, keyed by the webhook secret. */
    public static function verify(string $raw, string $secret): bool {
        if ($secret === '') { return false; }
        $msg = json_decode($raw, true);
        $sig = is_array($msg) ? (string) ($msg['datasignature'] ?? '') : '';
        if ($sig === '') { return false; }
        $calc = hash_hmac('sha256', self::data_bytes($raw, $msg), $secret);
        return hash_equals(strtolower($calc), strtolower($sig));
    }

    /** The exact `data` bytes BoxNow signs: the raw substring as received, else a re-encode. */
    private static function data_bytes(string $raw, $msg): string {
        $sub = self::extract_object($raw, '"data"');
        if ($sub !== null) { return $sub; }
        return (is_array($msg) && isset($msg['data'])) ? (string) wp_json_encode($msg['data']) : '';
    }

    /** Brace-matched substring of the JSON object that follows $key in $raw (e.g. `"data"`). */
    private static function extract_object(string $raw, string $key): ?string {
        $k = strpos($raw, $key);
        if ($k === false) { return null; }
        $b = strpos($raw, '{', $k);
        if ($b === false) { return null; }
        $depth = 0; $n = strlen($raw);
        for ($i = $b; $i < $n; $i++) {
            $c = $raw[$i];
            if ($c === '{') { $depth++; }
            elseif ($c === '}') { $depth--; if ($depth === 0) { return substr($raw, $b, $i - $b + 1); } }
        }
        return null;
    }

    /** Record the parcel state on the matching order (visibility only - no forced status transition). */
    /**
     * Record the parcel state on the matching order - through the same code every polled courier's
     * answer goes through. It used to write the state to a key of its own and add a note, on every
     * message; nothing read that key, so the orders list showed a blank for a BOX NOW parcel, it never
     * counted as finished, and it was never advanced on delivery. And a sender's retry - they do retry -
     * was another note and another save each time.
     */
    private static function apply(array $data): void {
        if (!function_exists('wc_get_orders')) { return; }
        $parcel = (string) ($data['parcelId'] ?? '');
        $order  = self::find_order($parcel, (string) ($data['orderNumber'] ?? ''));
        if (!$order) { return; }
        $t = BGCouriers_Boxnow::parse_tracking(['state' => (string) ($data['parcelState'] ?? '')],
                                               $parcel !== '' ? $parcel : (string) $order->get_meta('_bgcouriers_waybill'));
        BGCouriers_Tracking_Poller::record($order, $t, 'BOX NOW', (string) get_option('bgcouriers_autostatus_on_delivered', ''));
    }

    /** ParcelState enum -> human label. */
    public static function state_labels(): array {
        return [
            'new'                  => __('registered', 'bg-couriers'),
            'in-transit'           => __('in transit', 'bg-couriers'),
            'in-final-destination' => __('in the locker - ready for pickup', 'bg-couriers'),
            'delivered'            => __('delivered', 'bg-couriers'),
            'returned'             => __('returned', 'bg-couriers'),
            'expired-return'       => __('returned (not collected in time)', 'bg-couriers'),
            'canceled'             => __('canceled', 'bg-couriers'),
            'lost'                 => __('lost', 'bg-couriers'),
            'missing'              => __('missing', 'bg-couriers'),
        ];
    }

    /** Match our stored BoxNow parcel id first; fall back to a numeric order number that is ours. */
    private static function find_order(string $parcel, string $order_number) {
        if ($parcel !== '') {
            // Runs at most once per BoxNow parcel status webhook to find the order by our stored waybill;
            // a meta query is appropriate for this rare, event-driven lookup.
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $orders = wc_get_orders(['limit' => 1, 'meta_key' => '_bgcouriers_waybill', 'meta_value' => $parcel, 'return' => 'objects']);
            if (!empty($orders)) { return $orders[0]; }
        }
        if ($order_number !== '' && ctype_digit($order_number)) {
            $o = wc_get_order((int) $order_number);
            if ($o && $o->get_meta('_bgcouriers_courier') === 'boxnow') { return $o; }
        }
        return null;
    }
}
