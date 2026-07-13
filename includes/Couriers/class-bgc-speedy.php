<?php
defined('ABSPATH') || exit;

class BGC_Speedy extends BGC_Abstract_Courier {
    const BG_COUNTRY_ID = 100;
    const BASE = 'https://api.speedy.bg/v1'; // Speedy has no separate demo/sandbox host

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
        return array_merge(['userName' => $this->user, 'password' => $this->pass, 'language' => 'EN'], $body);
    }

    public function check_credentials(): bool {
        try {
            $r = $this->post_json($this->base . '/location/site', $this->auth(['countryId' => self::BG_COUNTRY_ID, 'name' => 'Sofia']));
            return !empty($r['sites']);
        } catch (BGC_Api_Exception $e) { return false; }
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
    public function quote(array $shipment): BGC_Quote {
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

    public static function parse_price(array $resp, string $currency): BGC_Quote {
        $calc = $resp['calculations'][0] ?? null;
        if (!$calc || empty($calc['price'])) { throw new BGC_Api_Exception('No price in Speedy response'); }
        $p = $calc['price'];
        $total = (float) ($p['total'] ?? $p['amount'] ?? 0);
        $vat   = (float) ($p['vat'] ?? 0);
        $base  = isset($p['amount']) ? (float) $p['amount'] : max(0.0, $total - $vat);
        return new BGC_Quote($base, $vat, (string) ($p['currency'] ?? $currency), 'live');
    }

    public function create_label(\WC_Order $order): BGC_Label {
        $body = $this->auth($this->build_shipment_body($order));
        $resp = $this->post_json($this->base . '/shipment', $body);
        return new BGC_Label(self::parse_shipment_id($resp));
    }

    private function build_shipment_body(\WC_Order $order): array {
        $method  = (string) $order->get_meta('_bgc_method') ?: 'address';
        $site_id = (int) $order->get_meta('_bgc_site_id');
        $office  = (int) $order->get_meta('_bgc_office_id');
        // trim() so an empty shipping name ("" joined = " ", which is truthy) still falls back to billing.
        $recipient = [
            'privatePerson' => true,
            'clientName'    => trim($order->get_formatted_shipping_full_name()) ?: trim($order->get_formatted_billing_full_name()),
            'phone1'        => ['number' => $order->get_billing_phone()],
            'email'         => BGC_Settings::label_email($order),
        ];
        if ($method === 'address') {
            $recipient['address'] = self::build_address($site_id, [
                'complex'   => $order->get_meta('_bgc_complex'),
                'street'    => $order->get_meta('_bgc_street_name'),
                'street_no' => $order->get_meta('_bgc_street_no'),
                'block'     => $order->get_meta('_bgc_block'),
                'entrance'  => $order->get_meta('_bgc_entrance'),
                'floor'     => $order->get_meta('_bgc_floor'),
                'apartment' => $order->get_meta('_bgc_apartment'),
                'note'      => $order->get_meta('_bgc_address_note'),
            ]);
        } else {
            $recipient['pickupOfficeId'] = $office;
        }
        $body = [
            'recipient' => $recipient,
            'service'   => ['autoAdjustPickupDate' => true, 'serviceId' => 505],
            'content'   => ['parcelsCount' => 1, 'contents' => 'Goods', 'package' => 'BOX',
                            'totalWeight' => (float) ($order->get_meta('_bgc_weight_kg') ?: 2.0)],
            'payment'   => ['courierServicePayer' => 'RECIPIENT'],
            'ref1'      => 'ORDER ' . $order->get_order_number(),
        ];
        $cod = self::cod_amount(
            (float) $order->get_total(),
            (float) $order->get_shipping_total(),
            (float) $order->get_shipping_tax()
        );
        if ($cod > 0) {
            $body['service']['additionalServices']['cod'] = ['amount' => $cod, 'processingType' => 'CASH'];
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
            'paperSize' => in_array($paper_size, ['A6', 'A4'], true) ? $paper_size : 'A6',
            'parcels'   => array_values(array_map(static fn($id) => ['parcel' => ['id' => (string) $id]], $parcel_ids)),
            'additionalWaybillSenderCopy' => 'NONE',
        ];
    }

    public function print_labels(array $parcel_ids, string $paper_size): string {
        $res = $this->http_post($this->base . '/print', $this->auth(self::build_print_body($parcel_ids, $paper_size)));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            throw new BGC_Api_Exception('Speedy print failed');
        }
        return (string) wp_remote_retrieve_body($res); // binary PDF
    }

    public function get_label_pdf(string $waybill): string {
        return $this->print_labels([$waybill], 'A6');
    }

    public function track(string $waybill): BGC_Tracking {
        $resp = $this->post_json($this->base . '/track', $this->auth(['parcels' => [['id' => $waybill]], 'lastOperationOnly' => false]));
        return self::parse_tracking($resp);
    }

    public static function parse_tracking(array $resp): BGC_Tracking {
        $parcel = $resp['parcels'][0] ?? [];
        $ops = $parcel['operations'] ?? [];
        $events = array_map(static fn($o) => [
            'code' => (string) ($o['operationCode'] ?? ''), 'name' => (string) ($o['name'] ?? ''), 'date' => (string) ($o['date'] ?? ''),
        ], $ops);
        $status = $events ? end($events)['code'] : 'UNKNOWN';
        return new BGC_Tracking((string) ($parcel['id'] ?? ''), $status, $events);
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
