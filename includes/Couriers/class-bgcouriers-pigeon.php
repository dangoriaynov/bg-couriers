<?php
defined('ABSPATH') || exit;

class BGCouriers_Pigeon extends BGCouriers_Abstract_Courier {
    const PROD = 'https://api.pigeonexpress.com';
    const DEMO = 'https://api-demo.pigeonexpress.com';

    private $key;
    private $secret;
    private $base;

    public function __construct(array $config) {
        $this->key    = (string) ($config['username'] ?? '');
        $this->secret = (string) ($config['password'] ?? '');
        // An empty base makes a malformed request URL, so fall back to PROD.
        $base = rtrim(trim((string) ($config['base'] ?? '')), '/');
        $this->base = $base !== '' ? $base : self::PROD;
    }

    public function id(): string { return 'pigeon'; }
    public function label(): string { return 'Pigeon Express'; }

    public function credential_hint(): array {
        return [
            'intro' => __('Pigeon Express issues an API Key + Secret to business clients on request.', 'bg-couriers'),
            'steps' => [
                __('Contact Pigeon Express and request API access (e-mail support@pigeonexpress.com, or via pigeonexpress.com).', 'bg-couriers'),
                __('They issue an API Key + API Secret (production; ask for a sandbox/test key to test).', 'bg-couriers'),
                __('Ask them for your pickup office ID (the office you drop parcels off at).', 'bg-couriers'),
                __('Enter the Key, Secret and pickup office below, click Validate, then Sync. Tick "Sandbox" only for a test account.', 'bg-couriers'),
            ],
            'receive'   => __('API Key + API Secret (+ your pickup office ID)', 'bg-couriers'),
            'url_label' => __('Pigeon API docs:', 'bg-couriers'),
            'url'       => 'https://api-docs.pigeonexpress.com',
        ];
    }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }

    public function enable_problems(): array {
        $p = parent::enable_problems();
        // A shop collected from its own address has no pickup office.
        if (get_option('bgcouriers_pigeon_pickup_from_address', 'no') !== 'yes') {
            $this->need_option($p, 'bgcouriers_pigeon_pickup_office_id',
                __('No pickup office ID is set.', 'bg-couriers'),
                __('Enter the “Pickup office ID” you ship from (used for quotes and labels).', 'bg-couriers'));
            return $p;
        }
        if ((int) get_option('bgcouriers_pigeon_pickup_city_id', 0) <= 0) {
            $p[] = [
                'msg' => __('Pigeon is set to collect from your address, but no town is chosen for it.', 'bg-couriers'),
                'fix' => __('Pick the town the courier comes to - or turn the address collection off and drop the parcels at an office.', 'bg-couriers'),
            ];
        }
        return $p;
    }

    // Transport

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

    protected function get_json(string $path, array $query = []): array {
        $url = $this->base . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        // Retry once on transport/5xx; the nomenclature sync spans many pages.
        $last = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $res = $this->http_get($url, [
                'Accept'       => 'application/json',
                'X-API-Key'    => $this->key,
                'X-API-Secret' => $this->secret,
            ], 20);
            if (is_wp_error($res)) { $last = 'transport error: ' . $res->get_error_message(); continue; }
            $code = (int) wp_remote_retrieve_response_code($res);
            $raw  = (string) wp_remote_retrieve_body($res);
            if ($code === 200) {
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    /* translators: %s: the API address that answered. */
                    throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The answer from %s is not valid JSON.', 'bg-couriers'), $url)));
                }
                return $data;
            }
            $last = 'HTTP ' . $code . ': ' . self::error_text($raw);
            if ($code >= 400 && $code < 500) { break; }
        }
        /* translators: 1: courier name, 2: the courier's own error text, or the HTTP status. */
        throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s could not be reached: %2$s', 'bg-couriers'), 'Pigeon', $last)));
    }

    // Credential check

    public function check_credentials(): bool {
        // Probe a single known city (759 = Sofia), like the official plugin; get_json throws on non-200.
        $this->get_json('/v1/cities/759');
        return true;
    }

    // Nomenclature

    public function fetch_cities(): array {
        $out      = [];
        $page     = 1;
        $per_page = 100;
        $cap      = 200;

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

    public function fetch_offices(int $city_id): array {
        // /v1/offices returns only the requested type, so lockers are fetched separately.
        return array_merge(
            $this->fetch_offices_of_type($city_id, 'office'),
            $this->fetch_offices_of_type($city_id, 'locker')
        );
    }

    /** One office type, all pages (the sync asks country-wide with city_id 0). */
    private function fetch_offices_of_type(int $city_id, string $type): array {
        $out      = [];
        $page     = 1;
        $per_page = 100;
        $cap      = 60;

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

    public static function default_box(): array {
        return BGCouriers_Settings::box_dims();
    }

    /** Streets matched by the API's `name` filter, pages read up to a cap; $country is accepted for the common signature. */
    public function search_streets(int $city_id, string $term, string $country = ''): array {
        $rows = [];
        $page = 1;
        $cap  = $term === '' ? 1 : 3;
        do {
            $query = ['per_page' => 100, 'page' => $page];
            if ($term !== '') { $query['name'] = $term; }
            $resp = $this->get_json('/v1/cities/' . $city_id . '/streets', $query);
            $rows = array_merge($rows, self::parse_streets($resp));
            $meta = $resp['meta'] ?? null;
            $last_page = (int) ($meta['last_page'] ?? 1);
            $curr_page = (int) ($meta['current_page'] ?? $page);
            $page++;
        } while ($curr_page < $last_page && $page <= $cap);
        if ($term === '') {
            return $rows;
        }
        $t = function_exists('mb_strtolower') ? mb_strtolower($term) : strtolower($term);
        return array_values(array_filter($rows, static function (array $s) use ($t): bool {
            $n = function_exists('mb_strtolower') ? mb_strtolower($s['name']) : strtolower($s['name']);
            return strpos($n, $t) !== false;
        }));
    }

    // Pure static parsers

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

    // Quote (calculate)

    /** Body for POST /v1/shipments/calculate; $box and $pickup are injected so this stays get_option-free. */
    public static function build_calculate_body(array $s, int $pickup_office_id, array $box = ['length' => 40, 'width' => 40, 'height' => 40], ?array $pickup = null): array {
        $body = [
            'pickup_type'      => 'office',
            'pickup_office_id' => $pickup_office_id,
            // Pigeon requires length/width/height on every package, else HTTP 422.
            'packages'         => [
                array_merge(['weight' => max(0.1, (float) ($s['weight_kg'] ?? 1.0))], $box),
            ],
            'service_type'     => 'standard',
            'who_pays'         => (($s['service_payer'] ?? 'sender') === 'recipient') ? 'receiver' : 'sender',
        ];

        if (is_array($pickup) && ($pickup['pickup_type'] ?? '') === 'address' && !empty($pickup['pickup_address']['city_id'])) {
            unset($body['pickup_office_id']);
            $body['pickup_type']    = 'address';
            $body['pickup_address'] = $pickup['pickup_address'];
        }

        if (($s['method'] ?? 'address') === 'address') {
            $body['delivery_type']    = 'address';
            // additional_info must be non-empty; street_id is optional.
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

    /** total_price is GROSS (the waybill says so) although the answer has no tax field. */
    public static function parse_price(array $resp, string $currency): BGCouriers_Quote {
        $d     = $resp['data'] ?? [];
        $gross = (float) ($d['total_price'] ?? 0);
        if ($gross <= 0) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s sent no price in its answer.', 'bg-couriers'), 'Pigeon')));
        }
        list($net, $tax) = BGCouriers_Pricing::split_gross($gross);
        return new BGCouriers_Quote($net, $tax, (string) ($d['currency'] ?? $currency), 'live');
    }

    public function quote(array $shipment): BGCouriers_Quote {
        $pickup = (int) get_option('bgcouriers_pigeon_pickup_office_id', 0);
        $resp   = $this->post_json(
            $this->base . '/v1/shipments/calculate',
            self::build_calculate_body($shipment, $pickup, self::default_box(), self::default_pickup())
        );
        return self::parse_price($resp, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
    }

    // Label creation

    /** @return array{pickup_type:string, pickup_address?:array} Where Pigeon collects from; an incomplete address falls back to the office. */
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
            'street_id'      => (int)    $order->get_meta('_bgcouriers_street_id'),
            'street_no'      => (string) $order->get_meta('_bgcouriers_street_no'),
            'weight_kg'      => self::order_weight_kg($order),
            'service_payer'  => self::service_payer('pigeon', $order),
            'cod_amount'     => $order->get_payment_method() === 'cod'
                ? self::cod_for_payer($order, self::service_payer('pigeon', $order))
                : 0.0,
        ];

        $body = self::build_calculate_body($s, $pickup_office_id, self::default_box(), self::default_pickup());

        // Pigeon has no search-by-order API, so the order number rides as external_reference.
        $body['external_reference'] = (string) $order->get_order_number();
        $body['receiver_name']  = $order->get_formatted_billing_full_name();
        $body['receiver_phone'] = (string) $order->get_billing_phone();
        $body['receiver_email'] = BGCouriers_Settings::label_email($order);

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

    public static function parse_shipment_id(array $resp): string {
        return (string) ($resp['data']['reference_number'] ?? '');
    }

    public function create_label(\WC_Order $order): BGCouriers_Label {
        $pickup = (int) get_option('bgcouriers_pigeon_pickup_office_id', 0);
        if ($pickup <= 0) {
            throw new BGCouriers_Api_Exception(esc_html__(
                'Set your Pigeon pickup office ID in Settings > BG Couriers > Pigeon Express before generating a label.',
                'bg-couriers'
            ));
        }
        $resp   = $this->post_json(
            $this->base . '/v1/shipments',
            self::build_shipment_body($order, $pickup)
        );
        // The label PDF comes inline (base64) only in the create response; there is no download endpoint.
        $pdf = isset($resp['data']['label_pdf'])
            ? (string) base64_decode((string) $resp['data']['label_pdf'], true)
            : '';
        return new BGCouriers_Label(self::parse_shipment_id($resp), $pdf);
    }

    /** Pigeon has no label-by-waybill endpoint; the PDF is saved at creation and cannot be re-fetched. */
    public function get_label_pdf(string $waybill, string $format = ''): string {
        throw new BGCouriers_Api_Exception(esc_html__(
            'Pigeon labels are only available at creation time and cannot be re-fetched. Regenerate the waybill to get a fresh label.',
            'bg-couriers'
        ));
    }

    // Tracking

    /** History lives under `tracking` (not `events`); status_code is the machine value handed on as the phase. */
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

    private static function handover(string $code): ?bool {
        if ($code === '') { return null; }
        // Both "not yet collected" codes are mapped, so an unknown code is safely a handover.
        return BGCouriers_Tracking::phase_stage($code) !== 'registered';
    }

    /** Codes that, on a RETURN leg, mean the goods are back with the shop (Pigeon reuses its delivery codes). */
    private const RETURN_HOME = ['shipment_returned', 'shipment_delivered_to_recipient',
        'shipment_delivered_to_office', 'shipment_left_in_locker', 'shipment_held_by_sender'];

    /** A chained shipment whose FIRST event is this one was created to carry a parcel back. */
    private const RETURN_OPENS = 'shipment_returning_to_sender';

    /** The newest leg chained after this shipment, or ''; returns and redirections travel under a new number. */
    public static function chained_ref(array $resp): string {
        $chain = $resp['data']['chain_after'] ?? [];
        if (!is_array($chain) || $chain === []) { return ''; }
        // Newest leg wins; parse the timestamp rather than compare strings, since the DST change reorders them.
        $ref = '';
        $at  = 0;
        foreach ($chain as $leg) {
            if (!is_array($leg)) { continue; }
            $leg_ref = (string) ($leg['reference_number'] ?? '');
            if ($leg_ref === '') { continue; }
            $when = (int) strtotime((string) ($leg['created_at'] ?? ''));
            if ($ref === '' || $when >= $at) { $ref = $leg_ref; $at = $when; }
        }
        return $ref;
    }

    /** Join a shipment to its chained leg: a return reads as one shipment under the RETURN's number; a redirection keeps the booked number. */
    public static function follow_chain(array $outward, array $chained): BGCouriers_Tracking {
        $out = self::parse_tracking($outward);
        $ret = self::parse_tracking($chained);
        if ((string) ($ret->events[0]['code'] ?? '') !== self::RETURN_OPENS) {
            return new BGCouriers_Tracking(
                $out->waybill,
                $ret->status,
                array_merge($out->events, $ret->events),
                $ret->phase,
                true,
                $ret->status_is_human
            );
        }
        $home = in_array($ret->phase, self::RETURN_HOME, true);
        return new BGCouriers_Tracking(
            $ret->waybill,
            $ret->status,
            array_merge($out->events, $ret->events),
            $home ? 'shipment_returned' : self::RETURN_OPENS,
            true,
            true
        );
    }

    public function track(string $waybill): BGCouriers_Tracking {
        $resp = $this->get_json('/v1/shipments/' . rawurlencode($waybill) . '/track');
        $next = self::chained_ref($resp);
        if ($next !== '' && $next !== $waybill) {
            try {
                return self::follow_chain($resp, $this->get_json('/v1/shipments/' . rawurlencode($next) . '/track'));
            } catch (\Exception $e) {
                // The chain is extra detail; a failed second call must not throw away the first.
                self::log_chain_failure($next, $e);
            }
        }
        return self::parse_tracking($resp);
    }

    private static function log_chain_failure(string $ref, \Exception $e): void {
        if (class_exists('BGCouriers_Logger')) {
            BGCouriers_Logger::debug('pigeon: could not read the chained shipment - the outward leg stands',
                ['ref' => $ref, 'error' => $e->getMessage()]);
        }
    }

    public function is_cancelled(string $waybill): bool {
        try {
            // The booked waybill's own status, never the chained return leg's.
            $status = self::parse_tracking(
                $this->get_json('/v1/shipments/' . rawurlencode($waybill) . '/track'))->status;
        } catch (\Exception $e) {
            return false;
        }
        return BGCouriers_Tracking::reads_cancelled($status);
    }

    public function tracking_url(string $waybill): string {
        return 'https://track.pigeonexpress.com/?tracking_number=' . rawurlencode($waybill);
    }

    public function cancel_label(string $waybill): bool {
        $resp = $this->post_json(
            $this->base . '/v1/shipments/' . rawurlencode($waybill) . '/cancel',
            []
        );
        // post_json throws on non-2xx; only an explicit success:false is a failure.
        if (isset($resp['success']) && $resp['success'] === false) {
            throw new BGCouriers_Api_Exception(esc_html('Pigeon: ' . (trim((string) ($resp['message'] ?? '')) ?: __('the request was refused', 'bg-couriers'))));
        }
        return true;
    }
}
