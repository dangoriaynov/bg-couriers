<?php
defined('ABSPATH') || exit;

/**
 * Express One (Bulgaria) courier adapter.
 *
 * Measured against the live test account on 2026-08-25; the shapes below are what the API answered, not
 * what its documentation says (the two disagree in several places - /1/me is documented POST and is
 * GET-only, `data` is an array from some endpoints and an object keyed "1","2",… from others).
 * The full write-up is docs/superpowers/specs/2026-08-25-expressone-design.md.
 *
 *   POST /1/authorize    {username,password}          -> data.authorization_code
 *   POST /1/accesstoken  {authorization_code}         -> data.access_token (+ expires_at, unix, ~24h)
 *   every later call carries it as the X-Access-Token header.
 *
 * Two rules run through the whole class:
 *  - EVERY answer is HTTP 200. Success is {"status":true,"data":…}, failure {"status":0,…,"message":…},
 *    so the HTTP code decides nothing and unwrap() decides everything.
 *  - More than 60 requests a minute blocks the IP for THIRTY MINUTES. On the shop's own server that
 *    takes the checkout down with it, so guard() counts and refuses rather than finding out.
 */
class BGCouriers_Expressone extends BGCouriers_Abstract_Courier implements BGCouriers_Courier_Interface {
    const BASE            = 'https://system.expressone.bg/api/web';
    const BG_COUNTRY_ID   = 100;
    const TRACK_URL       = 'https://expressone.bg/bg/tracking/';
    /** Their limit is 60/min; ours is lower, because being wrong about it costs half an hour of shop. */
    const CALLS_PER_MIN   = 45;

    /** Express One's own location kinds: 2 = its depot, 3 = a partner counter (PUP), 4 = a locker (EXOBOX). */
    const LOCATION_OFFICE = [2, 3];
    const LOCATION_LOCKER = 4;

    /** @var array */
    private $config;
    /** @var string */
    private $user;
    /** @var string */
    private $pass;

    public function __construct(array $config) {
        $this->config = $config;
        $this->user   = (string) ($config['username'] ?? '');
        $this->pass   = (string) ($config['password'] ?? '');
    }

    public function id(): string { return 'expressone'; }
    public function label(): string { return 'Express One'; }

    /**
     * Everything this plugin knows how to offer. Express One delivers to its own depots, to partner
     * counters, to its EXOBOX lockers and to an address; /1/calculate-bol prices each of those
     * differently (measured: address 4.76, office 4.06, locker 3.73 for the same 1 kg parcel), and
     * /1/request-courier calls a courier to collect.
     */
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote', 'pickup']; }

    // ── The envelope ─────────────────────────────────────────────────────────

    /**
     * The `data` of a successful answer, or an exception carrying what the courier objected to.
     *
     * A refusal arrives as HTTP 200 with `status` 0, so a caller that trusted the HTTP code would read
     * "The RECEIVER_CITY field cannot be empty!" as an empty success and print a label for nothing.
     *
     * @throws BGCouriers_Api_Exception
     */
    public static function unwrap(array $resp): array {
        if (empty($resp['status'])) {
            $msg = trim((string) ($resp['message'] ?? ''));
            throw new BGCouriers_Api_Exception(esc_html('Express One: ' . ($msg !== '' ? $msg : 'request refused')));
        }
        $d = $resp['data'] ?? [];
        return is_array($d) ? $d : [];
    }

    /**
     * The same, as a plain list. /1/list-office answers with an object keyed "1","2","3"… while
     * /1/list-city answers with a JSON array, and both mean "here are the rows".
     */
    public static function rows(array $resp): array { return array_values(self::unwrap($resp)); }

    // ── Calling ──────────────────────────────────────────────────────────────

    /** Transient key for this account, so two shops on one host never share a token or a call budget. */
    private function key(string $what): string {
        return 'bgcouriers_e1_' . $what . '_' . substr(md5($this->user), 0, 12);
    }

