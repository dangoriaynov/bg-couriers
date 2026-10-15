<?php
defined('ABSPATH') || exit;

/** Express One (Bulgaria) courier adapter: every answer is HTTP 200, `status` decides; over 60 requests a minute blocks the IP for 30 minutes. */
class BGCouriers_Expressone extends BGCouriers_Abstract_Courier implements BGCouriers_Courier_Interface {
    const BASE            = 'https://system.expressone.bg/api/web';
    const BG_COUNTRY_ID   = 100;
    const TRACK_URL       = 'https://expressone.bg/bg/tracking/';
    /** Their limit is 60/min; ours is lower on purpose. */
    const CALLS_PER_MIN   = 45;

    /** Express One's own location kinds: 2 = its depot, 3 = a partner counter (PUP), 4 = a locker (EXOBOX). */
    const LOCATION_OFFICE = [2, 3];
    const LOCATION_LOCKER = 4;

    private $config;
    private $user;
    private $pass;

    public function __construct(array $config) {
        $this->config = $config;
        $this->user   = (string) ($config['username'] ?? '');
        $this->pass   = (string) ($config['password'] ?? '');
    }

    public function id(): string { return 'expressone'; }
    public function label(): string { return 'Express One'; }

    public function credential_hint(): array {
        return [
            'intro' => __('Express One issues API access to contract clients, and starts you on a test environment before the live one.', 'bg-couriers'),
            'steps' => [
                __('Have (or open) an Express One business contract.', 'bg-couriers'),
                __('Ask your account manager for REST API access. They issue a username and password for the API - these are NOT your my.expressone.bg login.', 'bg-couriers'),
                __('Ask for the id of the address the courier collects from; it appears in "Send parcels from" below once the credentials validate.', 'bg-couriers'),
                __('Enter the username and password below, click Validate, then Sync. Test credentials are usually issued first, and the live ones once the integration works.', 'bg-couriers'),
            ],
            'receive'   => __('API username + password (+ the address parcels are collected from)', 'bg-couriers'),
            'url_label' => __('Express One API documentation:', 'bg-couriers'),
            'url'       => 'https://system.expressone.bg/api/web/site/documentation',
        ];
    }

    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote', 'pickup']; }

    // ── The envelope ─────────────────────────────────────────────────────────

    /** The `data` of a successful answer; a refusal is HTTP 200 with `status` 0. */
    public static function unwrap(array $resp): array {
        if (empty($resp['status'])) {
            $msg = self::message_text($resp['message'] ?? '');
            throw new BGCouriers_Api_Exception(esc_html('Express One: '
                . ($msg !== '' ? $msg : __('the request was refused', 'bg-couriers'))));
        }
        $d = $resp['data'] ?? [];
        return is_array($d) ? $d : [];
    }

    /**
     * The `message` of a refusal as words; it may be a nested object of field => messages.
     *
     * @param mixed $m
     */
    public static function message_text($m): string {
        if (is_string($m) || is_numeric($m)) { return trim((string) $m); }
        if (!is_array($m)) { return ''; }
        $out = [];
        foreach ($m as $v) {
            $t = self::message_text($v);
            if ($t !== '') { $out[] = $t; }
        }
        return implode('; ', $out);
    }

    /** The same as a plain list: some endpoints answer an object keyed "1","2",..., others an array. */
    public static function rows(array $resp): array { return array_values(self::unwrap($resp)); }

    // ── Calling ──────────────────────────────────────────────────────────────

    /** Transient key per account. */
    private function key(string $what): string {
        return 'bgcouriers_e1_' . $what . '_' . substr(md5($this->user), 0, 12);
    }

