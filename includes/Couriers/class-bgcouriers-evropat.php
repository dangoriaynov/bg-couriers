<?php
defined('ABSPATH') || exit;

/** Evropat (Evropat-2000, Bulgaria) courier adapter: every answer is HTTP 200, the `error` node decides. */
class BGCouriers_Evropat extends BGCouriers_Abstract_Courier implements BGCouriers_Courier_Interface {
    const BASE = 'https://api.evropat.com';

    /** Public tracking page; it cannot be deep-linked. */
    const TRACK_URL = 'https://evropat.bg/track/';

    /** Their `deliveryType`, keyed [sender end][recipient end]. */
    const DELIVERY_TYPE = [
        'office' => ['office' => 1, 'address' => 2],
        'door'   => ['office' => 3, 'address' => 4],
    ];

    /** `shipmentType`: 1 documents, 2 parcels, 3 pallet. */
    const SHIPMENT_PARCEL = 2;

    const VOLUMETRIC_DIVISOR = 6000;

    private $key;

    public function __construct(array $config) {
        // Evropat issues one key and no username, so it lives in the password slot.
        $this->key = (string) ($config['password'] ?? '');
    }

    public function id(): string { return 'evropat'; }
    public function label(): string { return 'Европът'; }

    /** No lockers: /getcountries answers countryBoxDeliveryAvailable "0" for BG. */
    public function capabilities(): array { return ['address', 'office', 'live_quote', 'pickup']; }

    public function credential_fields(): array { return ['password']; }

    public function credential_hint(): array {
        return [
            'intro' => __('Европът issues the API key to you - you generate it yourself in your own online cabinet, without waiting on anybody.', 'bg-couriers'),
            'steps' => [
                __('Sign in to your Европът account at online.evropat.com.', 'bg-couriers'),
                __('If there is no Settings menu, write to sales@evropat.com and ask them to activate it for your account. That is what unlocks the key.', 'bg-couriers'),
                __('Generate the API key in Settings and paste it below - there is no username for this courier, only the key.', 'bg-couriers'),
                __('Click Validate, then Sync. Then pick the address your parcels are sent from: it fills the sender half of every waybill and it is one half of every price.', 'bg-couriers'),
            ],
            'receive'   => __('One API key (+ the address parcels are sent from)', 'bg-couriers'),
            'url_label' => __('Европът API documentation:', 'bg-couriers'),
            'url'       => 'https://api.evropat.com/',
        ];
    }

    // The envelope

    /** The `response` of a successful answer; a refusal is HTTP 200 with an `error` code. */
    public static function unwrap(array $resp): array {
        $d = self::payload($resp);
        return is_array($d) ? $d : [];
    }

    /** The same without casting to array: /testclientkey answers a bare boolean. @return mixed */
    public static function payload(array $resp) {
        $err = trim((string) ($resp['error'] ?? ''));
        if ($err !== '') {
            $msg = trim((string) ($resp['errorMessage'] ?? ''));
            throw new BGCouriers_Api_Exception(esc_html('Европът: ' . ($msg !== '' ? $msg : $err) . ' [' . $err . ']'));
        }
        return $resp['response'] ?? null;
    }

    // Calling

    /** Transient key per account. */
    private function ckey(string $what): string {
        return 'bgcouriers_ev_' . $what . '_' . substr(md5($this->key), 0, 12);
    }