    /**
     * Refuse to make the call that would get us blocked.
     *
     * A rolling count of the calls made in the current minute. It is deliberately a refusal and not a
     * sleep: the checkout is a request a customer is waiting inside, and a half-minute pause there is
     * indistinguishable from a broken shop.
     *
     * @throws BGCouriers_Api_Exception
     */
    private function guard(): void {
        $k = $this->key('rate_' . gmdate('YmdHi'));
        $n = (int) get_transient($k);
        if ($n >= self::CALLS_PER_MIN) {
            throw new BGCouriers_Api_Exception('Express One: too many requests this minute - refusing to risk the 30-minute block');
        }
        set_transient($k, $n + 1, 120);
    }

    /** The access token, from the transient unless $fresh, in which case it is fetched again. */
    private function token(bool $fresh = false): string {
        $k = $this->key('token');
        if (!$fresh) {
            $t = get_transient($k);
            if (is_string($t) && $t !== '') { return $t; }
        }
        if ($this->user === '' || $this->pass === '') {
            throw new BGCouriers_Api_Exception('Express One: no credentials configured');
        }
        $auth = self::unwrap($this->http('/1/authorize', ['username' => $this->user, 'password' => $this->pass], ''));
        $code = (string) ($auth['authorization_code'] ?? '');
        if ($code === '') { throw new BGCouriers_Api_Exception('Express One: no authorization code in the answer'); }
        $tok = self::unwrap($this->http('/1/accesstoken', ['authorization_code' => $code], ''));
        $val = (string) ($tok['access_token'] ?? '');
        if ($val === '') { throw new BGCouriers_Api_Exception('Express One: no access token in the answer'); }
        // Their expires_at is a unix timestamp about a day out; keep ours comfortably inside it.
        $ttl = max(300, min((int) ($tok['expires_at'] ?? 0) - time() - 600, DAY_IN_SECONDS));
        set_transient($k, $val, $ttl);
        return $val;
    }

    /**
     * One authenticated call, unwrapped.
     *
     * A token can die before its stated expiry (the account is used elsewhere, the session is dropped),
     * and the API says so in the same envelope it uses for everything: {"status":0,"message":"Invalid
     * Access token"}. That one message is worth a second attempt with a fresh token; nothing else is,
     * because every other refusal is about the request and would be refused again identically.
     */
    protected function call(string $path, array $body = []): array {
        $raw = $this->http($path, $body, $this->token());
        if (empty($raw['status']) && stripos((string) ($raw['message'] ?? ''), 'access token') !== false) {
            $raw = $this->http($path, $body, $this->token(true));
        }
        return $raw;
    }

