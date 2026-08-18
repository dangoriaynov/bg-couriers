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
            $p = array_merge($p, $this->payout_problems());
        }
        return $p;
    }

    /**
     * Does the CHOSEN pay-out agreement actually match what the shop believes it is getting?
     *
     * Econt marks each agreement with `moneyTransfer`: true is a ППП (пощенски паричен превод), false is a
     * plain transfer. The shop separately declares, per courier, whether that courier pays out by ППП -
     * and the whole cash-on-delivery fiscalisation rests on that declaration: with "I rely on the
     * courier's ППП", a courier said not to do ППП is dropped from checkout entirely. Nothing compared
     * the two, so this account was collecting cash on delivery under a bank-transfer agreement while the
     * setting claimed ППП, and the money came back as an ordinary pay-out.
     *
     * @return array<int,array{msg:string,fix:string}>
     */
    public function payout_problems(): array {
        $chosen = (string) get_option('bgcouriers_econt_cd_num', '');
        if ($chosen === '') { return []; }
        // Cached: this is asked every time the settings screen paints a courier tab, and the answer
        // changes only when the merchant signs a new agreement with Econt.
        $key  = 'bgcouriers_econt_cd_raw';
        $opts = get_transient($key);
        if (!is_array($opts)) {
            try {
                $resp = $this->post_json($this->base . '/Profile/ProfileService.getClientProfiles.json', []);
            } catch (\Exception $e) {
                return []; // connection problems are reported elsewhere; do not invent a payout warning
            }
            $opts = (array) ($resp['profiles'][0]['cdPayOptions'] ?? []);
            set_transient($key, $opts, HOUR_IN_SECONDS);
        }
        $agreement = null;
        foreach ($opts as $o) {
            if ((string) ($o['num'] ?? '') === $chosen) { $agreement = $o; break; }
        }
        if ($agreement === null) {
            return [[
                /* translators: %s: the agreement number stored in the settings */
                'msg' => sprintf(__('The selected cash-on-delivery agreement (%s) no longer exists on your Econt profile.', 'bg-couriers'), $chosen),
                'fix' => __('Pick one of the agreements your profile currently has, below.', 'bg-couriers'),
            ]];
        }
        // Deliberately NOT judged here: which agreement is "the right one" is the merchant's call with
        // Econt, and Econt's moneyTransfer flag does not map onto it the way it looks. CD139925 is marked
        // moneyTransfer=false yet is exactly what this shop selects in Econt's own UI as "начин на
        // изплащане". Reading that flag as "not a ППП" put a red error on a correctly configured shop.
        return [];
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

        // Наложен платеж, on the QUOTE as well as the label. Econt's fee for collecting the money is
        // part of the price it answers with (measured 2026-08-18: 5.06 -> 6.60 for a 50 EUR collection,
        // with shipmentNumber null, so nothing was created). Quoting without it told the customer a
        // price the shipment could never cost. Field names per Econt's own OpenAPI, ShippingLabelServices.
        $cd = (float) ($s['cod_amount'] ?? 0);
        if ($cd > 0 && get_option('bgcouriers_econt_cod_enabled', 'no') === 'yes') {
            $label['services'] = [
                'cdAmount'   => $cd,
                'cdType'     => 'get', // collect from the receiver, as the label does
                'cdCurrency' => (string) ($s['currency'] ?? 'EUR'),
            ];
            // The pay-out agreement, because the fee is quoted AGAINST it. Econt charges 1.54 EUR to
            // collect 50 without one and 0.78 with this shop's (the agreement carries a discount), so a
            // quote that omitted it would have overcharged by 0.76 for a shipment the label creates at
            // the lower price. The label sends the same option - the two must ask the same question.
            $tpl = (string) get_option('bgcouriers_econt_cd_num', '');
            if ($tpl !== '') { $label['services']['cdPayOptionsTemplate'] = $tpl; }
        }

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
     * VAT split, settled against the live account on 2026-08-18: calculate mode returns no VAT figure
     * at all. The whole price side of the response is totalPrice, senderDueAmount, receiverDueAmount,
     * otherDueAmount, discountAmount and the two cd* fields - there is no totalPriceWithVAT, so the
     * fallback below makes the tax 0 and totalPrice stands as the price.
     *
     * That is the right reading: Econt documents totalPrice as the price WITHOUT VAT, and the quote's
     * price is used as a shipping rate's net cost, which WooCommerce then taxes itself. Speedy, which
     * does return the split, is parsed the same way - its net `amount`, never its `total`.
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
        return self::parse_price($resp, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
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
            // The shop's own order number, so a parcel in Econt's panel can be matched back to the order
            // it came from. Econt was the ONE courier never told this: left unsent, the field shows the
            // waybill number, which is the number the merchant already had and cannot look anything up
            // with. Speedy has carried it as ref1 all along, BOX NOW as orderNumber, Pigeon as
            // external_reference. Field read off Econt's own OpenAPI spec
            // (ee.econt.com/services/openapi.yaml -> ShippingLabel.orderNumber, string), not guessed.
            'orderNumber'         => (string) $order->get_order_number(),
        ];

        // Econt was the one courier we never told how big the parcel is. It prices on volumetric weight
        // and on sizeUnder60cm, so leaving all three blank makes it assume rather than measure - and the
        // shop's own default (a flat 10x10x2 box) is the smallest thing it can honestly be told.
        $dims = BGCouriers_Settings::box_dims();
        $label['shipmentDimensionsL'] = (float) $dims['length'];
        $label['shipmentDimensionsW'] = (float) $dims['width'];
        $label['shipmentDimensionsH'] = (float) $dims['height'];
        $label['sizeUnder60cm']       = max($dims) < 60;

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
        $inspect = BGCouriers_Settings::open_before_pay();
        if ($method !== 'automat' && $inspect !== 'no') {
            $services['payAfterAccept'] = true;
            // Econt splits the two: seeing the parcel and trying it out. "test" is the stronger promise
            // and includes looking at it, so both flags go together.
            if ($inspect === 'test') { $services['payAfterTest'] = true; }
        }

        // Наложен платеж (COD) + packing list - only when enabled in the Econt settings AND the order is
        // actually paid cash-on-delivery (so a prepaid order is never charged again on delivery).
        // Who pays the courier fee. Econt CAN charge the recipient - paymentReceiverMethod with the amount
        // as a percentage - which was verified live against ee.econt.com: the whole fee moves from
        // senderDueAmount to receiverDueAmount. paymentSenderMethod is still never set: 'credit' makes
        // Econt demand a payer client number the profile does not carry ("грешен клиентски номер за платец
        // подател"), and leaving it unset already means "bill the API client", which is what we want when
        // the merchant pays.
        $payer = self::service_payer('econt', $order);
        if ($payer === 'recipient') {
            $label['paymentReceiverMethod']          = 'cash';
            $label['paymentReceiverAmountIsPercent'] = true;
            $label['paymentReceiverAmount']          = 100;
        }

        // Наложен платеж (COD) + packing list - only when enabled in the Econt settings AND the order is
        // actually paid cash-on-delivery (so a prepaid order is never charged again on delivery).
        if (get_option('bgcouriers_econt_cod_enabled', 'no') === 'yes' && $order->get_payment_method() === 'cod') {
            // Goods-only when the recipient pays the courier at the door (they must not be charged the
            // delivery twice - once in the COD and again as the courier's own fee); the full total when
            // the merchant already charged shipping at checkout.
            $cod = self::cod_for_payer($order, $payer);
            $services['cdAmount']             = $cod;
            $services['cdType']               = 'get'; // collect from the receiver
            $services['cdCurrency']           = $order->get_currency();
            $services['cdPayOptionsTemplate'] = (string) get_option('bgcouriers_econt_cd_num', '');
            // Econt totals the опис as sum(price x count) and REJECTS the label unless it equals cdAmount,
            // so the list has to balance to whatever we are collecting, not to the order total.
        }

        // Частична доставка: the recipient may open the parcel at the counter and keep only part of it.
        // Econt reconciles what is kept against the packing list above, which is why this is only offered
        // alongside cash on delivery - without a collection there is nothing to settle at the door. The
        // merchant decides (Drusoft's plugin turns it on for every COD order; here it is a setting,
        // because the unkept half travels back at the merchant's expense).
        if (get_option('bgcouriers_econt_partial_delivery', 'no') === 'yes' && isset($services['cdAmount'])) {
            $label['partialDelivery'] = true;
        }

        // The опис lists what is IN the parcel, which has nothing to do with how it is paid for - it used
        // to be built inside the cash-on-delivery block, so a prepaid shipment left with no itemised list
        // at all. With COD it must total exactly the collected amount (Econt rejects the label otherwise);
        // without one there is nothing to balance against, so the items stand on their own.
        $lines = self::packing_list($order, isset($services['cdAmount']) ? (float) $services['cdAmount'] : null);
        if ($lines) {
            $label['packingListType'] = 'digital';
            $label['packingList']     = $lines;
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
    private static function packing_list(\WC_Order $order, ?float $cod_total): array {
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
        // Whatever is left between the item lines and the amount actually being collected: the delivery
        // and any fees when the merchant charged them, or just rounding when the recipient pays the
        // courier directly and only the goods are collected. With nothing being collected there is no
        // total to reconcile with, so the list is simply what is in the box.
        if ($cod_total === null) { return $out; }
        $remainder = round($cod_total - $sum, 2);
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
        $body = self::build_label_body($order, $sender, $office_code);
        $resp = $this->post_json($this->base . '/Shipments/LabelService.createLabel.json', $body);
        // The create response already carries the label PDF URL, so hand it back and skip a second call.
        return new BGCouriers_Label(
            self::parse_shipment_id($resp),
            (string) ($resp['label']['pdfURL'] ?? ''),
            self::check_applied($body, $resp)
        );
    }

    /**
     * Compare what Econt ACTUALLY put on the shipment against what we asked for.
     *
     * Econt echoes the applied services back on create - each one a {type, description, count, price,
     * paymentSide} row - so the cash-on-delivery can be confirmed without a second call: it is the row
     * with type 'CD', whose `count` is the amount to collect. A waybill that prints "НП: 0.00" has no
     * such row, and that is exactly the failure nobody notices until the parcel is already gone.
     *
     * @param array $body The request we sent.
     * @param array $resp Econt's create response.
     * @return string[] Problems, empty when everything we asked for was applied.
     */
    public static function check_applied(array $body, array $resp): array {
        $problems = [];
        $want_cd  = (float) ($body['label']['services']['cdAmount'] ?? 0);
        $services = (array) ($resp['label']['services'] ?? []);
        $got_cd   = null;
        foreach ($services as $s) {
            if (is_array($s) && (string) ($s['type'] ?? '') === 'CD') { $got_cd = (float) ($s['count'] ?? 0); break; }
        }
        if ($want_cd > 0 && $got_cd === null) {
            /* translators: %s: the cash-on-delivery amount that was requested */
            $problems[] = sprintf(__('Cash on delivery of %s was sent but Econt did not apply it - the waybill collects nothing.', 'bg-couriers'), (string) $want_cd);
        } elseif ($want_cd > 0 && abs($got_cd - $want_cd) > 0.01) {
            /* translators: 1: requested amount, 2: amount the courier applied */
            $problems[] = sprintf(__('Cash on delivery mismatch: %1$s requested, %2$s on the waybill.', 'bg-couriers'), (string) $want_cd, (string) $got_cd);
        }
        // Who pays the courier. Econt states it per service row and again as the due amounts.
        $want_recipient = !BGCouriers_Settings::ship_in_total('econt');
        $receiver_due   = (float) ($resp['label']['receiverDueAmount'] ?? 0);
        if ($want_recipient && $receiver_due <= 0) {
            $problems[] = __('The recipient was supposed to pay the delivery, but Econt billed the sender.', 'bg-couriers');
        }
        return $problems;
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

        // Bulgarian first: this is what the merchant reads. The English variant stays the fallback.
        $status = (string) ($st['shortDeliveryStatus'] ?? $st['shortDeliveryStatusEn'] ?? '');
        if ($status === '') {
            $last   = end($events);
            $status = $last ? (string) ($last['name'] ?? 'UNKNOWN') : 'UNKNOWN';
        }

        // Econt answers both questions outright, so neither has to be guessed from prose: sendTime is
        // stamped when the parcel is handed over, deliveryTime when the receiver has it. Both are null
        // for the whole journey up to their moment - the status text meanwhile says things like
        // "Awaiting delivery to Econt" while the parcel is still sitting on the merchant's desk.
        $phase    = ((string) ($st['deliveryTime'] ?? '')) !== '' ? 'DELIVERED' : '';
        $handover = ((string) ($st['sendTime'] ?? '')) !== '' || $phase === 'DELIVERED';

        return new BGCouriers_Tracking((string) ($st['shipmentNumber'] ?? ''), $status, $events, $phase, $handover, true);
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
            // deleteLabels reports per-shipment results. "Пратка ... не е открита" is not a failure: the
            // shipment is not there any more, which is exactly what cancelling was for - a second attempt
            // (or a cancel of something Econt already dropped) must not report failure and leave the
            // merchant unable to re-issue. Anything else IS a failure.
            foreach (($resp['results'] ?? []) as $res) {
                if (empty($res['error'])) { continue; }
                $m = mb_strtolower((string) ($res['error']['message'] ?? ''));
                if (mb_strpos($m, 'не е откри') !== false || strpos($m, 'not found') !== false) { continue; }
                return false;
            }
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
