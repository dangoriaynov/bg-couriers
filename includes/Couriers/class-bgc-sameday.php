<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

/**
 * Sameday courier adapter.
 *
 * Auth mechanism (confirmed from SamedayClient.php + SamedayAuthenticateRequest.php in
 * https://github.com/sameday-courier/php-sdk):
 *   POST /api/authenticate
 *   Headers: X-Auth-Username: <username>, X-Auth-Password: <password>
 *   Body: remember_me=true (application/x-www-form-urlencoded)
 *   Response JSON: { "token": "<string>", "expire_at": "YYYY-MM-DD HH:MM" }
 *   Token is passed on subsequent requests as header: X-AUTH-TOKEN
 */
class BGC_Sameday extends BGC_Abstract_Courier implements BGC_Courier_Interface {
    const PROD = 'https://api.sameday.ro';
    const DEMO = 'https://sameday-api.demo.zitec.com';

    /** @var array */
    private $config;

    /** @var string */
    private $base;

    public function __construct(array $config) {
        $this->config = $config;
        $demo = (defined('BGC_SAMEDAY_DEMO') && BGC_SAMEDAY_DEMO)
            || (function_exists('get_option') && get_option('bgc_sameday_sandbox') === 'yes');
        $this->base = $demo ? self::DEMO : self::PROD;
    }