    /** Refuses rather than sleeps when the minute's call budget is spent; a block would last 30 minutes. */
    private function guard(): void {
        $k = $this->key('rate_' . gmdate('YmdHi'));
        $n = (int) get_transient($k);
        if ($n >= self::CALLS_PER_MIN) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s has had too many requests this minute. Stopping here rather than risking their 30-minute block.', 'bg-couriers'), 'Express One')));
        }
        set_transient($k, $n + 1, 120);
    }

    /** The access token, from the transient unless $fresh. */
    private function token(bool $fresh = false): string {
        $k = $this->key('token');
        if (!$fresh) {
            $t = get_transient($k);
            if (is_string($t) && $t !== '') { return $t; }
        }
        if ($this->user === '' || $this->pass === '') {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('No credentials are saved for %s.', 'bg-couriers'), 'Express One')));
        }
        $auth = self::unwrap($this->http('/1/authorize', ['username' => $this->user, 'password' => $this->pass], ''));
        $code = (string) ($auth['authorization_code'] ?? '');
        if ($code === '') {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s did not return an authorization code, so the credentials were refused.', 'bg-couriers'), 'Express One')));
        }
        $tok = self::unwrap($this->http('/1/accesstoken', ['authorization_code' => $code], ''));
        $val = (string) ($tok['access_token'] ?? '');
        if ($val === '') {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s did not return an access token, so the credentials were refused.', 'bg-couriers'), 'Express One')));
        }
        // Keep our TTL comfortably inside their expires_at.
        $ttl = max(300, min((int) ($tok['expires_at'] ?? 0) - time() - 600, DAY_IN_SECONDS));
        set_transient($k, $val, $ttl);
        return $val;
    }

    /** One authenticated call; "Invalid Access token" earns one retry with a fresh token. */
    protected function call(string $path, array $body = []): array {
        $raw = $this->http($path, $body, $this->token());
        if (empty($raw['status']) && stripos((string) ($raw['message'] ?? ''), 'access token') !== false) {
            $raw = $this->http($path, $body, $this->token(true));
        }
        return $raw;
    }

    /** Seam: overridden in tests. */
    protected function http(string $path, array $body, string $token) {
        $this->guard();
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== '') { $headers['X-Access-Token'] = $token; }
        $res = wp_remote_post(self::BASE . $path, [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => wp_json_encode($body),
        ]);
        if (is_wp_error($res)) {
            /* translators: 1: courier name, 2: the error the connection reported. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s could not be reached: %2$s', 'bg-couriers'), 'Express One', $res->get_error_message())));
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($data)) {
            /* translators: %s: the API address that answered. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The answer from %s is not valid JSON.', 'bg-couriers'), $path)));
        }
        return $data;
    }

    public function check_credentials(): bool {
        return $this->token(true) !== '';
    }

    // ── Nomenclature ─────────────────────────────────────────────────────────

    public function fetch_cities(string $country = ''): array {
        return self::parse_cities($this->call('/1/list-city', ['country_id' => self::country_id($country)]));
    }

    /**
     * Towns, one row per town: the API answers town x postcode, so the lowest postcode stands for the town.
     *
     * @return array<int,array{city_id:int,name:string,post_code:string,region:string}>
     */
    public static function parse_cities(array $resp): array {
        $best = [];
        foreach (self::rows($resp) as $c) {
            $id = (int) ($c['ID'] ?? 0);
            if ($id <= 0) { continue; }
            $pc = trim((string) ($c['POSTCODE'] ?? ''));
            if (isset($best[$id])) {
                $have = $best[$id]['post_code'];
                // An empty postcode is not a postcode; any real one replaces it.
                if ($have !== '' && ($pc === '' || $pc >= $have)) { continue; }
            }
            $best[$id] = [
                'city_id'   => $id,
                'name'      => trim((string) ($c['NAME'] ?? '')),
                'post_code' => $pc,
                'region'    => trim((string) ($c['DISTRICT'] ?? '')),
            ];
        }
        return array_values($best);
    }

    /** Every point in one call; $city_id filters what came back. */
    public function fetch_offices(int $city_id, string $country = ''): array {
        $rows = self::parse_offices($this->call('/1/list-office', ['country_id' => self::country_id($country)]));
        if ($city_id <= 0) { return $rows; }
        return array_values(array_filter($rows, static function ($o) use ($city_id) {
            return (int) $o['city_id'] === $city_id;
        }));
    }

    /**
     * Points -> framework rows; LOCATION_TYPE 4 is a locker, 2 and 3 are offices. `ID` may be int or string.
     *
     * @return array<int,array{office_id:int,code:string,city_id:int,type:string,name:string,address:string,lat:float,lng:float}>
     */
    public static function parse_offices(array $resp): array {
        $out = [];
        foreach (self::rows($resp) as $o) {
            $id = (int) ($o['ID'] ?? 0);
            if ($id <= 0) { continue; }
            $kind = (int) ($o['LOCATION_TYPE'] ?? 0);
            $out[] = [
                'office_id' => $id,
                'code'      => (string) $id,
                'city_id'   => (int) ($o['CITY_ID'] ?? 0),
                'type'      => $kind === self::LOCATION_LOCKER ? 'automat' : 'office',
                'name'      => trim((string) ($o['NAME'] ?? '')),
                'address'   => trim((string) ($o['ADDRESS'] ?? '')),
                'lat'       => (float) ($o['LATITUDE'] ?? 0),
                'lng'       => (float) ($o['LONGITUDE'] ?? 0),
            ];
        }
        return $out;
    }

    /** Streets are never synced; the API takes no search term, so filtering happens here. */
    public function search_streets(int $city_id, string $term, string $country = ''): array {
        $rows = $this->streets_of($city_id);
        $term = trim(function_exists('mb_strtolower') ? mb_strtolower($term) : strtolower($term));
        if ($term === '') { return array_slice($rows, 0, BGCouriers_Settings::DROPDOWN_LIMIT); }
        $hit = array_values(array_filter($rows, static function ($s) use ($term) {
            $n = function_exists('mb_strtolower') ? mb_strtolower($s['name']) : strtolower($s['name']);
            return strpos($n, $term) !== false;
        }));
        return array_slice($hit, 0, BGCouriers_Settings::DROPDOWN_LIMIT);
    }

    /**
     * Streets -> checkout rows; the type belongs in the label.
     *
     * @return array<int,array{id:int,name:string,type:string,label:string}>
     */
    public static function parse_streets(array $resp): array {
        $out = [];
        foreach (self::rows($resp) as $s) {
            $id = (int) ($s['ID'] ?? 0);
            $name = trim((string) ($s['NAME'] ?? ''));
            if ($id <= 0 || $name === '') { continue; }
            $type = trim((string) ($s['TYPE_NAME'] ?? ''));
            $out[] = [
                'id'    => $id,
                'name'  => $name,
                'type'  => $type,
                'label' => trim($type . ' ' . $name),
            ];
        }
        return $out;
    }

    /**
     * The id of a street the town knows by that name; the stored id wins, then the type, else the first of several.
     *
     * @return array{id:int,label:string,ambiguous:bool}
     */
    public function street_match(int $city_id, string $name, string $type = '', int $street_id = 0): array {
        $want = self::fold($name);
        if ($city_id <= 0 || ($want === '' && $street_id <= 0)) { return ['id' => 0, 'label' => '', 'ambiguous' => false]; }
        $rows = $this->streets_of($city_id);
        if ($street_id > 0) {
            foreach ($rows as $r) {
                if ((int) $r['id'] === $street_id && ($want === '' || self::fold($r['name']) === $want)) {
                    return ['id' => $street_id, 'label' => (string) $r['label'], 'ambiguous' => false];
                }
            }
        }
        $hits = BGCouriers_Street::exact($rows, $name);
        if (count($hits) > 1 && trim($type) !== '') {
            $of_type = array_values(array_filter($hits, static function ($r) use ($type) { return BGCouriers_Street::same_type((string) $r['type'], $type); }));
            if ($of_type) { $hits = $of_type; }
        }
        if (!$hits) { return ['id' => 0, 'label' => '', 'ambiguous' => false]; }
        return ['id' => (int) $hits[0]['id'], 'label' => (string) $hits[0]['label'], 'ambiguous' => count($hits) > 1];
    }

    /** /1/create-bol refuses RECEIVER_STREET without RECEIVER_STREET_ID. */
    public function street_list_only(): bool { return true; }

    /** No COD to a locker; their API accepts it anyway, so the refusal has to come from here. */
    public function no_cod_methods(): array { return ['automat']; }

    /**
     * A town's whole street list, cached for a day; the key carries the nomenclature generation so "Sync now" retires it.
     *
     * @return array<int,array{id:int,name:string,type:string,label:string}>
     */
    private function streets_of(int $city_id): array {
        $gen = class_exists('BGCouriers_Ajax') ? BGCouriers_Ajax::nomenclature_generation('expressone') : '';
        $k = $this->key('streets_' . $city_id . ($gen !== '' ? '_' . substr(md5($gen), 0, 8) : ''));
        $c = get_transient($k);
        if (is_array($c)) { return $c; }
        $rows = self::parse_streets($this->call('/1/list-street', ['city_id' => $city_id]));
        if ($rows) { set_transient($k, $rows, DAY_IN_SECONDS); }
        return $rows;
    }

    /** Case and spacing are not part of a street's identity. */
    private static function fold(string $s): string {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /**
     * The account's own addresses for the "send from" setting; cached for a day.
     *
     * @return array<int,string> id => label.
     */
    public function sender_objects(): array {
        $k = $this->key('objects');
        $c = get_transient($k);
        if (is_array($c)) { return $c; }
        $out = [];
        foreach (self::rows($this->call('/1/list-object')) as $o) {
            $id = (int) ($o['ID'] ?? 0);
            if ($id <= 0) { continue; }
            $name = trim((string) ($o['OBJECT_NAME'] ?? $o['OBJECT'] ?? ''));
            $city = trim((string) ($o['OBJECT_CITY'] ?? ''));
            $addr = trim(trim((string) ($o['OBJECT_STREET'] ?? '')) . ' ' . trim((string) ($o['OBJECT_STREET_NO'] ?? '')));
            $out[$id] = trim($name . ' - ' . trim($city . ' ' . $addr), ' -');
        }
        if ($out) { set_transient($k, $out, DAY_IN_SECONDS); }
        return $out;
    }

    /** Bulgaria only. */
    private static function country_id(string $iso): int {
        $c = strtoupper(trim($iso));
        if ($c === '' || $c === 'BG') { return self::BG_COUNTRY_ID; }
        /* translators: 1: courier name, 2: country code. */
        throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s does not deliver to %2$s.', 'bg-couriers'), 'Express One', $c)));
    }

    // ── Price ────────────────────────────────────────────────────────────────

    public function quote(array $shipment): BGCouriers_Quote {
        $data = self::unwrap($this->call('/1/calculate-bol', self::build_calculate_body($shipment)));
        return self::parse_price($data, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
    }

    /** TAKE_OFFICE_ID is part of the price: office and locker deliveries are cheaper. */
    public static function build_calculate_body(array $s): array {
        $method = (string) ($s['method'] ?? 'address');
        $body = [
            'WEIGHT'              => max(0.1, (float) ($s['weight_kg'] ?? 1)),
            'RECEIVER_COUNTRY_ID' => self::country_id((string) ($s['country'] ?? '')),
            'RECEIVER_CITY_ID'    => (int) ($s['city_id'] ?? $s['site_id'] ?? 0),
        ];
        if ($method !== 'address' && (int) ($s['office_id'] ?? 0) > 0) {
            $body['TAKE_OFFICE_ID'] = (int) $s['office_id'];
        }
        // Collecting money is charged for, so the COD must reach the quote.
        $cod = (float) ($s['cod_amount'] ?? 0);
        if ($cod > 0) { $body['COD'] = round($cod, 2); }
        $ins = self::insured_value($s);
        if ($ins > 0) { $body['INSURANCE'] = $ins; }
        return $body;
    }

    /** Every COD shipment carries a declared value (their rule, not enforced by the API); the merchant's higher figure wins. */
    private static function insured_value(array $s): float {
        $ins = (float) ($s['insurance'] ?? 0);
        $cod = (float) ($s['cod_amount'] ?? 0);
        return round(max($ins, $cod > 0 ? $cod : 0.0), 2);
    }

    /** TOTAL includes VAT; WooCommerce taxes net costs itself. */
    public static function parse_price(array $data, string $currency): BGCouriers_Quote {
        $total = (float) ($data['TOTAL'] ?? 0);
        $vat   = (float) ($data['TAX_VAT'] ?? 0);
        if ($total <= 0) {
            throw new BGCouriers_Api_Exception(esc_html(
                /* translators: %s: courier name. */
                sprintf(__('%s sent no price in its answer.', 'bg-couriers'), 'Express One')
                . (($data['ERROR_MESSAGE'] ?? null) ? ' (' . $data['ERROR_MESSAGE'] . ')' : '')));
        }
        return new BGCouriers_Quote(round($total - $vat, 2), round($vat, 2), $currency, 'live');
    }

    // ── The waybill ──────────────────────────────────────────────────────────

    public function create_label(\WC_Order $order): BGCouriers_Label {
        $s = self::shipment_of($order);
        $problems = [];
        if (($s['method'] ?? '') === 'address') {
            $hit = $this->street_match((int) $s['city_id'], (string) $s['street'], (string) $s['street_type'], (int) $s['street_id']);
            if ($hit['id'] <= 0) {
                throw new BGCouriers_Api_Exception(esc_html(sprintf(
                    /* translators: %s: the street as it is written on the order. */
                    __('Express One does not list a street called "%s" in this town, and it refuses an address without one. Edit the delivery address and pick the street from the list.', 'bg-couriers'),
                    (string) $s['street'])));
            }
            $s['street_id'] = $hit['id'];
            if ($hit['ambiguous']) {
                $problems[] = sprintf(
                    /* translators: %s: the street as the courier spells it. */
                    __('More than one street in this town is called that; the parcel goes to "%s".', 'bg-couriers'), $hit['label']);
            }
        }
        $label = self::parse_created(self::unwrap($this->call('/1/create-bol', self::build_shipment_body($s, $order))));
        return $problems ? new BGCouriers_Label($label->waybill, $label->pdf, $problems) : $label;
    }

    /** The order's shipment in the keys the body builder reads; the town's name comes from the synced nomenclature. */
    private static function shipment_of(\WC_Order $order): array {
        $payer = self::service_payer('expressone', $order);
        $sid   = (int) $order->get_meta('_bgcouriers_site_id');
        $city  = class_exists('BGCouriers_Nomenclature') ? BGCouriers_Nomenclature::city_by_id('expressone', $sid) : null;
        return [
            'method'       => (string) $order->get_meta('_bgcouriers_method'),
            'city_id'      => $sid,
            'city_name'    => (string) ($city['name'] ?? ''),
            'post_code'    => (string) ($city['post_code'] ?? ''),
            'office_id'    => (int) $order->get_meta('_bgcouriers_office_id'),
            'street'       => (string) $order->get_meta('_bgcouriers_street_name'),
            'street_type'  => (string) $order->get_meta('_bgcouriers_street_type'),
            'street_id'    => (int) $order->get_meta('_bgcouriers_street_id'),
            'street_no'    => (string) $order->get_meta('_bgcouriers_street_no'),
            'weight_kg'    => self::order_weight_kg($order),
            'parcels'      => max(1, (int) $order->get_meta('_bgcouriers_parcels')),
            'insurance'    => (float) $order->get_meta('_bgcouriers_insurance'),
            'payer'        => $payer,
            'cod_amount'   => $order->get_payment_method() === 'cod' ? self::cod_for_payer($order, $payer) : 0.0,
            'order_number' => (string) $order->get_order_number(),
        ];
    }

    /** RECEIVER_CITY (the town's name) is required even beside RECEIVER_CITY_ID. */
    public static function build_shipment_body(array $s, ?\WC_Order $order = null): array {
        $method = (string) ($s['method'] ?? 'address');
        $body = [
            'SEND_OFFICE_ID'      => (int) get_option('bgcouriers_expressone_sender_object', 0),
            'RECEIVER_COUNTRY_ID' => self::country_id((string) ($s['country'] ?? '')),
            'RECEIVER_CITY_ID'    => (int) ($s['city_id'] ?? 0),
            'RECEIVER_CITY'       => (string) ($s['city_name'] ?? ''),
            'CONTENT'             => BGCouriers_Settings::shipment_contents(),
            'WEIGHT'              => max(0.1, (float) ($s['weight_kg'] ?? 1)),
            'PACK_COUNT'          => max(1, (int) ($s['parcels'] ?? 1)),
            'CLIENT_REFERENCE'    => (string) ($s['order_number'] ?? ''),
            // 0 = the sender pays the delivery, 1 = the recipient.
            'PAYER'               => ($s['payer'] ?? 'sender') === 'recipient' ? 1 : 0,
            // 0 = whatever this account is set to print.
            'PDF_FORMAT'          => 0,
        ];
        $pk = trim((string) ($s['post_code'] ?? ''));
        if ($pk !== '') { $body['RECEIVER_PK'] = $pk; }
        if ($order) {
            $body['RECEIVER_NAME']  = $order->get_formatted_billing_full_name();
            $body['RECEIVER_PHONE'] = (string) $order->get_billing_phone();
        } else {
            $body['RECEIVER_NAME']  = (string) ($s['receiver_name'] ?? '');
            $body['RECEIVER_PHONE'] = (string) ($s['receiver_phone'] ?? '');
        }
        if ($method !== 'address') {
            $body['TAKE_OFFICE_ID'] = (int) ($s['office_id'] ?? 0);
        } else {
            $body['RECEIVER_STREET']    = (string) ($s['street'] ?? '');
            $body['RECEIVER_STREET_NO'] = (string) ($s['street_no'] ?? '');
            if ((int) ($s['street_id'] ?? 0) > 0) { $body['RECEIVER_STREET_ID'] = (int) $s['street_id']; }
        }
        $cod = (float) ($s['cod_amount'] ?? 0);
        if ($cod > 0) { $body['COD'] = round($cod, 2); }
        $ins = self::insured_value($s);
        if ($ins > 0) { $body['INSURANCE'] = $ins; }
        // FIX_HOUR, SATURDAY_DELIVERY, RETURN_RECEIPT and FRAGILE are accepted by the API but not offered, at their request.
        // Never open-before-pay for a locker: nobody is there to supervise it.
        if ($method !== 'automat' && in_array(get_option('bgcouriers_open_before_pay', 'no'), ['open', 'test'], true)) {
            $body['CHECK_BEFORE_PAY'] = 1;
        }
        return $body;
    }

    /** The label comes back inside this answer, base64, the same bytes /1/print-bol would return. */
    public static function parse_created(array $data): BGCouriers_Label {
        $waybill = trim((string) ($data['BILLOFLADING'] ?? ''));
        if ($waybill === '') {
            throw new BGCouriers_Api_Exception(esc_html(
                /* translators: %s: courier name. */
                sprintf(__('%s sent no waybill number in its answer.', 'bg-couriers'), 'Express One')
                . (($data['ERROR_MESSAGE'] ?? null) ? ' (' . $data['ERROR_MESSAGE'] . ')' : '')));
        }
        $pdf = (string) ($data['LABEL'] ?? '');
        $pdf = $pdf !== '' ? (string) base64_decode($pdf, true) : '';
        return new BGCouriers_Label($waybill, $pdf);
    }

    public function multi_parcel(): bool { return true; }
    public function label_formats(): array { return []; }

    public function get_label_pdf(string $waybill, string $format = ''): string {
        $data = self::unwrap($this->call('/1/print-bol', [
            'bol_id'       => $waybill,
            'by_reference' => 0,
            'pdfformat'    => 0,
        ]));
        return self::assert_pdf((string) base64_decode((string) ($data['LABEL'] ?? ''), true), 'Express One');
    }

    // ── Cancelling ───────────────────────────────────────────────────────────

    public function cancel_label(string $waybill): bool {
        return self::parse_cancel(self::unwrap($this->call('/1/cancel-bol', ['bol_id' => $waybill, 'by_reference' => 0])));
    }

    /** A second cancel answers empty data rather than SUCCESS, and the waybill is cancelled either way. */
    public static function parse_cancel(array $data): bool {
        if ($data === []) { return true; }
        return (int) ($data['SUCCESS'] ?? 0) === 1;
    }

    /** STATUS_ID 7 is cancelled. */
    public function is_cancelled(string $waybill): bool {
        try {
            $d = self::unwrap($this->call('/1/bol-info', ['bol_id' => $waybill, 'by_reference' => 0]));
            return (int) ($d['STATUS_ID'] ?? -1) === 7;
        } catch (BGCouriers_Api_Exception $e) { return false; }
    }

    // ── Tracking ─────────────────────────────────────────────────────────────

    public function track(string $waybill): BGCouriers_Tracking {
        return self::parse_tracking(self::unwrap($this->call('/1/track-bol', ['bol_id' => $waybill, 'by_reference' => 0])), $waybill);
    }

    /**
     * The parcel's history. A null STATUS_ID ("N/A") means nothing has happened yet; a 6 (delivered) or 12
     * (returned) anywhere in the history outranks later document events such as 7 cancelled or 10 finalised.
     * 101 carries its meaning in the substatus text, so it gets no phase.
     */
    public static function parse_tracking(array $data, string $waybill): BGCouriers_Tracking {
        $events = [];
        foreach ($data as $e) {
            if (!is_array($e) || !isset($e['STATUS_ID']) || $e['STATUS_ID'] === null) { continue; }
            $name = trim((string) ($e['STATUS_NAME'] ?? ''));
            $sub  = trim((string) ($e['SUBSTATUS_NAME'] ?? ''));
            if ($sub !== '') { $name = $name !== '' ? $name . ' - ' . $sub : $sub; }
            $events[] = [
                'code' => (string) (int) $e['STATUS_ID'],
                'name' => $name,
                'date' => (string) ($e['DATE'] ?? ''),
            ];
        }
        usort($events, static function ($a, $b) { return strcmp($a['date'], $b['date']); });
        $verdict = [];
        foreach (['6', '12'] as $terminal) {
            foreach ($events as $e) {
                if ($e['code'] === $terminal) { $verdict = $e; break 2; }
            }
        }
        if (!$verdict) { $verdict = $events ? end($events) : []; }
        $code  = (string) ($verdict['code'] ?? '');
        $phase = ($code !== '' && $code !== '101') ? 'expressone_' . $code : '';
        return new BGCouriers_Tracking($waybill, (string) ($verdict['name'] ?? ''), $events, $phase, null, true);
    }

    /** Their tracking page fills the number in; a query string does not work. */
    public function tracking_url(string $waybill): string {
        return self::TRACK_URL . rawurlencode($waybill);
    }

    // ── Collection ───────────────────────────────────────────────────────────

    /** Express One takes a count, a weight and a time, not the waybills, and answers no request number; ERROR 0 is success. */
    public function request_pickup(array $waybills, array $opts): string {
        $from = trim((string) ($opts['from'] ?? ''));
        $to   = trim((string) ($opts['to'] ?? ''));
        $d = self::unwrap($this->call('/1/request-courier', [
            'count'          => max(1, (int) ($opts['packs'] ?? count($waybills))),
            'weight'         => max(0.1, (float) ($opts['weight_kg'] ?? count($waybills))),
            'readiness'      => $from !== '' && $to !== '' ? $from . '-' . $to : $from,
            'take_office_id' => (int) get_option('bgcouriers_expressone_sender_object', 0),
        ]));
        if ((int) ($d['ERROR'] ?? 0) !== 0) {
            throw new BGCouriers_Api_Exception(esc_html(sprintf(
                /* translators: 1: courier name, 2: the courier's own refusal text. */
                __('%1$s refused the pickup request: %2$s', 'bg-couriers'), 'Express One',
                (string) ($d['ERROR_MESSAGE'] ?: __('no reason given', 'bg-couriers')))));
        }
        return trim((string) ($d['REQUEST'] ?? ''));
    }

    public function enable_problems(): array {
        $problems = parent::enable_problems();
        $this->need_option($problems, 'bgcouriers_expressone_sender_object',
            __('No "send from" address is chosen for Express One.', 'bg-couriers'),
            __('Pick one of your Express One addresses on this tab - every waybill is collected from it.', 'bg-couriers'));
        return $problems;
    }
}
