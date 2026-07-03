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
        $this->base   = (defined('BGC_SAMEDAY_DEMO') && BGC_SAMEDAY_DEMO) ? self::DEMO : self::PROD;
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

    public function fetch_cities(): array { return []; }

    public function fetch_offices(int $city_id): array { return []; }

    public function quote(array $shipment): BGC_Quote {
        throw new BGC_Api_Exception('BGC_Sameday::quote() not implemented yet');
    }

    public function create_label(\WC_Order $order): BGC_Label {
        throw new BGC_Api_Exception('BGC_Sameday::create_label() not implemented yet');
    }

    public function get_label_pdf(string $waybill): string {
        throw new BGC_Api_Exception('BGC_Sameday::get_label_pdf() not implemented yet');
    }

    public function cancel_label(string $waybill): bool { return false; }

    public function track(string $waybill): BGC_Tracking {
        throw new BGC_Api_Exception('BGC_Sameday::track() not implemented yet');
    }

    public function tracking_url(string $waybill): string {
        return 'https://www.sameday.ro/tracking/' . rawurlencode($waybill);
    }
}
