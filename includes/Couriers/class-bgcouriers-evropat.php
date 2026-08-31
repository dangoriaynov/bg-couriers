<?php
defined('ABSPATH') || exit;

/**
 * Европът / Evropat-2000 (Bulgaria) courier adapter.
 *
 * Measured against the shop's own live account on 2026-08-31. The shapes below are what the API
 * answered, not what its documentation says - and the two disagree in the places that matter most:
 *
 *   - api.evropat.com serves its OWN apiDoc at the root, and that documentation is behind the live
 *     service. /calculateprice refused the documented parameter list with INVALID_FROM_DESTINATION_ID
 *     for `fromDestID`, a field the docs do not list at all; asking with `method` 1 (pay by account)
 *     then refused again for `clientNumber`, also unlisted. Every required field here was found by
 *     being refused, the way Express One's were.
 *   - The same field is spelled differently by different endpoints: /calculateprice wants `fromDestID`,
 *     /getshipmentprice wants `fromDestinationID`. They are not interchangeable.
 *
 * Two rules run through the whole class:
 *  - EVERY answer is HTTP 200. Success is {"error":null,"response":…}, failure {"error":"CODE",
 *    "errorMessage":"…в Bulgarian…","response":null}, so the HTTP code decides nothing and unwrap()
 *    decides everything.
 *  - The account decides what the courier will accept. ППП (postalMoneyOrder) is silently DROPPED -
 *    priced at 0.00, no error, no flag - on an account whose /getclientaddresses says
 *    allowedPostalMoneyOrder "0". A waybill built on that answer would travel with no money to
 *    collect, so it is refused here instead (see ppp_allowed()).
 *
 * Measured prices, Sofia -> Sofia, 1 kg parcel, sender pays by account (EUR, net):
 *   office -> office 4.5885 | office -> door 5.4389 | door -> door 6.5150
 * So `deliveryType` - which encodes BOTH ends of the journey - moves the price by 40%, which is why
 * the sender's end is a setting and not an assumption. Sofia -> Varna quoted identically to
 * Sofia -> Sofia: the tariff is by zone, and both are zone 1.
 */
class BGCouriers_Evropat extends BGCouriers_Abstract_Courier implements BGCouriers_Courier_Interface {
    const BASE = 'https://api.evropat.com';

    /** Their `deliveryType`, keyed [sender end][recipient end]. 1..4 = ОФ-ОФ, ОФ-ВР, ВР-ОФ, ВР-ВР. */
    const DELIVERY_TYPE = [
        'office' => ['office' => 1, 'address' => 2],
        'door'   => ['office' => 3, 'address' => 4],
    ];

    /** `shipmentType`: 1 documents in an A4 envelope, 2 one or more parcels, 3 a pallet. A shop ships 2. */
    const SHIPMENT_PARCEL = 2;

    /** Their own volumetric divisor, from the module manual: width x length x height (cm) / 6000. */
    const VOLUMETRIC_DIVISOR = 6000;

    /** @var string The one credential this courier has: an API key generated in the merchant's cabinet. */
    private $key;

    public function __construct(array $config) {
        // The plugin's credential pair is username+password; Европът issues ONE key and no username,
        // so it lives in the password slot (encrypted at rest) and credential_fields() tells the rest
        // of the plugin not to ask for the other half.
        $this->key = (string) ($config['password'] ?? '');
    }

    public function id(): string { return 'evropat'; }
    public function label(): string { return 'Европът'; }

    /**
     * No lockers, and that is a fact about Bulgaria rather than about the courier.
     *
     * Their API does have them - /get-boxes, and `deliveryType` 5 and 6 deliver to one - but
     * /getcountries answers `countryBoxDeliveryAvailable: "0"` for BG, so there is nothing to offer
     * here. The day that flag turns to "1", 'automat' belongs in this list and box_delivery() below is
     * what should decide it.
     */
    public function capabilities(): array { return ['address', 'office', 'live_quote', 'pickup']; }

    /** One key, no username - so the settings tab asks for one field and creds_present() checks one. */
    public function credential_fields(): array { return ['password']; }

    // ── The envelope ─────────────────────────────────────────────────────────

    /**
     * The `response` of a successful answer, or an exception carrying what the courier objected to.
     *
     * A refusal arrives as HTTP 200 with an `error` code, so a caller that trusted the HTTP code would
     * read "Невалидно населено място" as an empty success and quote a price of nothing.
     *
     * @throws BGCouriers_Api_Exception
     */
    public static function unwrap(array $resp): array {
        $d = self::payload($resp);
        return is_array($d) ? $d : [];
    }

    /**
     * The same, WITHOUT flattening the answer to an array.
     *
     * /testclientkey answers with a bare boolean, and it is the one endpoint whose entire job is saying
     * whether the key works. Casting that to an array throws the answer away - which is how a dead key
     * would have validated green: an invalid key is not an error here, it is `{"error":null,
     * "response":false}` (measured 2026-08-31), and their own error list for that endpoint has no
     * INVALID_CLIENT_KEY in it while every other endpoint's does.
     *
     * @return mixed
     * @throws BGCouriers_Api_Exception
     */
    public static function payload(array $resp) {
        $err = trim((string) ($resp['error'] ?? ''));
        if ($err !== '') {
            $msg = trim((string) ($resp['errorMessage'] ?? ''));
            throw new BGCouriers_Api_Exception(esc_html('Европът: ' . ($msg !== '' ? $msg : $err) . ' [' . $err . ']'));
        }
        return $resp['response'] ?? null;
    }

