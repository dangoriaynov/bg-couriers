<?php
defined('ABSPATH') || exit;

class BGC_Pigeon extends BGC_Abstract_Courier {
    const PROD = 'https://api.pigeonexpress.com';
    const DEMO = 'https://api-demo.pigeonexpress.com';

    private $key;   // X-API-Key   (from $config['username'])
    private $secret; // X-API-Secret (from $config['password'])
    private $base;

    public function __construct(array $config) {
        $this->key    = (string) ($config['username'] ?? '');
        $this->secret = (string) ($config['password'] ?? '');
        // Base host comes from $config['base'] (the bootstrap resolves prod vs the DEMO sandbox from the
        // bgc_pigeon_sandbox toggle and passes it in). Fall back to PROD for an empty/missing base - an empty
        // base makes a malformed request URL -> "missing valid address" transport errors.
        $base = rtrim(trim((string) ($config['base'] ?? '')), '/');
        $this->base = $base !== '' ? $base : self::PROD;
    }

    public function id(): string { return 'pigeon'; }
    public function label(): string { return 'Pigeon Express'; }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }

    public function enable_problems(): array {
        $p = parent::enable_problems();
        $this->need_option($p, 'bgc_pigeon_pickup_office_id',
            __('No pickup office ID is set.', 'bg-couriers'),
            __('Enter the “Pickup office ID” you ship from (used for quotes and labels).', 'bg-couriers'));
        return $p;
    }

    // ── Transport ────────────────────────────────────────────────────────────

    /** Pigeon uses two header tokens, not Basic auth - override http_post. */
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
        // Retry once on transport/5xx like the abstract post_json - nomenclature sync spans ~106
        // pages, so a single network blip shouldn't hard-fail the whole sync.
        $last = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $res = wp_remote_get($url, [
                'timeout' => 20,
                'headers' => [
                    'Accept'       => 'application/json',
                    'X-API-Key'    => $this->key,
                    'X-API-Secret' => $this->secret,
                ],
            ]);
            if (is_wp_error($res)) { $last = 'transport error: ' . $res->get_error_message(); continue; }
            $code = (int) wp_remote_retrieve_response_code($res);
            $raw  = (string) wp_remote_retrieve_body($res);
            if ($code === 200) {
                $data = json_decode($raw, true);
                if (!is_array($data)) { throw new BGC_Api_Exception(esc_html('Pigeon invalid JSON from ' . $url)); }
                return $data;
            }
            $last = 'HTTP ' . $code . ': ' . substr($raw, 0, 200);
            if ($code >= 400 && $code < 500) { break; } // client error (auth/bad request) - retry won't help
        }
        throw new BGC_Api_Exception(esc_html('Pigeon GET failed: ' . $last));
    }

    // ── Credential check ─────────────────────────────────────────────────────

    public function check_credentials(): bool {
        // Match the official plugin's probe: GET a single known city (759 = Sofia). A 200 means the
        // key/secret are valid; get_json throws on any non-200 (auth failures are 401/403). The list
        // endpoints don't return a `success` flag, so we rely on the HTTP status, not a body field.
        try {
            $this->get_json('/v1/cities/759');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // ── Nomenclature ─────────────────────────────────────────────────────────

    /**
     * Fetch all cities (paginated - Pigeon has ~5275 cities across ~106 pages).
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
            $resp = $this->get_json('/v1/cities', ['per_page' => $per_page, 'page' => $page]);
            $out  = array_merge($out, self::parse_cities($resp));
            $meta = $resp['meta'] ?? null;
            if ($meta === null && class_exists('BGC_Logger')) {
                BGC_Logger::debug('pigeon: cities page missing meta - pagination may be incomplete', ['page' => $page]);
            }
            $last_page = (int) ($meta['last_page'] ?? 1);
            $curr_page = (int) ($meta['current_page'] ?? $page);
            $page++;
        } while ($curr_page < $last_page && $page <= $cap);

        if ($page > $cap && $curr_page < $last_page && class_exists('BGC_Logger')) {
            BGC_Logger::debug('pigeon: cities pagination hit the safety cap', ['cap' => $cap, 'last_page' => $last_page]);
        }
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
        // /v1/offices returns ONLY the requested `type`; lockers/APS are a separate type (not in the default
        // office list), so fetch both and merge - parse_offices maps type 'locker' -> 'automat'.
        $offices = self::parse_offices($this->get_json('/v1/offices', ['city_id' => $city_id, 'type' => 'office']));
        $lockers = self::parse_offices($this->get_json('/v1/offices', ['city_id' => $city_id, 'type' => 'locker']));
        return array_merge($offices, $lockers);
    }

    /** Default parcel box (cm) for quotes/labels; Pigeon requires length/width/height. Merchant-overridable. */
    public static function default_box(): array {
        return [
            'length' => max(1, (int) get_option('bgc_pigeon_box_length', 40)),
            'width'  => max(1, (int) get_option('bgc_pigeon_box_width', 40)),
            'height' => max(1, (int) get_option('bgc_pigeon_box_height', 40)),
        ];
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
                'lat'       => (float) ($o['latitude'] ?? 0),
                'lng'       => (float) ($o['longitude'] ?? 0),
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

    // ── Quote (calculate) ────────────────────────────────────────────────────

    /**
     * Build the request body for POST /v1/shipments/calculate.
     *
     * Pickup is always office-type (merchant ships from a fixed office).
     * Delivery type derives from $s['method']:
     *   'address'       → delivery_type='address', delivery_address block
     *   'automat'       → delivery_type='locker',  delivery_office_id
     *   'office' / else → delivery_type='office',  delivery_office_id
     *
     * COD: service_codes.cod_amount is added only when $s['cod_amount'] > 0.
     *
     * @param array $s                Shipment descriptor.
     * @param int   $pickup_office_id Merchant's Pigeon pickup office id (from settings).
     * @return array                  JSON-encodable request body.
     */
    public static function build_calculate_body(array $s, int $pickup_office_id, array $box = ['length' => 40, 'width' => 40, 'height' => 40]): array {
        $body = [
            'pickup_type'      => 'office',
            'pickup_office_id' => $pickup_office_id,
            // Pigeon requires length/width/height on every package (not just weight), else HTTP 422.
            // The box is injected by the caller (default_box() reads the merchant's settings); the literal
            // default keeps this method pure and get_option-free so it can be unit-tested directly.
            'packages'         => [
                array_merge(['weight' => max(0.1, (float) ($s['weight_kg'] ?? 1.0))], $box),
            ],
            'service_type'     => 'standard',
            // The merchant pays the courier for delivery: our checkout already quotes and charges the
            // shipping fee to the customer, so who_pays='sender' (not 'receiver', which would make the
            // courier collect the fee AGAIN at the door). This is also what the quote price reflects.
            'who_pays'         => 'sender',
        ];

        if (($s['method'] ?? 'address') === 'address') {
            $body['delivery_type']    = 'address';
            // The API accepts address delivery with just city_id + additional_info (a free-text address);
            // street_id is optional (we only have the street as text). additional_info must be non-empty.
            $addr = trim((string) ($s['street_name'] ?? '') . ' ' . (string) ($s['street_no'] ?? ''));
            $body['delivery_address'] = [
                'city_id'         => (int) ($s['site_id'] ?? 0),
                'additional_info' => $addr !== '' ? $addr : '-',
            ];
            if (!empty($s['street_id'])) {
                $body['delivery_address']['street_id'] = (int) $s['street_id'];
            }
        } else {
            $body['delivery_type']      = ($s['method'] === 'automat') ? 'locker' : 'office';
            $body['delivery_office_id'] = (int) ($s['office_id'] ?? 0);
        }

        if (!empty($s['cod_amount'])) {
            $body['service_codes'] = ['cod_amount' => (float) $s['cod_amount']];
        }

        return $body;
    }

    /**
     * Parse a /v1/shipments/calculate response into a BGC_Quote.
     *
     * Pigeon's total_price already includes all service fees; tax split TBD at live-verify.
     *
     * @param array  $resp     Decoded JSON response (expects $resp['data']).
     * @param string $currency Fallback currency if not present in response.
     * @return BGC_Quote
     * @throws BGC_Api_Exception When no usable price is found.
     */
    public static function parse_price(array $resp, string $currency): BGC_Quote {
        $d     = $resp['data'] ?? [];
        $total = (float) ($d['total_price'] ?? 0);
        if ($total <= 0) {
            throw new BGC_Api_Exception('No price in Pigeon response');
        }
        return new BGC_Quote($total, 0.0, (string) ($d['currency'] ?? $currency), 'live');
    }

    /**
     * Fetch a live shipping quote via POST /v1/shipments/calculate.
     * Live - do NOT call in tests (no credentials in CI).
     */
    public function quote(array $shipment): BGC_Quote {
        $pickup = (int) get_option('bgc_pigeon_pickup_office_id', 0);
        $resp   = $this->post_json(
            $this->base . '/v1/shipments/calculate',
            self::build_calculate_body($shipment, $pickup, self::default_box())
        );
        return self::parse_price($resp, (string) ($shipment['currency'] ?? 'EUR'));
    }

    // ── Label creation ──────────────────────────────────────────────────────

    /**
     * Build the request body for POST /v1/shipments.
     *
     * Starts from build_calculate_body() using order meta, then adds:
     * - receiver_name, receiver_phone, receiver_email (from order billing)
     * - inventory_items (one entry per order line item; fallback to [{description:'Goods',quantity:1}])
     *
     * @param \WC_Order $order           The WooCommerce order.
     * @param int       $pickup_office_id Merchant's Pigeon pickup office id (from settings).
     * @return array                     JSON-encodable request body.
     */
    public static function build_shipment_body(\WC_Order $order, int $pickup_office_id): array {
        $s = [
            'method'      => (string) $order->get_meta('_bgc_method'),
            'site_id'     => (int)    $order->get_meta('_bgc_site_id'),
            'office_id'   => (int)    $order->get_meta('_bgc_office_id'),
            'street_name' => (string) $order->get_meta('_bgc_street_name'),
            'street_no'   => (string) $order->get_meta('_bgc_street_no'),
            'weight_kg'   => self::order_weight_kg($order),
            // COD only for cash-on-delivery orders. who_pays='sender' (see build_calculate_body): the
            // merchant already collected the shipping fee at checkout, so the courier collects the FULL
            // order total from the customer at delivery - goods + shipping - like Econt/Sameday/BOX NOW.
            'cod_amount'  => $order->get_payment_method() === 'cod'
                ? max(0.0, round((float) $order->get_total(), 2))
                : 0.0,
        ];

        $body = self::build_calculate_body($s, $pickup_office_id, self::default_box());

        // Stamp our order number as the external reference so every shipment is identifiable in the Pigeon
        // dashboard / by support (Pigeon has no list or search-by-order API, so this is the only way to
        // reconcile a waybill back to its order after the fact).
        $body['external_reference'] = (string) $order->get_order_number();
        $body['receiver_name']  = $order->get_formatted_billing_full_name();
        $body['receiver_phone'] = (string) $order->get_billing_phone();
        $body['receiver_email'] = BGC_Settings::label_email($order);

        // Build inventory_items from order line items; fall back to generic goods entry.
        $items = [];
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $items[] = [
                'description' => (string) $item->get_name(),
                'quantity'    => (int) $item->get_quantity(),
            ];
        }
        $body['inventory_items'] = !empty($items) ? $items : [['description' => 'Goods', 'quantity' => 1]];

        return $body;
    }

    /**
     * Extract the reference number from a POST /v1/shipments response.
     *
     * @param array $resp Decoded JSON response.
     * @return string     The reference_number, or '' if not present.
     */
    public static function parse_shipment_id(array $resp): string {
        return (string) ($resp['data']['reference_number'] ?? '');
    }

    /**
     * Issue a real waybill for the given order via POST /v1/shipments.
     * Live - do NOT call in tests.
     */
    public function create_label(\WC_Order $order): BGC_Label {
        $pickup = (int) get_option('bgc_pigeon_pickup_office_id', 0);
        if ($pickup <= 0) {
            // Fail with a clear, actionable message instead of Pigeon's raw HTTP 422
            // ("invalid pickup office id") when the merchant hasn't set their drop-off office.
            throw new BGC_Api_Exception(esc_html__(
                'Set your Pigeon pickup office ID in Settings > BG Couriers > Pigeon Express before generating a label.',
                'bg-couriers'
            ));
        }
        $resp   = $this->post_json(
            $this->base . '/v1/shipments',
            self::build_shipment_body($order, $pickup)
        );
        // Pigeon returns the label PDF INLINE (base64) in the create response - there is no separate
        // label-download endpoint, so capture it now. BGC_Labels::generate() persists these bytes to disk;
        // reprints then serve the saved file (collect_label_pdfs prefers the file over re-fetching).
        $pdf = isset($resp['data']['label_pdf'])
            ? (string) base64_decode((string) $resp['data']['label_pdf'], true)
            : '';
        return new BGC_Label(self::parse_shipment_id($resp), $pdf);
    }

    /**
     * Pigeon has NO label-by-waybill endpoint: the PDF is returned only once, inline in the
     * create-shipment response (see create_label(), which persists it to disk via BGC_Labels).
     * This is a pure fallback for the rare case the saved file is gone - we cannot re-fetch it.
     *
     * @param string $waybill The Pigeon reference number.
     * @return string         Never returns; always throws.
     * @throws BGC_Api_Exception Always - the label is not retrievable after creation.
     */
    public function get_label_pdf(string $waybill): string {
        throw new BGC_Api_Exception(esc_html__(
            'Pigeon labels are only available at creation time and cannot be re-fetched. Regenerate the waybill to get a fresh label.',
            'bg-couriers'
        ));
    }

    // ── Tracking ────────────────────────────────────────────────────────────

    /**
     * Parse a GET /v1/shipments/{ref}/track response into a BGC_Tracking object.
     *
     * Event mapping: status_code → code, status → name, timestamp → date.
     *
     * @param array $resp Decoded JSON response (expects $resp['data']).
     * @return BGC_Tracking
     */
    public static function parse_tracking(array $resp): BGC_Tracking {
        $d      = $resp['data'] ?? [];
        $events = array_map(static function (array $e): array {
            return [
                'code' => (string) ($e['status_code'] ?? ''),
                'name' => (string) ($e['status']      ?? ''),
                'date' => (string) ($e['timestamp']   ?? ''),
            ];
        }, $d['events'] ?? []);

        return new BGC_Tracking(
            (string) ($d['reference_number'] ?? ''),
            (string) ($d['status']           ?? 'UNKNOWN'),
            $events
        );
    }

    /**
     * Fetch live tracking info for a reference number.  Live - do NOT call in tests.
     */
    public function track(string $waybill): BGC_Tracking {
        $resp = $this->get_json('/v1/shipments/' . rawurlencode($waybill) . '/track');
        return self::parse_tracking($resp);
    }

    /**
     * Whether a waybill is already cancelled at Pigeon (status "Отказана"). Used by BGC_Labels::cancel()
     * to reach the desired end-state gracefully when a cancel call reports failure because the shipment
     * was already voided. Live - do NOT call in tests.
     */
    public function is_cancelled(string $waybill): bool {
        try {
            $status = $this->track($waybill)->status;
        } catch (\Exception $e) {
            return false;
        }
        $s = function_exists('mb_strtolower') ? mb_strtolower($status) : strtolower($status);
        return strpos($s, 'отказ') !== false || strpos($s, 'анулир') !== false || strpos($s, 'cancel') !== false;
    }

    /**
     * Return the public Pigeon tracking URL for a reference number.
     * Public tracker (no auth): https://track.pigeonexpress.com/?tracking_number={n}
     */
    public function tracking_url(string $waybill): string {
        return 'https://track.pigeonexpress.com/?tracking_number=' . rawurlencode($waybill);
    }

    /**
     * Cancel a shipment via POST /v1/shipments/{ref}/cancel.
     * Live - do NOT call in tests.
     *
     * @param string $waybill The Pigeon reference number.
     * @return bool           True if the API reported success.
     */
    public function cancel_label(string $waybill): bool {
        try {
            $resp = $this->post_json(
                $this->base . '/v1/shipments/' . rawurlencode($waybill) . '/cancel',
                []
            );
            // post_json throws on non-2xx, so reaching here means the request succeeded. Treat any
            // non-error response as success (matches the official plugin); only an explicit
            // success:false marks a failure.
            return !(isset($resp['success']) && $resp['success'] === false);
        } catch (BGC_Api_Exception $e) {
            return false;
        }
    }
}
