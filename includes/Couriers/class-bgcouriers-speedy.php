<?php
defined('ABSPATH') || exit;

class BGCouriers_Speedy extends BGCouriers_Abstract_Courier {
    const BG_COUNTRY_ID = 100;
    const BASE = 'https://api.speedy.bg/v1'; // Speedy has no separate demo/sandbox host
    /** Operation code of "Доставка на клиент" - the final operation on a delivered parcel. */
    const OP_DELIVERED = '-14';

    private string $user; private string $pass; private string $base; private array $sender;

    public function __construct(array $config) {
        $this->user = (string) ($config['username'] ?? '');
        $this->pass = (string) ($config['password'] ?? '');
        $this->sender = (array) ($config['sender'] ?? []);
        $this->base = self::BASE;
    }

    private function sender_block(): array {
        // No clientId: Speedy derives the sender from the authenticated API user's own client.
        // Sending an explicit (often inactive) clientId triggers "Sender client not found".
        $sender = [];
        $s = $this->sender;
        if (!empty($s['name']))  { $sender['contactName'] = $s['name']; }
        if (!empty($s['phone'])) { $sender['phone1'] = ['number' => $s['phone']]; }
        if (!empty($s['email'])) { $sender['email'] = $s['email']; }
        return $sender;
    }

    public static function cod_amount(float $total, float $shipping_total, float $shipping_tax): float {
        return max(0.0, round($total - $shipping_total - $shipping_tax, 2));
    }

    public static function build_address(int $site_id, array $fields): array {
        $addr = ['countryId' => self::BG_COUNTRY_ID, 'siteId' => $site_id];
        $map = ['complex' => 'complexName', 'street' => 'streetName', 'street_no' => 'streetNo',
                'block' => 'blockNo', 'entrance' => 'entranceNo', 'floor' => 'floorNo',
                'apartment' => 'apartmentNo', 'note' => 'addressNote'];
        foreach ($map as $k => $api) {
            $v = trim((string) ($fields[$k] ?? ''));
            if ($v !== '') { $addr[$api] = $v; }
        }
        return $addr;
    }