    // ── Calling ──────────────────────────────────────────────────────────────

    /** Transient key for this account, so two shops on one host never share a cache. */
    private function ckey(string $what): string {
        return 'bgcouriers_ev_' . $what . '_' . substr(md5($this->key), 0, 12);
    }

    /**
     * One call, unwrapped. The key goes on every request - there is no session and no token.
     *
     * @throws BGCouriers_Api_Exception
     */
    protected function call(string $path, array $body = []): array {
        if ($this->key === '') { throw new BGCouriers_Api_Exception('Европът: no API key configured'); }
        return self::unwrap($this->post_json(self::BASE . $path, array_merge(['clientKey' => $this->key], $body)));
    }

    /**
     * The same, for /print - which answers with the PDF itself and not with the envelope.
     *
     * It still refuses in JSON, at HTTP 200, so the two are told apart by what came back rather than by
     * the status line: bytes beginning %PDF are the document, anything else is an error to be unwrapped
     * (which throws) - and if it somehow parses as a success, that is an answer we do not understand and
     * must not hand to a printer.
     *
     * @throws BGCouriers_Api_Exception
     */
    protected function post_raw(string $path, array $body): string {
        $res = $this->http_post(self::BASE . $path, array_merge(['clientKey' => $this->key], $body));
        if (is_wp_error($res)) {
            throw new BGCouriers_Api_Exception(esc_html('Европът: ' . $res->get_error_message()));
        }
        $raw = (string) wp_remote_retrieve_body($res);
        if (strpos($raw, '%PDF') === 0) { return $raw; }
        $data = json_decode($raw, true);
        if (is_array($data)) { self::unwrap($data); }  // throws with the courier's own words
        throw new BGCouriers_Api_Exception(esc_html('Европът: ' . $path . ' did not return a PDF'));
    }

    /**
     * Does this key work? Their answer is a boolean, and only a boolean true is a yes.
     *
     * Deliberately not routed through call(): that returns an array, and this endpoint's whole answer
     * is the scalar it would discard.
     */
    public function check_credentials(): bool {
        if ($this->key === '') { return false; }
        try {
            return self::payload($this->post_json(self::BASE . '/testclientkey', ['clientKey' => $this->key])) === true;
        } catch (BGCouriers_Api_Exception $e) { return false; }
    }

    // ── Nomenclature ─────────────────────────────────────────────────────────

    /**
     * Every town, in one call. `limit: -1` turns their default 20-row cap off - documented, and the only
     * way to get the whole list; there is no paging here.
     */
    public function fetch_cities(string $country = ''): array {
        self::assert_home($country);
        return self::parse_cities($this->call('/getdestinations', ['limit' => -1]));
    }

    /**
     * Towns -> framework rows.
     *
     * `destinationServicingOfficeID` is the office that serves the town, which is what makes an office
     * delivery possible for a town with no counter of its own - it is kept so resolve_office() has
     * something to fall back to rather than refusing the delivery.
     *
     * @return array<int,array{city_id:int,name:string,post_code:string,region:string,office_id:int}>
     */
    public static function parse_cities(array $rows): array {
        $out = [];
        foreach ($rows as $c) {
            if (!is_array($c)) { continue; }
            $id = (int) ($c['destinationID'] ?? 0);
            if ($id <= 0) { continue; }
            $out[] = [
                'city_id'   => $id,
                'name'      => trim((string) ($c['destinationName'] ?? '')),
                'name_lat'  => trim((string) ($c['destinationNameInEnglish'] ?? '')),
                'post_code' => trim((string) ($c['destinationPostCode'] ?? '')),
                'region'    => trim((string) ($c['destinationProvince'] ?? '')),
                'office_id' => (int) ($c['destinationServicingOfficeID'] ?? 0),
            ];
        }
        return $out;
    }

    /** Every office in the country in one call, filtered here - there is no per-town office endpoint. */
    public function fetch_offices(int $city_id, string $country = ''): array {
        self::assert_home($country);
        $rows = self::parse_offices($this->call('/getoffices', ['limit' => -1]));
        if ($city_id <= 0) { return $rows; }
        return array_values(array_filter($rows, static function ($o) use ($city_id) {
            return (int) $o['city_id'] === $city_id;
        }));
    }

