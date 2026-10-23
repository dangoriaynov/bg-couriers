<?php
defined('ABSPATH') || exit;

class BGCouriers_Speedy extends BGCouriers_Abstract_Courier {
    const BG_COUNTRY_ID = 100;
    /** Speedy's country ids by ISO alpha-2; only countries with a full site nomenclature (siteNomen 1). */
    const COUNTRY_IDS = ['BG' => 100, 'RO' => 642];
    const BASE = 'https://api.speedy.bg/v1'; // Speedy has no separate demo/sandbox host
    /** Operation code of "delivery to the customer". */
    const OP_DELIVERED = '-14';
    /** Operations meaning the parcel is physically with Speedy. */
    const OPS_PICKED_UP = ['39', '11'];

    private string $user; private string $pass; private string $base; private array $sender;

    public function __construct(array $config) {
        $this->user = (string) ($config['username'] ?? '');
        $this->pass = (string) ($config['password'] ?? '');
        $this->sender = (array) ($config['sender'] ?? []);
        $this->base = self::BASE;
    }

    private function sender_block(): array {
        // No clientId: Speedy derives the sender from the API user; an explicit one triggers "Sender client not found".
        $sender = [];
        $s = $this->sender;
        if (!empty($s['name']))  { $sender['contactName'] = $s['name']; }
        if (!empty($s['phone'])) { $sender['phone1'] = ['number' => $s['phone']]; }
        if (!empty($s['email'])) { $sender['email'] = $s['email']; }
        $office = self::dropoff_office();
        if ($office > 0) { $sender['dropoffOfficeId'] = $office; }
        return $sender;
    }

    /** The office the shop hands parcels in at; 0 = a courier collects. Without it Speedy prices a pickup. */
    public static function dropoff_office(): int {
        return function_exists('get_option') ? max(0, (int) get_option('bgcouriers_speedy_dropoff_office', 0)) : 0;
    }

    /** true/false from /location/office/{id}, null when Speedy could not be asked; the cached list lacks the flag. */
    public function office_takes_dropoff(int $office_id): ?bool {
        if ($office_id <= 0) { return false; }
        try {
            $r = $this->post_json($this->base . '/location/office/' . $office_id, $this->auth([]));
        } catch (\Throwable $e) {
            return null;
        }
        if (empty($r['office']) || !is_array($r['office'])) { return null; }
        return !empty($r['office']['dropOffAllowed']);
    }

    /** The address as Speedy wants it; a bare street name is refused when the town has several, so id or type + name go first. */
    public static function build_address(int $site_id, array $fields, string $country = ''): array {
        $addr = ['countryId' => self::dest_country_id($country), 'siteId' => $site_id];
        $map = ['complex' => 'complexName', 'street' => 'streetName', 'street_no' => 'streetNo',
                'block' => 'blockNo', 'entrance' => 'entranceNo', 'floor' => 'floorNo',
                'apartment' => 'apartmentNo', 'note' => 'addressNote'];
        foreach ($map as $k => $api) {
            $v = trim((string) ($fields[$k] ?? ''));
            if ($v !== '') { $addr[$api] = $v; }
        }
        $street_id = (int) ($fields['street_id'] ?? 0);
        $type      = trim((string) ($fields['street_type'] ?? ''));
        if ($street_id > 0) {
            $addr['streetId'] = $street_id;
            unset($addr['streetName']);
        } elseif ($type !== '' && isset($addr['streetName'])) {
            $addr['streetType'] = $type;
        }
        return $addr;
    }

