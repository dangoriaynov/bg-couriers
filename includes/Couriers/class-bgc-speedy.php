<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Speedy extends BGC_Abstract_Courier {
    const BG_COUNTRY_ID = 100;
    const LIVE = 'https://api.speedy.bg/v1';
    const DEMO = 'https://api.speedy.bg/v1'; // Speedy has no separate demo host; demo = test account creds

    private string $user; private string $pass; private string $base; private int $client_id;

    public function __construct(array $config) {
        $this->user = (string) ($config['username'] ?? '');
        $this->pass = (string) ($config['password'] ?? '');
        $this->client_id = (int) ($config['client_id'] ?? 0);
        $this->base = ($config['env'] ?? 'demo') === 'live' ? self::LIVE : self::DEMO;
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
        $r = $this->post_json($this->base . '/location/office', $this->auth(['countryId' => self::BG_COUNTRY_ID, 'siteId' => $city_id]));
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
    public function quote(array $shipment): BGC_Quote { throw new BGC_Api_Exception('not implemented'); }
    public function create_label(\WC_Order $order): BGC_Label { throw new BGC_Api_Exception('not implemented'); }
    public function get_label_pdf(string $waybill): string { throw new BGC_Api_Exception('not implemented'); }
    public function cancel_label(string $waybill): bool { return false; }
    public function track(string $waybill): BGC_Tracking { throw new BGC_Api_Exception('not implemented'); }
    public function tracking_url(string $waybill): string { return 'https://www.speedy.bg/en/track-shipment?shipmentNumber=' . rawurlencode($waybill); }
}