    /** Seam: overridden in tests; the real one posts JSON with the token header. */
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
            throw new BGCouriers_Api_Exception(esc_html('Express One: ' . $res->get_error_message()));
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($data)) {
            throw new BGCouriers_Api_Exception(esc_html('Express One: invalid JSON from ' . $path));
        }
        return $data;
    }

    public function check_credentials(): bool {
        try { return $this->token(true) !== ''; }
        catch (BGCouriers_Api_Exception $e) { return false; }
    }

    // ── Nomenclature ─────────────────────────────────────────────────────────

    public function fetch_cities(string $country = ''): array {
        return self::parse_cities($this->call('/1/list-city', ['country_id' => self::country_id($country)]));
    }

    /**
     * Towns, one row per town.
     *
     * The API answers 9000 rows for Bulgaria and only 4337 of them are distinct places: the list is
     * town × postcode, and Sofia alone accounts for 964 rows. The cities table is keyed on
     * (courier, city_id), so handing it the raw list would make every town fight itself on every sync.
     * The lowest postcode stands for the town - it is the central one in Bulgarian numbering, and it is
     * only ever shown beside the name.
     *
     * @param array $resp The raw /1/list-city answer.
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
                // Keep the lower postcode - but an empty one is not a postcode at all, so any real one
                // replaces it however late it arrives.
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

    /**
     * Every point in the country, in ONE call.
     *
     * $city_id is honoured by filtering what came back rather than by asking per city: the sync asks
     * with 0 (everything), and a per-city question for 4337 towns would be 4337 requests against a
     * 60-a-minute limit that answers with a half-hour block.
     */
    public function fetch_offices(int $city_id, string $country = ''): array {
        $rows = self::parse_offices($this->call('/1/list-office', ['country_id' => self::country_id($country)]));
        if ($city_id <= 0) { return $rows; }
        return array_values(array_filter($rows, static function ($o) use ($city_id) {
            return (int) $o['city_id'] === $city_id;
        }));
    }

    /**
     * Points -> framework rows. LOCATION_TYPE 4 is an EXOBOX locker (our 'automat'); 2 (Express One's
     * own depot) and 3 (a partner counter) are both places with a person behind the counter, which is
     * our 'office'.
     *
     * `ID` arrives as an int from the per-city call and as a STRING from the country-wide one, which is
     * why everything is cast rather than compared.
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

    /**
     * Streets of one town, asked live.
     *
     * Sofia alone has 4884 of them, and they are only available per town - so unlike the towns and the
     * points, these are never synced. The checkout asks as the customer types, and the answer is
     * filtered here rather than by the API, which takes no search term.
     */
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
     * Streets -> the rows the checkout renders, in the shape every courier here uses: the picker stores
     * `name` and shows `label`, so the type ("УЛ.", "БУЛ.", "ЖК") belongs in the label - it is what tells
     * a customer that "ЖК Младост" and "УЛ. Младост" are two different places.
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
     * The id of a street this town knows by that name.
     *
     * Needed because Express One refuses an address it was not given one for - "Please set
     * RECEIVER_STREET_ID when sending RECEIVER_STREET" - while what the checkout stores on the order is
     * the street's NAME (the picker's value is the name, and a customer may type one of their own).
     * So the id is looked up from the town's own list at the moment the waybill is made.
     *
     * A name can belong to more than one street: Sofia has a "1" that is a УЛ. and a "1" that is an АЛ.,
     * and the customer's pick lost the difference on its way into the order. The first is used and the
     * caller is told, because a parcel on the right-named street is recoverable and a refused waybill at
     * the packing table is not.
     *
     * @return array{id:int,label:string,ambiguous:bool}
     */
    public function street_match(int $city_id, string $name): array {
        $want = self::fold($name);
        if ($city_id <= 0 || $want === '') { return ['id' => 0, 'label' => '', 'ambiguous' => false]; }
        $rows = $this->streets_of($city_id);
        $hits = [];
        foreach ($rows as $r) {
            if (self::fold($r['name']) === $want || self::fold($r['label']) === $want) { $hits[] = $r; }
        }
        if (!$hits) { return ['id' => 0, 'label' => '', 'ambiguous' => false]; }
        return ['id' => (int) $hits[0]['id'], 'label' => (string) $hits[0]['label'], 'ambiguous' => count($hits) > 1];
    }

    /**
     * A town's whole street list, kept for a day.
     *
     * Sofia's is 4884 rows and there is no way to ask for one street, so every address label and every
     * keystroke in the street box would otherwise download all of it - against a limit that blocks the
     * IP for half an hour. Printing fifty labels for one town is then one request, not fifty.
     *
     * @return array<int,array{id:int,name:string,type:string,label:string}>
     */
    private function streets_of(int $city_id): array {
        $k = $this->key('streets_' . $city_id);
        $c = get_transient($k);
        if (is_array($c)) { return $c; }
        $rows = self::parse_streets($this->call('/1/list-street', ['city_id' => $city_id]));
        if ($rows) { set_transient($k, $rows, DAY_IN_SECONDS); }
        return $rows;
    }

    /** Case and spacing are not part of a street's identity; the API's own list is not consistent about either. */
    private static function fold(string $s): string {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /**
     * The account's own addresses, for the "send from" setting.
     *
     * The test account has eighteen. A merchant typing one of those ids by hand is a parcel collected
     * from the wrong warehouse, so the setting is a list and this is what fills it. Cached for a day:
     * it changes when the merchant's contract does, not otherwise.
     *
     * @return array<int,string> id => a line a human can pick out.
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

    /** The numeric country id for an ISO code. Bulgaria only: delivery abroad is switched off plugin-wide. */
    private static function country_id(string $iso): int {
        $c = strtoupper(trim($iso));
        if ($c === '' || $c === 'BG') { return self::BG_COUNTRY_ID; }
        throw new BGCouriers_Api_Exception(esc_html('Express One does not deliver to ' . $c));
    }

    // ── Price ────────────────────────────────────────────────────────────────

    public function quote(array $shipment): BGCouriers_Quote {
        $data = self::unwrap($this->call('/1/calculate-bol', self::build_calculate_body($shipment)));
        return self::parse_price($data, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
    }

    /**
     * What to ask a price for.
     *
     * The destination TYPE is part of the price and not a detail: the same 1 kg parcel to Sofia was
     * quoted 4.76 to an address, 4.06 to a partner counter and 3.73 to a locker, and the cheaper two
     * only appear when TAKE_OFFICE_ID is sent. Quoting an office delivery without it overcharges every
     * customer who collects their own parcel.
     */
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
        // Collecting money is a service the courier charges for - 0.35 on a 50 EUR collection, measured.
        // Sending a zero would ask for the price of a shipment that collects nothing, which is a different
        // shipment (0.3.6 shipped exactly that mistake for every other courier).
        $cod = (float) ($s['cod_amount'] ?? 0);
        if ($cod > 0) { $body['COD'] = round($cod, 2); }
        $ins = (float) ($s['insurance'] ?? 0);
        if ($ins > 0) { $body['INSURANCE'] = round($ins, 2); }
        return $body;
    }

    /**
     * Their total, as a net price plus its tax.
     *
     * TOTAL is what the shop pays INCLUDING VAT (3.27 service + 0.70 fuel + 0.79 VAT = 4.76, measured),
     * and WooCommerce is handed net costs which it then taxes itself. Passing TOTAL through would tax
     * the tax - which is precisely the fault 0.3.5 shipped and had to fix for the whole plugin.
     */
    public static function parse_price(array $data, string $currency): BGCouriers_Quote {
        $total = (float) ($data['TOTAL'] ?? 0);
        $vat   = (float) ($data['TAX_VAT'] ?? 0);
        if ($total <= 0) {
            throw new BGCouriers_Api_Exception(esc_html('Express One: no price in the answer'
                . (($data['ERROR_MESSAGE'] ?? null) ? ' (' . $data['ERROR_MESSAGE'] . ')' : '')));
        }
        return new BGCouriers_Quote(round($total - $vat, 2), round($vat, 2), $currency, 'live');
    }

    // ── The waybill ──────────────────────────────────────────────────────────

    public function create_label(\WC_Order $order): BGCouriers_Label {
        $s = self::shipment_of($order);
        $problems = [];
        if (($s['method'] ?? '') === 'address') {
            $hit = $this->street_match((int) $s['city_id'], (string) $s['street']);
            if ($hit['id'] <= 0) {
                throw new BGCouriers_Api_Exception(esc_html(sprintf(
                    /* translators: %s: the street as it is written on the order. */
                    __('Express One does not list a street called "%s" in this town, and it refuses an address without one. Edit the delivery address and pick the street from the list.', 'bg-couriers'),
                    (string) $s['street'])));
            }
            $s['street_id'] = $hit['id'];
            if ($hit['ambiguous']) {
                $problems[] = sprintf(
                    /* translators: %s: the street as Express One spells it. */
                    __('More than one street in this town is called that; the parcel goes to "%s".', 'bg-couriers'), $hit['label']);
            }
        }
        $label = self::parse_created(self::unwrap($this->call('/1/create-bol', self::build_shipment_body($s, $order))));
        return $problems ? new BGCouriers_Label($label->waybill, $label->pdf, $problems) : $label;
    }

    /**
     * What the order says about where its parcel is going, in the keys the body builder reads.
     *
     * The town's NAME and postcode are not on the order - only the courier's town id is - and Express One
     * insists on the name beside the id. Both come from the synced nomenclature, which is where every
     * other courier here reads what it needs about a town it was given the id of.
     */
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
            'street_no'    => (string) $order->get_meta('_bgcouriers_street_no'),
            'weight_kg'    => self::order_weight_kg($order),
            'parcels'      => max(1, (int) $order->get_meta('_bgcouriers_parcels')),
            'insurance'    => (float) $order->get_meta('_bgcouriers_insurance'),
            'payer'        => $payer,
            'cod_amount'   => $order->get_payment_method() === 'cod' ? self::cod_for_payer($order, $payer) : 0.0,
            'order_number' => (string) $order->get_order_number(),
        ];
    }

    /**
     * The shipment as Express One wants it.
     *
     * RECEIVER_CITY - the town's NAME - is required even when RECEIVER_CITY_ID is sent; without it the
     * API refuses the whole thing with "The RECEIVER_CITY field cannot be empty!". That is not in its
     * documentation and was found by being refused.
     *
     * @param array          $s     The shipment (see shipment_of()).
     * @param \WC_Order|null $order The order, when there is one - only its recipient details are read.
     */
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
            // 0 = the sender pays the delivery, 1 = the recipient does. The plugin already works this out
            // from the "delivery in the order total" setting, and the COD amount above follows the same
            // answer - the two must agree or the courier collects the wrong money.
            'PAYER'               => ($s['payer'] ?? 'sender') === 'recipient' ? 1 : 0,
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
        $ins = (float) ($s['insurance'] ?? 0);
        if ($ins > 0) { $body['INSURANCE'] = round($ins, 2); }
        // One promise made at the checkout, kept on the waybill: the shop-wide "open before payment".
        // Never for a locker - there is nobody there to supervise it.
        if ($method !== 'automat' && in_array(get_option('bgcouriers_open_before_pay', 'no'), ['open', 'test'], true)) {
            $body['CHECK_BEFORE_PAY'] = 1;
        }
        return $body;
    }

    /**
     * The created shipment. The label comes back INSIDE this answer, base64, so a printed waybill costs
     * one call and not two - and the PDF is the same bytes /1/print-bol would hand back afterwards.
     */
    public static function parse_created(array $data): BGCouriers_Label {
        $waybill = trim((string) ($data['BILLOFLADING'] ?? ''));
        if ($waybill === '') {
            throw new BGCouriers_Api_Exception(esc_html('Express One: no bill of lading in the answer'
                . (($data['ERROR_MESSAGE'] ?? null) ? ' (' . $data['ERROR_MESSAGE'] . ')' : '')));
        }
        $pdf = (string) ($data['LABEL'] ?? '');
        $pdf = $pdf !== '' ? (string) base64_decode($pdf, true) : '';
        return new BGCouriers_Label($waybill, $pdf);
    }

    /** A6 is the label layout their account prints, A4 the plain PDF one. ZPL (3, 4) is a printer's business. */
    public function label_formats(): array { return ['A6', 'A4']; }

    public function get_label_pdf(string $waybill, string $format = ''): string {
        $data = self::unwrap($this->call('/1/print-bol', [
            'bol_id'       => $waybill,
            'by_reference' => 0,
            'pdfformat'    => strtoupper($format) === 'A4' ? 1 : 2,
        ]));
        $pdf = (string) base64_decode((string) ($data['LABEL'] ?? ''), true);
        if (strpos($pdf, '%PDF') !== 0) { throw new BGCouriers_Api_Exception('Express One: the label is not a PDF'); }
        return $pdf;
    }

    // ── Cancelling ───────────────────────────────────────────────────────────

    public function cancel_label(string $waybill): bool {
        return self::parse_cancel(self::unwrap($this->call('/1/cancel-bol', ['bol_id' => $waybill, 'by_reference' => 0])));
    }

    /**
     * Cancelled, including when it already was.
     *
     * The first cancel answers {"SUCCESS":1}. A second one answers an EMPTY data - no flag, no error -
     * and /1/bol-info then reports STATUS_ID 7 either way. Reading that empty answer as a failure is how
     * a merchant is told "the courier did not cancel it" about a shipment the courier has cancelled; the
     * same fault was shipped for Sameday and fixed in 0.3.6, and for Speedy in 0.3.7.
     */
    public static function parse_cancel(array $data): bool {
        if ($data === []) { return true; }
        return (int) ($data['SUCCESS'] ?? 0) === 1;
    }

    /** Their own word for it, for the check that runs after a cancel the plugin is not sure about. */
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
     * The parcel's history.
     *
     * A shipment nobody has touched answers with ONE row whose STATUS_ID is null and whose name is
     * "N/A". That is "nothing has happened yet", not an error and not an event - a waybill printed a
     * minute ago is in exactly that state, and letting "N/A" through would print it on the order as the
     * parcel's status.
     *
     * The phase handed on is the courier's own numeric code, prefixed so it cannot collide with another
     * courier's vocabulary; BGCouriers_Tracking::PHASES turns it into a stage. 101 is Express One's
     * "something happened" code and carries the meaning in its substatus text, so it gets no phase and
     * the text rules read it.
     *
     * WHAT HAPPENED TO THE PARCEL OUTRANKS WHAT HAPPENED TO THE PAPERWORK. A delivered shipment on the
     * test account reads, in order: 0 booked, 3 at the office, 5 out for delivery, 6 DELIVERED, 10
     * finalised, 7 CANCELLED. Reading the newest event - which is what every other courier here needs -
     * would file a parcel the customer is holding as cancelled, and the order with it. So a 6 or a 12
     * anywhere in the history is the verdict: those two are facts about the parcel (it reached the
     * recipient, it came back), while 7 and 10 after them are facts about the document.
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
        foreach (['6', '12'] as $terminal) {                       // delivered, then returned
            foreach ($events as $e) {
                if ($e['code'] === $terminal) { $verdict = $e; break 2; }
            }
        }
        if (!$verdict) { $verdict = $events ? end($events) : []; }
        $code  = (string) ($verdict['code'] ?? '');
        $phase = ($code !== '' && $code !== '101') ? 'expressone_' . $code : '';
        return new BGCouriers_Tracking($waybill, (string) ($verdict['name'] ?? ''), $events, $phase, null, true);
    }

    /** The page a customer can open: it fills the number in for them. A query string does not work. */
    public function tracking_url(string $waybill): string {
        return self::TRACK_URL . rawurlencode($waybill);
    }

    // ── Collection ───────────────────────────────────────────────────────────

    /**
     * Ask a courier to come for the parcels.
     *
     * Express One takes a count and a total weight rather than the waybills themselves, so the request
     * is about the pile on the merchant's desk and not about particular shipments - which is why the
     * waybills are only counted and weighed here.
     */
    public function request_pickup(array $waybills, array $opts): string {
        $d = self::unwrap($this->call('/1/request-courier', [
            'count'          => max(1, count($waybills)),
            'weight'         => max(0.1, (float) ($opts['weight_kg'] ?? count($waybills))),
            'readiness'      => (string) ($opts['ready_time'] ?? ''),
            'take_office_id' => (int) get_option('bgcouriers_expressone_sender_object', 0),
        ]));
        $id = trim((string) ($d['REQUEST'] ?? ''));
        if ($id === '') { throw new BGCouriers_Api_Exception('Express One: no pickup request id in the answer'); }
        return $id;
    }

    /** The sender address is the one setting nothing else can stand in for. */
    public function enable_problems(): array {
        $problems = parent::enable_problems();
        $this->need_option($problems, 'bgcouriers_expressone_sender_object',
            __('No "send from" address is chosen for Express One.', 'bg-couriers'),
            __('Pick one of your Express One addresses on this tab - every waybill is collected from it.', 'bg-couriers'));
        return $problems;
    }
}
