<?php
defined('ABSPATH') || exit;

/**
 * BOX NOW webhook receiver - real-time parcel tracking.
 *
 * BOX NOW posts a WebhookMessage to a URL the merchant registers with BOX NOW on every parcel event.
 * A message is trusted on either of two proofs, both keyed by the one "Webhook secret"
 * (bgcouriers_boxnow_webhook_secret):
 *
 *  - the secret itself in the HEADER header - the "name/value pair configured in the profile" that
 *    BOX NOW's Webhook Guide (v5, 2025-12) offers as the way to authenticate ("if additional
 *    authentication is required, this can be managed through request headers"), and what their
 *    support asks a shop for;
 *  - `datasignature` = HMAC-SHA256 over the `data` object, hex or Base64, for a partner BOX NOW has
 *    handed a signing key to. The guide gives one out only "if needed".
 *
 * Until 0.4.12 the signature was the ONLY proof and the settings said the key "arrives after you
 * register the URL" - it does not, it has to be asked for - so the first live shop to register the
 * webhook answered 401 to every message and nothing said why. A refusal now names its reason.
 * Auth is the secret, not a WP capability, so the route is public.
 */
class BGCouriers_Boxnow_Webhook {
    const NS     = 'bgc/v1';
    const PATH   = '/boxnow-webhook';
    /** The header a shop hands BOX NOW: this name, the webhook secret as the value. Hyphenated, so nginx passes it. */
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

    /** The public URL to paste into the BoxNow account. */
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
        // The shape of what arrived, never the values. On a refusal: enough to see whether BOX NOW
        // signs at all and what the signature looks like - the one question a 401 could not answer.
        // On an accepted message too: which proof carried it, and the event beside the state - the
        // first real message is the only chance to learn which bytes BOX NOW signs and whether the
        // two ever disagree, and it would have answered 200 and taught nothing.
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

    /**
     * Why a message is not trusted - '' when it is. $header is what arrived in self::HEADER.
     *
     * Either proof suffices: the secret in the header, or a signature that checks out. A wrong header
     * beside a good signature is still a good signature. The reason names the FIRST thing to fix:
     * no secret saved at all, nothing to check (neither header nor datasignature), or the one that
     * was sent being wrong.
     */
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

    /**
     * Verify the HMAC-SHA256 signature over the message's `data`, keyed by the webhook secret.
     *
     * The guide says "HMAC SHA256 digest" and no more, so the digest is accepted as hex or as Base64
     * (standard or URL-safe, padded or not) - the same 32 bytes either way, and no weaker.
     */
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

    /** The message's `datasignature`, '' when there is none. */
    private static function signature(string $raw): string {
        $msg = json_decode($raw, true);
        return is_array($msg) ? (string) ($msg['datasignature'] ?? '') : '';
    }

    /** What a signature looks like, for the log: hex, base64, other, or none. */
    private static function shape(string $sig): string {
        if ($sig === '') { return 'none'; }
        if (preg_match('/^[0-9a-f]+$/i', $sig)) { return 'hex'; }
        if (preg_match('/^[A-Za-z0-9+\/_-]+=*$/', $sig)) { return 'base64'; }
        return 'other';
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
        // The guide: "although parcelState and event appear similar, please rely on the event property
        // when parsing the status" - it is what the customer's tracking page shows. The state is the
        // fallback, for a message that carries only that.
        $state = (string) ($data['event'] ?? '');
        if ($state === '') { $state = (string) ($data['parcelState'] ?? ''); }
        $t = BGCouriers_Boxnow::parse_tracking(['state' => $state],
                                               $parcel !== '' ? $parcel : (string) $order->get_meta('_bgcouriers_waybill'));
        BGCouriers_Tracking_Poller::record($order, $t, 'BOX NOW', (string) get_option('bgcouriers_autostatus_on_delivered', ''));
    }

    /** ParcelState enum, and the webhook's event vocabulary (Webhook Guide v5) -> human label. */
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
            // The events, where they are spelled differently or have no state of their own.
            'final-destination'    => __('in the locker - ready for pickup', 'bg-couriers'),
            'expired'              => __('returned (not collected in time)', 'bg-couriers'),
            'cancelled'            => __('canceled', 'bg-couriers'),
            'in-depot'             => __('at a BOX NOW depot', 'bg-couriers'),
            'accepted-to-locker'   => __('dropped in the locker - on its way', 'bg-couriers'),
            'accepted-for-return'  => __('accepted for return', 'bg-couriers'),
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
