<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

/**
 * BOX NOW webhook receiver - real-time parcel tracking.
 *
 * BoxNow posts a WebhookMessage to a URL the merchant registers in their BoxNow account on every parcel
 * event. The message's `data` object is authenticated by `datasignature` = HMAC-SHA256 of the data, keyed
 * by the shared "Webhook secret" (bgc_boxnow_webhook_secret). We verify that, then record the state on the
 * matching order. Auth is the HMAC, not a WP capability, so the route is public.
 */
class BGC_Boxnow_Webhook {
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
        $secret = (string) get_option('bgc_boxnow_webhook_secret', '');
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
    private static function apply(array $data): void {
        if (!function_exists('wc_get_orders')) { return; }
        $order = self::find_order((string) ($data['parcelId'] ?? ''), (string) ($data['orderNumber'] ?? ''));
        if (!$order) { return; }
        $state  = (string) ($data['parcelState'] ?? '');
        $labels = self::state_labels();
        $human  = $labels[$state] ?? ($state !== '' ? $state : 'unknown');
        $order->update_meta_data('_bgc_boxnow_state', $state);
        /* translators: %s: human-readable parcel state, e.g. "delivered" */
        $order->add_order_note(sprintf(__('BOX NOW tracking update: %s', 'bg-couriers'), $human));
        $order->save();
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
            $orders = wc_get_orders(['limit' => 1, 'meta_key' => '_bgc_waybill', 'meta_value' => $parcel, 'return' => 'objects']);
            if (!empty($orders)) { return $orders[0]; }
        }
        if ($order_number !== '' && ctype_digit($order_number)) {
            $o = wc_get_order((int) $order_number);
            if ($o && $o->get_meta('_bgc_courier') === 'boxnow') { return $o; }
        }
        return null;
    }
}
