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
}
