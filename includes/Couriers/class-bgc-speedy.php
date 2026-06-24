<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Speedy extends BGC_Abstract_Courier {
    const BG_COUNTRY_ID = 100;
    const LIVE = 'https://api.speedy.bg/v1';
    const DEMO = 'https://api.speedy.bg/v1'; // Speedy has no separate demo host; demo = test account creds

    private string $user; private string $pass; private string $base; private int $client_id; private array $sender;

    public function __construct(array $config) {
        $this->user = (string) ($config['username'] ?? '');
        $this->pass = (string) ($config['password'] ?? '');
        $this->client_id = (int) ($config['client_id'] ?? 0);
        $this->sender = (array) ($config['sender'] ?? []);
        $this->base = ($config['env'] ?? 'demo') === 'live' ? self::LIVE : self::DEMO;
    }

    private function sender_block(): array {
        $sender = [];
        if ($this->client_id) { $sender['clientId'] = $this->client_id; }
        $s = $this->sender;
        if (!empty($s['name']))  { $sender['contactName'] = $s['name']; }
        if (!empty($s['phone'])) { $sender['phone1'] = ['number' => $s['phone']]; }
        if (!empty($s['email'])) { $sender['email'] = $s['email']; }
        return $sender;
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
        // /location/site/csv/{countryId} returns all sites; for the slice we page by name is impractical,
        // so use the CSV export and parse it. The HTTP path is integration-tested; parse_sites covers JSON shape.
        $r = $this->post_json($this->base . '/location/site', $this->auth(['countryId' => self::BG_COUNTRY_ID]));
        return self::parse_sites($r);
    }

    public function fetch_offices(int $city_id): array {
        $body = ['countryId' => self::BG_COUNTRY_ID];
        if ($city_id > 0) { $body['siteId'] = $city_id; }
        $r = $this->post_json($this->base . '/location/office', $this->auth($body));
        return self::parse_offices($r);
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
            ];
        }
        return $out;
    }

    // Filled in Tasks 6–7:
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
            'sender'    => [], // sender clientId added by quote() caller config if needed
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
        $recipient = [
            'privatePerson' => true,
            'clientName'    => $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name(),
            'phone1'        => ['number' => $order->get_billing_phone()],
            'email'         => $order->get_billing_email(),
        ];
        if ($method === 'address') {
            $recipient['address'] = ['countryId' => self::BG_COUNTRY_ID, 'siteId' => $site_id,
                'streetId' => (int) $order->get_meta('_bgc_street_id'), 'streetNo' => (string) $order->get_meta('_bgc_street_no')];
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
        $sender = $this->sender_block();
        if ($sender) { $body['sender'] = $sender; }
        return $body;
    }

    public static function parse_shipment_id(array $resp): string {
        return (string) ($resp['id'] ?? ($resp['parcels'][0]['id'] ?? ''));
    }

    public function get_label_pdf(string $waybill): string {
        $res = $this->http_post($this->base . '/print', $this->auth([
            'paperSize' => 'A6', 'parcels' => [['parcel' => ['id' => $waybill]]], 'additionalWaybillSenderCopy' => 'NONE',
        ]));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            throw new BGC_Api_Exception('Speedy print failed');
        }
        return (string) wp_remote_retrieve_body($res); // binary PDF
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

    public function cancel_label(string $waybill): bool { return false; }
    public function tracking_url(string $waybill): string { return 'https://www.speedy.bg/en/track-shipment?shipmentNumber=' . rawurlencode($waybill); }
}
