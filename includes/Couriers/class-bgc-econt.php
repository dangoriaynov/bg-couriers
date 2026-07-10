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

    public function enable_problems(): array {
        $p = parent::enable_problems();
        if (get_option('bgc_econt_cod_enabled') === 'yes') {
            $this->need_option($p, 'bgc_econt_cd_num',
                __('Cash on delivery is on, but no pay-out agreement is selected.', 'bg-couriers'),
                __('Choose a “CD pay-out agreement” below, or turn off cash on delivery.', 'bg-couriers'));
        }
        return $p;
    }

    /** Econt uses HTTP Basic, not a body credential - override the transport. */
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
                'address' => (string) ($o['address']['fullAddress'] ?? ''),
                'lat' => (float) ($o['address']['location']['latitude'] ?? 0),
                'lng' => (float) ($o['address']['location']['longitude'] ?? 0)];
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
            throw new BGC_Api_Exception('Econt sender profile missing client/address - check getClientProfiles');
        }
        $sender   = ['client' => $client, 'address' => $address];
        set_transient('bgc_econt_sender', $sender, DAY_IN_SECONDS);
        return $sender;
    }

    /** CD (наложен платеж) pay-out agreements from the client profile - `num` => human label. Cached. */
    public function cd_pay_options(): array {
        $cached = get_transient('bgc_econt_cd_options');
        if (is_array($cached)) { return $cached; }
        $out = [];
        try {
            $resp = $this->post_json($this->base . '/Profile/ProfileService.getClientProfiles.json', []);
            foreach ($resp['profiles'][0]['cdPayOptions'] ?? [] as $o) {
                $num = (string) ($o['num'] ?? '');
                if ($num === '') { continue; }
                $desc = !empty($o['moneyTransfer'])
                    ? __('postal money transfer', 'bg-couriers') . (!empty($o['officeCode']) ? ' (office ' . $o['officeCode'] . ')' : '')
                    : (!empty($o['IBAN']) ? 'IBAN ' . $o['IBAN'] : (string) ($o['method'] ?? ''));
                $out[$num] = $num . ' - ' . $desc;
            }
            set_transient('bgc_econt_cd_options', $out, HOUR_IN_SECONDS);
        } catch (\Exception $e) { /* leave empty on API failure */ }
        return $out;
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
                'street'  => (string) ($sender['address']['street'] ?? ''),
                'num'     => (string) ($sender['address']['num'] ?? ''),
                'quarter' => (string) ($sender['address']['quarter'] ?? ''), // Econt needs street+num OR quarter+other
                'other'   => (string) ($sender['address']['other'] ?? ''),
            ],
            'receiverClient' => [
                'name'   => 'Получател',
                'phones' => ['0000000000'],
            ],
            'packCount'           => 1,
            'weight'              => max(0.1, (float) ($s['weight_kg'] ?? 1.0)),
            'shipmentType'        => 'pack',
            'shipmentDescription' => ((string) get_option('bgc_econt_shipment_description', '')) ?: 'Goods',
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

    // ── Label creation ──────────────────────────────────────────────────────

    /**
     * Build the request body for createLabel in create mode (issues a real waybill).
     *
     * Uses the order's billing name/phone for receiverClient and the order's
     * delivery method meta (_bgc_method) to set either receiverOfficeCode or
     * receiverAddress.  Adds senderAgent for juridical-entity senders.
     *
     * @param \WC_Order $order       The WooCommerce order.
     * @param array     $sender      Sender profile as returned by sender_profile(): {client, address}.
     * @param string    $office_code Econt string office code (e.g. "1009") for office/automat orders.
     * @return array                 Full request body suitable for the Econt createLabel endpoint.
     */
    public static function build_label_body(\WC_Order $order, array $sender, string $office_code): array {
        $label = [
            'senderClient'  => [
                'name'   => (string) ($sender['client']['name'] ?? ''),
                'phones' => array_slice($sender['client']['phones'] ?? [], 0, 1),
            ],
            'senderAddress' => [
                'city'   => ['id' => (int) ($sender['address']['city']['id'] ?? 0)],
                'street'  => (string) ($sender['address']['street'] ?? ''),
                'num'     => (string) ($sender['address']['num'] ?? ''),
                'quarter' => (string) ($sender['address']['quarter'] ?? ''), // Econt needs street+num OR quarter+other
                'other'   => (string) ($sender['address']['other'] ?? ''),
            ],
            'receiverClient' => [
                'name'   => $order->get_formatted_billing_full_name(),
                'phones' => [$order->get_billing_phone()],
            ],
            'packCount'           => 1,
            'weight'              => max(0.1, (float) ($order->get_meta('_bgc_weight_kg') ?: 1.0)),
            'shipmentType'        => 'pack',
            'shipmentDescription' => ((string) get_option('bgc_econt_shipment_description', '')) ?: 'Goods',
        ];

        $method = (string) $order->get_meta('_bgc_method');
        if ($method === 'office' || $method === 'automat') {
            $label['receiverOfficeCode'] = $office_code;
        } else {
            // address delivery
            $label['receiverAddress'] = [
                'city'   => ['id' => (int) $order->get_meta('_bgc_site_id')],
                'street' => (string) $order->get_meta('_bgc_street_name'),
                'num'    => (string) $order->get_meta('_bgc_street_no'),
            ];
        }

        // Juridical senders require senderAgent (authorised person / MOL).
        if (!empty($sender['client']['juridicalEntity'])) {
            $mol = (string) ($sender['client']['molName'] ?? '');
            if ($mol === '') {
                $mol = (string) ($sender['client']['name'] ?? '');
            }
            $label['senderAgent'] = [
                'name'   => $mol,
                'phones' => array_slice($sender['client']['phones'] ?? [], 0, 1),
            ];
        }

        // Наложен платеж (COD) + packing list - only when enabled in the Econt settings.
        if (get_option('bgc_econt_cod_enabled', 'no') === 'yes') {
            $label['services'] = [
                'cdAmount'             => round((float) $order->get_total(), 2),
                'cdType'               => 'get', // collect from the receiver
                'cdCurrency'           => $order->get_currency(),
                'cdPayOptionsTemplate' => (string) get_option('bgc_econt_cd_num', ''),
            ];
            // Who pays the delivery (за чий рахунок): left to Econt's default - the API client (the
            // sender/merchant, ЗЕЛЕНИ ДОБАВКИ) is billed on their own account. Setting
            // paymentSenderMethod='credit' explicitly makes Econt demand a payer client number the
            // profile doesn't carry → rejected ("грешен клиентски номер за платец подател"). The COD
            // (goods + VAT) still returns to the merchant in full via ППП (the cdPayOptionsTemplate above).
            $label['packingListType'] = 'digital';
            $label['packingList']     = self::packing_list($order);
        }

        return ['mode' => 'create', 'label' => $label];
    }

    /**
     * Order line items as Econt PackingListElement[] - seq #, name, weight (kg), qty, price.
     * Econt totals the опис as sum(price × count), so price + weight are PER UNIT (tax-inclusive).
     * Econt requires that опис total to equal the наложен платеж (cdAmount = order total), so any
     * remainder (shipping, fees, rounding) is folded into one balancing line.
     */
    private static function packing_list(\WC_Order $order): array {
        $out = []; $i = 0; $sum = 0.0;
        foreach ($order->get_items() as $item) {
            $i++;
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $weight  = ($product && $product->get_weight() !== '') ? (float) wc_get_weight((float) $product->get_weight(), 'kg') : 0.0;
            $qty     = max(1, (int) $item->get_quantity());
            $sku     = $product ? (string) $product->get_sku() : '';
            $unit    = round(((float) $item->get_total() + (float) $item->get_total_tax()) / $qty, 2); // per-unit, tax-incl
            $sum    += $unit * $qty;
            $out[] = [
                'inventoryNum' => $sku !== '' ? $sku : (string) $i,
                'description'  => (string) $item->get_name(),
                'weight'       => round($weight, 3), // per unit; Econt scales by count
                'count'        => $qty,
                'price'        => $unit,
            ];
        }
        $remainder = round((float) $order->get_total() - $sum, 2); // shipping + fees + rounding
        if (abs($remainder) >= 0.01) {
            $out[] = [
                'inventoryNum' => 'S',
                'description'  => __('Shipping & fees', 'bg-couriers'),
                'weight'       => 0,
                'count'        => 1,
                'price'        => $remainder,
            ];
        }
        return $out;
    }

    /**
     * Extract the waybill number from a createLabel response.
     *
     * @param array $resp Decoded JSON response from the Econt createLabel endpoint.
     * @return string     The shipment number (waybill), or '' if not present.
     */
    public static function parse_shipment_id(array $resp): string {
        return (string) ($resp['label']['shipmentNumber'] ?? '');
    }

    /**
     * Issue a real waybill for the given order.  Live - do NOT call in tests.
     */
    public function create_label(\WC_Order $order): BGC_Label {
        $sender = $this->sender_profile();
        $office_code = $this->office_code(
            (int) $order->get_meta('_bgc_site_id'),
            (int) $order->get_meta('_bgc_office_id')
        );
        $resp = $this->post_json(
            $this->base . '/Shipments/LabelService.createLabel.json',
            self::build_label_body($order, $sender, $office_code)
        );
        return new BGC_Label(self::parse_shipment_id($resp));
    }

    /**
     * Fetch label PDF bytes for a given waybill.  Live - do NOT call in tests.
     */
    public function get_label_pdf(string $waybill): string {
        $resp = $this->post_json(
            $this->base . '/Shipments/LabelService.getShipmentStatuses.json',
            ['shipmentNumbers' => [$waybill]]
        );
        $url = (string) ($resp['shipmentStatuses'][0]['status']['pdfURL'] ?? '');
        if ($url === '') { throw new BGC_Api_Exception('No pdfURL in Econt getShipmentStatuses response'); }
        $r = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($r)) { throw new BGC_Api_Exception('Econt PDF download failed: ' . $r->get_error_message()); }
        return (string) wp_remote_retrieve_body($r);
    }

    // ── Tracking ────────────────────────────────────────────────────────────

    /**
     * Parse a getShipmentStatuses response into a BGC_Tracking object.
     *
     * Event mapping: destinationType → code, destinationDetailsEn → name, time(ms) → date.
     */
    public static function parse_tracking(array $resp): BGC_Tracking {
        $st     = $resp['shipmentStatuses'][0]['status'] ?? [];
        $events = array_map(static function (array $e): array {
            return [
                'code' => (string) ($e['destinationType'] ?? ''),
                'name' => (string) ($e['destinationDetailsEn'] ?? ''),
                'date' => (string) ($e['time'] ?? ''),
            ];
        }, $st['trackingEvents'] ?? []);

        $status = (string) ($st['shortDeliveryStatusEn'] ?? '');
        if ($status === '') {
            $last   = end($events);
            $status = $last ? (string) ($last['name'] ?? 'UNKNOWN') : 'UNKNOWN';
        }

        return new BGC_Tracking((string) ($st['shipmentNumber'] ?? ''), $status, $events);
    }

    /**
     * Fetch live tracking info for a waybill.  Live - do NOT call in tests.
     */
    public function track(string $waybill): BGC_Tracking {
        $resp = $this->post_json(
            $this->base . '/Shipments/LabelService.getShipmentStatuses.json',
            ['shipmentNumbers' => [$waybill]]
        );
        return self::parse_tracking($resp);
    }

    /**
     * Return the public Econt tracking URL for a waybill.
     */
    public function tracking_url(string $waybill): string {
        return 'https://www.econt.com/en/services/track-shipment/' . rawurlencode($waybill);
    }

    /**
     * Cancel/delete a waybill.  Live - do NOT call in tests.
     * The owner cancels test waybills manually; this is implemented for completeness.
     */
    public function cancel_label(string $waybill): bool {
        try {
            $resp = $this->post_json(
                $this->base . '/Shipments/LabelService.deleteLabels.json',
                ['shipmentNumbers' => [$waybill]]
            );
            return empty($resp['error']);
        } catch (BGC_Api_Exception $e) {
            return false;
        }
    }
}