    /** Looks the street id up on Speedy's list when the order has none; several of that name with no type is refused here. */
    public function resolve_street(int $site_id, array $fields, string $country = ''): array {
        $name = trim((string) ($fields['street'] ?? ''));
        if ((int) ($fields['street_id'] ?? 0) > 0 || $name === '' || $site_id <= 0) { return $fields; }
        // Speedy's search finds nothing for a name with the type in it, so search by the bare name.
        $split = BGCouriers_Street::split($name);
        try {
            $rows = $this->search_streets($site_id, $split['name'], $country);
        } catch (\Exception $e) {
            return $fields;
        }
        $hits = BGCouriers_Street::exact($rows, $name);
        $type = (string) ($fields['street_type'] ?? '');
        if (count($hits) > 1 && trim($type) !== '') {
            $hits = array_values(array_filter($hits, static function ($r) use ($type) { return BGCouriers_Street::same_type((string) $r['type'], $type); })) ?: $hits;
        }
        if (count($hits) === 1) {
            $fields['street_id'] = (int) $hits[0]['id'];
            return $fields;
        }
        if (count($hits) > 1) {
            throw new BGCouriers_Api_Exception(esc_html(sprintf(
                /* translators: 1: the street as it is written on the order, 2: the streets of that name, as the courier lists them. */
                __('Speedy lists more than one street called "%1$s" in this town (%2$s) and refuses the name alone. Edit the delivery address and pick one from the list.', 'bg-couriers'),
                $name, implode(', ', array_map(static function ($r) { return $r['label']; }, $hits)))));
        }
        return $fields;
    }

    public function id(): string { return 'speedy'; }
    public function label(): string { return 'Speedy'; }

    public function ppp_payout_by_default(): bool { return true; }

    public function credential_hint(): array {
        return [
            'intro' => __('Speedy issues API access to registered business clients - there is no instant self-signup.', 'bg-couriers'),
            'steps' => [
                __('Have (or open) a Speedy business contract.', 'bg-couriers'),
                __('Request REST API access (ask for a test account first) from your Speedy account manager, or via the integration contact on the Speedy "System integration" page below.', 'bg-couriers'),
                __('Speedy issues an API username + password for api.speedy.bg.', 'bg-couriers'),
                __('Enter the username and password below, click Validate, then Sync.', 'bg-couriers'),
            ],
            'receive'   => __('API username + password', 'bg-couriers'),
            'url_label' => __('Speedy system integration:', 'bg-couriers'),
            'url'       => 'https://www.speedy.bg/en/system-integration',
        ];
    }

    /** Only Romania has been proven end to end. */
    public function intl_countries(): array { return ['RO']; }

    /** Speedy's numeric country id for an ISO code; 0 when Speedy is not asked to deliver there. */
    public static function country_id(string $iso): int {
        return self::COUNTRY_IDS[strtoupper(trim($iso))] ?? 0;
    }

