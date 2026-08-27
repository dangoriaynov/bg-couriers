<?php
defined('ABSPATH') || exit;

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
class BGCouriers_Sameday extends BGCouriers_Abstract_Courier implements BGCouriers_Courier_Interface {
    // Bulgarian instance hosts (per-country envs, from the official samedaycourier-shipping plugin's
    // env map: BG prod api.sameday.bg, BG demo sameday-api-bg.demo.zitec.com; the .ro pair is Romania's).
    const PROD = 'https://api.sameday.bg';
    const DEMO = 'https://sameday-api-bg.demo.zitec.com';

    /** @var array */
    private $config;

    /** @var string */
    private $base;

    public function __construct(array $config) {
        $this->config = $config;
        $demo = (defined('BGCOURIERS_SAMEDAY_DEMO') && BGCOURIERS_SAMEDAY_DEMO)
            || (function_exists('get_option') && get_option('bgcouriers_sameday_live', 'yes') !== 'yes');
        $this->base = $demo ? self::DEMO : self::PROD;
    }

    public function id(): string { return 'sameday'; }
    public function label(): string { return 'Sameday'; }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }

    /** Stable Sameday service codes per delivery type (24H home / Locker NextDay / PUDO). */
    const SERVICE_CODES = ['address' => '24', 'automat' => 'LN', 'office' => 'PP'];

    /**
     * The account's service id for a delivery type, discovered from /api/client/services by its stable
     * serviceCode (cached a day). The merchant never types ids - Sameday's own plugin imports them too.
     */
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

    /** The sender pickup point: the option when set, else the account's DEFAULT pickup point (cached a day). */
    public function pickup_point_id(): int {
        $opt = (int) get_option('bgcouriers_sameday_pickup_point', 0);
        if ($opt > 0) { return $opt; }
        $ckey = $this->cache_key('pickup_default');
        $id   = (int) get_transient($ckey);
        if ($id > 0) { return $id; }
        foreach ($this->get_paged('/api/client/pickup-points') as $pp) {
            if (!empty($pp['defaultPickupPoint'])) { $id = (int) ($pp['id'] ?? 0); break; }
            if (!$id) { $id = (int) ($pp['id'] ?? 0); } // fall back to the first one
        }
        if ($id > 0) { set_transient($ckey, $id, DAY_IN_SECONDS); }
        return $id;
    }

    public function enable_problems(): array {
        $p = parent::enable_problems();
        // Reported before the credential check: this one is about the contract, not the connection, and
        // it is fatal - with it unresolved Sameday creates no waybill at all for any order.
        if (get_option(self::NO_RECIPIENT_PAY, '') === 'yes' && !BGCouriers_Settings::ship_in_total('sameday')) {
            $p[] = [
                'msg' => __('Sameday does not support “the recipient pays the delivery” on this account - no waybill can be created while that is how it is set.', 'bg-couriers'),
                'fix' => __('Turn on “Delivery in the order total” for Sameday, or ask Sameday to allow recipient payment on your contract.', 'bg-couriers'),
            ];
        }
        if (!$this->check_credentials()) { return $p; } // creds problems are already reported by the parent
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
        } catch (\Exception $e) { /* API hiccup - credential/API problems surface elsewhere */ }
        return $p;
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    /**
     * Cache key for anything that belongs to ONE account on ONE environment - the auth token, the
     * discovered service map, the default pickup point. Without the account+host in the key, switching
     * the credentials or flipping Live mode kept serving the previous account's cached values: the old
     * token went to the new host and every call came back HTTP 401, and the service ids / pickup point
     * silently stayed those of the other account. Changing either now simply misses the cache.
     */
    private function cache_key(string $suffix): string {
        $who = $this->base . '|' . (string) ($this->config['username'] ?? '');
        return 'bgcouriers_sameday_' . $suffix . '_' . substr(md5($who), 0, 12);
    }

    /**
     * Return a valid X-Auth-Token, fetching a new one when absent/expired.
     *
     * Request shape confirmed from SDK:
     *   SamedayAuthenticateRequest::buildRequest() - headers X-Auth-Username / X-Auth-Password,
     *   body 'remember_me=true' (URL-encoded form).
     * Response shape confirmed from SamedayAuthenticateResponse:
     *   JSON { "token": "...", "expire_at": "YYYY-MM-DD HH:MM" }
     */
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
            throw new BGCouriers_Api_Exception(esc_html('Sameday auth transport error: ' . $r->get_error_message()));
        }
        $body = json_decode(wp_remote_retrieve_body($r), true);
        $tok  = (string) ($body['token'] ?? '');
        if ($tok === '') {
            throw new BGCouriers_Api_Exception('Sameday authentication failed: no token in response');
        }
        // expire_at is "YYYY-MM-DD HH:MM"; token TTL ~1h, refresh 10 min early.
        set_transient($key, $tok, 50 * MINUTE_IN_SECONDS);
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

    /**
     * Flatten Sameday's nested validation errors into "field: reason" lines.
     *
     * The shape is {errors: {children: {awbRecipient: {children: {phoneNumber: {errors: ["..."]}}}}}} -
     * arbitrarily deep, and only the leaves carry the message that says what to fix.
     *
     * @param array  $node  An `errors` node (or any `children` node below it).
     * @param string $path  Field path accumulated so far.
     * @return string[]     One entry per leaf error.
     */
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
        // Sameday returns 4xx/5xx with a JSON error body; without a status check those would be treated as a
        // successful (empty) response and produce a blank waybill. Throw so the caller surfaces the real error.
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) ? ($data['message'] ?? $data['error'] ?? $raw) : $raw;
            if (!is_scalar($msg)) { $msg = wp_json_encode($msg); } // nested field errors arrive as arrays
            // Sameday answers a rejected shipment with a bare "Validation Failed" and puts the actual
            // reason in errors.children.<field>.errors[] - without digging that out, the merchant is told
            // only that something was wrong, and there is no way to tell WHICH field the courier rejected.
            $fields = is_array($data) ? self::field_errors((array) ($data['errors'] ?? [])) : [];
            $msg = substr((string) $msg, 0, 300) . ($fields ? ' (' . implode('; ', array_slice($fields, 0, 6)) . ')' : '');
            throw new BGCouriers_Api_Exception(esc_html('Sameday HTTP ' . $code . ': ' . $msg));
        }
        return is_array($data) ? $data : [];
    }

    // ── BGCouriers_Courier_Interface stubs (to be filled in later tasks) ─────────────

    public function check_credentials(): bool {
        try {
            return $this->auth_token() !== '';
        } catch (\Exception $e) {
            return false;
        }
    }

    // ── Nomenclature ─────────────────────────────────────────────────────────
    // Shapes LIVE-CONFIRMED on the BG demo env (sameday-api-bg.demo.zitec.com, 2026-07-23):
    // paginated {total,currentPage,pages,perPage,data:[...]}; lockers carry lockerId, OOH points oohId.

    /** Merge every page of a paginated listing into one data[] array. */
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
        // 5398 BG localities, paginated (a bare GET returns only the first page).
        return self::parse_cities($this->get_paged('/api/geolocation/city'));
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
        $rows = self::parse_offices($this->get_paged('/api/client/lockers'), $this->get_paged('/api/client/ooh-locations'));
        return $city_id > 0
            ? array_values(array_filter($rows, static function ($o) use ($city_id) { return (int) $o['city_id'] === $city_id; }))
            : $rows;
    }

    /**
     * easyBox lockers -> 'automat', out-of-home points -> 'office'. Framework row shape.
     * Live shapes: lockers carry `lockerId`, OOH points `oohId` (no plain `id`), and the OOH listing
     * INCLUDES the easyboxes - those are deduped away so only true PUDO points become 'office'.
     */
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

    // ── Quote (live, weight-based) ───────────────────────────────────────────

    public function quote(array $shipment): BGCouriers_Quote {
        // Service + pickup point come from the ACCOUNT (auto-discovered), not from typed-in settings.
        $shipment['service_id']   = $this->service_id((string) ($shipment['method'] ?? 'address'));
        $shipment['pickup_point'] = $this->pickup_point_id();
        $resp = $this->post_json('/api/awb/estimate-cost', self::build_estimate_body($shipment));
        return self::parse_price($resp, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
    }

    /**
     * Estimate body - field names LIVE-CONFIRMED against the BG demo env (its 400 names each field):
     * packageNumber + thirdPartyPickup are required, and the recipient takes `city` (an id, NOT `cityId`)
     * plus `county`/`countyString` (the county resolves from countyString = our stored region name).
     */
    public static function build_estimate_body(array $s): array {
        // From the shipment array, never from an order: this is also called at checkout, where no order
        // exists yet. BGCouriers_Order::shipment_from_order() puts both in for the order-side callers.
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
            'awbPayment'      => 1, // client (sender) pays the delivery
            // The amount the courier collects at the door. Sameday charges for collecting it and the
            // charge is in the estimate, so a quote that always said 0 under-quoted every COD order
            // (measured: 1.30 -> 1.80 on a 50 EUR collection). The vendor's own SDK sends it on the
            // estimation request too - SamedayPostAwbEstimationRequest has cashOnDelivery beside
            // insuredValue.
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

    /**
     * estimate-cost -> BGCouriers_Quote. LIVE shape: {"amount":15.81,"currency":"Ron","time":96} (amount, not cost).
     * Only a quote already in the store's own currency is usable; anything else (the shared demo
     * tarifficator prices in RON) throws, so the pricing pipeline falls back to the reference/fixed
     * price instead of charging a foreign number.
     */
    /** Shared default parcel dimensions in Sameday's field names (width/length/height, cm). */
    protected static function parcel_dims(): array {
        $d = BGCouriers_Settings::box_dims();
        return ['width' => $d['width'], 'length' => $d['length'], 'height' => $d['height']];
    }

    public static function parse_price(array $resp, string $currency): BGCouriers_Quote {
        $amount = (float) ($resp['amount'] ?? $resp['cost'] ?? 0);
        $cur    = strtoupper((string) ($resp['currency'] ?? $currency));
        $store  = strtoupper($currency);
        if ($cur !== $store) {
            throw new BGCouriers_Api_Exception('Sameday quote currency does not match the store currency');
        }
        return new BGCouriers_Quote(round($amount, 2), 0.0, $store, 'live');
    }

    // ── Label ────────────────────────────────────────────────────────────────

    /** Set once Sameday has refused a recipient-paid shipment, so the settings screen can warn up front. */
    const NO_RECIPIENT_PAY = 'bgcouriers_sameday_no_recipient_pay';

    public function create_label(\WC_Order $order): BGCouriers_Label {
        $method = (string) $order->get_meta('_bgcouriers_method');
        $recipient_pays = self::service_payer('sameday', $order) === 'recipient';
        try {
            $resp = $this->post_json('/api/awb', self::build_awb_body($order, $this->service_id($method), $this->pickup_point_id()));
        } catch (BGCouriers_Api_Exception $e) {
            // Not every Sameday contract allows the delivery to be charged to the recipient. When it does
            // not, the API rejects awbPayment outright and NO waybill is created - every order with this
            // courier simply fails. Remember it, so the settings screen can flag the courier before the
            // merchant discovers it one unshippable order at a time, and say which setting causes it
            // instead of passing on Sameday's "The selected choice is invalid".
            if (strpos($e->getMessage(), 'awbPayment') !== false) {
                update_option(self::NO_RECIPIENT_PAY, 'yes');
                throw new BGCouriers_Api_Exception(esc_html__(
                    'Sameday refused "the recipient pays the delivery" - this account is not contracted for it. Turn on "Delivery in the order total" for Sameday, or ask Sameday to enable recipient payment.',
                    'bg-couriers'
                ));
            }
            throw $e;
        }
        // It worked with the recipient paying, so whatever we learned before no longer holds - a contract
        // can be extended, and a stale warning is its own kind of wrong.
        if ($recipient_pays && get_option(self::NO_RECIPIENT_PAY, '') !== '') { delete_option(self::NO_RECIPIENT_PAY); }
        return new BGCouriers_Label(self::parse_awb_id($resp));
    }

    public static function build_awb_body(\WC_Order $order, int $service_id = 0, int $pickup_point = 0): array {
        // How many boxes, and what they are insured for. Both were literals - 1 and 0 - so a shop sending
        // three parcels got one waybill for one, and insurance was unreachable however much the goods
        // were worth. See BGCouriers_Order.
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
            // Sameday's free-text remark on the AWB - the only place it takes a contents description
            // (POST /api/awb has observation/priceObservation/clientObservation and no contents field).
            // Same shop-wide description Speedy and Econt print.
            'observation'    => BGCouriers_Settings::shipment_contents(),
            // AWBs must carry a unique clientInternalReference - even a CANCELLED one keeps its reference
            // forever, so a bare order id would break regeneration; suffix with a timestamp.
            // The ORDER NUMBER, not the post id: they are the same on a plain shop, but a shop numbering
            // its orders through a plugin shows the merchant the number this reference is meant to be
            // matched against. Every other courier already carries get_order_number() for this reason.
            'clientInternalReference' => $order->get_order_number() . '-' . time(),
            'awbRecipient'   => array_filter([
                'name'        => $order->get_formatted_billing_full_name(),
                'phoneNumber' => (string) $order->get_billing_phone(),
                'email'       => BGCouriers_Settings::label_email($order), // empty unless sharing is enabled
                'personType'  => 0, // individual
                // LIVE-CONFIRMED names (the API's own 400 details): `city` id + county via countyString.
                'city'         => $sid,
                'countyString' => $county,
                'address'      => $addr !== '' ? $addr : '-',
                'postalCode'   => (string) $order->get_meta('_bgcouriers_post_code'),
            ], static function ($v) { return $v !== ''; }),
            'parcels'        => array_map(
                static function ($kg) { return array_merge(['weight' => $kg], self::parcel_dims()); },
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

    public function label_formats(): array { return ['A6', 'A4']; }

    public function get_label_pdf(string $waybill, string $format = ''): string {
        // Sameday's AWB download supports a paper type; request it when a size is asked for. When $format is
        // empty we send NO type param so the native download is unchanged. (Format param per the official
        // php-sdk; not live-verified here - no Sameday account yet.)
        $url = $this->base . '/api/awb/download/' . rawurlencode($waybill);
        if (in_array($format, ['A6', 'A4'], true)) { $url = add_query_arg('type', $format, $url); }
        $r = wp_remote_get($url, [
            'timeout' => 40, 'headers' => ['X-AUTH-TOKEN' => $this->auth_token()],
        ]);
        if (is_wp_error($r)) { throw new BGCouriers_Api_Exception(esc_html($r->get_error_message())); }
        $pdf = (string) wp_remote_retrieve_body($r);
        if (strpos($pdf, '%PDF') !== 0) { throw new BGCouriers_Api_Exception('Sameday label is not a PDF'); }
        return $pdf;
    }

    public function cancel_label(string $waybill): bool {
        $r = wp_remote_request($this->base . '/api/awb/' . rawurlencode($waybill), [
            'method' => 'DELETE', 'timeout' => 30, 'headers' => ['X-AUTH-TOKEN' => $this->auth_token()],
        ]);
        if (is_wp_error($r)) { return false; }
        $code = (int) wp_remote_retrieve_response_code($r);
        if ($code < 300) { return true; }
        // A shipment Sameday does not have is a shipment nobody is coming for, which is the whole point
        // of cancelling - so 404 counts as done, the way Econt's "не е открита" already does. Without
        // this, cancelling an AWB that was cancelled earlier (or lives on the demo stack) reported
        // "the courier did not cancel it" and left a dead number stuck on the order.
        return $code === 404;
    }

    /**
     * Sameday's own view: gone (404) or carrying a cancelled status. Asked when cancel_label() refuses,
     * to tell "already done" apart from "still live" - the abstract's default says false to everything,
     * which made every second cancel look like a failure.
     */
    public function is_cancelled(string $waybill): bool {
        try {
            $t = $this->track($waybill);
        } catch (\Exception $e) {
            // 404 from the status endpoint means Sameday has no such AWB any more.
            return strpos($e->getMessage(), '404') !== false;
        }
        // Sameday says it outright in expeditionSummary.canceled, which parse_tracking now carries as
        // the phase; the wording is the fallback, and it is read with the same vocabulary the orders
        // list uses rather than a shorter one of this class's own.
        return $t->stage() === 'cancelled' || BGCouriers_Tracking::reads_cancelled($t->human());
    }

    // ── Tracking ─────────────────────────────────────────────────────────────

    public function track(string $waybill): BGCouriers_Tracking {
        return self::parse_tracking($this->get_json('/api/client/awb/' . rawurlencode($waybill) . '/status'), $waybill);
    }

    /**
     * LIVE shape (BG demo, 2026-07-23): { expeditionSummary:{delivered,canceled,...},
     * expeditionStatus:{statusId,status(BG),statusLabel(RO),statusState,statusDate,...},
     * expeditionHistory:[{statusId,status,statusDate,...}] }. `status` is already localised Bulgarian.
     */
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
        // Sameday returns its history NEWEST FIRST. Everything downstream - human(), classify(), the status
        // shown in the orders list - reads the LAST entry as the current one, so an unsorted list reports
        // the moment the label was created forever: a cancelled shipment still read "Създадена
        // товарителница". Sort ascending so the newest really is last.
        usort($events, static function ($a, $b) {
            return strcmp((string) $a['date'], (string) $b['date']) ?: 0;
        });
        // Same-second entries (a create and its cancellation can share a timestamp) keep API order, which
        // is newest-first - so reverse those back. Compare on the statusId, which grows with the lifecycle.
        usort($events, static function ($a, $b) {
            $d = strcmp((string) $a['date'], (string) $b['date']);
            return $d !== 0 ? $d : ((int) $a['code'] <=> (int) $b['code']);
        });

        $cur     = (array) ($resp['expeditionStatus'] ?? []);
        $summary = (array) ($resp['expeditionSummary'] ?? []);
        $status  = (string) ($cur['status'] ?? ($events ? $events[count($events) - 1]['name'] : 'unknown'));
        // Sameday states the outcome outright, so the verdict does not depend on reading Bulgarian prose.
        $phase = !empty($summary['delivered']) ? 'DELIVERED' : (!empty($summary['canceled']) ? 'CANCELLED' : '');
        return new BGCouriers_Tracking($waybill, $status, $events, $phase, null, true);
    }

    public function tracking_url(string $waybill): string {
        return 'https://www.sameday.bg/track-awb?awb=' . rawurlencode($waybill);
    }
}