    protected function call(string $path, array $body = []): array {
        if ($this->key === '') {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('No credentials are saved for %s.', 'bg-couriers'), 'Европът')));
        }
        return self::unwrap($this->post_json(self::BASE . $path, array_merge(['clientKey' => $this->key], $body)));
    }

    /** Only a boolean true is a yes; not routed through call(), which would discard the scalar. */
    public function check_credentials(): bool {
        if ($this->key === '') { return false; }
        return self::payload($this->post_json(self::BASE . '/testclientkey', ['clientKey' => $this->key])) === true;
    }

    // Nomenclature

    /** `limit: -1` turns their default 20-row cap off; there is no paging. */
    public function fetch_cities(string $country = ''): array {
        self::assert_home($country);
        return self::parse_cities($this->call('/getdestinations', ['limit' => -1]));
    }

    /**
     * Towns -> framework rows; office_id is the office serving the town, for towns with no counter.
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

    /** Every office in one call, filtered here; there is no per-town endpoint. */
    public function fetch_offices(int $city_id, string $country = ''): array {
        self::assert_home($country);
        $rows = self::parse_offices($this->call('/getoffices', ['limit' => -1]));
        if ($city_id <= 0) { return $rows; }
        return array_values(array_filter($rows, static function ($o) use ($city_id) {
            return (int) $o['city_id'] === $city_id;
        }));
    }

    /**
     * Offices -> framework rows; no lockers here, so nothing is ever an 'automat'.
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

    /** The waybill takes an addressID; a typed street is refused. */
    public function street_list_only(): bool { return true; }

    /**
     * A town's whole street list, cached for a day.
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
     * Streets -> the checkout's rows; `addressFull` carries the type prefix, `address` is the bare name.
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

    /** @return array<int,array{id:int,name:string,type:string,label:string}> */
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

    /** @return array{id:int,label:string,ambiguous:bool} */
    public function street_match(int $city_id, string $name, string $type = '', int $street_id = 0): array {
        $want = self::fold($name);
        if ($city_id <= 0 || ($want === '' && $street_id <= 0)) { return ['id' => 0, 'label' => '', 'ambiguous' => false]; }
        $rows = $this->streets_of($city_id);
        // The checkout's own street id wins; one not on this town's list is ignored and the name decides.
        if ($street_id > 0) {
            foreach ($rows as $s) {
                if ((int) $s['id'] === $street_id && ($want === '' || self::fold($s['name']) === $want)) {
                    return ['id' => $street_id, 'label' => (string) $s['label'], 'ambiguous' => false];
                }
            }
        }
        $exact = BGCouriers_Street::exact($rows, $name);
        if (count($exact) > 1 && trim($type) !== '') {
            $of_type = array_values(array_filter($exact, static function ($s) use ($type) { return BGCouriers_Street::same_type((string) $s['type'], $type); }));
            if ($of_type) { $exact = $of_type; }
        }
        if (!$exact) {
            // Fall back to the single street that contains the name; two would be a guess.
            $near = $this->search_streets($city_id, BGCouriers_Street::split($name)['name']);
            if (count($near) !== 1) { return ['id' => 0, 'label' => '', 'ambiguous' => false]; }
            $exact = $near;
        }
        return ['id' => (int) $exact[0]['id'], 'label' => (string) $exact[0]['label'], 'ambiguous' => count($exact) > 1];
    }

    /** Case and spacing are not part of a street's identity. */
    private static function fold(string $s): string {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /** Bulgaria only; their international waybill is a separate endpoint. */
    private static function assert_home(string $iso): void {
        $c = strtoupper(trim($iso));
        if ($c !== '' && $c !== 'BG') {
            /* translators: 1: courier name, 2: country code. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s does not deliver to %2$s.', 'bg-couriers'), 'Европът', $c)));
        }
    }

    // The sender

    /**
     * The account's sending addresses for the "send parcels from" setting; cached for a day.
     * @return array<int,string> fileID => label.
     */
    public function sender_files(): array {
        $k = $this->ckey('senders');
        $c = get_transient($k);
        if (is_array($c)) { return $c; }
        $out = [];
        foreach ($this->client_addresses() as $a) {
            $id = (int) ($a['fileID'] ?? 0);
            // fileID -1 is the account's registered address, a real choice.
            if (!isset($a['fileID'])) { continue; }
            $firm = trim((string) ($a['firmName'] ?? ''));
            $addr = trim((string) ($a['address'] ?? ''));
            $out[$id] = trim($firm . ' - ' . $addr, ' -');
        }
        if ($out) { set_transient($k, $out, DAY_IN_SECONDS); }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function client_addresses(): array {
        $k = $this->ckey('senderrows');
        $c = get_transient($k);
        if (is_array($c)) { return $c; }
        $rows = array_values(array_filter($this->call('/getclientaddresses'), 'is_array'));
        if ($rows) { set_transient($k, $rows, DAY_IN_SECONDS); }
        return $rows;
    }

    /** @return array<string,mixed> */
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

    /** A PPP the account may not do is priced at 0.00 and silently dropped, so the account flag decides. */
    public function ppp_allowed(): bool {
        $rec = $this->sender_record();
        return (string) ($rec['allowedPostalMoneyOrder'] ?? '0') === '1';
    }

    private function sender_dest_id(): int {
        return (int) ($this->sender_record()['destinationID'] ?? 0);
    }

    private function client_number(): string {
        return trim((string) ($this->sender_record()['clientNumber'] ?? ''));
    }

    public static function sender_end(): string {
        return get_option('bgcouriers_evropat_sender_end', 'office') === 'door' ? 'door' : 'office';
    }

    /** `deliveryType` encodes both ends of the journey, so the sender end is a setting. */
    public static function delivery_type(string $method): int {
        $to = ($method === 'address') ? 'address' : 'office';
        return self::DELIVERY_TYPE[self::sender_end()][$to];
    }

    // Price

    public function quote(array $shipment): BGCouriers_Quote {
        $data = $this->call('/calculateprice', $this->build_calculate_body($shipment));
        return self::parse_price($data, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
    }

    /** `fromDestID` and `clientNumber` are unlisted in their docs but refused when missing; the COD amount changes the price. */
    public function build_calculate_body(array $s): array {
        $method = (string) ($s['method'] ?? 'address');
        $payer  = ((string) ($s['payer'] ?? '')) === 'recipient' ? 2 : 1;
        $client = $this->client_number();
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
            // PPP and plain COD are mutually exclusive here.
            $body[$this->cod_field()] = $cod;
        }
        return $body;
    }

    /** PPP only when the shop asked for it AND the account may do one; otherwise the amount is silently dropped. */
    private function cod_field(): string {
        $ppp = class_exists('BGCouriers_Settings')
            && BGCouriers_Settings::cod_fiscalization() === 'ppp'
            && BGCouriers_Settings::courier_ppp_payout('evropat');
        return ($ppp && $this->ppp_allowed()) ? 'postalMoneyOrder' : 'cashOnDelivery';
    }

    /** The greater of real and volumetric weight; the API takes ONE `weight`. */
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
     * Their total, net of VAT. Which of price/priceSecondCurrency is EUR depends on the account,
     * and the price is GROSS although nothing in the API says so.
     */
    public static function parse_price(array $data, string $currency): BGCouriers_Quote {
        $want   = strtoupper(trim($currency));
        $main   = self::iso_currency((string) ($data['mainCurrency'] ?? ''));
        $second = self::iso_currency((string) ($data['secondCurrency'] ?? ''));
        $gross  = (float) ($data['price'] ?? 0);
        if ($want !== '' && $want === $second && $second !== $main) {
            $gross = (float) ($data['priceSecondCurrency'] ?? 0);
        }
        if ($gross <= 0) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s sent no price in its answer.', 'bg-couriers'), 'Европът')));
        }
        list($net, $tax) = BGCouriers_Pricing::split_gross($gross);
        return new BGCouriers_Quote($net, $tax, $currency, 'live');
    }

    /** They write EURO where ISO writes EUR. */
    private static function iso_currency(string $c): string {
        $c = strtoupper(trim($c));
        return $c === 'EURO' ? 'EUR' : $c;
    }

    // The waybill

    public function create_label(\WC_Order $order): BGCouriers_Label {
        $s        = $this->shipment_of($order);
        $problems = [];
        if ($s['method'] === 'address') {
            $hit = $this->street_match((int) $s['city_id'], (string) $s['street'], (string) $s['street_type'], (int) $s['street_id']);
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
        // The shop asked for a PPP the account cannot do: say so rather than let the API drop it.
        if ($s['cod_amount'] > 0 && $this->cod_field() === 'cashOnDelivery'
            && class_exists('BGCouriers_Settings') && BGCouriers_Settings::cod_fiscalization() === 'ppp'
            && BGCouriers_Settings::courier_ppp_payout('evropat')) {
            $problems[] = __('This Европът account is not allowed to collect ППП, so the money is collected as наложен платеж instead.', 'bg-couriers');
        }
        $data = $this->call('/createshipment', $this->build_shipment_body($s, $order));
        return self::parse_created($data, $problems);
    }

    /** @return array<string,mixed> */
    private function shipment_of(\WC_Order $order): array {
        $payer = self::service_payer('evropat', $order);
        $sid   = (int) $order->get_meta('_bgcouriers_site_id');
        return [
            'method'       => (string) $order->get_meta('_bgcouriers_method'),
            'city_id'      => $sid,
            'office_id'    => (int) $order->get_meta('_bgcouriers_office_id'),
            'office_name'  => self::office_line((int) $order->get_meta('_bgcouriers_office_id')),
            'street'       => (string) $order->get_meta('_bgcouriers_street_name'),
            'street_type'  => (string) $order->get_meta('_bgcouriers_street_type'),
            'street_id'    => (int) $order->get_meta('_bgcouriers_street_id'),
            'street_no'    => (string) $order->get_meta('_bgcouriers_street_no'),
            'weight_kg'    => self::order_weight_kg($order),
            'parcels'      => max(1, (int) $order->get_meta('_bgcouriers_parcels')),
            'payer'        => $payer,
            'cod_amount'   => $order->get_payment_method() === 'cod' ? self::cod_for_payer($order, $payer) : 0.0,
            'order_number' => (string) $order->get_order_number(),
        ];
    }

    /** `senderFileID` fills senderDestID, senderAddress, senderName, senderPhone, senderFirm, clientNumber and paymentWay. */
    public function build_shipment_body(array $s, ?\WC_Order $order = null): array {
        $method = (string) ($s['method'] ?? 'address');
        $body = [
            'senderFileID'        => (int) get_option('bgcouriers_evropat_sender_file', -1),
            'recipientDestID'     => (int) ($s['city_id'] ?? 0),
            'deliveryType'        => self::delivery_type($method),
            'shipmentType'        => self::SHIPMENT_PARCEL,
            'paymentWay'          => self::payment_way((string) ($s['payer'] ?? 'sender')),
            'shipmentDescription' => BGCouriers_Settings::shipment_contents(),
            // `shipmentWeight` here, not `weight` as on /calculateprice.
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
            // recipientAddress is required even for an office delivery; their side replaces it with the office's.
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
                // 0 = collect from the recipient, pay the sender.
                $body['cashOnDeliveryDirection'] = 0;
            }
            if (in_array(get_option('bgcouriers_open_before_pay', 'no'), ['open', 'test'], true)) {
                $body['allowShipmentCheck'] = true;
            }
        }
        return $body;
    }

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
     * The created shipment: an object with `extendedResponse`, a plain array of barcodes without.
     * @param string[] $problems
     */
    public static function parse_created(array $data, array $problems = []): BGCouriers_Label {
        $waybill = trim((string) ($data['barcode'] ?? ''));
        if ($waybill === '' && isset($data[0]) && is_scalar($data[0])) { $waybill = trim((string) $data[0]); }
        if ($waybill === '') {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s sent no waybill number in its answer.', 'bg-couriers'), 'Европът')));
        }
        // The label is a separate call; /createshipment returns the record, not the document.
        return new BGCouriers_Label($waybill, '', $problems);
    }

    /** `format` is read and ignored by their API, so no choice is offered. */
    public function label_formats(): array { return []; }

    /**
     * /printshipment answers the PDF's URL, not the PDF, and that URL carries the API key:
     * it is fetched here and never returned, stored or logged.
     */
    public function get_label_pdf(string $waybill, string $format = ''): string {
        $url = self::payload($this->post_json(self::BASE . '/printshipment', [
            'clientKey'       => $this->key,
            'shipmentBarCode' => $waybill,
        ]));
        if (!is_string($url) || strpos($url, 'http') !== 0) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s sent no label link in its answer.', 'bg-couriers'), 'Европът')));
        }
        return $this->fetch_pdf($url, [], 'Европът');
    }

    // Cancelling

    /** Their answer is a bare true; a second cancel is refused with INVALID_SHIPMENT_STATE, see is_cancelled(). */
    public function cancel_label(string $waybill): bool {
        return self::payload($this->post_json(
            self::BASE . '/cancelshipment',
            ['clientKey' => $this->key, 'shipmentBarcode' => $waybill]
        )) === true;
    }

    /** 18 is the cancelled status; a second cancel refuses rather than saying "already done". */
    public function is_cancelled(string $waybill): bool {
        try {
            foreach ($this->history($waybill) as $e) {
                if ((int) $e['code'] === 18) { return true; }
            }
        } catch (BGCouriers_Api_Exception $e) { return false; }
        return false;
    }

    // Tracking

    public function track(string $waybill): BGCouriers_Tracking {
        return self::verdict($this->history($waybill), $waybill);
    }

    /**
     * The parcel's history, status names resolved to ids.
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
            // statusID is on the live answer though not in their docs; stateName is the nomenclature's DESCRIPTION, not its NAME.
            $code = (int) ($e['statusID'] ?? 0);
            if ($code <= 0) { $code = (int) ($map[self::fold($name)] ?? 0); }
            $out[] = [
                'code' => $code,
                'name' => $more !== '' ? $name . ' - ' . $more : $name,
                'date' => (string) ($e['dateAndTime'] ?? ''),
                'seq'  => count($out),
            ];
        }
        // Equal timestamps happen and usort is not stable, so fall back to arrival order.
        usort($out, static function ($a, $b) {
            return strcmp($a['date'], $b['date']) ?: ($a['seq'] <=> $b['seq']);
        });
        return $out;
    }

    /**
     * Folded status name => id, cached for a day; both name and description are indexed.
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
     * A terminal parcel event (19 delivered, 10 returned) anywhere in the history outranks the newest event.
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

    /** Their public tracking page cannot be deep-linked, so the waybill is not in the URL. */
    public function tracking_url(string $waybill): string { return self::TRACK_URL; }

    // Collection

    /** The first waybill names the collection and the rest ride with it. */
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
        // An empty answer is still accepted; a refusal throws.
        foreach (['requestID', 'requestId', 'id', 'courierRequestID'] as $k) {
            if (!empty($d[$k])) { return (string) $d[$k]; }
        }
        return isset($d[0]) && is_scalar($d[0]) ? (string) $d[0] : '';
    }

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
