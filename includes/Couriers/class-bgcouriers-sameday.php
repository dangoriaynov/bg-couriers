<?php
defined('ABSPATH') || exit;

/** Sameday courier adapter: X-Auth-Username/X-Auth-Password login, X-AUTH-TOKEN header on every call. */
class BGCouriers_Sameday extends BGCouriers_Abstract_Courier implements BGCouriers_Courier_Interface {
    const PROD = 'https://api.sameday.bg';
    const DEMO = 'https://sameday-api-bg.demo.zitec.com';

    private $config;

    private $base;

    public function __construct(array $config) {
        $this->config = $config;
        $demo = (defined('BGCOURIERS_SAMEDAY_DEMO') && BGCOURIERS_SAMEDAY_DEMO)
            || (function_exists('get_option') && get_option('bgcouriers_sameday_live', 'yes') !== 'yes');
        $this->base = $demo ? self::DEMO : self::PROD;
    }

    public function id(): string { return 'sameday'; }
    public function label(): string { return 'Sameday'; }

    public function credential_hint(): array {
        return [
            'intro' => __('Sameday issues API credentials (username + password) to clients after a business contract.', 'bg-couriers'),
            'steps' => [
                __('Sign a Sameday business contract (via sameday.bg or your Sameday account manager).', 'bg-couriers'),
                __('Request API / eAWB access; you receive a username + password.', 'bg-couriers'),
                __('Ask for your pickup-point ID and the service IDs for each delivery type (office / address / easyBox locker) from your contract.', 'bg-couriers'),
                __('Enter the username, password, pickup point and service IDs below; tick "Sandbox" to use the test environment (sameday-api.demo.zitec.com).', 'bg-couriers'),
            ],
            'receive'   => __('username + password (+ pickup point and per-type service IDs)', 'bg-couriers'),
            'url_label' => __('Sameday Bulgaria:', 'bg-couriers'),
            'url'       => 'https://sameday.bg',
        ];
    }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }

    public function books_pickup_on_create(): bool { return true; }

    const SERVICE_CODES = ['address' => '24', 'automat' => 'LN', 'office' => 'PP'];

    public function service_id(string $type): int {
        $ckey = $this->cache_key('services');
        $map  = get_transient($ckey);
        if (!is_array($map) || !$map) {
            $map = [];
            foreach ($this->get_paged('/api/client/services') as $s) {
                $map[(string) ($s['serviceCode'] ?? '')] = (int) ($s['id'] ?? 0);
            }
            if ($map) { set_transient($ckey, $map, DAY_IN_SECONDS); }
        }
        return (int) ($map[self::SERVICE_CODES[$type] ?? ''] ?? 0);
    }

    public function pickup_point_id(): int {
        $opt = (int) get_option('bgcouriers_sameday_pickup_point', 0);
        if ($opt > 0) { return $opt; }
        $ckey = $this->cache_key('pickup_default');
        $id   = (int) get_transient($ckey);
        if ($id > 0) { return $id; }
        foreach ($this->get_paged('/api/client/pickup-points') as $pp) {
            if (!empty($pp['defaultPickupPoint'])) { $id = (int) ($pp['id'] ?? 0); break; }
            if (!$id) { $id = (int) ($pp['id'] ?? 0); }
        }
        if ($id > 0) { set_transient($ckey, $id, DAY_IN_SECONDS); }
        return $id;
    }

    public function enable_problems(): array {
        $p = parent::enable_problems();
        if (get_option(self::NO_RECIPIENT_PAY, '') === 'yes' && !BGCouriers_Settings::ship_in_total('sameday')) {
            $p[] = [
                'msg' => __('Sameday does not support “the recipient pays the delivery” on this account - no waybill can be created while that is how it is set.', 'bg-couriers'),
                'fix' => __('Turn on “Delivery in the order total” for Sameday, or ask Sameday to allow recipient payment on your contract.', 'bg-couriers'),
            ];
        }
        try { if (!$this->check_credentials()) { return $p; } }
        catch (BGCouriers_Api_Exception $e) { return $p; }
        $labels = [
            'office'  => __('to office', 'bg-couriers'),
            'address' => __('to address', 'bg-couriers'),
            'automat' => __('to locker (easyBox)', 'bg-couriers'),
        ];
        try {
            if ($this->pickup_point_id() <= 0) {
                $p[] = [
                    'msg' => __('Your Sameday account has no pickup point.', 'bg-couriers'),
                    'fix' => __('Add a pickup point in the Sameday eAWB portal (or enter its ID below).', 'bg-couriers'),
                ];
            }
            foreach (BGCouriers_Settings::enabled_methods('sameday') as $m) {
                if (!isset($labels[$m]) || $this->service_id($m) > 0) { continue; }
                $p[] = [
                    /* translators: %s: delivery type, e.g. "to office" */
                    'msg' => sprintf(__('Your Sameday account has no service for the enabled “%s” delivery type.', 'bg-couriers'), $labels[$m]),
                    'fix' => __('Ask Sameday to enable that service on your contract, or disable the delivery type.', 'bg-couriers'),
                ];
            }
        } catch (\Exception $e) { /* reported elsewhere */ }
        return $p;
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    /** Cache key scoped to account + host, so switching credentials or Live mode misses the cache. */
    private function cache_key(string $suffix): string {
        $who = $this->base . '|' . (string) ($this->config['username'] ?? '');
        return 'bgcouriers_sameday_' . $suffix . '_' . substr(md5($who), 0, 12);
    }

    protected function auth_token(): string {
        $key = $this->cache_key('token');
        $tok = get_transient($key);
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
            /* translators: 1: courier name, 2: the error the connection reported. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s could not be reached: %2$s', 'bg-couriers'), 'Sameday', $r->get_error_message())));
        }
        $raw  = (string) wp_remote_retrieve_body($r);
        $body = json_decode($raw, true);
        $tok  = (string) ($body['token'] ?? '');
        if ($tok === '') {
            $words = self::error_words($raw);
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s did not return an access token, so the credentials were refused.', 'bg-couriers'), 'Sameday')
                . ($words !== '' ? ' (' . $words . ')' : '')));
        }
        set_transient($key, $tok, 50 * MINUTE_IN_SECONDS);
        return $tok;
    }

    // ── HTTP helpers ─────────────────────────────────────────────────────────

    protected function get_json(string $path): array {
        return $this->decode($this->http_get($this->base . $path, ['X-AUTH-TOKEN' => $this->auth_token()], 30));
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

    public static function field_errors(array $node, string $path = ''): array {
        $out = [];
        foreach ((array) ($node['errors'] ?? []) as $e) {
            if (is_scalar($e)) { $out[] = ($path !== '' ? $path . ': ' : '') . (string) $e; }
        }
        foreach ((array) ($node['children'] ?? []) as $name => $child) {
            if (is_array($child)) {
                $out = array_merge($out, self::field_errors($child, $path === '' ? (string) $name : $path . '.' . $name));
            }
        }
        return $out;
    }

    private function decode($r): array {
        if (is_wp_error($r)) {
            throw new BGCouriers_Api_Exception(esc_html($r->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        $raw  = (string) wp_remote_retrieve_body($r);
        $data = json_decode($raw, true);
        // 4xx/5xx carry a JSON error body; without the status check they would read as an empty success.
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) ? ($data['message'] ?? $data['error'] ?? $raw) : $raw;
            if (!is_scalar($msg)) { $msg = wp_json_encode($msg); }
            // The real reason hides in errors.children.<field>.errors[] behind a bare "Validation Failed".
            $fields = is_array($data) ? self::field_errors((array) ($data['errors'] ?? [])) : [];
            $msg = substr((string) $msg, 0, 300) . ($fields ? ' (' . implode('; ', array_slice($fields, 0, 6)) . ')' : '');
            /* translators: 1: courier name, 2: HTTP status code, 3: the courier's own error text. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s answered HTTP %2$d: %3$s', 'bg-couriers'), 'Sameday', $code, $msg)));
        }
        return is_array($data) ? $data : [];
    }

    // ── Credentials ──────────────────────────────────────────────────────────

    public function check_credentials(): bool {
        return $this->auth_token() !== '';
    }

    // ── Nomenclature ─────────────────────────────────────────────────────────

    protected function get_paged(string $path): array {
        $sep = strpos($path, '?') === false ? '?' : '&';
        $out = [];
        for ($page = 1; $page <= 200; $page++) {
            $j = $this->get_json($path . $sep . 'countPerPage=500&page=' . $page);
            $rows = (array) ($j['data'] ?? []);
            $out = array_merge($out, $rows);
            $pages = (int) ($j['pages'] ?? 1);
            if ($page >= $pages || !$rows) { break; }
        }
        return $out;
    }

    public function fetch_cities(): array {
        return self::parse_cities($this->get_paged('/api/geolocation/city'));
    }

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
        $rows = self::parse_offices($this->get_paged('/api/client/lockers'), $this->get_paged('/api/client/ooh-locations'));
        return $city_id > 0
            ? array_values(array_filter($rows, static function ($o) use ($city_id) { return (int) $o['city_id'] === $city_id; }))
            : $rows;
    }

    /** Lockers -> 'automat', OOH points -> 'office'; the OOH listing includes the easyboxes, so they are deduped. */
    public static function parse_offices(array $lockers, array $ooh): array {
        $out = [];
        $seen = [];
        $map = static function ($rows, $type) use (&$out, &$seen) {
            foreach (($rows['data'] ?? $rows) as $o) {
                $id = (int) ($o['id'] ?? $o['lockerId'] ?? $o['oohId'] ?? 0);
                if (!$id || isset($seen[$id])) { continue; }
                $seen[$id] = true;
                $out[] = [
                    'office_id' => $id,
                    'code'      => (string) $id,
                    'city_id'   => (int) ($o['cityId'] ?? $o['city']['id'] ?? 0),
                    'type'      => $type,
                    'name'      => (string) ($o['name'] ?? ''),
                    'address'   => (string) ($o['address'] ?? ''),
                    'lat'       => (float) ($o['lat'] ?? 0),
                    'lng'       => (float) ($o['lng'] ?? 0),
                ];
            }
        };
        $map($lockers, 'automat');
        $map($ooh, 'office');
        return $out;
    }

    // ── easyBox availability ─────────────────────────────────────────────────

    /** easyBox sizes, smallest first; a parcel fits its size or any larger. */
    public const BOX_SIZES = ['S', 'M', 'L'];

    /** @return array<int,array<string,int>> lockerId => size => free count */
    public function fetch_locker_availability(): array {
        $out = [];
        foreach ($this->get_paged('/api/client/lockers') as $l) {
            $id = (int) ($l['lockerId'] ?? $l['id'] ?? 0);
            if (!$id) { continue; }
            // Staffed "SAMEDAY point" counters have no compartments and are left out; a full easyBox is kept.
            $has_compartments = !empty($l['availableBoxes']) || !empty($l['occupiedBoxes']) || !empty($l['reservedBoxes']);
            if (!$has_compartments) { continue; }
            $free = [];
            foreach ((array) ($l['availableBoxes'] ?? []) as $b) {
                $size = strtoupper((string) ($b['size'] ?? ''));
                if ($size !== '') { $free[$size] = (int) ($b['number'] ?? 0); }
            }
            $out[$id] = $free;
        }
        return $out;
    }

    /**
     * easyBox compartment envelope in cm, from Sameday's published cell sizes (the API has none); keys must stay S/M/L.
     * @return array{max_length:float,max_width:float,heights:array<string,float>}
     */
    public static function box_dims_table(): array {
        $t = apply_filters('bgcouriers_sameday_box_dims', [
            'max_length' => 47.0,
            'max_width'  => 44.5,
            'heights'    => ['S' => 10.0, 'M' => 20.0, 'L' => 39.0],
        ]);
        $heights = (is_array($t) && !empty($t['heights']) && is_array($t['heights'])) ? array_map('floatval', $t['heights']) : ['S' => 10.0, 'M' => 20.0, 'L' => 39.0];
        return [
            'max_length' => (float) (is_array($t) ? ($t['max_length'] ?? 47.0) : 47.0),
            'max_width'  => (float) (is_array($t) ? ($t['max_width'] ?? 44.5) : 44.5),
            'heights'    => $heights,
        ];
    }

    /** The smallest easyBox size a parcel (cm) needs; a misfit maps to the largest, never to "fits nothing". */
    public static function box_size_for(array $dims): string {
        $t = self::box_dims_table();
        $l = (float) ($dims['length'] ?? 0);
        $w = (float) ($dims['width'] ?? 0);
        $h = (float) ($dims['height'] ?? 0);
        if ($l <= $t['max_length'] && $w <= $t['max_width']) {
            $heights = $t['heights'];
            asort($heights);
            foreach ($heights as $size => $max) {
                if ($h <= (float) $max) { return (string) $size; }
            }
        }
        return 'L';
    }

    /** @param array<string,int> $free_boxes size => free count */
    public static function locker_fits(array $free_boxes, string $required): bool {
        $from = array_search($required, self::BOX_SIZES, true);
        if ($from === false) { return false; }
        foreach (array_slice(self::BOX_SIZES, $from) as $size) {
            if ((int) ($free_boxes[$size] ?? 0) > 0) { return true; }
        }
        return false;
    }

    // ── Quote (live, weight-based) ───────────────────────────────────────────

    public function quote(array $shipment): BGCouriers_Quote {
        $shipment['service_id']   = $this->service_id((string) ($shipment['method'] ?? 'address'));
        $shipment['pickup_point'] = $this->pickup_point_id();
        $resp = $this->post_json('/api/awb/estimate-cost', self::build_estimate_body($shipment));
        return self::parse_price($resp, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
    }

    /** Recipient takes `city` (an id, not cityId) plus countyString; packageNumber and thirdPartyPickup are required. */
    public static function build_estimate_body(array $s): array {
        // From the shipment array, never from an order: also called at checkout, before any order exists.
        $parcel_n = max(1, (int) ($s['parcels'] ?? 1));
        $insured  = max(0.0, (float) ($s['insurance'] ?? 0));
        $type = $s['method'] ?? 'address';
        $w    = max(0.1, (float) ($s['weight_kg'] ?? 1.0));
        $sid  = (int) ($s['site_id'] ?? 0);
        $county = (string) ($s['region'] ?? '');
        if ($county === '' && $sid && class_exists('BGCouriers_Nomenclature')) {
            $county = (string) (BGCouriers_Nomenclature::city_by_id('sameday', $sid)['region'] ?? '');
        }
        $body = [
            'pickupPoint'     => (int) ($s['pickup_point'] ?? get_option('bgcouriers_sameday_pickup_point', 0)),
            'service'         => (string) ($s['service_id'] ?? ''),
            'packageType'     => 0,
            'packageNumber'   => $parcel_n,
            'packageWeight'   => $w,
            'awbPayment'      => 1,
            // Sameday charges for collecting COD, and the charge is in the estimate.
            'cashOnDelivery'  => max(0.0, (float) ($s['cod_amount'] ?? 0)),
            'insuredValue'    => $insured,
            'thirdPartyPickup'=> 0,
            'currency'        => (string) ($s['currency'] ?? get_woocommerce_currency()),
            'parcels'         => [array_merge(['weight' => $w], self::parcel_dims())],
            'awbRecipient'    => ['city' => $sid, 'countyString' => $county],
        ];
        if ($type === 'automat')      { $body['lockerLastMile'] = (int) ($s['office_id'] ?? 0); }
        elseif ($type === 'office')   { $body['oohLastMile']    = (int) ($s['office_id'] ?? 0); }
        return $body;
    }

    protected static function parcel_dims(?\WC_Order $order = null): array {
        $d = BGCouriers_Settings::box_dims($order);
        return ['width' => $d['width'], 'length' => $d['length'], 'height' => $d['height']];
    }

    /** Sameday's `amount` is net (an invoice confirmed it); VAT is added here. A foreign-currency quote throws. */
    public static function parse_price(array $resp, string $currency): BGCouriers_Quote {
        $amount = (float) ($resp['amount'] ?? $resp['cost'] ?? 0);
        $cur    = strtoupper((string) ($resp['currency'] ?? $currency));
        $store  = strtoupper($currency);
        if ($cur !== $store) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s quoted a price in a different currency from the shop.', 'bg-couriers'), 'Sameday')));
        }
        $net = round($amount, 2);
        return new BGCouriers_Quote($net, BGCouriers_Pricing::courier_tax($net), $store, 'live');
    }

    // ── Label ────────────────────────────────────────────────────────────────

    /** Set once Sameday has refused a recipient-paid shipment, so the settings screen can warn. */
    const NO_RECIPIENT_PAY = 'bgcouriers_sameday_no_recipient_pay';

    public function create_label(\WC_Order $order): BGCouriers_Label {
        $method = (string) $order->get_meta('_bgcouriers_method');
        $recipient_pays = self::service_payer('sameday', $order) === 'recipient';
        try {
            $resp = $this->post_json('/api/awb', self::build_awb_body($order, $this->service_id($method), $this->pickup_point_id()));
        } catch (BGCouriers_Api_Exception $e) {
            // A contract without recipient payment rejects awbPayment and creates no waybill; remember it for the settings screen.
            if (strpos($e->getMessage(), 'awbPayment') !== false) {
                update_option(self::NO_RECIPIENT_PAY, 'yes');
                throw new BGCouriers_Api_Exception(esc_html__(
                    'Sameday refused "the recipient pays the delivery" - this account is not contracted for it. Turn on "Delivery in the order total" for Sameday, or ask Sameday to enable recipient payment.',
                    'bg-couriers'
                ));
            }
            throw $e;
        }
        if ($recipient_pays && get_option(self::NO_RECIPIENT_PAY, '') !== '') { delete_option(self::NO_RECIPIENT_PAY); }
        return new BGCouriers_Label(self::parse_awb_id($resp));
    }

    public static function build_awb_body(\WC_Order $order, int $service_id = 0, int $pickup_point = 0): array {
        $parcel_n = BGCouriers_Order::parcels($order);
        $insured  = BGCouriers_Order::insurance($order);
        $method = (string) $order->get_meta('_bgcouriers_method');
        $w      = max(0.1, self::order_weight_kg($order));
        $is_cod = $order->get_payment_method() === 'cod';
        $payer  = self::service_payer('sameday', $order);
        $sid    = (int) $order->get_meta('_bgcouriers_site_id');
        $county = class_exists('BGCouriers_Nomenclature') ? (string) (BGCouriers_Nomenclature::city_by_id('sameday', $sid)['region'] ?? '') : '';
        $addr   = trim((string) $order->get_meta('_bgcouriers_street_name') . ' ' . (string) $order->get_meta('_bgcouriers_street_no'));
        $body = [
            'pickupPoint'    => $pickup_point ?: (int) get_option('bgcouriers_sameday_pickup_point', 0),
            'service'        => (string) ($service_id ?: ''),
            'awbPayment'     => $payer === 'recipient' ? 2 : 1, // 1=CLIENT/sender, 2=RECIPIENT
            'packageType'    => 0,
            'packageNumber'  => $parcel_n,
            'packageWeight'  => $w,
            'cashOnDelivery' => $is_cod ? self::cod_for_payer($order, $payer) : 0,
            'insuredValue'   => $insured,
            'thirdPartyPickup' => 0,
            // The AWB remark is the only place Sameday takes a contents description.
            'observation'    => BGCouriers_Settings::shipment_contents(),
            // clientInternalReference must be unique forever, even across cancelled AWBs, hence the timestamp.
            'clientInternalReference' => $order->get_order_number() . '-' . time(),
            'awbRecipient'   => array_filter([
                'name'        => $order->get_formatted_billing_full_name(),
                'phoneNumber' => (string) $order->get_billing_phone(),
                'email'       => BGCouriers_Settings::label_email($order),
                'personType'  => 0, // individual
                'city'         => $sid,
                'countyString' => $county,
                'address'      => $addr !== '' ? $addr : '-',
                'postalCode'   => (string) $order->get_meta('_bgcouriers_post_code'),
            ], static function ($v) { return $v !== ''; }),
            'parcels'        => array_map(
                static function ($kg) use ($order) { return array_merge(['weight' => $kg], self::parcel_dims($order)); },
                BGCouriers_Order::parcel_weights($w, $parcel_n)
            ),
        ];
        $office = (int) $order->get_meta('_bgcouriers_office_id');
        if ($method === 'automat')    { $body['lockerLastMile'] = $office; }
        elseif ($method === 'office') { $body['oohLastMile']    = $office; }
        return $body;
    }

    public static function parse_awb_id(array $resp): string {
        return (string) ($resp['awbNumber'] ?? $resp['awbCost']['awbNumber'] ?? '');
    }


    public function multi_parcel(): bool { return true; }
    public function label_formats(): array { return ['A6', 'A4']; }

    public function get_label_pdf(string $waybill, string $format = ''): string {
        $url = $this->base . '/api/awb/download/' . rawurlencode($waybill);
        if (in_array($format, ['A6', 'A4'], true)) { $url = add_query_arg('type', $format, $url); }
        return $this->fetch_pdf($url, ['X-AUTH-TOKEN' => $this->auth_token()], 'Sameday');
    }

    public function cancel_label(string $waybill): bool {
        $r = wp_remote_request($this->base . '/api/awb/' . rawurlencode($waybill), [
            'method' => 'DELETE', 'timeout' => 30, 'headers' => ['X-AUTH-TOKEN' => $this->auth_token()],
        ]);
        if (is_wp_error($r)) {
            /* translators: %s: the transport error. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The request failed: %s', 'bg-couriers'), $r->get_error_message())));
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        if ($code < 300) { return true; }
        // 404 counts as done: a shipment Sameday does not have is one nobody is coming for.
        if ($code === 404) { return true; }
        /* translators: %s: the courier's own error text, or the HTTP status. */
        throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The request failed: %s', 'bg-couriers'), 'HTTP ' . $code . ': ' . self::error_text((string) wp_remote_retrieve_body($r)))));
    }

    public function is_cancelled(string $waybill): bool {
        try {
            $t = $this->track($waybill);
        } catch (\Exception $e) {
            return strpos($e->getMessage(), '404') !== false;
        }
        return $t->stage() === 'cancelled' || BGCouriers_Tracking::reads_cancelled($t->human());
    }

    // ── Tracking ─────────────────────────────────────────────────────────────

    public function track(string $waybill): BGCouriers_Tracking {
        return self::parse_tracking($this->get_json('/api/client/awb/' . rawurlencode($waybill) . '/status'), $waybill);
    }

    /** expeditionStatus.status is already localised Bulgarian; expeditionSummary carries the delivered/canceled flags. */
    public static function parse_tracking(array $resp, string $waybill): BGCouriers_Tracking {
        $events = [];
        foreach ((array) ($resp['expeditionHistory'] ?? $resp['awbHistory'] ?? []) as $h) {
            if (!is_array($h)) { continue; }
            $events[] = [
                'code' => (string) ($h['statusId'] ?? ''),
                'name' => (string) ($h['status'] ?? $h['statusState'] ?? $h['reason'] ?? ''),
                'date' => (string) ($h['statusDate'] ?? $h['date'] ?? ''),
            ];
        }
        // Sameday returns history newest first; everything downstream reads the last entry as current.
        usort($events, static function ($a, $b) {
            return strcmp((string) $a['date'], (string) $b['date']) ?: 0;
        });
        // Same-second entries keep API order (newest first), so break ties on statusId, which grows with the lifecycle.
        usort($events, static function ($a, $b) {
            $d = strcmp((string) $a['date'], (string) $b['date']);
            return $d !== 0 ? $d : ((int) $a['code'] <=> (int) $b['code']);
        });

        $cur     = (array) ($resp['expeditionStatus'] ?? []);
        $summary = (array) ($resp['expeditionSummary'] ?? []);
        $status  = (string) ($cur['status'] ?? ($events ? $events[count($events) - 1]['name'] : 'unknown'));
        $phase = !empty($summary['delivered']) ? 'DELIVERED' : (!empty($summary['canceled']) ? 'CANCELLED' : '');
        return new BGCouriers_Tracking($waybill, $status, $events, $phase, self::handover($status), true);
    }

    /** Whether the courier holds the parcel: two wordings say it is still with the sender; null means not said. */
    public static function handover(string $status): ?bool {
        $s = function_exists('mb_strtolower') ? mb_strtolower($status) : strtolower($status);
        foreach (['взимане от подател', 'създадена товарителница'] as $k) {
            if (strpos($s, $k) !== false) { return false; }
        }
        return null;
    }

    public function tracking_url(string $waybill): string {
        return 'https://sameday.bg/status-na-pratkata/?awb=' . rawurlencode($waybill);
    }
}