    public function id(): string { return 'speedy'; }
    public function label(): string { return 'Speedy'; }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }

    private function auth(array $body): array {
        // Default all Speedy API content to Bulgarian (labels, tracking operation names, error messages). A
        // per-call 'language' in $body still wins. Ref: https://api.speedy.bg/web-api.html (language BG|EN).
        return array_merge(['userName' => $this->user, 'password' => $this->pass, 'language' => 'BG'], $body);
    }

    public function check_credentials(): bool {
        try {
            $r = $this->post_json($this->base . '/location/site', $this->auth(['countryId' => self::BG_COUNTRY_ID, 'name' => 'Sofia']));
            return !empty($r['sites']);
        } catch (BGCouriers_Api_Exception $e) { return false; }
    }

    public function fetch_cities(): array {
        // The CSV export returns ALL sites; plain /location/site returns only a small default set.
        $res = $this->http_post($this->base . '/location/site/csv/' . self::BG_COUNTRY_ID, $this->auth(['language' => 'BG']));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) { return []; }
        return self::parse_sites_csv((string) wp_remote_retrieve_body($res));
    }

    public static function parse_sites_csv(string $csv): array {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (!$lines || count($lines) < 2) { return []; }
        $header = str_getcsv((string) array_shift($lines));
        $idx = array_flip($header);
        $get = function (array $row, string $name) use ($idx) {
            return isset($idx[$name], $row[$idx[$name]]) ? (string) $row[$idx[$name]] : '';
        };
        $out = [];
        foreach ($lines as $line) {
            if ($line === '') { continue; }
            $row = str_getcsv($line);
            $id = (int) $get($row, 'id');
            if (!$id) { continue; }
            $out[] = [
                'city_id'   => $id,
                'name'      => $get($row, 'name'),
                'name_lat'  => $get($row, 'nameEn'),
                'post_code' => $get($row, 'postCode'),
                'region'    => $get($row, 'region'),
            ];
        }
        return $out;
    }

    public function fetch_offices(int $city_id): array {
        $body = ['countryId' => self::BG_COUNTRY_ID, 'language' => 'BG'];
        if ($city_id > 0) { $body['siteId'] = $city_id; }
        $r = $this->post_json($this->base . '/location/office', $this->auth($body));
        return self::parse_offices($r);
    }

    public function search_streets(int $site_id, string $term): array {
        $r = $this->post_json($this->base . '/location/street', $this->auth([
            'countryId' => self::BG_COUNTRY_ID, 'language' => 'BG', 'siteId' => $site_id, 'name' => $term,
        ]));
        return self::parse_streets($r);
    }

    public static function parse_streets(array $resp): array {
        $out = [];
        foreach (($resp['streets'] ?? []) as $s) {
            $name = (string) ($s['name'] ?? '');
            if ($name === '') { continue; }
            $type = (string) ($s['type'] ?? '');
            $out[] = ['id' => (int) ($s['id'] ?? 0), 'name' => $name, 'type' => $type, 'label' => trim($type . ' ' . $name)];
        }
        return $out;
    }

    public static function parse_sites(array $resp): array {
        $out = [];
        foreach (($resp['sites'] ?? []) as $s) {
            $out[] = [
                'city_id'   => (int) ($s['id'] ?? 0),
                'name'      => (string) ($s['name'] ?? ''),
                'name_lat'  => (string) ($s['nameEn'] ?? ($s['name'] ?? '')),
                'post_code' => (string) ($s['postCode'] ?? ''),
                'region'    => (string) ($s['region'] ?? ($s['municipality'] ?? '')),
            ];
        }
        return $out;
    }

    public static function parse_offices(array $resp): array {
        $out = [];
        foreach (($resp['offices'] ?? []) as $o) {
            $type = strtoupper((string) ($o['type'] ?? '')) === 'APT' ? 'automat' : 'office';
            $addr = $o['address']['fullAddressString'] ?? ($o['address'] ?? '');
            $out[] = [
                'office_id' => (int) ($o['id'] ?? 0),
                'city_id'   => (int) ($o['siteId'] ?? 0),
                'type'      => $type,
                'name'      => (string) ($o['name'] ?? ''),
                'address'   => is_string($addr) ? $addr : '',
                'lat'       => (float) ($o['address']['y'] ?? 0), // Speedy address.y = latitude
                'lng'       => (float) ($o['address']['x'] ?? 0), // Speedy address.x = longitude
            ];
        }
        return $out;
    }

    // Filled in Tasks 6-7:
    public function quote(array $shipment): BGCouriers_Quote {
        $resp = $this->post_json($this->base . '/calculate', $this->auth(self::build_calculate_body($shipment)));
        return self::parse_price($resp, (string) ($shipment['currency'] ?? 'BGN'));
    }

    public static function build_calculate_body(array $s): array {
        $recipient = ['privatePerson' => true];
        if (($s['method'] ?? 'address') === 'address') {
            $recipient['addressLocation'] = ['countryId' => self::BG_COUNTRY_ID, 'siteId' => (int) $s['site_id']];
        } else { // office or automat
            $recipient['pickupOfficeId'] = (int) $s['office_id'];
        }
        $service = ['autoAdjustPickupDate' => true, 'serviceIds' => [505]];
        if (!empty($s['cod_amount'])) {
            $service['additionalServices']['cod'] = ['amount' => (float) $s['cod_amount'], 'processingType' => 'CASH'];
        }
        return [
            // No 'sender' on a price calc: Speedy expects an object, and PHP's [] serialises to a
            // JSON array, which Speedy rejects ("Cannot deserialize CalculationSender from Array").
            'recipient' => $recipient,
            'service'   => $service,
            'content'   => ['parcelsCount' => 1, 'totalWeight' => (float) ($s['weight_kg'] ?? 2.0)],
            'payment'   => ['courierServicePayer' => 'RECIPIENT'],
        ];
    }

    public static function parse_price(array $resp, string $currency): BGCouriers_Quote {
        $calc = $resp['calculations'][0] ?? null;
        if (!$calc || empty($calc['price'])) { throw new BGCouriers_Api_Exception('No price in Speedy response'); }
        $p = $calc['price'];
        $total = (float) ($p['total'] ?? $p['amount'] ?? 0);
        $vat   = (float) ($p['vat'] ?? 0);
        $base  = isset($p['amount']) ? (float) $p['amount'] : max(0.0, $total - $vat);
        return new BGCouriers_Quote($base, $vat, (string) ($p['currency'] ?? $currency), 'live');
    }

    public function create_label(\WC_Order $order): BGCouriers_Label {
        $body = $this->auth($this->build_shipment_body($order));
        $resp = $this->post_json($this->base . '/shipment', $body);
        // Speedy reports validation failures as HTTP 200 + an `error` object (e.g. parcel size vs the
        // APS compartment) - surface it instead of letting an empty waybill through (same 200+error
        // contract cancel_label already handles).
        if (!empty($resp['error'])) {
            throw new BGCouriers_Api_Exception(esc_html('Speedy: ' . (string) ($resp['error']['message'] ?? 'shipment rejected')));
        }
        $id = self::parse_shipment_id($resp);
        if ($id === '') { throw new BGCouriers_Api_Exception('Speedy: no shipment id in the response'); }
        return new BGCouriers_Label($id);
    }

    private function build_shipment_body(\WC_Order $order): array {
        $method  = (string) $order->get_meta('_bgcouriers_method') ?: 'address';
        $site_id = (int) $order->get_meta('_bgcouriers_site_id');
        $office  = (int) $order->get_meta('_bgcouriers_office_id');
        // trim() so an empty shipping name ("" joined = " ", which is truthy) still falls back to billing.
        $recipient = [
            'privatePerson' => true,
            'clientName'    => trim($order->get_formatted_shipping_full_name()) ?: trim($order->get_formatted_billing_full_name()),
            'phone1'        => ['number' => $order->get_billing_phone()],
            'email'         => BGCouriers_Settings::label_email($order),
        ];
        if ($method === 'address') {
            $recipient['address'] = self::build_address($site_id, [
                'complex'   => $order->get_meta('_bgcouriers_complex'),
                'street'    => $order->get_meta('_bgcouriers_street_name'),
                'street_no' => $order->get_meta('_bgcouriers_street_no'),
                'block'     => $order->get_meta('_bgcouriers_block'),
                'entrance'  => $order->get_meta('_bgcouriers_entrance'),
                'floor'     => $order->get_meta('_bgcouriers_floor'),
                'apartment' => $order->get_meta('_bgcouriers_apartment'),
                'note'      => $order->get_meta('_bgcouriers_address_note'),
            ]);
        } else {
            $recipient['pickupOfficeId'] = $office;
        }
        $payer   = self::service_payer('speedy');
        $package = in_array(get_option('bgcouriers_speedy_package', 'BOX'), ['BOX', 'ENVELOPE', 'PALLET'], true)
            ? (string) get_option('bgcouriers_speedy_package', 'BOX')
            : 'BOX';
        $contents = BGCouriers_Settings::shipment_contents();
        $dims     = BGCouriers_Settings::box_dims();
        $body = [
            'recipient' => $recipient,
            'service'   => ['autoAdjustPickupDate' => true, 'serviceId' => 505],
            'content'   => ['parcelsCount' => 1, 'contents' => $contents, 'package' => $package,
                            'totalWeight' => self::order_weight_kg($order),
                            // ShipmentParcelSize {width,height,depth} cm (schema-confirmed); lockers must fit.
                            'parcels'     => [['seqNo' => 1, 'weight' => self::order_weight_kg($order),
                                               'size' => ['width' => $dims['width'], 'depth' => $dims['length'], 'height' => $dims['height']]]]],
            'payment'   => ['courierServicePayer' => $payer === 'recipient' ? 'RECIPIENT' : 'SENDER'],
            // Printed on the waybill as the merchant's own reference, so it is read by Bulgarian staff and
            // the recipient - translatable rather than a hardcoded English "ORDER".
            /* translators: %s: order number, printed on the waybill as the sender's reference */
            'ref1'      => sprintf(__('Order %s', 'bg-couriers'), $order->get_order_number()),
        ];
        // COD only for orders actually paid cash-on-delivery (never re-collect on a prepaid order). Speedy
        // pays the collected amount out per the merchant's Speedy contract (postal money transfer / bank).
        if ($order->get_payment_method() === 'cod') {
            $cod = self::cod_for_payer($order, $payer);
            if ($cod > 0) {
                $processing_type = BGCouriers_Settings::courier_ppp_payout('speedy') ? 'POSTAL_MONEY_TRANSFER' : 'CASH';
                $body['service']['additionalServices']['cod'] = [
                    'amount'               => $cod,
                    'processingType'       => $processing_type,
                    'ignoreIfNotApplicable' => true,
                ];
                // Per-delivery-option "card payment for COD" toggle: ON sends nothing (the Speedy
                // account default applies), OFF forbids it (ShipmentCODAdditionalService.cardPaymentForbidden).
                if (get_option('bgcouriers_speedy_' . $method . '_card_payment', 'yes') !== 'yes') {
                    $body['service']['additionalServices']['cod']['cardPaymentForbidden'] = true;
                }
            }
        }
        // Open-before-payment (OBPD): allow/test inspection before the customer pays. Never sent for
        // locker (automat) deliveries - there is no courier at an APT to supervise an inspection, so the
        // field stays at the API's own default there.
        $obpd_val = (string) get_option('bgcouriers_speedy_open_before_pay', 'no');
        if ($method !== 'automat' && ($obpd_val === 'open' || $obpd_val === 'test')) {
            $body['service']['additionalServices']['obpd'] = [
                'option'                  => strtoupper($obpd_val),
                'returnShipmentServiceId' => 505,
                'returnShipmentPayer'     => 'SENDER',
                'ignoreIfNotApplicable'   => true,
            ];
        }
        $sender = $this->sender_block();
        if ($sender) { $body['sender'] = $sender; }
        return $body;
    }

    public static function parse_shipment_id(array $resp): string {
        return (string) ($resp['id'] ?? ($resp['parcels'][0]['id'] ?? ''));
    }

    public static function build_print_body(array $parcel_ids, string $paper_size): array {
        return [
            // Print the label in Bulgarian (the /print API defaults to the account language, which was giving
            // English text). BG couriers/recipients -> BG. Ref: https://api.speedy.bg/web-api.html (language BG|EN).
            'language'  => 'BG',
            'paperSize' => in_array($paper_size, ['A6', 'A4', 'A4_4xA6'], true) ? $paper_size : 'A6',
            'parcels'   => array_values(array_map(static fn($id) => ['parcel' => ['id' => (string) $id]], $parcel_ids)),
            'additionalWaybillSenderCopy' => 'NONE',
        ];
    }

    /**
     * Speedy prints multiple waybills in one native call - on A4 it lays them out itself (landscape, up to a
     * few per sheet), which is exactly how Speedy prints them 1-by-1. Used for batch printing so we DON'T
     * re-pack A6 stickers ourselves. Returns one PDF for all the given waybills.
     */
    public function has_native_batch(): bool { return true; }

    public function batch_label_pdf(array $waybills, string $format = ''): string {
        $paper = in_array($format, ['A6', 'A4'], true) ? $format : BGCouriers_Settings::label_paper_size('speedy');
        $ids   = array_values(array_filter(array_map('strval', $waybills)));
        if ($paper !== 'A4') { return $this->print_labels($ids, $paper); }
        // A4 batch = how the merchant already prints them one by one: Speedy's plain 'A4' renders ONE
        // full-size waybill form in the LEFT half of a landscape sheet - so fetch those native pages
        // and pair them two-up (left + mirrored right) onto single sheets, never scaling. If the
        // bundled FPDI can't combine them, fall back to Speedy's own 'A4_4xA6' grid (4 A6 per page).
        $pdf = $this->print_labels($ids, 'A4');
        $res = BGCouriers_Label_Packer::compose_a4([$pdf]);
        if ($res['pdf'] !== '') { return $res['pdf']; }
        return $this->print_labels($ids, 'A4_4xA6');
    }

    public function print_labels(array $parcel_ids, string $paper_size): string {
        $res = $this->http_post($this->base . '/print', $this->auth(self::build_print_body($parcel_ids, $paper_size)));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            throw new BGCouriers_Api_Exception('Speedy print failed');
        }
        $body = (string) wp_remote_retrieve_body($res);
        // A 200 whose body is not a PDF is Speedy's JSON error payload (same 200+error contract as /shipment).
        if (strncmp($body, '%PDF', 4) !== 0) {
            $j = json_decode($body, true);
            throw new BGCouriers_Api_Exception(esc_html('Speedy print failed: ' . (string) ($j['error']['message'] ?? 'response is not a PDF')));
        }
        return $body;
    }

    public function label_formats(): array { return ['A6', 'A4']; }

    public function get_label_pdf(string $waybill, string $format = ''): string {
        // Ask Speedy's /print for the requested size, so the courier returns the correctly-sized native PDF
        // (no scaling on our side). Empty/invalid falls back to the merchant's configured size, not hardcoded.
        $paper = in_array($format, ['A6', 'A4'], true) ? $format : BGCouriers_Settings::label_paper_size('speedy');
        return $this->print_labels([$waybill], $paper);
    }

    public function track(string $waybill): BGCouriers_Tracking {
        $resp = $this->post_json($this->base . '/track', $this->auth(['parcels' => [['id' => $waybill]], 'lastOperationOnly' => false]));
        return self::parse_tracking($resp);
    }

    /**
     * Parse a /track response. Field names are Speedy's own (api.speedy.bg/v1/schema): TrackedParcel
     * {parcelId, operations, trackPhase} and TrackedParcelOperation {operationCode, dateTime, description,
     * comment, place}. There is no `name`, `date` or `id` on the response side - reading those left every
     * event unnamed, so order notes printed the bare numeric operationCode ("Speedy tracking update: 148")
     * and BGCouriers_Tracking::classify() never saw the Bulgarian text it matches on. The REQUEST side does use
     * `id` (TrackShipmentParcelRef) - that part was right.
     */
    public static function parse_tracking(array $resp): BGCouriers_Tracking {
        $parcel = $resp['parcels'][0] ?? [];
        $ops = $parcel['operations'] ?? [];
        $events = array_map(static fn($o) => [
            'code' => (string) ($o['operationCode'] ?? ''),
            // description is the operation name (Bulgarian - auth() defaults language=BG); comment is the
            // next-best text. Both empty leaves the name blank and human() falls back to the code.
            'name' => (string) (($o['description'] ?? '') ?: ($o['comment'] ?? '')),
            'date' => (string) ($o['dateTime'] ?? ''),
        ], $ops);
        $status = $events ? end($events)['code'] : 'UNKNOWN';
        // trackPhase is an unambiguous lifecycle enum, but Speedy omits it on every parcel we have seen -
        // pass it through when it IS there, and otherwise fall back to the operation code, which is still
        // a machine value rather than prose: -14 is "Доставка на клиент" and is the last operation on
        // every delivered parcel on this account. Only then does BGCouriers_Tracking read the text.
        $phase = (string) ($parcel['trackPhase'] ?? '');
        if ($phase === '' && $events && (string) end($events)['code'] === self::OP_DELIVERED) { $phase = 'DELIVERED'; }
        return new BGCouriers_Tracking((string) ($parcel['parcelId'] ?? ''), $status, $events, $phase);
    }

    public function cancel_label(string $waybill): bool {
        // Speedy: POST /shipment/cancel {shipmentId, comment}; a 200 with no `error` means cancelled.
        $resp = $this->post_json($this->base . '/shipment/cancel', $this->auth(['shipmentId' => $waybill, 'comment' => 'Cancelled from WooCommerce']));
        return empty($resp['error']);
    }

    /** Already cancelled if /track shows the "Canceled" operation (code 128) - so a refused cancel is a no-op. */
    public function is_cancelled(string $waybill): bool {
        try {
            $resp = $this->post_json($this->base . '/track', $this->auth(['parcels' => [['id' => $waybill]]]));
            foreach (($resp['parcels'][0]['operations'] ?? []) as $op) {
                if ((int) ($op['operationCode'] ?? 0) === 128
                    || stripos((string) ($op['description'] ?? ''), 'cancel') !== false) { return true; }
            }
        } catch (\Exception $e) { /* can't confirm -> treat as not cancelled */ }
        return false;
    }
    public function tracking_url(string $waybill): string { return 'https://www.speedy.bg/en/track-shipment?shipmentNumber=' . rawurlencode($waybill); }
}
