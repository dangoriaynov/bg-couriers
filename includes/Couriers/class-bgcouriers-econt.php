<?php
defined('ABSPATH') || exit;

class BGCouriers_Econt extends BGCouriers_Abstract_Courier {
    const PROD = 'https://ee.econt.com/services';
    const DEMO = 'https://demo.econt.com/ee/services';
    private $user; private $pass; private $sender; private $base;

    public function __construct(array $config) {
        $this->user   = (string) ($config['username'] ?? '');
        $this->pass   = (string) ($config['password'] ?? '');
        $this->sender = (array) ($config['sender'] ?? []);
        $this->base   = (defined('BGCOURIERS_ECONT_DEMO') && BGCOURIERS_ECONT_DEMO) ? self::DEMO : self::PROD;
    }

    public function id(): string { return 'econt'; }
    public function label(): string { return 'Econt'; }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }

    public function enable_problems(): array {
        $p = parent::enable_problems();
        if (get_option('bgcouriers_econt_cod_enabled') === 'yes') {
            $this->need_option($p, 'bgcouriers_econt_cd_num',
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
        // The ship-from address is chosen in settings (bgcouriers_econt_sender_address); the cache key includes it
        // so changing the setting takes effect immediately instead of waiting for a stale transient.
        $chosen = (string) get_option('bgcouriers_econt_sender_address', '');
        $key    = 'bgcouriers_econt_sender_' . ($chosen !== '' ? $chosen : 'auto');
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['client'], $cached['address'])) {
            return $cached;
        }
        $resp     = $this->post_json($this->base . '/Profile/ProfileService.getClientProfiles.json', []);
        $profile  = $resp['profiles'][0] ?? [];
        $client   = $profile['client'] ?? [];
        $addrs    = $profile['addresses'] ?? [];
        $address  = $addrs[0] ?? []; // default: first profile address
        if ($chosen !== '') {
            foreach ($addrs as $a) { if ((string) ($a['id'] ?? '') === $chosen) { $address = $a; break; } }
        }
        if (empty($client['name']) || empty($address['city']['id'])) {
            throw new BGCouriers_Api_Exception('Econt sender profile missing client/address - check getClientProfiles');
        }
        $sender   = ['client' => $client, 'address' => $address];
        set_transient($key, $sender, DAY_IN_SECONDS);
        return $sender;
    }

    /**
     * Ship-from addresses from the client profile as id => human label, for the settings dropdown.
     * Cached for a day; empty on API failure (the setting then just offers "automatic").
     *
     * @return array<string,string>
     */
    public function sender_addresses(): array {
        $cached = get_transient('bgcouriers_econt_sender_addrs');
        if (is_array($cached)) { return $cached; }
        $out = [];
        try {
            $resp = $this->post_json($this->base . '/Profile/ProfileService.getClientProfiles.json', []);
            foreach ($resp['profiles'][0]['addresses'] ?? [] as $a) {
                $id = (string) ($a['id'] ?? '');
                if ($id === '') { continue; }
                $parts = array_filter([
                    (string) ($a['city']['name'] ?? ''),
                    (string) ($a['quarter'] ?? ''),
                    trim(((string) ($a['street'] ?? '')) . ' ' . ((string) ($a['num'] ?? ''))),
                    (string) ($a['other'] ?? ''),
                ], static function ($v) { return trim((string) $v) !== ''; });
                $out[$id] = implode(', ', $parts) ?: $id;
            }
            set_transient('bgcouriers_econt_sender_addrs', $out, DAY_IN_SECONDS);
        } catch (\Exception $e) { /* leave empty on API failure */ }
        return $out;
    }

    /** CD (наложен платеж) pay-out agreements from the client profile - `num` => human label. Cached. */
    public function cd_pay_options(): array {
        $cached = get_transient('bgcouriers_econt_cd_options');
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
            set_transient('bgcouriers_econt_cd_options', $out, HOUR_IN_SECONDS);
        } catch (\Exception $e) { /* leave empty on API failure */ }
        return $out;
    }

    /**
     * Resolve the Econt office string code (e.g. "1000") from a numeric office_id.
     * Uses BGCouriers_Nomenclature::office_by_id which returns a row with a 'code' key
     * (populated if the schema has been extended; returns '' otherwise).
     */
    private function office_code(int $site_id, int $office_id): string {
        if ($office_id <= 0) { return ''; }
        if (class_exists('BGCouriers_Nomenclature')) {
            $row = BGCouriers_Nomenclature::office_by_id('econt', $office_id);
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
            'shipmentDescription' => BGCouriers_Settings::shipment_contents(),
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
     * Parse the createLabel calculate response into a BGCouriers_Quote.
     *
     * NOTE on VAT split: The fixture shows totalPrice === totalPriceWithVAT (both 4.68 EUR).
     * This means either VAT is already included in totalPrice, or VAT is zero-rated.
     * The tax field is set to max(0, totalPriceWithVAT - totalPrice) which will be 0.0 in
     * this fixture case. Verify at Task 7 with a live response to confirm the intended split.
     */
    public static function parse_price(array $resp, string $currency): BGCouriers_Quote {
        $total    = (float) ($resp['label']['totalPrice'] ?? 0);
        $withVat  = (float) ($resp['label']['totalPriceWithVAT'] ?? $total);
        if ($total <= 0) { throw new BGCouriers_Api_Exception('No price in Econt response'); }
        return new BGCouriers_Quote($total, max(0.0, $withVat - $total),
            (string) ($resp['label']['currency'] ?? $currency), 'live');
    }

    /**
     * Fetch a live shipping quote via Econt's createLabel in calculate mode.
     */
    public function quote(array $shipment): BGCouriers_Quote {
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
     * delivery method meta (_bgcouriers_method) to set either receiverOfficeCode or
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
            'receiverClient' => array_filter([
                'name'   => $order->get_formatted_billing_full_name(),
                'phones' => [$order->get_billing_phone()],
                'email'  => BGCouriers_Settings::label_email($order), // empty unless sharing is enabled
            ], static function ($v) { return $v !== ''; }),
            'packCount'           => 1,
            'weight'              => max(0.1, self::order_weight_kg($order)),
            'shipmentType'        => 'pack',
            'shipmentDescription' => BGCouriers_Settings::shipment_contents(),
        ];

        $method = (string) $order->get_meta('_bgcouriers_method');
        if ($method === 'office' || $method === 'automat') {
            $label['receiverOfficeCode'] = $office_code;
        } else {
            // Address delivery. Econt only accepts a street+num pair when the street matches its OWN
            // nomenclature; our checkout street is free text, so it errors ExInvalidAddress ("insufficient,
            // add street+num OR quarter+other"). We therefore also pass the full free-text address as
            // quarter+other, which Econt falls back to when the street is not recognised.
            // Address delivery. Econt validates the receiver address against the city; street+num is accepted
            // as long as the CITY is correct (verified via validateAddress: even a loosely-spelled street
            // validates 'normal' in the right city, and fails only when the city is wrong). We also pass the
            // building details in `other` and the complex as `quarter`, mirroring Econt's own address shape.
            $street = (string) $order->get_meta('_bgcouriers_street_name');
            $num    = (string) $order->get_meta('_bgcouriers_street_no');
            $other  = self::receiver_other($order);
            $addr   = ['city' => ['id' => (int) $order->get_meta('_bgcouriers_site_id')]];
            if ($street !== '') { $addr['street'] = $street; }
            if ($num !== '')    { $addr['num']    = $num; }
            $complex = (string) $order->get_meta('_bgcouriers_complex');
            if ($complex !== '') { $addr['quarter'] = $complex; }
            if ($other !== '')   { $addr['other']   = $other; }
            $label['receiverAddress'] = $addr;
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

        // Optional services (SMS, e-mail, pay-after-accept) — applied regardless of COD.
        $services = [];
        if (get_option('bgcouriers_econt_sms_notification', 'no') === 'yes') {
            $services['smsNotification'] = true;
        }
        $notify_email = trim((string) get_option('bgcouriers_econt_delivery_email', ''));
        if ($notify_email !== '') {
            $services['emailOnDelivery'] = $notify_email;
        }
        // "Виж преди да платиш" needs a courier at handover - never sent for Econtomat (automat)
        // deliveries; the API default applies there.
        if ($method !== 'automat' && get_option('bgcouriers_econt_pay_after_accept', 'no') === 'yes') {
            $services['payAfterAccept'] = true;
        }

        // Наложен платеж (COD) + packing list - only when enabled in the Econt settings AND the order is
        // actually paid cash-on-delivery (so a prepaid order is never charged again on delivery).
        if (get_option('bgcouriers_econt_cod_enabled', 'no') === 'yes' && $order->get_payment_method() === 'cod') {
            $services['cdAmount']             = round((float) $order->get_total(), 2);
            $services['cdType']               = 'get'; // collect from the receiver
            $services['cdCurrency']           = $order->get_currency();
            $services['cdPayOptionsTemplate'] = (string) get_option('bgcouriers_econt_cd_num', '');
            // Who pays the delivery (за чий рахунок): left to Econt's default - the API client (the
            // sender/merchant, ЗЕЛЕНИ ДОБАВКИ) is billed on their own account. Setting
            // paymentSenderMethod='credit' explicitly makes Econt demand a payer client number the
            // profile doesn't carry → rejected ("грешен клиентски номер за платец подател"). The COD
            // (goods + VAT) still returns to the merchant in full via ППП (the cdPayOptionsTemplate above).
            $label['packingListType'] = 'digital';
            $label['packingList']     = self::packing_list($order);
        }

        if (!empty($services)) {
            $label['services'] = $services;
        }

        return ['mode' => 'create', 'label' => $label];
    }

    /** Building details for Econt's `other` field: block/entrance/floor/apt plus any note (street+num are separate). */
    private static function receiver_other(\WC_Order $order): string {
        $parts = [];
        foreach (['_bgcouriers_block' => 'бл.', '_bgcouriers_entrance' => 'вх.', '_bgcouriers_floor' => 'ет.', '_bgcouriers_apartment' => 'ап.'] as $meta => $lbl) {
            $v = trim((string) $order->get_meta($meta));
            if ($v !== '') { $parts[] = $lbl . ' ' . $v; }
        }
        $note = trim((string) $order->get_meta('_bgcouriers_address_note'));
        if ($note !== '') { $parts[] = $note; }
        return implode(', ', $parts);
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
    public function create_label(\WC_Order $order): BGCouriers_Label {
        $sender = $this->sender_profile();
        $office_code = $this->office_code(
            (int) $order->get_meta('_bgcouriers_site_id'),
            (int) $order->get_meta('_bgcouriers_office_id')
        );
        $resp = $this->post_json(
            $this->base . '/Shipments/LabelService.createLabel.json',
            self::build_label_body($order, $sender, $office_code)
        );
        // The create response already carries the label PDF URL, so hand it back and skip a second call.
        return new BGCouriers_Label(self::parse_shipment_id($resp), (string) ($resp['label']['pdfURL'] ?? ''));
    }

    /**
     * Fetch label PDF bytes for a given waybill.  Live - do NOT call in tests.
     */
    public function label_formats(): array { return ['A4']; } // Econt labels are A4-landscape; no size param in the API

    public function get_label_pdf(string $waybill, string $format = ''): string {
        $resp = $this->post_json(
            $this->base . '/Shipments/ShipmentService.getShipmentStatuses.json',
            ['shipmentNumbers' => [$waybill]]
        );
        $url = (string) ($resp['shipmentStatuses'][0]['status']['pdfURL'] ?? '');
        if ($url === '') { throw new BGCouriers_Api_Exception('No pdfURL in Econt getShipmentStatuses response'); }
        $r = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($r)) { throw new BGCouriers_Api_Exception(esc_html('Econt PDF download failed: ' . $r->get_error_message())); }
        return (string) wp_remote_retrieve_body($r);
    }

    // ── Tracking ────────────────────────────────────────────────────────────

    /**
     * Parse a getShipmentStatuses response into a BGCouriers_Tracking object.
     *
     * Event mapping: destinationType → code, destinationDetailsEn → name, time(ms) → date.
     */
    public static function parse_tracking(array $resp): BGCouriers_Tracking {
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

        // deliveryTime is Econt's own answer to "has this been delivered": null for the entire journey,
        // a timestamp once the receiver has it. Prefer it over reading the status text, which is prose and
        // says things like "Awaiting delivery to Econt" while the parcel is still on the merchant's desk.
        $phase = ((string) ($st['deliveryTime'] ?? '')) !== '' ? 'DELIVERED' : '';

        return new BGCouriers_Tracking((string) ($st['shipmentNumber'] ?? ''), $status, $events, $phase);
    }

    /**
     * Fetch live tracking info for a waybill.  Live - do NOT call in tests.
     */
    public function track(string $waybill): BGCouriers_Tracking {
        $resp = $this->post_json(
            $this->base . '/Shipments/ShipmentService.getShipmentStatuses.json',
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
            if (!empty($resp['error'])) { return false; }
            // deleteLabels reports per-shipment results; a per-shipment error (e.g. "не е открита") means it
            // was not cancelled by this call - is_cancelled() then decides whether it was already gone.
            foreach (($resp['results'] ?? []) as $res) { if (!empty($res['error'])) { return false; } }
            return true;
        } catch (BGCouriers_Api_Exception $e) {
            return false;
        }
    }

    /** Already cancelled if getShipmentStatuses reports an "Анулирана"/canceled status or the shipment is gone. */
    public function is_cancelled(string $waybill): bool {
        try {
            $resp = $this->post_json($this->base . '/Shipments/ShipmentService.getShipmentStatuses.json', ['shipmentNumbers' => [$waybill]]);
            $st = $resp['shipmentStatuses'][0] ?? [];
            if (!empty($st['error'])) { return true; } // not found -> gone
            $status = mb_strtolower((string) ($st['status']['shortDeliveryStatus'] ?? '') . ' ' . (string) ($st['status']['shortDeliveryStatusEn'] ?? ''));
            if (mb_strpos($status, 'анулир') !== false || strpos($status, 'cancel') !== false) { return true; }
            foreach (($st['status']['trackingEvents'] ?? []) as $ev) {
                if (($ev['destinationType'] ?? '') === 'canceled') { return true; }
            }
        } catch (\Exception $e) {
            $m = mb_strtolower($e->getMessage());
            if (mb_strpos($m, 'не е откри') !== false || strpos($m, 'not found') !== false) { return true; }
        }
        return false;
    }
}