    public function id(): string { return 'sameday'; }
    public function label(): string { return 'Sameday'; }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }

    // ── Auth ─────────────────────────────────────────────────────────────────

    /**
     * Return a valid X-Auth-Token, fetching a new one when absent/expired.
     *
     * Request shape confirmed from SDK:
     *   SamedayAuthenticateRequest::buildRequest() — headers X-Auth-Username / X-Auth-Password,
     *   body 'remember_me=true' (URL-encoded form).
     * Response shape confirmed from SamedayAuthenticateResponse:
     *   JSON { "token": "...", "expire_at": "YYYY-MM-DD HH:MM" }
     */
    protected function auth_token(): string {
        $tok = get_transient('bgc_sameday_token');
        if (is_string($tok) && $tok !== '') {
            return $tok;
        }

        $r = wp_remote_post($this->base . '/api/authenticate', [
            'timeout' => 20,
            'headers' => [
                'X-Auth-Username' => (string) ($this->config['username'] ?? ''),
                'X-Auth-Password' => (string) ($this->config['password'] ?? ''),
                'Content-Type'    => 'application/x-www-form-urlencoded',
            ],
            'body' => 'remember_me=true',
        ]);

        if (is_wp_error($r)) {
            throw new BGC_Api_Exception('Sameday auth transport error: ' . $r->get_error_message());
        }
        $body = json_decode(wp_remote_retrieve_body($r), true);
        $tok  = (string) ($body['token'] ?? '');
        if ($tok === '') {
            throw new BGC_Api_Exception('Sameday authentication failed: no token in response');
        }
        // expire_at is "YYYY-MM-DD HH:MM"; token TTL ~1h, refresh 10 min early.
        set_transient('bgc_sameday_token', $tok, 50 * MINUTE_IN_SECONDS);
        return $tok;
    }

    // ── HTTP helpers ─────────────────────────────────────────────────────────

    protected function get_json(string $path): array {
        $r = wp_remote_get($this->base . $path, [
            'timeout' => 30,
            'headers' => ['X-AUTH-TOKEN' => $this->auth_token()],
        ]);
        return $this->decode($r);
    }

    protected function post_json(string $path, array $body): array {
        $r = wp_remote_post($this->base . $path, [
            'timeout' => 40,
            'headers' => [
                'X-AUTH-TOKEN' => $this->auth_token(),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
        return $this->decode($r);
    }

    private function decode($r): array {
        if (is_wp_error($r)) {
            throw new BGC_Api_Exception($r->get_error_message());
        }
        return (array) json_decode(wp_remote_retrieve_body($r), true);
    }

    // ── BGC_Courier_Interface stubs (to be filled in later tasks) ─────────────

    public function check_credentials(): bool {
        try {
            return $this->auth_token() !== '';
        } catch (\Exception $e) {
            return false;
        }
    }

    // ── Nomenclature ─────────────────────────────────────────────────────────
    // NOTE: shapes are from the Sameday php-sdk; the exact JSON keys (data wrapper, cityId vs city.id)
    // must be confirmed against the sandbox before production (built without live creds, 2026-07-08).

    public function fetch_cities(): array {
        return self::parse_cities($this->get_json('/api/geolocation/city'));
    }

    /** cities -> framework rows ['city_id','name','post_code','region'] (Sameday cities live under counties). */
    public static function parse_cities(array $resp): array {
        $out = [];
        foreach (($resp['data'] ?? $resp) as $c) {
            if (empty($c['id'])) { continue; }
            $out[] = [
                'city_id'   => (int) $c['id'],
                'name'      => (string) ($c['name'] ?? ''),
                'post_code' => (string) ($c['postalCode'] ?? ''),
                'region'    => (string) (is_array($c['county'] ?? null) ? ($c['county']['name'] ?? '') : ($c['county'] ?? '')),
            ];
        }
        return $out;
    }

    public function fetch_offices(int $city_id): array {
        $rows = self::parse_offices($this->get_json('/api/client/lockers'), $this->get_json('/api/client/ooh-locations'));
        return $city_id > 0
            ? array_values(array_filter($rows, static function ($o) use ($city_id) { return (int) $o['city_id'] === $city_id; }))
            : $rows;
    }

    /** easyBox lockers -> 'automat', out-of-home points -> 'office'. Framework row shape. */
    public static function parse_offices(array $lockers, array $ooh): array {
        $out = [];
        $map = static function ($rows, $type) use (&$out) {
            foreach (($rows['data'] ?? $rows) as $o) {
                if (empty($o['id'])) { continue; }
                $out[] = [
                    'office_id' => (int) $o['id'],
                    'code'      => (string) $o['id'],
                    'city_id'   => (int) ($o['cityId'] ?? $o['city']['id'] ?? 0),
                    'type'      => $type,
                    'name'      => (string) ($o['name'] ?? ''),
                    'address'   => (string) ($o['address'] ?? ''),
                ];
            }
        };
        $map($lockers, 'automat');
        $map($ooh, 'office');
        return $out;
    }

    // ── Quote (live, weight-based) ───────────────────────────────────────────

    public function quote(array $shipment): BGC_Quote {
        $resp = $this->post_json('/api/awb/estimate-cost', self::build_estimate_body($shipment));
        return self::parse_price($resp, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
    }

    public static function build_estimate_body(array $s): array {
        $type = $s['method'] ?? 'address';
        $w    = max(0.1, (float) ($s['weight_kg'] ?? 1.0));
        $body = [
            'pickupPoint'   => (int) get_option('bgc_sameday_pickup_point', 0),
            'service'       => (string) get_option('bgc_sameday_service_' . $type, ''),
            'packageType'   => 0,
            'packageWeight' => $w,
            'awbPayment'    => 1, // client (sender) pays the delivery
            'cashOnDelivery'=> 0,
            'insuredValue'  => 0,
            'currency'      => (string) ($s['currency'] ?? get_woocommerce_currency()),
            'parcels'       => [['weight' => $w]],
            'awbRecipient'  => ['cityId' => (int) ($s['site_id'] ?? 0)],
        ];
        if ($type === 'automat')      { $body['lockerLastMile'] = (int) ($s['office_id'] ?? 0); }
        elseif ($type === 'office')   { $body['oohLastMile']    = (int) ($s['office_id'] ?? 0); }
        return $body;
    }

    /** estimate-cost -> BGC_Quote. Response carries `cost` + `currency` (net). */
    public static function parse_price(array $resp, string $currency): BGC_Quote {
        $amount = (float) ($resp['cost'] ?? 0);
        return new BGC_Quote(round($amount, 2), 0.0, (string) ($resp['currency'] ?? $currency), 'live');
    }

    // ── Label ────────────────────────────────────────────────────────────────

    public function create_label(\WC_Order $order): BGC_Label {
        $resp = $this->post_json('/api/awb', self::build_awb_body($order));
        return new BGC_Label(self::parse_awb_id($resp));
    }

    public static function build_awb_body(\WC_Order $order): array {
        $method = (string) $order->get_meta('_bgc_method');
        $w      = max(0.1, (float) ($order->get_meta('_bgc_weight_kg') ?: 1.0));
        $is_cod = $order->get_payment_method() === 'cod';
        $body = [
            'pickupPoint'    => (int) get_option('bgc_sameday_pickup_point', 0),
            'service'        => (string) get_option('bgc_sameday_service_' . $method, ''),
            'awbPayment'     => 1,
            'packageType'    => 0,
            'packageNumber'  => 1,
            'packageWeight'  => $w,
            'cashOnDelivery' => $is_cod ? round((float) $order->get_total(), 2) : 0,
            'insuredValue'   => 0,
            'awbRecipient'   => [
                'name'        => $order->get_formatted_billing_full_name(),
                'phoneNumber' => (string) $order->get_billing_phone(),
                'personType'  => 0, // individual
                'cityId'      => (int) $order->get_meta('_bgc_site_id'),
                'address'     => trim((string) $order->get_meta('_bgc_street_name') . ' ' . (string) $order->get_meta('_bgc_street_no')),
            ],
            'parcels'        => [['weight' => $w]],
        ];
        $office = (int) $order->get_meta('_bgc_office_id');
        if ($method === 'automat')    { $body['lockerLastMile'] = $office; }
        elseif ($method === 'office') { $body['oohLastMile']    = $office; }
        return $body;
    }

    public static function parse_awb_id(array $resp): string {
        return (string) ($resp['awbNumber'] ?? $resp['awbCost']['awbNumber'] ?? '');
    }

    public function get_label_pdf(string $waybill): string {
        $r = wp_remote_get($this->base . '/api/awb/download/' . rawurlencode($waybill), [
            'timeout' => 40, 'headers' => ['X-AUTH-TOKEN' => $this->auth_token()],
        ]);
        if (is_wp_error($r)) { throw new BGC_Api_Exception($r->get_error_message()); }
        $pdf = (string) wp_remote_retrieve_body($r);
        if (strpos($pdf, '%PDF') !== 0) { throw new BGC_Api_Exception('Sameday label is not a PDF'); }
        return $pdf;
    }

    public function cancel_label(string $waybill): bool {
        $r = wp_remote_request($this->base . '/api/awb/' . rawurlencode($waybill), [
            'method' => 'DELETE', 'timeout' => 30, 'headers' => ['X-AUTH-TOKEN' => $this->auth_token()],
        ]);
        return !is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) < 300;
    }

    // ── Tracking ─────────────────────────────────────────────────────────────

    public function track(string $waybill): BGC_Tracking {
        return self::parse_tracking($this->get_json('/api/client/awb/' . rawurlencode($waybill) . '/status'), $waybill);
    }

    public static function parse_tracking(array $resp, string $waybill): BGC_Tracking {
        $history = $resp['awbHistory'] ?? $resp['data'] ?? $resp;
        $events  = [];
        foreach ($history as $h) {
            if (!is_array($h)) { continue; }
            $events[] = [
                'name' => (string) ($h['statusState'] ?? $h['status'] ?? $h['reason'] ?? ''),
                'time' => (string) ($h['statusDate'] ?? $h['date'] ?? ''),
            ];
        }
        $status = $events ? $events[count($events) - 1]['name'] : (string) ($resp['status'] ?? 'unknown');
        return new BGC_Tracking($waybill, $status, $events);
    }

    public function tracking_url(string $waybill): string {
        return 'https://www.sameday.bg/track-awb?awb=' . rawurlencode($waybill);
    }
}