    /**
     * Offices -> framework rows. Every one of them is a counter with a person behind it: this courier
     * has no lockers in Bulgaria, so nothing here is ever an 'automat'.
     *
     * @return array<int,array{office_id:int,code:string,city_id:int,type:string,name:string,address:string,lat:float,lng:float}>
     */
    public static function parse_offices(array $rows): array {
        $out = [];
        foreach ($rows as $o) {
            if (!is_array($o)) { continue; }
            $id = (int) ($o['officeID'] ?? 0);
            if ($id <= 0) { continue; }
            $name = trim((string) ($o['officeName'] ?? ''));
            $from = trim((string) ($o['officeWorkTimeFrom'] ?? ''));
            $to   = trim((string) ($o['officeWorkTimeTo'] ?? ''));
            $out[] = [
                'office_id' => $id,
                'code'      => (string) $id,
                'city_id'   => (int) ($o['officeDestinationID'] ?? 0),
                'type'      => 'office',
                'name'      => $name,
                'address'   => trim((string) ($o['officeAddress'] ?? '')),
                'phone'     => trim((string) ($o['officePhone'] ?? '')),
                'hours'     => ($from !== '' && $to !== '') ? substr($from, 0, 5) . ' - ' . substr($to, 0, 5) : '',
                'lat'       => (float) ($o['lat'] ?? 0),
                'lng'       => (float) ($o['lng'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * A street must come from their list.
     *
     * /getaddresses hands back an `addressID` per street, and the waybill takes that id. A typed street
     * is not a street this courier knows, and the refusal would arrive at the packing table hours after
     * the customer has gone - the same fault Express One taught us.
     */
    public function street_list_only(): bool { return true; }

    /**
     * A town's whole street list, kept for a day.
     *
     * Their search takes a `prefix`, but the checkout filters as the customer types and a request per
     * keystroke is a request per keystroke. One town, one download, one day.
     *
     * @return array<int,array{id:int,name:string,type:string,label:string}>
     */
    private function streets_of(int $city_id): array {
        $k = $this->ckey('streets_' . $city_id);
        $c = get_transient($k);
        if (is_array($c)) { return $c; }
        $rows = self::parse_streets($this->call('/getaddresses', ['destinationID' => $city_id, 'limit' => -1]));
        if ($rows) { set_transient($k, $rows, DAY_IN_SECONDS); }
        return $rows;
    }

    /**
     * Streets -> the shape the checkout's street box reads.
     *
     * `address` is the street on its own and `addressFull` carries their type prefix ("ул.", "жк."),
     * which is what a customer recognises - so the prefix is what is shown and the bare name is what is
     * matched on.
     *
     * @return array<int,array{id:int,name:string,type:string,label:string}>
     */
    public static function parse_streets(array $rows): array {
        $out = [];
        foreach ($rows as $s) {
            if (!is_array($s)) { continue; }
            $id = (int) ($s['addressID'] ?? 0);
            if ($id <= 0) { continue; }
            $name = trim((string) ($s['address'] ?? ''));
            if ($name === '') { continue; }
            $out[] = [
                'id'    => $id,
                'name'  => $name,
                'type'  => trim((string) ($s['typeName'] ?? '')),
                'label' => trim((string) ($s['addressFull'] ?? $name)),
            ];
        }
        return $out;
    }

    /**
     * Streets of one town matching what the customer has typed so far.
     *
     * @return array<int,array{id:int,name:string,type:string,label:string}>
     */
    public function search_streets(int $city_id, string $term, string $country = ''): array {
        self::assert_home($country);
        if ($city_id <= 0) { return []; }
        $term = self::fold($term);
        $all  = $this->streets_of($city_id);
        if ($term === '') { return array_slice($all, 0, 50); }
        $hits = array_values(array_filter($all, static function ($s) use ($term) {
            return strpos(self::fold($s['name']), $term) !== false;
        }));
        return array_slice($hits, 0, 50);
    }

    /**
     * The street id for a street written on an order, plus whether the town has more than one of them.
     *
     * @return array{id:int,label:string,ambiguous:bool}
     */
    public function street_match(int $city_id, string $name): array {
        $want = self::fold($name);
        if ($city_id <= 0 || $want === '') { return ['id' => 0, 'label' => '', 'ambiguous' => false]; }
        $exact = [];
        foreach ($this->streets_of($city_id) as $s) {
            if (self::fold($s['name']) === $want) { $exact[] = $s; }
        }
        if (!$exact) {
            // Nothing spelled exactly that; fall back to the single street that CONTAINS it, if there is
            // exactly one - two would be a guess about where somebody's parcel goes.
            $near = $this->search_streets($city_id, $name);
            if (count($near) !== 1) { return ['id' => 0, 'label' => '', 'ambiguous' => false]; }
            $exact = $near;
        }
        return ['id' => (int) $exact[0]['id'], 'label' => (string) $exact[0]['label'], 'ambiguous' => count($exact) > 1];
    }

    /** Case and spacing are not part of a street's identity; their own list is not consistent about either. */
    private static function fold(string $s): string {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /** Bulgaria only: delivery abroad is switched off plugin-wide, and their international waybill is a separate endpoint. */
    private static function assert_home(string $iso): void {
        $c = strtoupper(trim($iso));
        if ($c !== '' && $c !== 'BG') {
            throw new BGCouriers_Api_Exception(esc_html('Европът does not deliver to ' . $c));
        }
    }

    // ── The sender ───────────────────────────────────────────────────────────

    /**
     * The account's own sending addresses, for the "send parcels from" setting.
     *
     * One of these is what fills the sender half of every waybill: `senderFileID` carries the town, the
     * address, the name, the phone, the client number and the payment way in one field, so a merchant
     * picks a line here instead of retyping six. Cached for a day - it changes when their contract does.
     *
     * @return array<int,string> fileID => a line a human can pick out.
     */
    public function sender_files(): array {
        $k = $this->ckey('senders');
        $c = get_transient($k);
        if (is_array($c)) { return $c; }
        $out = [];
        foreach ($this->client_addresses() as $a) {
            $id = (int) ($a['fileID'] ?? 0);
            // fileID -1 is the account's registered address, which is a real, usable choice.
            if (!isset($a['fileID'])) { continue; }
            $firm = trim((string) ($a['firmName'] ?? ''));
            $addr = trim((string) ($a['address'] ?? ''));
            $out[$id] = trim($firm . ' - ' . $addr, ' -');
        }
        if ($out) { set_transient($k, $out, DAY_IN_SECONDS); }
        return $out;
    }

    /** The raw client-address records, cached for a day. @return array<int,array<string,mixed>> */
    private function client_addresses(): array {
        $k = $this->ckey('senderrows');
        $c = get_transient($k);
        if (is_array($c)) { return $c; }
        $rows = array_values(array_filter($this->call('/getclientaddresses'), 'is_array'));
        if ($rows) { set_transient($k, $rows, DAY_IN_SECONDS); }
        return $rows;
    }

    /** The chosen sending address, or the first one the account has. @return array<string,mixed> */
    private function sender_record(): array {
        $want = get_option('bgcouriers_evropat_sender_file', '');
        $rows = $this->client_addresses();
        if ($want !== '') {
            foreach ($rows as $a) {
                if ((string) ($a['fileID'] ?? '') === (string) $want) { return $a; }
            }
        }
        return $rows ? $rows[0] : [];
    }

    /**
     * Is this account allowed to collect money as ППП?
     *
     * Their API does not refuse a ППП it cannot do - it prices it at 0.00 and books the shipment without
     * one (measured: postalMoneyOrder 50 came back with pppPrice 0.00000 and the same total as a
     * shipment collecting nothing). So the account's own answer is the only thing that knows, and it is
     * read here rather than hardcoded: another shop's Европът account may well have ППП switched on,
     * since they activate it per account on request.
     */
    public function ppp_allowed(): bool {
        $rec = $this->sender_record();
        return (string) ($rec['allowedPostalMoneyOrder'] ?? '0') === '1';
    }

    /** The town the parcel is sent FROM - one half of every price this courier quotes. */
    private function sender_dest_id(): int {
        return (int) ($this->sender_record()['destinationID'] ?? 0);
    }

    /** The account's client number (e.g. E1862XX), required whenever the sender pays against the account. */
    private function client_number(): string {
        return trim((string) ($this->sender_record()['clientNumber'] ?? ''));
    }

    /** Which end the parcel starts at: the merchant drops it at a counter, or a courier collects it. */
    public static function sender_end(): string {
        return get_option('bgcouriers_evropat_sender_end', 'office') === 'door' ? 'door' : 'office';
    }

    /**
     * Their `deliveryType`, which encodes BOTH ends of the journey in one number.
     *
     * This is the field that makes a sender-end setting necessary rather than decorative: the same 1 kg
     * parcel to Sofia was quoted 4.59 office-to-office and 6.52 door-to-door, so a shop that hands its
     * parcels over at a counter and is quoted for a collection overcharges every customer it has.
     */
    public static function delivery_type(string $method): int {
        $to = ($method === 'address') ? 'address' : 'office';
        return self::DELIVERY_TYPE[self::sender_end()][$to];
    }

    // ── Price ────────────────────────────────────────────────────────────────

    public function quote(array $shipment): BGCouriers_Quote {
        $data = $this->call('/calculateprice', $this->build_calculate_body($shipment));
        return self::parse_price($data, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
    }

    /**
     * What to ask a price for.
     *
     * `fromDestID` and `clientNumber` are both absent from their published parameter list and both are
     * refused when missing - the first always, the second whenever `method` is 1. Sending the money to
     * be collected is not optional either: a 50 EUR collection cost 0.61 and lifted the fuel surcharge
     * with it (the surcharge is a percentage of the whole service, not of the base rate), so a quote
     * without it is a quote the shop pays the difference on. That was the 0.3.6 fault, for every courier.
     */
    public function build_calculate_body(array $s): array {
        $method = (string) ($s['method'] ?? 'address');
        $payer  = ((string) ($s['payer'] ?? '')) === 'recipient' ? 2 : 1;
        $client = $this->client_number();
        // Payment method: against the account's invoice when the SENDER pays and there is an account to
        // bill, cash otherwise - the recipient at the door has no client number to charge.
        $pay_by_account = ($payer === 1 && $client !== '');
        $body = [
            'fromDestID'   => $this->sender_dest_id(),
            'toDestID'     => (int) ($s['city_id'] ?? $s['site_id'] ?? 0),
            'weight'       => self::tariff_weight($s),
            'shipmentType' => self::SHIPMENT_PARCEL,
            'payer'        => $payer,
            'method'       => $pay_by_account ? 1 : 2,
            'deliveryType' => self::delivery_type($method),
            'parcelsCount' => max(1, min(10, (int) ($s['parcels'] ?? 1))),
        ];
        if ($pay_by_account) { $body['clientNumber'] = $client; }
        $cod = round((float) ($s['cod_amount'] ?? 0), 2);
        if ($cod > 0) {
            // ППП and НП are mutually exclusive here ("When this is passed COD is not allowed!"), and
            // which one this is depends on how the SHOP fiscalises its cash - and on whether the account
            // may do ППП at all.
            $body[$this->cod_field()] = $cod;
        }
        return $body;
    }

    /**
     * Whether the money is collected as ППП (a postal money order) or as plain наложен платеж.
     *
     * ППП only when the shop asked for it AND the account can actually do one. Without the second half
     * the amount is accepted, priced at nothing and quietly dropped, and the parcel goes out with no
     * money to collect.
     */
    private function cod_field(): string {
        $ppp = class_exists('BGCouriers_Settings')
            && BGCouriers_Settings::cod_fiscalization() === 'ppp'
            && BGCouriers_Settings::courier_ppp_payout('evropat');
        return ($ppp && $this->ppp_allowed()) ? 'postalMoneyOrder' : 'cashOnDelivery';
    }

    /**
     * The weight this courier charges on: the greater of what the parcel weighs and what it displaces.
     *
     * Their tariff weight is volumetric where volume rules - width x length x height / 6000, from their
     * own module manual - and the API takes ONE `weight`. Sending only the real weight quotes a big
     * light parcel at a price the invoice will not match, so the larger of the two is what is asked for.
     */
    public static function tariff_weight(array $s): float {
        $real = (float) ($s['weight_kg'] ?? 1);
        $dims = class_exists('BGCouriers_Settings') ? BGCouriers_Settings::box_dims() : [];
        $vol  = 0.0;
        if (!empty($dims['length']) && !empty($dims['width']) && !empty($dims['height'])) {
            $vol = ((float) $dims['length'] * (float) $dims['width'] * (float) $dims['height'])
                 / self::VOLUMETRIC_DIVISOR;
            $vol *= max(1, (int) ($s['parcels'] ?? 1));
        }
        return max(0.1, round(max($real, $vol), 3));
    }

    /**
     * Their total, in the currency the shop actually sells in.
     *
     * The answer carries the same figure twice - `price` in `mainCurrency` and `priceSecondCurrency` in
     * `secondCurrency` - and which of the two is EUR depends on the account, not on us: this account
     * answers EURO/BGN while their own documented example answers BGN/EURO. Reading `price` blindly is
     * therefore a 1.95583x error waiting for the first shop whose account is set the other way round.
     *
     * It is a NET price. Nothing in the whole API - no request field, no response field, no example, no
     * error - mentions VAT at all, and `price` is the exact sum of the parts it lists (3.313 service +
     * 1.27551 fuel = 4.5885), with the fuel surcharge itself exactly `fuelTaxValue` percent of the
     * service. There is no tax in it to take out, so WooCommerce adds it once, as it does for every
     * other courier here.
     */
    public static function parse_price(array $data, string $currency): BGCouriers_Quote {
        $want   = strtoupper(trim($currency));
        $main   = self::iso_currency((string) ($data['mainCurrency'] ?? ''));
        $second = self::iso_currency((string) ($data['secondCurrency'] ?? ''));
        $price  = (float) ($data['price'] ?? 0);
        if ($want !== '' && $want === $second && $second !== $main) {
            $price = (float) ($data['priceSecondCurrency'] ?? 0);
        }
        if ($price <= 0) {
            throw new BGCouriers_Api_Exception(esc_html('Европът: no price in the answer'));
        }
        return new BGCouriers_Quote(round($price, 2), 0.0, $currency, 'live');
    }

    /** Their word for the currency to an ISO code - they write EURO where the world writes EUR. */
    private static function iso_currency(string $c): string {
        $c = strtoupper(trim($c));
        return $c === 'EURO' ? 'EUR' : $c;
    }

    // ── The waybill ──────────────────────────────────────────────────────────

    public function create_label(\WC_Order $order): BGCouriers_Label {
        $s        = $this->shipment_of($order);
        $problems = [];
        if ($s['method'] === 'address') {
            $hit = $this->street_match((int) $s['city_id'], (string) $s['street']);
            if ($hit['id'] <= 0) {
                throw new BGCouriers_Api_Exception(esc_html(sprintf(
                    /* translators: %s: the street as it is written on the order. */
                    __('Европът does not list a street called "%s" in this town, and it refuses an address without one. Edit the delivery address and pick the street from the list.', 'bg-couriers'),
                    (string) $s['street'])));
            }
            $s['street_id'] = $hit['id'];
            if ($hit['ambiguous']) {
                $problems[] = sprintf(
                    /* translators: %s: the street as the courier spells it. */
                    __('More than one street in this town is called that; the parcel goes to "%s".', 'bg-couriers'), $hit['label']);
            }
        }
        // Said out loud rather than discovered on the invoice: the shop asked for a ППП, the account
        // cannot do one, and the API would take the amount and drop it.
        if ($s['cod_amount'] > 0 && $this->cod_field() === 'cashOnDelivery'
            && class_exists('BGCouriers_Settings') && BGCouriers_Settings::cod_fiscalization() === 'ppp'
            && BGCouriers_Settings::courier_ppp_payout('evropat')) {
            $problems[] = __('This Европът account is not allowed to collect ППП, so the money is collected as наложен платеж instead.', 'bg-couriers');
        }
        $data = $this->call('/createshipment', $this->build_shipment_body($s, $order));
        return self::parse_created($data, $problems);
    }

    /**
     * What the order says about where its parcel is going, in the keys the body builder reads.
     *
     * @return array<string,mixed>
     */
    private function shipment_of(\WC_Order $order): array {
        $payer = self::service_payer('evropat', $order);
        $sid   = (int) $order->get_meta('_bgcouriers_site_id');
        return [
            'method'       => (string) $order->get_meta('_bgcouriers_method'),
            'city_id'      => $sid,
            'office_id'    => (int) $order->get_meta('_bgcouriers_office_id'),
            'office_name'  => self::office_line((int) $order->get_meta('_bgcouriers_office_id')),
            'street'       => (string) $order->get_meta('_bgcouriers_street_name'),
            'street_no'    => (string) $order->get_meta('_bgcouriers_street_no'),
            'weight_kg'    => self::order_weight_kg($order),
            'parcels'      => max(1, (int) $order->get_meta('_bgcouriers_parcels')),
            'payer'        => $payer,
            'cod_amount'   => $order->get_payment_method() === 'cod' ? self::cod_for_payer($order, $payer) : 0.0,
            'order_number' => (string) $order->get_order_number(),
        ];
    }

    /**
     * The shipment as Европът wants it.
     *
     * `senderFileID` does the work of six fields: their own note on it is that it "actually fills the
     * following parameters when they are not passed: senderDestID, senderAddress, senderName,
     * senderPhone, senderFirm, clientNumber, paymentWay". So the sender half of the waybill is the
     * merchant's chosen address from their own cabinet, not something this plugin retypes.
     *
     * @param array          $s     The shipment (see shipment_of()).
     * @param \WC_Order|null $order The order, when there is one - only its recipient details are read.
     */
    public function build_shipment_body(array $s, ?\WC_Order $order = null): array {
        $method = (string) ($s['method'] ?? 'address');
        $body = [
            'senderFileID'        => (int) get_option('bgcouriers_evropat_sender_file', -1),
            'recipientDestID'     => (int) ($s['city_id'] ?? 0),
            'deliveryType'        => self::delivery_type($method),
            'shipmentType'        => self::SHIPMENT_PARCEL,
            'paymentWay'          => self::payment_way((string) ($s['payer'] ?? 'sender')),
            'shipmentDescription' => BGCouriers_Settings::shipment_contents(),
            // NOT `weight`. /calculateprice takes `weight`; this one takes `shipmentWeight`, and its
            // published parameter list does not mention a weight field at all - the API refuses without
            // one (INVALID_SHIPMENT_WEIGHT, measured 2026-08-31).
            'shipmentWeight'      => self::tariff_weight($s),
            'parcelCount'         => max(1, min(10, (int) ($s['parcels'] ?? 1))),
            'shipmentMoreInfo'    => (string) ($s['order_number'] ?? ''),
            'extendedResponse'    => true,
        ];
        if ($order) {
            $body['recipientName']  = $order->get_formatted_billing_full_name();
            $body['recipientPhone'] = (string) $order->get_billing_phone();
            $company = trim((string) $order->get_billing_company());
            if ($company !== '') { $body['recipientFirm'] = $company; }
            $email = BGCouriers_Settings::label_email($order);
            if ($email !== '') { $body['recipientEmail'] = $email; $body['notification'] = true; }
        } else {
            $body['recipientName']  = (string) ($s['receiver_name'] ?? '');
            $body['recipientPhone'] = (string) ($s['receiver_phone'] ?? '');
        }
        if ($method !== 'address') {
            $body['recipientOfficeID'] = (int) ($s['office_id'] ?? 0);
            // Their own warning: with an office delivery type the recipient address is replaced by the
            // office's. It is still required, so it is sent as the office it is going to.
            $body['recipientAddress'] = (string) ($s['office_name'] ?? 'офис');
        } else {
            $body['recipientAddress']       = trim((string) ($s['street'] ?? ''));
            $body['recipientAddressNumber'] = (string) ($s['street_no'] ?? '');
            if ((int) ($s['street_id'] ?? 0) > 0) { $body['recipientAddressID'] = (int) $s['street_id']; }
        }
        $cod = round((float) ($s['cod_amount'] ?? 0), 2);
        if ($cod > 0) {
            $field = $this->cod_field();
            $body[$field] = $cod;
            if ($field === 'cashOnDelivery') {
                // Their direction is mandatory and it is not a default worth trusting: 0 collects from
                // the recipient and pays the sender, which is the only direction a shop ever wants.
                $body['cashOnDeliveryDirection'] = 0;
            }
            // One promise made at the checkout, kept on the waybill.
            if (in_array(get_option('bgcouriers_open_before_pay', 'no'), ['open', 'test'], true)) {
                $body['allowShipmentCheck'] = true;
            }
        }
        return $body;
    }

    /**
     * Their `paymentWay`: who pays the courier, and how.
     *
     * 1 sender/cash, 2 recipient/cash, 3 sender/account, 4 recipient/account, 5 third party/account.
     * A shop billed on contract pays against its client number; a recipient paying at the door pays
     * cash, because the door has no account to charge.
     */
    /**
     * The office an office delivery is going to, as a line of text.
     *
     * `recipientAddress` is required whatever the delivery type, and with an office type their own note
     * says the office's own data replaces it. Sending the synced office's name and address rather than
     * the literal word "офис" costs nothing and means the field says something true if it is ever kept.
     */
    private static function office_line(int $office_id): string {
        if ($office_id <= 0 || !class_exists('BGCouriers_Nomenclature')) { return 'офис'; }
        $o = BGCouriers_Nomenclature::office_by_id('evropat', $office_id);
        if (!$o) { return 'офис'; }
        $line = trim(trim((string) ($o['name'] ?? '')) . ' ' . trim((string) ($o['address'] ?? '')));
        return $line !== '' ? $line : 'офис';
    }

    private static function payment_way(string $payer): int {
        return $payer === 'recipient' ? 2 : 3;
    }

    /**
     * The created shipment.
     *
     * With `extendedResponse` it is an object carrying the whole waybill back; without it, a plain array
     * of barcodes. Both shapes are read, because one of them is what a shop on an older account gets.
     *
     * @param string[] $problems
     */
    public static function parse_created(array $data, array $problems = []): BGCouriers_Label {
        $waybill = trim((string) ($data['barcode'] ?? ''));
        if ($waybill === '' && isset($data[0]) && is_scalar($data[0])) { $waybill = trim((string) $data[0]); }
        if ($waybill === '') {
            throw new BGCouriers_Api_Exception(esc_html('Европът: no waybill number in the answer'));
        }
        // The label is a separate call here - /createshipment returns the record, not the document.
        return new BGCouriers_Label($waybill, '', $problems);
    }

    /** Their printout comes as A4, A6 or a sticker roll, so the paper setting has something real to choose. */
    public function label_formats(): array { return ['A6', 'A4']; }

    /**
     * The label, from an endpoint their documentation does not name correctly.
     *
     * The published Print_Document group calls it POST /print taking a `barcodes` ARRAY of up to 200.
     * There is no /print - it answers SERVICE_NOT_FOUND - and the endpoint that does exist,
     * `/printshipment`, reads a SINGLE `shipmentBarCode` (their capitalisation, the same as
     * /getshipmenthistory uses). Both were found by reading the field name back out of the refusal:
     * their errors echo the parameter the service actually looked at, which is how the spelling is
     * knowable without guessing.
     *
     * Because it is one waybill per call, there is no native batch here - see has_native_batch().
     */
    public function get_label_pdf(string $waybill, string $format = ''): string {
        return $this->post_raw('/printshipment', [
            'shipmentBarCode'   => $waybill,
            'format'            => self::paper($format),
            'disableForcePrint' => true,
        ]);
    }

    /** The paper they name it by; anything we do not recognise falls to the shop's own setting. */
    private static function paper(string $format): string {
        $f = strtoupper(trim($format));
        if ($f === 'A4' || $f === 'A6') { return $f; }
        return BGCouriers_Settings::label_paper_size('evropat');
    }

    // ── Cancelling ───────────────────────────────────────────────────────────

    /**
     * Void a waybill. Their answer is a bare `true` (measured 2026-08-31), so it is read rather than
     * assumed - and a SECOND cancel of the same waybill is refused outright with INVALID_SHIPMENT_STATE
     * rather than answering "already done". That refusal throws, which is why is_cancelled() below has
     * to be able to tell "the courier would not touch it" from "the courier has already done it".
     */
    public function cancel_label(string $waybill): bool {
        return self::payload($this->post_json(
            self::BASE . '/cancelshipment',
            ['clientKey' => $this->key, 'shipmentBarcode' => $waybill]
        )) === true;
    }

    /**
     * Their own word for it, for the check that runs after a cancel the plugin is not sure about.
     *
     * 18 is "Анулирана" in their status nomenclature. A second cancel of an already-cancelled waybill
     * refuses, and reading that refusal as "the courier did not cancel it" is how a merchant is told the
     * wrong thing about a shipment that is gone - the fault fixed for Sameday in 0.3.6 and Speedy in 0.3.7.
     */
    public function is_cancelled(string $waybill): bool {
        try {
            foreach ($this->history($waybill) as $e) {
                if ((int) $e['code'] === 18) { return true; }
            }
        } catch (BGCouriers_Api_Exception $e) { return false; }
        return false;
    }

    // ── Tracking ─────────────────────────────────────────────────────────────

    public function track(string $waybill): BGCouriers_Tracking {
        return self::verdict($this->history($waybill), $waybill);
    }

    /**
     * The parcel's history, with their status NAMES resolved back to numbers.
     *
     * /getshipmenthistory answers `{dateAndTime, stateName, additionalInformation}` and NO status id, so
     * the only machine-readable handle on an event is the nomenclature: /shipment-statuses-nomenclature
     * publishes id, name and description, and the history's `stateName` is the DESCRIPTION ("Създадена")
     * rather than the name ("Създаване"). Both are indexed, so either spelling resolves.
     *
     * @return array<int,array{code:int,name:string,date:string}>
     */
    private function history(string $waybill): array {
        $rows = $this->call('/getshipmenthistory', ['shipmentBarCode' => $waybill]);
        $map  = $this->status_ids();
        $out  = [];
        foreach ($rows as $e) {
            if (!is_array($e)) { continue; }
            $name = trim((string) ($e['stateName'] ?? ''));
            $more = trim((string) ($e['additionalInformation'] ?? ''));
            // The live answer carries `statusID` even though their documented example does not, so the
            // machine value is read straight off the event. The nomenclature is the fallback for an
            // answer that omits it - the history's `stateName` is the nomenclature's DESCRIPTION
            // ("Създадена") rather than its NAME ("Създаване"), so both spellings are indexed.
            $code = (int) ($e['statusID'] ?? 0);
            if ($code <= 0) { $code = (int) ($map[self::fold($name)] ?? 0); }
            $out[] = [
                'code' => $code,
                'name' => $more !== '' ? $name . ' - ' . $more : $name,
                'date' => (string) ($e['dateAndTime'] ?? ''),
            ];
        }
        usort($out, static function ($a, $b) { return strcmp($a['date'], $b['date']); });
        return $out;
    }

    /**
     * Their status vocabulary, folded name => id, cached for a day. Both the name and the description are
     * indexed because the history quotes one and the nomenclature is keyed on the other.
     *
     * @return array<string,int>
     */
    private function status_ids(): array {
        $k = $this->ckey('statuses');
        $c = get_transient($k);
        if (is_array($c)) { return $c; }
        $map = [];
        foreach ($this->call('/shipment-statuses-nomenclature') as $s) {
            if (!is_array($s)) { continue; }
            $id = (int) ($s['statusID'] ?? 0);
            if ($id <= 0) { continue; }
            foreach ([$s['statusName'] ?? '', $s['statusDescription'] ?? ''] as $n) {
                $n = self::fold((string) $n);
                if ($n !== '') { $map[$n] = $id; }
            }
        }
        if ($map) { set_transient($k, $map, DAY_IN_SECONDS); }
        return $map;
    }

    /**
     * What the history means.
     *
     * WHAT HAPPENED TO THE PARCEL OUTRANKS WHAT HAPPENED TO THE PAPERWORK, the rule Express One taught
     * us: a delivered shipment can pick up later document events, and reading the newest one would file
     * a parcel the customer is holding as something else. 19 (Разнесена - delivered) and 10 (Върната на
     * подател - returned to the sender) are facts about the parcel, so either of them anywhere in the
     * history is the verdict.
     *
     * @param array<int,array{code:int,name:string,date:string}> $events
     */
    public static function verdict(array $events, string $waybill): BGCouriers_Tracking {
        $found = [];
        foreach ([19, 10] as $terminal) {
            foreach ($events as $e) {
                if ($e['code'] === $terminal) { $found = $e; break 2; }
            }
        }
        if (!$found) { $found = $events ? end($events) : []; }
        $code  = (int) ($found['code'] ?? 0);
        $phase = $code > 0 ? 'evropat_' . $code : '';
        return new BGCouriers_Tracking($waybill, (string) ($found['name'] ?? ''), $events, $phase, null, true);
    }

    /**
     * No public tracking page to send a customer to.
     *
     * evropat.com is their web application - the only routes it exposes are the cabinet's own, and there
     * is no address that shows one waybill to somebody who is not signed in. So there is no link to
     * give, and an invented one would be a dead end printed in an order e-mail. The waybill number is
     * shown on its own instead, which is what the customer quotes on the phone anyway.
     */
    public function tracking_url(string $waybill): string { return ''; }

    // ── Collection ───────────────────────────────────────────────────────────

    /**
     * Ask a courier to come for the parcels.
     *
     * Their request is built around ONE waybill used as a template - `shipmentBarcode`, and their note
     * that "when this parameter is passed, others optional parameters will not be used" - with the day
     * and the window beside it. So the first waybill names the collection and the rest ride with it,
     * which is how their own Заяви куриер screen works.
     *
     * The cut-off is theirs and it is hard: after 17:30 on a full working day (12:00 on a short one) the
     * request rolls to the next working day. The plugin does not try to be clever about that - it sends
     * the date the merchant asked for and lets Европът answer.
     */
    public function request_pickup(array $waybills, array $opts): string {
        $codes = array_values(array_filter(array_map('strval', $waybills), static function ($w) { return $w !== ''; }));
        $body = [
            'pickupTimeFrom' => (string) ($opts['from'] ?? '09:00'),
            'pickupTimeTo'   => (string) ($opts['to'] ?? '17:00'),
        ];
        $date = trim((string) ($opts['date'] ?? ''));
        if ($date !== '') { $body['pickupDate'] = $date; }
        if ($codes) { $body['shipmentBarcode'] = $codes[0]; }
        $d = $this->call('/createcourierrequest', $body);
        // Whatever they call the request; an empty answer is still a request they accepted, since a
        // refusal arrives as an error and throws.
        foreach (['requestID', 'requestId', 'id', 'courierRequestID'] as $k) {
            if (!empty($d[$k])) { return (string) $d[$k]; }
        }
        return isset($d[0]) && is_scalar($d[0]) ? (string) $d[0] : '';
    }

    /** The sending address is the one setting nothing else can stand in for. */
    public function enable_problems(): array {
        $problems = parent::enable_problems();
        if (trim((string) get_option('bgcouriers_evropat_sender_file', '')) === '') {
            $problems[] = [
                'msg' => __('No "send parcels from" address is chosen for Европът.', 'bg-couriers'),
                'fix' => __('Pick one of your Европът addresses on this tab - it fills the sender half of every waybill and it is one half of every price.', 'bg-couriers'),
            ];
        }
        return $problems;
    }
}
