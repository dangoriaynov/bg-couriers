<?php
defined('ABSPATH') || exit;

class BGCouriers_Pigeon extends BGCouriers_Abstract_Courier {
    const PROD = 'https://api.pigeonexpress.com';
    const DEMO = 'https://api-demo.pigeonexpress.com';

    private $key;   // X-API-Key   (from $config['username'])
    private $secret; // X-API-Secret (from $config['password'])
    private $base;

    public function __construct(array $config) {
        $this->key    = (string) ($config['username'] ?? '');
        $this->secret = (string) ($config['password'] ?? '');
        // Base host comes from $config['base'] (the bootstrap resolves prod vs the DEMO sandbox from the
        // bgcouriers_pigeon_sandbox toggle and passes it in). Fall back to PROD for an empty/missing base - an empty
        // base makes a malformed request URL -> "missing valid address" transport errors.
        $base = rtrim(trim((string) ($config['base'] ?? '')), '/');
        $this->base = $base !== '' ? $base : self::PROD;
    }

    public function id(): string { return 'pigeon'; }
    public function label(): string { return 'Pigeon Express'; }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }

    public function enable_problems(): array {
        $p = parent::enable_problems();
        // Asked for only when the parcels are actually dropped at an office. A shop whose courier comes
        // to its own address has no pickup office and must not be told to invent one.
        if (get_option('bgcouriers_pigeon_pickup_from_address', 'no') !== 'yes') {
            $this->need_option($p, 'bgcouriers_pigeon_pickup_office_id',
                __('No pickup office ID is set.', 'bg-couriers'),
                __('Enter the “Pickup office ID” you ship from (used for quotes and labels).', 'bg-couriers'));
            return $p;
        }
        // Collection from an address needs a town at the very least: with only a street line Pigeon has
        // nowhere to send the courier, and the shipment would quietly go out as an office drop instead.
        if ((int) get_option('bgcouriers_pigeon_pickup_city_id', 0) <= 0) {
            $p[] = [
                'msg' => __('Pigeon is set to collect from your address, but no town is chosen for it.', 'bg-couriers'),
                'fix' => __('Pick the town the courier comes to - or turn the address collection off and drop the parcels at an office.', 'bg-couriers'),
            ];
        }
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
     * @throws BGCouriers_Api_Exception on transport error or non-200 response.
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
                if (!is_array($data)) { throw new BGCouriers_Api_Exception(esc_html('Pigeon invalid JSON from ' . $url)); }
                return $data;
            }
            $last = 'HTTP ' . $code . ': ' . substr($raw, 0, 200);
            if ($code >= 400 && $code < 500) { break; } // client error (auth/bad request) - retry won't help
        }
        throw new BGCouriers_Api_Exception(esc_html('Pigeon GET failed: ' . $last));
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
            if ($meta === null && class_exists('BGCouriers_Logger')) {
                BGCouriers_Logger::debug('pigeon: cities page missing meta - pagination may be incomplete', ['page' => $page]);
            }
            $last_page = (int) ($meta['last_page'] ?? 1);
            $curr_page = (int) ($meta['current_page'] ?? $page);
            $page++;
        } while ($curr_page < $last_page && $page <= $cap);

        if ($page > $cap && $curr_page < $last_page && class_exists('BGCouriers_Logger')) {
            BGCouriers_Logger::debug('pigeon: cities pagination hit the safety cap', ['cap' => $cap, 'last_page' => $last_page]);
        }
        return $out;
    }

    /**
     * Fetch all offices (and lockers) for a given city.
     * Type filtering (office vs automat) is handled by BGCouriers_Ajax::city_offices.
     *
     * @param int $city_id  Pigeon city id.
     * @return array[]      Parsed office rows.
     */
    public function fetch_offices(int $city_id): array {
        // /v1/offices returns ONLY the requested `type`; lockers/APS are a separate type (not in the default
        // office list), so fetch both and merge - parse_offices maps type 'locker' -> 'automat'.
        return array_merge(
            $this->fetch_offices_of_type($city_id, 'office'),
            $this->fetch_offices_of_type($city_id, 'locker')
        );
    }

    /**
     * One office type, ALL pages. /v1/offices is paginated exactly like /v1/cities - 100 per page with a
     * `meta.last_page` - and the sync fetches country-wide (city_id 0). Requesting a single page silently
     * kept only the first 100 of Bulgaria's 180 offices, so every office after that was invisible in the
     * checkout and settings dropdowns even though the courier serves it. Accumulates until the last page
     * or the safety cap, same shape as fetch_cities().
     *
     * @param int    $city_id Pigeon city id, or 0 for country-wide.
     * @param string $type    'office' or 'locker'.
     * @return array[]        Parsed office rows.
     */
    private function fetch_offices_of_type(int $city_id, string $type): array {
        $out      = [];
        $page     = 1;
        $per_page = 100;
        $cap      = 60; // defensive safety cap

        do {
            $resp = $this->get_json('/v1/offices', ['city_id' => $city_id, 'type' => $type, 'per_page' => $per_page, 'page' => $page]);
            $out  = array_merge($out, self::parse_offices($resp));
            $meta = $resp['meta'] ?? null;
            if ($meta === null && class_exists('BGCouriers_Logger')) {
                BGCouriers_Logger::debug('pigeon: offices page missing meta - pagination may be incomplete', ['page' => $page, 'type' => $type]);
            }
            $last_page = (int) ($meta['last_page'] ?? 1);
            $curr_page = (int) ($meta['current_page'] ?? $page);
            $page++;
        } while ($curr_page < $last_page && $page <= $cap);

        if ($page > $cap && $curr_page < $last_page && class_exists('BGCouriers_Logger')) {
            BGCouriers_Logger::debug('pigeon: offices pagination hit the safety cap', ['cap' => $cap, 'last_page' => $last_page, 'type' => $type]);
        }
        return $out;
    }

    /** Default parcel box (cm) for quotes/labels; Pigeon requires length/width/height. Shared across couriers. */
    public static function default_box(): array {
        return BGCouriers_Settings::box_dims();
    }

    /**
     * Search streets in a city by a term (substring, case-insensitive, Cyrillic-aware).
     *
     * @param int    $city_id  Pigeon city id.
     * @param string $term     Search term.
     * @return array[]         Matching parsed street rows.
     */
    /** $country is accepted so every courier answers the same call; Pigeon delivers in Bulgaria only. */
    public function search_streets(int $city_id, string $term, string $country = ''): array {
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
    public static function build_calculate_body(array $s, int $pickup_office_id, array $box = ['length' => 40, 'width' => 40, 'height' => 40], ?array $pickup = null): array {
        $body = [
            // Where the parcel STARTS. Pigeon takes either end - a drop at one of its offices, or a
            // courier to the merchant's own address - and a shop on an address-collection contract could
            // not be served at all while this said 'office' and nothing else. $pickup is passed by the
            // live callers (default_pickup() reads the settings); the office form stays the default and
            // the fallback, so a shop that has not set an address is untouched.
            'pickup_type'      => 'office',
            'pickup_office_id' => $pickup_office_id,
            // Pigeon requires length/width/height on every package (not just weight), else HTTP 422.
            // The box is injected by the caller (default_box() reads the merchant's settings); the literal
            // default keeps this method pure and get_option-free so it can be unit-tested directly.
            'packages'         => [
                array_merge(['weight' => max(0.1, (float) ($s['weight_kg'] ?? 1.0))], $box),
            ],
            'service_type'     => 'standard',
            // Who pays the courier delivery fee. Default 'sender': the merchant already charged the
            // customer at checkout, so the courier bills the sender. 'receiver' means the courier
            // collects the fee from the recipient at the door (in addition to any COD amount).
            'who_pays'         => (($s['service_payer'] ?? 'sender') === 'recipient') ? 'receiver' : 'sender',
        ];

        if (is_array($pickup) && ($pickup['pickup_type'] ?? '') === 'address' && !empty($pickup['pickup_address']['city_id'])) {
            unset($body['pickup_office_id']);
            $body['pickup_type']    = 'address';
            $body['pickup_address'] = $pickup['pickup_address'];
        }

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
     * Parse a /v1/shipments/calculate response into a BGCouriers_Quote.
     *
     * Pigeon's total_price already includes all service fees; tax split TBD at live-verify.
     *
     * @param array  $resp     Decoded JSON response (expects $resp['data']).
     * @param string $currency Fallback currency if not present in response.
     * @return BGCouriers_Quote
     * @throws BGCouriers_Api_Exception When no usable price is found.
     */
    public static function parse_price(array $resp, string $currency): BGCouriers_Quote {
        $d     = $resp['data'] ?? [];
        $total = (float) ($d['total_price'] ?? 0);
        if ($total <= 0) {
            throw new BGCouriers_Api_Exception('No price in Pigeon response');
        }
        return new BGCouriers_Quote($total, 0.0, (string) ($d['currency'] ?? $currency), 'live');
    }

    /**
     * Fetch a live shipping quote via POST /v1/shipments/calculate.
     * Live - do NOT call in tests (no credentials in CI).
     */
    public function quote(array $shipment): BGCouriers_Quote {
        $pickup = (int) get_option('bgcouriers_pigeon_pickup_office_id', 0);
        $resp   = $this->post_json(
            $this->base . '/v1/shipments/calculate',
            self::build_calculate_body($shipment, $pickup, self::default_box(), self::default_pickup())
        );
        return self::parse_price($resp, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
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
    /**
     * Where Pigeon collects from, per the merchant's settings.
     *
     * Their own plugin offers the same two: drop the parcels at a Pigeon office, or have a courier come
     * to your premises. The address form needs a city and something to find you by - with only a city
     * the carrier cannot route a collection - so an incomplete address falls back to the office rather
     * than sending half an instruction. BGCouriers_Pigeon::enable_problems() says so on the settings
     * screen, where it can be fixed.
     *
     * @return array{pickup_type:string, pickup_address?:array}
     */
    public static function default_pickup(): array {
        if (get_option('bgcouriers_pigeon_pickup_from_address', 'no') !== 'yes') { return ['pickup_type' => 'office']; }
        $city = (int) get_option('bgcouriers_pigeon_pickup_city_id', 0);
        $addr = trim((string) get_option('bgcouriers_pigeon_pickup_address', ''));
        if ($city <= 0) { return ['pickup_type' => 'office']; }
        $out = ['city_id' => $city, 'additional_info' => $addr !== '' ? $addr : '-'];
        $street = (int) get_option('bgcouriers_pigeon_pickup_street_id', 0);
        if ($street > 0) { $out['street_id'] = $street; }
        return ['pickup_type' => 'address', 'pickup_address' => $out];
    }

    public static function build_shipment_body(\WC_Order $order, int $pickup_office_id): array {
        $s = [
            'method'         => (string) $order->get_meta('_bgcouriers_method'),
            'site_id'        => (int)    $order->get_meta('_bgcouriers_site_id'),
            'office_id'      => (int)    $order->get_meta('_bgcouriers_office_id'),
            'street_name'    => (string) $order->get_meta('_bgcouriers_street_name'),
            'street_no'      => (string) $order->get_meta('_bgcouriers_street_no'),
            'weight_kg'      => self::order_weight_kg($order),
            'service_payer'  => self::service_payer('pigeon', $order),
            // COD only for cash-on-delivery orders. The COD amount depends on who pays the delivery fee:
            // when the sender (merchant) pays, the courier collects the FULL order total (goods + shipping);
            // when the recipient pays the delivery, we collect goods-only (shipping excluded from COD).
            'cod_amount'     => $order->get_payment_method() === 'cod'
                ? self::cod_for_payer($order, self::service_payer('pigeon', $order))
                : 0.0,
        ];

        $body = self::build_calculate_body($s, $pickup_office_id, self::default_box(), self::default_pickup());

        // Stamp our order number as the external reference so every shipment is identifiable in the Pigeon
        // dashboard / by support (Pigeon has no list or search-by-order API, so this is the only way to
        // reconcile a waybill back to its order after the fact).
        $body['external_reference'] = (string) $order->get_order_number();
        $body['receiver_name']  = $order->get_formatted_billing_full_name();
        $body['receiver_phone'] = (string) $order->get_billing_phone();
        $body['receiver_email'] = BGCouriers_Settings::label_email($order);

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
    public function create_label(\WC_Order $order): BGCouriers_Label {
        $pickup = (int) get_option('bgcouriers_pigeon_pickup_office_id', 0);
        if ($pickup <= 0) {
            // Fail with a clear, actionable message instead of Pigeon's raw HTTP 422
            // ("invalid pickup office id") when the merchant hasn't set their drop-off office.
            throw new BGCouriers_Api_Exception(esc_html__(
                'Set your Pigeon pickup office ID in Settings > BG Couriers > Pigeon Express before generating a label.',
                'bg-couriers'
            ));
        }
        $resp   = $this->post_json(
            $this->base . '/v1/shipments',
            self::build_shipment_body($order, $pickup)
        );
        // Pigeon returns the label PDF INLINE (base64) in the create response - there is no separate
        // label-download endpoint, so capture it now. BGCouriers_Labels::generate() persists these bytes to disk;
        // reprints then serve the saved file (collect_label_pdfs prefers the file over re-fetching).
        $pdf = isset($resp['data']['label_pdf'])
            ? (string) base64_decode((string) $resp['data']['label_pdf'], true)
            : '';
        return new BGCouriers_Label(self::parse_shipment_id($resp), $pdf);
    }

    /**
     * Pigeon has NO label-by-waybill endpoint: the PDF is returned only once, inline in the
     * create-shipment response (see create_label(), which persists it to disk via BGCouriers_Labels).
     * This is a pure fallback for the rare case the saved file is gone - we cannot re-fetch it.
     *
     * @param string $waybill The Pigeon reference number.
     * @return string         Never returns; always throws.
     * @throws BGCouriers_Api_Exception Always - the label is not retrievable after creation.
     */
    public function get_label_pdf(string $waybill, string $format = ''): string {
        throw new BGCouriers_Api_Exception(esc_html__(
            'Pigeon labels are only available at creation time and cannot be re-fetched. Regenerate the waybill to get a fresh label.',
            'bg-couriers'
        ));
    }

    // ── Tracking ────────────────────────────────────────────────────────────

    /**
     * Parse a GET /v1/shipments/{ref}/track response into a BGCouriers_Tracking object.
     *
     * Pigeon keeps the history under `tracking` (NOT `events`) and stamps each entry with `created_at`;
     * alongside every status text it sends `status_code`, its own machine value from
     * GET /v1/shipment-statuses. Event mapping: status_code → code, status → name, created_at → date.
     *
     * The code is what we hand on as the phase, so no verdict about a Pigeon parcel is ever reached by
     * reading Bulgarian prose - which is how "Доставена в офис/локър" (the parcel REACHED the office) was
     * being read as delivered to the customer.
     *
     * @param array $resp Decoded JSON response (expects $resp['data']).
     * @return BGCouriers_Tracking
     */
    public static function parse_tracking(array $resp): BGCouriers_Tracking {
        $d       = $resp['data'] ?? [];
        $history = $d['tracking'] ?? [];
        $events  = [];
        foreach (is_array($history) ? $history : [] as $e) {
            if (!is_array($e)) { continue; }
            $events[] = [
                'code' => (string) ($e['status_code'] ?? ''),
                'name' => (string) ($e['status']      ?? ''),
                'date' => (string) ($e['created_at']  ?? ''),
            ];
        }

        $code = (string) ($d['status_code'] ?? '');
        return new BGCouriers_Tracking(
            (string) ($d['reference_number'] ?? ''),
            (string) ($d['status']           ?? 'UNKNOWN'),
            $events, $code, self::handover($code), true
        );
    }

    /**
     * Does this status code mean the courier physically has the parcel? Pigeon answers outright, so the
     * poller never has to guess from the length of the history - which for Pigeon told it nothing anyway.
     *
     * @param string $code Pigeon's status_code.
     * @return bool|null True/false when the code is one we know, null when it is not.
     */
    private static function handover(string $code): ?bool {
        if ($code === '') { return null; }
        // Anything we have not seen before means SOMETHING happened after registration, and the two codes
        // that mean "not yet collected" are both mapped - so an unknown code is safely a handover.
        return BGCouriers_Tracking::phase_stage($code) !== 'registered';
    }

    /**
     * The status codes that, ON A RETURN LEG, mean the goods are back within the shop's reach. Pigeon
     * words the end of a return with the very same codes it uses to end a delivery - the "office" in
     * "Готова за взимане в офис/АПС" is simply the SHOP's office this time - so these only ever carry
     * this meaning after follow_chain() has established that the leg is a journey home.
     *
     * @var string[]
     */
    private const RETURN_HOME = ['shipment_returned', 'shipment_delivered_to_recipient',
        'shipment_delivered_to_office', 'shipment_left_in_locker', 'shipment_held_by_sender'];

    /** A chained shipment whose FIRST event is this one was created to carry a parcel back. */
    private const RETURN_OPENS = 'shipment_returning_to_sender';

    /**
     * The reference number of the shipment that carries on from this one, or '' when there is none.
     *
     * Pigeon never walks the booked waybill through 'returning_to_sender' / 'returned'. A parcel nobody
     * collected FREEZES on "Непотърсена" for good, and the journey home travels under a brand new number
     * linked from `chain_after` - so a return read off the booked number alone is simply invisible.
     * A redirection chains the same way, which is why the caller still has to check what the new leg is.
     *
     * @param array $resp Decoded GET /v1/shipments/{ref}/track response.
     * @return string The newest chained reference number, or ''.
     */
    public static function chained_ref(array $resp): string {
        $chain = $resp['data']['chain_after'] ?? [];
        if (!is_array($chain) || $chain === []) { return ''; }
        // Newest wins: a parcel can be chained more than once (redirected, then returned), and it is the
        // last leg that says where the goods are now. Sorting on the courier's own timestamp rather than
        // trusting the array order, which nothing documents.
        $ref = '';
        $at  = '';
        foreach ($chain as $leg) {
            if (!is_array($leg)) { continue; }
            $when = (string) ($leg['created_at'] ?? '');
            if ($ref === '' || strcmp($when, $at) >= 0) { $ref = (string) ($leg['reference_number'] ?? ''); $at = $when; }
        }
        return $ref;
    }

    /**
     * Join a shipment to the leg that carries on from it, when that leg is a journey home.
     *
     * The result reads as ONE shipment: the whole history out and back, the return's own wording, and -
     * deliberately - the RETURN's number as the waybill, because that is what the shop has to quote at
     * the counter to get its goods back. The phase is translated into the two codes our stage rules
     * already understand, so nothing downstream has to know Pigeon chains anything.
     *
     * A chain that is NOT a return (a redirection makes one too, and that parcel is still going forward)
     * leaves the outward verdict exactly as it was.
     *
     * @param array $outward Decoded track response for the booked waybill.
     * @param array $chained Decoded track response for the shipment named by chained_ref().
     * @return BGCouriers_Tracking
     */
    public static function follow_chain(array $outward, array $chained): BGCouriers_Tracking {
        $out = self::parse_tracking($outward);
        $ret = self::parse_tracking($chained);
        if ((string) ($ret->events[0]['code'] ?? '') !== self::RETURN_OPENS) { return $out; }
        $home = in_array($ret->phase, self::RETURN_HOME, true);
        return new BGCouriers_Tracking(
            $ret->waybill,
            $ret->status,
            array_merge($out->events, $ret->events),
            $home ? 'shipment_returned' : self::RETURN_OPENS,
            true, // a parcel that is on its way back was plainly collected in the first place
            true
        );
    }

    /**
     * Fetch live tracking info for a reference number.  Live - do NOT call in tests.
     *
     * Follows the return chain when there is one - see chained_ref(). The extra request is only ever made
     * for a shipment that has actually been chained, which for a healthy parcel is never.
     */
    public function track(string $waybill): BGCouriers_Tracking {
        $resp = $this->get_json('/v1/shipments/' . rawurlencode($waybill) . '/track');
        $next = self::chained_ref($resp);
        if ($next !== '' && $next !== $waybill) {
            try {
                return self::follow_chain($resp, $this->get_json('/v1/shipments/' . rawurlencode($next) . '/track'));
            } catch (\Exception $e) {
                // The chain is extra detail, not the answer. A second call that failed must not throw away
                // the first one - the poller would read that as "ask again next time" and show nothing.
                self::log_chain_failure($next, $e);
            }
        }
        return self::parse_tracking($resp);
    }

    /** Why a return leg could not be read. Its own method so track() stays about tracking. */
    private static function log_chain_failure(string $ref, \Exception $e): void {
        if (class_exists('BGCouriers_Logger')) {
            BGCouriers_Logger::debug('pigeon: could not read the chained shipment - the outward leg stands',
                ['ref' => $ref, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Whether a waybill is already cancelled at Pigeon (status "Отказана"). Used by BGCouriers_Labels::cancel()
     * to reach the desired end-state gracefully when a cancel call reports failure because the shipment
     * was already voided. Live - do NOT call in tests.
     */
    public function is_cancelled(string $waybill): bool {
        try {
            // The booked waybill's OWN status, never the chained return leg's: a parcel travelling back
            // has not been cancelled, and reading the return's wording here would answer about the wrong
            // shipment (and cost a second request to do it).
            $status = self::parse_tracking(
                $this->get_json('/v1/shipments/' . rawurlencode($waybill) . '/track'))->status;
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
        } catch (BGCouriers_Api_Exception $e) {
            return false;
        }
    }
}