    /** '' is the shop's own country; an unknown one is refused rather than turned into Bulgaria. */
    private static function dest_country_id(string $iso): int {
        $c = strtoupper(trim($iso));
        if ($c === '' || $c === 'BG') { return self::BG_COUNTRY_ID; }
        $id = self::country_id($c);
        if ($id <= 0) {
            /* translators: 1: courier name, 2: country code. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s does not deliver to %2$s.', 'bg-couriers'), 'Speedy', $c)));
        }
        return $id;
    }

    /** Parcel service per destination: 505 Bulgaria, 202 Romania; the two are disjoint. */
    const SERVICE_IDS = ['BG' => 505, 'RO' => 202];

    /** The service id for a destination country; '' = the shop's own. Unknown countries are refused. */
    public static function service_id(string $iso = ''): int {
        $c = strtoupper(trim($iso));
        if ($c === '') { $c = 'BG'; }
        if (!isset(self::SERVICE_IDS[$c])) {
            /* translators: 1: courier name, 2: country code. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s has no delivery service for %2$s.', 'bg-couriers'), 'Speedy', $c)));
        }
        return self::SERVICE_IDS[$c];
    }

    private static function is_abroad(string $iso): bool {
        $c = strtoupper(trim($iso));
        return $c !== '' && $c !== 'BG';
    }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote', 'pickup']; }

    private function auth(array $body): array {
        // Default language BG; a per-call 'language' in $body wins.
        return array_merge(['userName' => $this->user, 'password' => $this->pass, 'language' => 'BG'], $body);
    }

    public function check_credentials(): bool {
        $r = $this->post_json($this->base . '/location/site', $this->auth(['countryId' => self::BG_COUNTRY_ID, 'name' => 'Sofia']));
        if (!empty($r['error'])) { self::refuse($r); }
        return !empty($r['sites']);
    }

    /** /location/site/csv/{countryId} answers the same CSV shape for every country. */
    public function fetch_cities(string $country = ''): array {
        // The CSV export returns ALL sites; plain /location/site returns a small default set.
        $res = $this->http_post($this->base . '/location/site/csv/' . self::dest_country_id($country), $this->auth(['language' => 'BG']));
        if (is_wp_error($res)) {
            /* translators: %s: the transport error. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The request failed: %s', 'bg-couriers'), $res->get_error_message())));
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            /* translators: %s: the courier's own error text, or the HTTP status. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The request failed: %s', 'bg-couriers'), 'HTTP ' . $code . ': ' . self::error_text((string) wp_remote_retrieve_body($res)))));
        }
        return self::parse_sites_csv((string) wp_remote_retrieve_body($res));
    }

    /** The escape argument is explicitly empty: Speedy's CSV is RFC-4180 and PHP 8.4 deprecates the backslash default. */
    public static function parse_sites_csv(string $csv): array {
        // Speedy refuses with HTTP 200 and a JSON error where the CSV should be.
        if (strncmp(ltrim($csv), '{', 1) === 0) {
            $j = json_decode($csv, true);
            if (is_array($j) && !empty($j['error'])) { self::refuse($j); }
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (!$lines || count($lines) < 2) { return []; }
        $header = str_getcsv((string) array_shift($lines), ',', '"', '');
        $idx = array_flip($header);
        $get = function (array $row, string $name) use ($idx) {
            return isset($idx[$name], $row[$idx[$name]]) ? (string) $row[$idx[$name]] : '';
        };
        $out = [];
        foreach ($lines as $line) {
            if ($line === '') { continue; }
            $row = str_getcsv($line, ',', '"', '');
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

    public function fetch_offices(int $city_id, string $country = ''): array {
        $body = ['countryId' => self::dest_country_id($country), 'language' => 'BG'];
        if ($city_id > 0) { $body['siteId'] = $city_id; }
        $r = $this->post_json($this->base . '/location/office', $this->auth($body));
        return self::parse_offices($r);
    }

    public function search_streets(int $site_id, string $term, string $country = ''): array {
        $r = $this->post_json($this->base . '/location/street', $this->auth([
            'countryId' => self::dest_country_id($country), 'language' => 'BG', 'siteId' => $site_id, 'name' => $term,
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
        // A refusal is HTTP 200 with an `error` node and no offices.
        if (!empty($resp['error'])) { self::refuse($resp); }
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

    public function quote(array $shipment): BGCouriers_Quote {
        $resp = $this->post_json($this->base . '/calculate', $this->auth(self::build_calculate_body($shipment)));
        return self::parse_price($resp, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
    }

    public static function build_calculate_body(array $s): array {
        $country   = (string) ($s['country'] ?? '');
        $recipient = ['privatePerson' => true];
        if (($s['method'] ?? 'address') === 'address') {
            $recipient['addressLocation'] = ['countryId' => self::dest_country_id($country), 'siteId' => (int) $s['site_id']];
        } else {
            $recipient['pickupOfficeId'] = (int) $s['office_id'];
        }
        $service = ['autoAdjustPickupDate' => true, 'serviceIds' => [self::service_id($country)]];
        if (!empty($s['cod_amount'])) {
            // The same processingType the label will use; Speedy prices CASH and POSTAL_MONEY_TRANSFER differently.
            $service['additionalServices']['cod'] = [
                'amount'         => (float) $s['cod_amount'],
                'processingType' => (class_exists('BGCouriers_Settings') && BGCouriers_Settings::ppp_payout_reaches('speedy', $country))
                    ? 'POSTAL_MONEY_TRANSFER' : 'CASH',
            ];
        }
        $body = [
            // No 'sender' unless there is something to say: an empty [] serialises to a JSON array, which Speedy rejects.
            'recipient' => $recipient,
            'service'   => $service,
            'content'   => ['parcelsCount' => 1, 'totalWeight' => (float) ($s['weight_kg'] ?? 2.0)],
            // Abroad a RECIPIENT payer is refused on service 202, so the sender pays.
            'payment'   => ['courierServicePayer' => self::is_abroad($country) ? 'SENDER' : 'RECIPIENT'],
        ];
        // The quote must start where the shipment will.
        $office = self::dropoff_office();
        if ($office > 0) { $body['sender'] = ['dropoffOfficeId' => $office]; }
        return $body;
    }

    public static function parse_price(array $resp, string $currency): BGCouriers_Quote {
        $calc = $resp['calculations'][0] ?? null;
        if (!$calc || empty($calc['price'])) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s sent no price in its answer.', 'bg-couriers'), 'Speedy')));
        }
        $p = $calc['price'];
        $total = (float) ($p['total'] ?? $p['amount'] ?? 0);
        $vat   = (float) ($p['vat'] ?? 0);
        $base  = isset($p['amount']) ? (float) $p['amount'] : max(0.0, $total - $vat);
        return new BGCouriers_Quote($base, $vat, (string) ($p['currency'] ?? $currency), 'live');
    }

    /**
     * Did Speedy apply the COD? A dropped COD leaves codPremium at zero.
     * @return string[]
     */
    public static function check_applied(array $body, array $resp): array {
        $want = (float) ($body['service']['additionalServices']['cod']['amount'] ?? 0);
        if ($want <= 0) { return []; }
        $premium = (float) ($resp['price']['details']['codPremium']['amount'] ?? 0);
        if ($premium > 0) { return []; }
        /* translators: %s: the cash-on-delivery amount that was requested */
        return [sprintf(__('Cash on delivery of %s was sent but Speedy did not apply it - the waybill collects nothing.', 'bg-couriers'), (string) $want)];
    }

    public function create_label(\WC_Order $order): BGCouriers_Label {
        $plain = $this->build_shipment_body($order);
        $body  = $this->auth($plain);
        $resp  = $this->post_json($this->base . '/shipment', $body);
        // Validation failures are HTTP 200 + an `error` object.
        if (!empty($resp['error'])) {
            throw new BGCouriers_Api_Exception(esc_html('Speedy: ' . (string) ($resp['error']['message']
                ?? __('the shipment was rejected', 'bg-couriers'))));
        }
        $id = self::parse_shipment_id($resp);
        if ($id === '') {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s sent no shipment id in its answer.', 'bg-couriers'), 'Speedy')));
        }
        return new BGCouriers_Label($id, '', self::check_applied($plain, $resp));
    }

    private function build_shipment_body(\WC_Order $order): array {
        $parcel_n = BGCouriers_Order::parcels($order);
        $method  = (string) $order->get_meta('_bgcouriers_method') ?: 'address';
        $site_id = (int) $order->get_meta('_bgcouriers_site_id');
        $office  = (int) $order->get_meta('_bgcouriers_office_id');
        $country = BGCouriers_Settings::order_country($order);
        $abroad  = self::is_abroad($country);
        // trim() so an empty shipping name (" ") still falls back to billing.
        $recipient = [
            'privatePerson' => true,
            'clientName'    => trim($order->get_formatted_shipping_full_name()) ?: trim($order->get_formatted_billing_full_name()),
            // Abroad the phone goes in full international form.
            'phone1'        => ['number' => $abroad
                ? (BGCouriers_Phone::e164((string) $order->get_billing_phone(), BGCouriers_Phone::cc_for($country)) ?: (string) $order->get_billing_phone())
                : $order->get_billing_phone()],
            'email'         => BGCouriers_Settings::label_email($order),
        ];
        if ($method === 'address') {
            $recipient['address'] = self::build_address($site_id, $this->resolve_street($site_id, [
                'complex'     => $order->get_meta('_bgcouriers_complex'),
                'street'      => $order->get_meta('_bgcouriers_street_name'),
                'street_id'   => (int) $order->get_meta('_bgcouriers_street_id'),
                'street_type' => $order->get_meta('_bgcouriers_street_type'),
                'street_no'   => $order->get_meta('_bgcouriers_street_no'),
                'block'       => $order->get_meta('_bgcouriers_block'),
                'entrance'    => $order->get_meta('_bgcouriers_entrance'),
                'floor'       => $order->get_meta('_bgcouriers_floor'),
                'apartment'   => $order->get_meta('_bgcouriers_apartment'),
                'note'        => $order->get_meta('_bgcouriers_address_note'),
            ], $country), $country);
        } else {
            $recipient['pickupOfficeId'] = $office;
        }
        // Speedy refuses a RECIPIENT payer on the international service.
        $payer   = $abroad ? 'sender' : self::service_payer('speedy', $order);
        $package = in_array(get_option('bgcouriers_speedy_package', 'BOX'), ['BOX', 'ENVELOPE', 'PALLET'], true)
            ? (string) get_option('bgcouriers_speedy_package', 'BOX')
            : 'BOX';
        $contents = BGCouriers_Settings::shipment_contents();
        $dims     = BGCouriers_Settings::box_dims($order);
        // One entry per box, weights adding back up to totalWeight.
        $parcels = [];
        foreach (BGCouriers_Order::parcel_weights(self::order_weight_kg($order), $parcel_n) as $i => $kg) {
            $parcels[] = ['seqNo' => $i + 1, 'weight' => $kg,
                          'size' => ['width' => $dims['width'], 'depth' => $dims['length'], 'height' => $dims['height']]];
        }
        $body = [
            'recipient' => $recipient,
            'service'   => ['autoAdjustPickupDate' => true, 'serviceId' => self::service_id($country)],
            'content'   => ['parcelsCount' => $parcel_n, 'contents' => $contents, 'package' => $package,
                            'totalWeight' => self::order_weight_kg($order),
                            // ShipmentParcelSize {width,height,depth} cm.
                            'parcels'     => $parcels],
            'payment'   => ['courierServicePayer' => $payer === 'recipient' ? 'RECIPIENT' : 'SENDER'],
            /* translators: %s: order number, printed on the waybill as the sender's reference */
            'ref1'      => sprintf(__('Order %s', 'bg-couriers'), $order->get_order_number()),
        ];
        // Only for orders actually paid cash-on-delivery.
        if ($order->get_payment_method() === 'cod') {
            $cod = self::cod_for_payer($order, $payer);
            if ($cod > 0) {
                // Abroad always CASH: the postal money transfer is refused for a foreign address.
                $processing_type = BGCouriers_Settings::ppp_payout_reaches('speedy', $country) ? 'POSTAL_MONEY_TRANSFER' : 'CASH';
                // No ignoreIfNotApplicable: it lets Speedy drop the COD and still accept the shipment.
                $body['service']['additionalServices']['cod'] = [
                    'amount'         => $cod,
                    'processingType' => $processing_type,
                ];
                // ON sends nothing (account default); OFF sets cardPaymentForbidden.
                if (get_option('bgcouriers_speedy_' . $method . '_card_payment', 'yes') !== 'yes') {
                    $body['service']['additionalServices']['cod']['cardPaymentForbidden'] = true;
                }
            }
        }
        // Never for lockers (no courier to supervise) nor abroad (forbidden on the international service).
        $obpd_val = BGCouriers_Settings::open_before_pay();
        if (!$abroad && $method !== 'automat' && ($obpd_val === 'open' || $obpd_val === 'test')) {
            $body['service']['additionalServices']['obpd'] = [
                'option'                  => strtoupper($obpd_val),
                'returnShipmentServiceId' => self::service_id($country),
                'returnShipmentPayer'     => 'SENDER',
                'ignoreIfNotApplicable'   => true,
            ];
        }
        // declaredValue is sent without ignoreIfNotApplicable, for the same reason as cod.
        $insured = BGCouriers_Order::insurance($order);
        if ($insured <= 0 && get_option('bgcouriers_speedy_declared_value', 'no') === 'cod') {
            // Read out of the body, not recomputed: whether the delivery fee rides in the COD is a setting.
            $insured = (float) ($body['service']['additionalServices']['cod']['amount'] ?? 0);
        }
        if ($insured > 0) {
            $body['service']['additionalServices']['declaredValue'] = ['amount' => $insured];
        }
        // Paid by the SENDER; not abroad, where the return voucher is forbidden.
        if (!$abroad && BGCouriers_Settings::return_voucher('speedy')) {
            $body['service']['additionalServices']['returns']['returnVoucher'] =
                ['serviceId' => self::service_id($country), 'payer' => 'SENDER'];
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
            // Print in Bulgarian; the /print default is the account language.
            'language'  => 'BG',
            'paperSize' => in_array($paper_size, ['A6', 'A4', 'A4_4xA6'], true) ? $paper_size : 'A6',
            'parcels'   => array_values(array_map(static fn($id) => ['parcel' => ['id' => (string) $id]], $parcel_ids)),
            'additionalWaybillSenderCopy' => 'NONE',
        ];
    }

    /** Speedy prints several waybills in one call and lays them out itself. */
    public function has_native_batch(): bool { return true; }

    public function batch_label_pdf(array $waybills, string $format = ''): string {
        $paper = in_array($format, ['A6', 'A4'], true) ? $format : BGCouriers_Settings::label_paper_size('speedy');
        $ids   = array_values(array_filter(array_map('strval', $waybills)));
        if ($paper !== 'A4') { return $this->print_labels($ids, $paper); }
        // Speedy's plain A4 renders one waybill in the left half of a landscape sheet; pair them two-up, else fall back to A4_4xA6.
        $pdf = $this->print_labels($ids, 'A4');
        $res = BGCouriers_Label_Packer::compose_a4([$pdf]);
        if ($res['pdf'] !== '') { return $res['pdf']; }
        return $this->print_labels($ids, 'A4_4xA6');
    }

    public function print_labels(array $parcel_ids, string $paper_size): string {
        $res = $this->http_post($this->base . '/print', $this->auth(self::build_print_body($parcel_ids, $paper_size)));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s could not print the label.', 'bg-couriers'), 'Speedy')));
        }
        $body = (string) wp_remote_retrieve_body($res);
        // A 200 whose body is not a PDF is Speedy's JSON error, which says why.
        if (strncmp($body, '%PDF', 4) !== 0) {
            $j = json_decode($body, true);
            /* translators: 1: courier name, 2: the courier's own error text. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s could not print the label: %2$s', 'bg-couriers'), 'Speedy',
                (string) ($j['error']['message'] ?? __('the answer is not a PDF', 'bg-couriers')))));
        }
        return $body;
    }


    public function multi_parcel(): bool { return true; }
    public function label_formats(): array { return ['A6', 'A4']; }

    public function get_label_pdf(string $waybill, string $format = ''): string {
        $paper = in_array($format, ['A6', 'A4'], true) ? $format : BGCouriers_Settings::label_paper_size('speedy');
        return $this->print_labels([$waybill], $paper);
    }

    public function track(string $waybill): BGCouriers_Tracking {
        $resp = $this->post_json($this->base . '/track', $this->auth(['parcels' => [['id' => $waybill]], 'lastOperationOnly' => false]));
        return self::parse_tracking($resp);
    }

    /** /track: TrackedParcel {parcelId, operations, trackPhase}, operations {operationCode, dateTime, description, comment}. */
    public static function parse_tracking(array $resp): BGCouriers_Tracking {
        $parcel = $resp['parcels'][0] ?? [];
        $ops = $parcel['operations'] ?? [];
        $events = array_map(static fn($o) => [
            'code' => (string) ($o['operationCode'] ?? ''),
            // description is the operation name (BG); comment is the next-best text.
            'name' => (string) (($o['description'] ?? '') ?: ($o['comment'] ?? '')),
            'date' => (string) ($o['dateTime'] ?? ''),
        ], $ops);
        $status = $events ? end($events)['code'] : 'UNKNOWN';
        // trackPhase is usually omitted; fall back to the operation code.
        $codes = array_map(static fn($e) => (string) $e['code'], $events);
        $phase = (string) ($parcel['trackPhase'] ?? '');
        // Search the whole history: later document operations can follow -14.
        if ($phase === '' && in_array(self::OP_DELIVERED, $codes, true)) { $phase = 'DELIVERED'; }
        // 148 (label registered) means nothing has been collected yet.
        $handover = (bool) array_intersect(self::OPS_PICKED_UP, $codes);
        return new BGCouriers_Tracking((string) ($parcel['parcelId'] ?? ''), $status, $events, $phase, $handover);
    }

    public function cancel_label(string $waybill): bool {
        $resp = $this->post_json($this->base . '/shipment/cancel', $this->auth(['shipmentId' => $waybill, 'comment' => 'Cancelled from WooCommerce']));
        if (!empty($resp['error'])) { self::refuse($resp); }
        return true;
    }

    /** Operation 128 is "Canceled"; it appears about a second after the cancel, so ask twice. */
    public function is_cancelled(string $waybill): bool {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($attempt > 0) { sleep(1); }
            try {
                $resp = $this->post_json($this->base . '/track', $this->auth(['parcels' => [['id' => $waybill]]]));
                foreach (($resp['parcels'][0]['operations'] ?? []) as $op) {
                    if ((int) ($op['operationCode'] ?? 0) === 128
                        || stripos((string) ($op['description'] ?? ''), 'cancel') !== false) { return true; }
                }
            } catch (\Exception $e) { /* can't confirm -> treat as not cancelled */ }
        }
        return false;
    }
    // Pickup request

    /**
     * Body for POST /pickup. pickupDateTime must carry the shop's zone offset or Speedy answers HTTP 400.
     * @param string[] $waybills
     */
    public static function build_pickup_body(array $waybills, array $opts): array {
        $date = (string) ($opts['date'] ?? '');
        $from = (string) ($opts['from'] ?? '09:00');
        $tz   = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $at   = date_create_immutable($date . ' ' . $from . ':00', $tz);
        return [
            'pickupDateTime'         => $at ? $at->format('Y-m-d\TH:i:sO') : $date . 'T' . $from . ':00',
            'pickupScope'            => 'EXPLICIT_SHIPMENT_ID_LIST',
            'explicitShipmentIdList' => array_values(array_map('strval', $waybills)),
            'visitEndTime'           => (string) ($opts['to'] ?? '18:00'),
            'contactName'            => (string) ($opts['contact'] ?? ''),
            'phoneNumber'            => ['number' => (string) ($opts['phone'] ?? '')],
            'autoAdjustPickupDate'   => false,
        ];
    }

    /** Speedy refuses with HTTP 200 and an `error` node; this throws it in Speedy's words. */
    private static function refuse(array $resp): void {
        throw new BGCouriers_Api_Exception(esc_html('Speedy: ' . (string) ($resp['error']['message']
            ?? __('the request was refused', 'bg-couriers'))));
    }

    /** PickupResponse -> the order id. */
    public static function parse_pickup_id(array $resp): string {
        if (!empty($resp['error'])) {
            throw new BGCouriers_Api_Exception(esc_html('Speedy: ' . (string) ($resp['error']['message']
                ?? __('the pickup request was refused', 'bg-couriers'))));
        }
        return (string) ($resp['orders'][0]['id'] ?? '');
    }

    public function request_pickup(array $waybills, array $opts): string {
        if (empty($waybills)) { throw new BGCouriers_Api_Exception(esc_html__('There are no shipments to collect.', 'bg-couriers')); }
        $resp = $this->post_json($this->base . '/pickup', $this->auth(self::build_pickup_body($waybills, $opts)));
        $id   = self::parse_pickup_id($resp);
        if ($id === '') {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s sent no pickup request number in its answer.', 'bg-couriers'), 'Speedy')));
        }
        return $id;
    }

    /**
     * The cut-offs Speedy still accepts for a date; read-only.
     * @return string[]
     */
    public function pickup_terms(string $date): array {
        $ts = strtotime($date . ' 00:00:00');
        if (!$ts) { return []; }
        try {
            $resp = $this->post_json($this->base . '/pickup/terms', $this->auth([
                // MILLISECONDS: seconds are read as 1970 and refused.
                'startingDate' => $ts * 1000,
                // Required; 505 because the courier comes to the shop, which is in Bulgaria.
                'serviceId'    => 505,
            ]));
        } catch (\Exception $e) { return []; }
        if (!empty($resp['error'])) { return []; }
        return array_values(array_filter(array_map('strval', (array) ($resp['cutoffs'] ?? []))));
    }

    /** Kept separate so the millisecond rule is testable. */
    public static function build_pickup_terms_body(string $date): array {
        $ts = strtotime($date . ' 00:00:00');
        return ['startingDate' => $ts ? $ts * 1000 : 0, 'serviceId' => 505];
    }

    public function tracking_url(string $waybill): string { return 'https://www.speedy.bg/en/track-shipment?shipmentNumber=' . rawurlencode($waybill); }
}
