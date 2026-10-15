<?php
defined('ABSPATH') || exit;

/** BOX NOW webhook receiver: a message is trusted on the secret in the HEADER header or on a valid datasignature HMAC. */
class BGCouriers_Boxnow_Webhook {
    const NS     = 'bgc/v1';
    const PATH   = '/boxnow-webhook';
    /** Hyphenated, so nginx passes it. */
    const HEADER = 'X-BGC-Webhook-Secret';

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

    public static function url(): string {
        return function_exists('rest_url') ? rest_url(self::NS . self::PATH) : '';
    }

    public function handle($request) {
        $raw    = (string) $request->get_body();
        $secret = (string) get_option('bgcouriers_boxnow_webhook_secret', '');
        $header = (string) $request->get_header(self::HEADER);
        $why    = self::refusal($raw, $secret, $header);
        $msg    = json_decode($raw, true);
        $data   = (is_array($msg) && isset($msg['data']) && is_array($msg['data'])) ? $msg['data'] : [];
        $sig    = self::signature($raw);
        // Log the shape of what arrived, never the values.
        $shape = [
            'headers' => array_keys((array) $request->get_headers()), 'body_keys' => is_array($msg) ? array_keys($msg) : [],
            'data_keys' => array_keys($data), 'sig_len' => strlen($sig), 'sig_shape' => self::shape($sig),
        ];
        if ($why !== '') {
            BGCouriers_Logger::debug('boxnow webhook: refused', ['reason' => $why] + $shape);
            return new WP_REST_Response(['ok' => false, 'reason' => $why], 401);
        }
        BGCouriers_Logger::debug('boxnow webhook: accepted', [
            'proof' => self::header_ok($secret, $header) ? 'header' : 'signature',
            'event' => (string) ($data['event'] ?? ''), 'parcelState' => (string) ($data['parcelState'] ?? ''),
        ] + $shape);
        self::apply($data);
        return new WP_REST_Response(['ok' => true], 200);
    }

    /** Why a message is not trusted, '' when it is. Either proof suffices; the reason names the first thing to fix. */
    public static function refusal(string $raw, string $secret, string $header): string {
        if ($secret === '') { return 'no_secret'; }
        if (self::header_ok($secret, $header)) { return ''; }
        if (self::verify($raw, $secret)) { return ''; }
        if ($header !== '') { return 'bad_header'; }
        return self::signature($raw) === '' ? 'no_credential' : 'bad_signature';
    }

    private static function header_ok(string $secret, string $header): bool {
        return $secret !== '' && $header !== '' && hash_equals($secret, $header);
    }

    /** HMAC-SHA256 over `data`, keyed by the webhook secret; accepted as hex or Base64 (standard or URL-safe). */
    public static function verify(string $raw, string $secret): bool {
        if ($secret === '') { return false; }
        $sig = trim(self::signature($raw));
        if ($sig === '') { return false; }
        $mac = hash_hmac('sha256', self::data_bytes($raw, json_decode($raw, true)), $secret, true);
        $b64 = base64_encode($mac);
        return hash_equals(bin2hex($mac), strtolower($sig))
            || hash_equals($b64, $sig)
            || hash_equals(rtrim(strtr($b64, '+/', '-_'), '='), rtrim(strtr($sig, '+/', '-_'), '='));
    }

    private static function signature(string $raw): string {
        $msg = json_decode($raw, true);
        return is_array($msg) ? (string) ($msg['datasignature'] ?? '') : '';
    }

    private static function shape(string $sig): string {
        if ($sig === '') { return 'none'; }
        if (preg_match('/^[0-9a-f]+$/i', $sig)) { return 'hex'; }
        if (preg_match('/^[A-Za-z0-9+\/_-]+=*$/', $sig)) { return 'base64'; }
        return 'other';
    }

    /** The exact `data` bytes BOX NOW signs: the raw substring as received, else a re-encode. */
    private static function data_bytes(string $raw, $msg): string {
        $sub = self::extract_object($raw, '"data"');
        if ($sub !== null) { return $sub; }
        return (is_array($msg) && isset($msg['data'])) ? (string) wp_json_encode($msg['data']) : '';
    }

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

    /** Record the parcel state on the matching order through the same path as polled couriers. */
    private static function apply(array $data): void {
        if (!function_exists('wc_get_orders')) { return; }
        $parcel = (string) ($data['parcelId'] ?? '');
        $order  = self::find_order($parcel, (string) ($data['orderNumber'] ?? ''));
        if (!$order) { return; }
        // The guide says to rely on event over parcelState; the state is the fallback.
        $state = (string) ($data['event'] ?? '');
        if ($state === '') { $state = (string) ($data['parcelState'] ?? ''); }
        $t = BGCouriers_Boxnow::parse_tracking(['state' => $state],
                                               $parcel !== '' ? $parcel : (string) $order->get_meta('_bgcouriers_waybill'));
        BGCouriers_Tracking_Poller::record($order, $t, 'BOX NOW', (string) get_option('bgcouriers_autostatus_on_delivered', ''));
    }

    /** ParcelState enum and webhook event vocabulary -> human label. */
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
            // Events spelled differently from the state, or with no state of their own.
            'final-destination'    => __('in the locker - ready for pickup', 'bg-couriers'),
            'expired'              => __('returned (not collected in time)', 'bg-couriers'),
            'cancelled'            => __('canceled', 'bg-couriers'),
            'in-depot'             => __('at a BOX NOW depot', 'bg-couriers'),
            'accepted-to-locker'   => __('dropped in the locker - on its way', 'bg-couriers'),
            'accepted-for-return'  => __('accepted for return', 'bg-couriers'),
        ];
    }

    private static function find_order(string $parcel, string $order_number) {
        if ($parcel !== '') {
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
