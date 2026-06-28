<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Pigeon extends BGC_Abstract_Courier {
    const PROD = 'https://api.pigeonexpress.com';

    private $key;   // X-API-Key   (from $config['username'])
    private $secret; // X-API-Secret (from $config['password'])
    private $base;

    public function __construct(array $config) {
        $this->key    = (string) ($config['username'] ?? '');
        $this->secret = (string) ($config['password'] ?? '');
        $this->base   = (string) ($config['base'] ?? self::PROD);
    }

    public function id(): string { return 'pigeon'; }
    public function label(): string { return 'Pigeon Express'; }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }

    // ── Transport ────────────────────────────────────────────────────────────

    /** Pigeon uses two header tokens, not Basic auth — override http_post. */
    protected function http_post(string $url, array $body) {
        return wp_remote_post($url, [
            'timeout' => 20,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'X-API-Key'     => $this->key,
                'X-API-Secret'  => $this->secret,
            ],
            'body'    => wp_json_encode($body),
        ]);
    }

    /**
     * GET a JSON endpoint with optional query parameters.
     * Returns the decoded response array.
     *
     * @param string $path  Path relative to $this->base, e.g. '/v1/cities'.
     * @param array  $query Optional key→value query parameters.
     * @return array        Decoded JSON response.
     * @throws BGC_Api_Exception on transport error or non-200 response.
     */
    protected function get_json(string $path, array $query = []): array {
        $url = $this->base . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        $res = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'Accept'       => 'application/json',
                'X-API-Key'    => $this->key,
                'X-API-Secret' => $this->secret,
            ],
        ]);
        if (is_wp_error($res)) {
            throw new BGC_Api_Exception('Pigeon GET transport error: ' . $res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);
        if ($code !== 200) {
            throw new BGC_Api_Exception('Pigeon GET HTTP ' . $code . ': ' . substr($raw, 0, 200));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new BGC_Api_Exception('Pigeon invalid JSON from ' . $url);
        }
        return $data;
    }

    // ── Credential check ─────────────────────────────────────────────────────

    public function check_credentials(): bool {
        try {
            $r = $this->get_json('/v1/cities', ['per_page' => 1]);
            return !empty($r['success']);
        } catch (\Exception $e) {
            return false;
        }
    }

    // ── Nomenclature ─────────────────────────────────────────────────────────

    /**
     * Fetch all cities (paginated — Pigeon has ~5275 cities across ~106 pages).
     * Accumulates pages until current_page >= last_page or the safety cap is hit.
     *
     * @return array[] Parsed city rows.
     */
    public function fetch_cities(): array {
        $out      = [];
        $page     = 1;
        $per_page = 100;
        $cap      = 200; // defensive safety cap

        do {
            $resp      = $this->get_json('/v1/cities', ['per_page' => $per_page, 'page' => $page]);
            $out       = array_merge($out, self::parse_cities($resp));
            $meta      = $resp['meta'] ?? [];
            $last_page = (int) ($meta['last_page'] ?? 1);
            $curr_page = (int) ($meta['current_page'] ?? $page);
            $page++;
        } while ($curr_page < $last_page && $page <= $cap);

        return $out;
    }

    /**
     * Fetch all offices (and lockers) for a given city.
     * Type filtering (office vs automat) is handled by BGC_Ajax::city_offices.
     *
     * @param int $city_id  Pigeon city id.
     * @return array[]      Parsed office rows.
     */
    public function fetch_offices(int $city_id): array {
        return self::parse_offices($this->get_json('/v1/offices', ['city_id' => $city_id]));
    }

    /**
     * Search streets in a city by a term (substring, case-insensitive, Cyrillic-aware).
     *
     * @param int    $city_id  Pigeon city id.
     * @param string $term     Search term.
     * @return array[]         Matching parsed street rows.
     */
    public function search_streets(int $city_id, string $term): array {
        $rows = self::parse_streets($this->get_json('/v1/cities/' . $city_id . '/streets'));
        if ($term === '') {
            return $rows;
        }
        $t = function_exists('mb_strtolower') ? mb_strtolower($term) : strtolower($term);
        return array_values(array_filter($rows, static function (array $s) use ($t): bool {
            $n = function_exists('mb_strtolower') ? mb_strtolower($s['name']) : strtolower($s['name']);
            return strpos($n, $t) !== false;
        }));
    }

    // ── Pure static parsers ───────────────────────────────────────────────────

    /**
     * Parse a /v1/cities response page into city rows.
     *
     * API field → internal key:
     *   id          → city_id  (int)
     *   name        → name
     *   name_en     → name_lat
     *   postal_code → post_code
     *   district    → region
     *
     * @param array $resp  Decoded API response (expects $resp['data']).
     * @return array[]
     */
    public static function parse_cities(array $resp): array {
        $out = [];
        foreach (($resp['data'] ?? []) as $c) {
            if (empty($c['id'])) {
                continue;
            }
            $out[] = [
                'city_id'  => (int) $c['id'],
                'name'     => (string) ($c['name'] ?? ''),
                'name_lat' => (string) ($c['name_en'] ?? ''),
                'post_code' => (string) ($c['postal_code'] ?? ''),
                'region'   => (string) ($c['district'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Parse a /v1/offices response into office rows.
     *
     * API field → internal key:
     *   id        → office_id  (int)
     *   code      → code
     *   city.id   → city_id    (int)
     *   type      → type  ("locker" → "automat", else "office")
     *   name      → name
     *   address   → address
     *
     * @param array $resp  Decoded API response (expects $resp['data']).
     * @return array[]
     */
    public static function parse_offices(array $resp): array {
        $out = [];
        foreach (($resp['data'] ?? []) as $o) {
            if (empty($o['id'])) {
                continue;
            }
            $out[] = [
                'office_id' => (int) $o['id'],
                'code'      => (string) ($o['code'] ?? ''),
                'city_id'   => (int) ($o['city']['id'] ?? 0),
                'type'      => (($o['type'] ?? '') === 'locker') ? 'automat' : 'office',
                'name'      => (string) ($o['name'] ?? ''),
                'address'   => (string) ($o['address'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Parse a /v1/cities/{id}/streets response into street rows.
     *
     * API field → internal key:
     *   id    → id   (int)
     *   name  → name
     *   type  → type  (e.g. "булевард", "улица")
     *   label = trim(type . ' ' . name)
     *
     * @param array $resp  Decoded API response (expects $resp['data']).
     * @return array[]
     */
    public static function parse_streets(array $resp): array {
        $out = [];
        foreach (($resp['data'] ?? []) as $s) {
            $name = (string) ($s['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = (string) ($s['type'] ?? '');
            $out[] = [
                'id'    => (int) ($s['id'] ?? 0),
                'name'  => $name,
                'type'  => $type,
                'label' => trim($type . ' ' . $name),
            ];
        }
        return $out;
    }

    // ── Deferred live methods (Tasks 2 & 3) ─────────────────────────────────

    /** @throws BGC_Api_Exception Always — implemented in Task 2. */
    public function quote(array $shipment): BGC_Quote {
        throw new BGC_Api_Exception('BGC_Pigeon::quote not yet implemented');
    }

    /** @throws BGC_Api_Exception Always — implemented in Task 3. */
    public function create_label(\WC_Order $order): BGC_Label {
        throw new BGC_Api_Exception('BGC_Pigeon::create_label not yet implemented');
    }

    /** @throws BGC_Api_Exception Always — implemented in Task 3. */
    public function get_label_pdf(string $waybill): string {
        throw new BGC_Api_Exception('BGC_Pigeon::get_label_pdf not yet implemented');
    }

    /** @return bool Always false — implemented in Task 3. */
    public function cancel_label(string $waybill): bool {
        return false;
    }

    /** @throws BGC_Api_Exception Always — implemented in Task 3. */
    public function track(string $waybill): BGC_Tracking {
        throw new BGC_Api_Exception('BGC_Pigeon::track not yet implemented');
    }

    /** Return a Pigeon public tracking URL (placeholder until live-verify). */
    public function tracking_url(string $waybill): string {
        return 'https://pigeonexpress.com/track/' . rawurlencode($waybill);
    }
}
