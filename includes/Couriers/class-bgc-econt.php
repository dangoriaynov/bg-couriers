<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Econt extends BGC_Abstract_Courier {
    const PROD = 'https://ee.econt.com/services';
    const DEMO = 'https://demo.econt.com/ee/services';
    private $user; private $pass; private $sender; private $base;

    public function __construct(array $config) {
        $this->user   = (string) ($config['username'] ?? '');
        $this->pass   = (string) ($config['password'] ?? '');
        $this->sender = (array) ($config['sender'] ?? []);
        $this->base   = (defined('BGC_ECONT_DEMO') && BGC_ECONT_DEMO) ? self::DEMO : self::PROD;
    }

    public function id(): string { return 'econt'; }
    public function label(): string { return 'Econt'; }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }

    /** Econt uses HTTP Basic, not a body credential — override the transport. */
    protected function http_post(string $url, array $body) {
        return wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json',
                          'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->pass)],
            'body'    => wp_json_encode($body),
        ]);
    }

    public function check_credentials(): bool {
        try { $r = $this->post_json($this->base . '/Profile/ProfileService.getClientProfiles.json', []);
            return isset($r['profiles']); } catch (\Exception $e) { return false; }
    }

    public function fetch_cities(): array {
        return self::parse_cities($this->post_json($this->base . '/Nomenclatures/NomenclaturesService.getCities.json', ['countryCode' => 'BGR']));
    }
    public function fetch_offices(int $city_id): array {
        $rows = self::parse_offices($this->post_json($this->base . '/Nomenclatures/NomenclaturesService.getOffices.json', ['countryCode' => 'BGR']));
        return $city_id > 0 ? array_values(array_filter($rows, static function ($o) use ($city_id) { return $o['city_id'] === $city_id; })) : $rows;
    }
    public function search_streets(int $city_id, string $term): array {
        $rows = self::parse_streets($this->post_json($this->base . '/Nomenclatures/NomenclaturesService.getStreets.json', ['cityID' => $city_id]));
        if ($term === '') { return $rows; }
        $t = function_exists('mb_strtolower') ? mb_strtolower($term) : strtolower($term);
        return array_values(array_filter($rows, static function ($s) use ($t) {
            return strpos(function_exists('mb_strtolower') ? mb_strtolower($s['name']) : strtolower($s['name']), $t) !== false;
        }));
    }

    public static function parse_cities(array $resp): array {
        $out = [];
        foreach (($resp['cities'] ?? []) as $c) {
            if (empty($c['id'])) { continue; }
            $out[] = ['city_id' => (int) $c['id'], 'name' => (string) ($c['name'] ?? ''),
                'name_lat' => (string) ($c['nameEn'] ?? ''), 'post_code' => (string) ($c['postCode'] ?? ''),
                'region' => (string) ($c['regionName'] ?? '')];
        }
        return $out;
    }
    public static function parse_offices(array $resp): array {
        $out = [];
        foreach (($resp['offices'] ?? []) as $o) {
            if (empty($o['id'])) { continue; }
            $out[] = ['office_id' => (int) $o['id'], 'code' => (string) ($o['code'] ?? ''),
                'city_id' => (int) ($o['address']['city']['id'] ?? 0),
                'type' => !empty($o['isAPS']) ? 'automat' : 'office', 'name' => (string) ($o['name'] ?? ''),
                'address' => (string) ($o['address']['fullAddress'] ?? '')];
        }
        return $out;
    }
    public static function parse_streets(array $resp): array {
        $out = [];
        foreach (($resp['streets'] ?? []) as $s) {
            $name = (string) ($s['name'] ?? '');
            if ($name === '') { continue; }
            $out[] = ['id' => (int) ($s['id'] ?? 0), 'name' => $name, 'type' => '', 'label' => $name];
        }
        return $out;
    }

    // ── Quote (calculate mode) ──────────────────────────────────────────────

    /**
     * Fetch the sender profile from Econt's ProfileService and cache it for one day.
     * A sender is REQUIRED by Econt's createLabel API ("подател" error otherwise).
     *
     * @return array{client:array,address:array}
     */
    private function sender_profile(): array {
        $cached = get_transient('bgc_econt_sender');
        if (is_array($cached) && isset($cached['client'], $cached['address'])) {
            return $cached;
        }
        $resp     = $this->post_json($this->base . '/Profile/ProfileService.getClientProfiles.json', []);
        $profile  = $resp['profiles'][0] ?? [];
        $client   = $profile['client'] ?? [];
        $address  = $profile['addresses'][0] ?? [];
        if (empty($client['name']) || empty($address['city']['id'])) {
            throw new BGC_Api_Exception('Econt sender profile missing client/address — check getClientProfiles');
        }
        $sender   = ['client' => $client, 'address' => $address];
        set_transient('bgc_econt_sender', $sender, DAY_IN_SECONDS);
        return $sender;
    }

    /**
     * Resolve the Econt office string code (e.g. "1000") from a numeric office_id.
     * Uses BGC_Nomenclature::office_by_id which returns a row with a 'code' key
     * (populated if the schema has been extended; returns '' otherwise).
     */
    private function office_code(int $site_id, int $office_id): string {
        if ($office_id <= 0) { return ''; }
        if (class_exists('BGC_Nomenclature')) {
            $row = BGC_Nomenclature::office_by_id('econt', $office_id);
            if ($row && isset($row['code'])) { return (string) $row['code']; }
        }
        return '';
    }

    /**
     * Build the request body for createLabel in calculate mode.
     *
     * @param array $s      Shipment descriptor (method, site_id, office_code, weight_kg, street_name, street_no …)
     * @param array $sender Sender profile as returned by sender_profile(): {client, address}
     */
    public static function build_calculate_body(array $s, array $sender): array {
        $label = [
            'senderClient'  => [
                'name'   => (string) ($sender['client']['name'] ?? ''),
                'phones' => array_slice($sender['client']['phones'] ?? [], 0, 1),
            ],
            'senderAddress' => [
                'city'  => ['id' => (int) ($sender['address']['city']['id'] ?? 0)],
                'street' => (string) ($sender['address']['street'] ?? ''),
                'num'    => (string) ($sender['address']['num'] ?? ''),
                'other'  => (string) ($sender['address']['other'] ?? ''),
            ],
            'receiverClient' => [
                'name'   => 'Получател',
                'phones' => ['0000000000'],
            ],
            'packCount'           => 1,
            'weight'              => max(0.1, (float) ($s['weight_kg'] ?? 1.0)),
            'shipmentType'        => 'pack',
            'shipmentDescription' => 'Goods',
        ];

        if (($s['method'] ?? 'address') === 'address') {
            $label['receiverAddress'] = [
                'city'   => ['id' => (int) ($s['site_id'] ?? 0)],
                'street' => (string) ($s['street_name'] ?? ''),
                'num'    => (string) ($s['street_no'] ?? ''),
            ];
        } else {
            $label['receiverOfficeCode'] = (string) ($s['office_code'] ?? '');
        }

        return ['mode' => 'calculate', 'label' => $label];
    }

    /**
     * Parse the createLabel calculate response into a BGC_Quote.
     *
     * NOTE on VAT split: The fixture shows totalPrice === totalPriceWithVAT (both 4.68 EUR).
     * This means either VAT is already included in totalPrice, or VAT is zero-rated.
     * The tax field is set to max(0, totalPriceWithVAT - totalPrice) which will be 0.0 in
     * this fixture case. Verify at Task 7 with a live response to confirm the intended split.
     */
    public static function parse_price(array $resp, string $currency): BGC_Quote {
        $total    = (float) ($resp['label']['totalPrice'] ?? 0);
        $withVat  = (float) ($resp['label']['totalPriceWithVAT'] ?? $total);
        if ($total <= 0) { throw new BGC_Api_Exception('No price in Econt response'); }
        return new BGC_Quote($total, max(0.0, $withVat - $total),
            (string) ($resp['label']['currency'] ?? $currency), 'live');
    }

    /**
     * Fetch a live shipping quote via Econt's createLabel in calculate mode.
     */
    public function quote(array $shipment): BGC_Quote {
        if (($shipment['method'] ?? 'address') !== 'address') {
            $shipment['office_code'] = $this->office_code(
                (int) ($shipment['site_id'] ?? 0),
                (int) ($shipment['office_id'] ?? 0)
            );
        }
        $resp = $this->post_json(
            $this->base . '/Shipments/LabelService.createLabel.json',
            self::build_calculate_body($shipment, $this->sender_profile())
        );
        return self::parse_price($resp, (string) ($shipment['currency'] ?? 'EUR'));
    }
}
