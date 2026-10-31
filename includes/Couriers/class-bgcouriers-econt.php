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

    public function ppp_payout_by_default(): bool { return true; }

    public function credential_hint(): array {
        return [
            'intro' => __('Econt\'s API uses your own e-Econt ("Моят Еконт") business account - there is no separate API key.', 'bg-couriers'),
            'steps' => [
                __('Register or open a business account in "Моят Еконт" at ee.econt.com.', 'bg-couriers'),
                __('Confirm with Econt that API access is enabled for your account.', 'bg-couriers'),
                __('Your API username is your account e-mail; the password is your account password.', 'bg-couriers'),
                __('Enter the e-mail and password below, click Validate, then Sync.', 'bg-couriers'),
            ],
            'receive'   => __('account e-mail (username) + password', 'bg-couriers'),
            'url_label' => __('Моят Еконт:', 'bg-couriers'),
            'url'       => 'https://ee.econt.com',
        ];
    }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote', 'pickup']; }

    /** The office the shop hands parcels in at (a synced office id); 0 = a courier collects from the address. */
    public static function dropoff_office(): int {
        return function_exists('get_option') ? max(0, (int) get_option('bgcouriers_econt_dropoff_office', 0)) : 0;
    }

    /** Econt wants the office CODE as senderOfficeCode; '' when none is chosen or it left the synced list. */
    public static function dropoff_office_code(): string {
        $id = self::dropoff_office();
        if ($id <= 0 || !class_exists('BGCouriers_Nomenclature')) { return ''; }
        $row = BGCouriers_Nomenclature::office_by_id('econt', $id);
        return $row ? (string) ($row['code'] ?? '') : '';
    }

    public function hands_in_at_office(): bool { return self::dropoff_office() > 0; }

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
     * Is the chosen pay-out agreement still on the profile?
     * @return array<int,array{msg:string,fix:string}>
     */
    public function payout_problems(): array {
        $chosen = (string) get_option('bgcouriers_econt_cd_num', '');
        if ($chosen === '') { return []; }
        $key  = 'bgcouriers_econt_cd_raw';
        $opts = get_transient($key);
        if (!is_array($opts)) {
            try {
                $resp = $this->post_json($this->base . '/Profile/ProfileService.getClientProfiles.json', []);
            } catch (\Exception $e) {
                return [];
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
        // Not judged further: Econt's moneyTransfer flag does not map onto "is a PPP".
        return [];
    }

    /** Econt uses HTTP Basic, not a body credential. */
    protected function http_post(string $url, array $body) {
        return wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json',
                          'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->pass)],
            'body'    => wp_json_encode($body),
        ]);
    }

    public function check_credentials(): bool {
        // A refusal is HTTP 517 with Econt's message, thrown by post_json().
        $r = $this->post_json($this->base . '/Profile/ProfileService.getClientProfiles.json', []);
        return isset($r['profiles']);
    }

    public function fetch_cities(): array {
        return self::parse_cities($this->post_json($this->base . '/Nomenclatures/NomenclaturesService.getCities.json', ['countryCode' => 'BGR']));
    }
    public function fetch_offices(int $city_id): array {
        $rows = self::parse_offices($this->post_json($this->base . '/Nomenclatures/NomenclaturesService.getOffices.json', ['countryCode' => 'BGR']));
        return $city_id > 0 ? array_values(array_filter($rows, static function ($o) use ($city_id) { return $o['city_id'] === $city_id; })) : $rows;
    }
    public function search_streets(int $city_id, string $term, string $country = ''): array {
        $rows = $this->streets_of($city_id);
        if ($term === '') { return $rows; }
        $t = function_exists('mb_strtolower') ? mb_strtolower($term) : strtolower($term);
        return array_values(array_filter($rows, static function ($s) use ($t) {
            return strpos(function_exists('mb_strtolower') ? mb_strtolower($s['name']) : strtolower($s['name']), $t) !== false;
        }));
    }

    /**
     * A town's whole street list, cached for a day; getStreets takes no term. The key carries the
     * nomenclature generation so "Sync now" retires it.
     * @return array<int,array{id:int,name:string,type:string,label:string}>
     */
    private function streets_of(int $city_id): array {
        $gen = class_exists('BGCouriers_Ajax') ? BGCouriers_Ajax::nomenclature_generation('econt') : '';
        $k = 'bgcouriers_econt_streets_' . $city_id . '_' . substr(md5($this->base . '|' . $gen), 0, 8);
        $c = get_transient($k);
        if (is_array($c)) { return $c; }
        $rows = self::parse_streets($this->post_json($this->base . '/Nomenclatures/NomenclaturesService.getStreets.json', ['cityID' => $city_id]));
        if ($rows) { set_transient($k, $rows, DAY_IN_SECONDS); }
        return $rows;
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

    // Quote (calculate mode)

    /**
     * The sender profile, cached for a day; createLabel requires a sender.
     * @return array{client:array,address:array}
     */
    private function sender_profile(): array {
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
        $address  = $addrs[0] ?? [];
        if ($chosen !== '') {
            foreach ($addrs as $a) { if ((string) ($a['id'] ?? '') === $chosen) { $address = $a; break; } }
        }
        if (empty($client['name']) || empty($address['city']['id'])) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The %s sender profile has no client or address on it. Check the profile in your courier account.', 'bg-couriers'), 'Econt')));
        }
        $sender   = ['client' => $client, 'address' => $address];
        set_transient($key, $sender, DAY_IN_SECONDS);
        return $sender;
    }

    /**
     * Ship-from addresses as id => label for the settings dropdown; cached for a day, empty on failure.
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

    /** CD pay-out agreements from the profile, `num` => label; cached. */
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

    private function office_code(int $site_id, int $office_id): string {
        if ($office_id <= 0) { return ''; }
        if (class_exists('BGCouriers_Nomenclature')) {
            $row = BGCouriers_Nomenclature::office_by_id('econt', $office_id);
            if ($row && isset($row['code'])) { return (string) $row['code']; }
        }
        return '';
    }

    public static function build_calculate_body(array $s, array $sender, string $from_office = ''): array {
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

        // COD on the quote too: Econt's collection fee is part of the price.
        $cd = (float) ($s['cod_amount'] ?? 0);
        if ($cd > 0 && get_option('bgcouriers_econt_cod_enabled', 'no') === 'yes') {
            $label['services'] = [
                'cdAmount'   => $cd,
                'cdType'     => 'get',
                'cdCurrency' => (string) ($s['currency'] ?? 'EUR'),
            ];
            // The pay-out agreement changes the fee, so the quote must send what the label sends.
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
        // The address stays: the office alone prices the same, and the address is the client's on the waybill.
        if ($from_office !== '') { $label['senderOfficeCode'] = $from_office; }

        return ['mode' => 'calculate', 'label' => $label];
    }

    /** totalPrice is GROSS and calculate mode returns no VAT figure; totalPriceWithVAT wins when present. */
    public static function parse_price(array $resp, string $currency): BGCouriers_Quote {
        $total    = (float) ($resp['label']['totalPrice'] ?? 0);
        $withVat  = (float) ($resp['label']['totalPriceWithVAT'] ?? 0);
        $cur      = (string) ($resp['label']['currency'] ?? $currency);
        if ($total <= 0) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s sent no price in its answer.', 'bg-couriers'), 'Econt')));
        }
        if ($withVat > $total) {
            return new BGCouriers_Quote($total, round($withVat - $total, 2), $cur, 'live');
        }
        list($net, $tax) = BGCouriers_Pricing::split_gross($total);
        return new BGCouriers_Quote($net, $tax, $cur, 'live');
    }

    public function quote(array $shipment): BGCouriers_Quote {
        if (($shipment['method'] ?? 'address') !== 'address') {
            $shipment['office_code'] = $this->office_code(
                (int) ($shipment['site_id'] ?? 0),
                (int) ($shipment['office_id'] ?? 0)
            );
        }
        $resp = $this->post_json(
            $this->base . '/Shipments/LabelService.createLabel.json',
            self::build_calculate_body($shipment, $this->sender_profile(), self::dropoff_office_code())
        );
        return self::parse_price($resp, (string) ($shipment['currency'] ?? get_woocommerce_currency()));
    }

    // Label creation

    public static function build_label_body(\WC_Order $order, array $sender, string $office_code, string $from_office = ''): array {
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
                'email'  => BGCouriers_Settings::label_email($order),
            ], static function ($v) { return $v !== ''; }),
            'packCount'           => 1,
            'weight'              => max(0.1, self::order_weight_kg($order)),
            'shipmentType'        => 'pack',
            'shipmentDescription' => BGCouriers_Settings::shipment_contents(),
            // The shop's order number, so a parcel in Econt's panel can be matched to the order.
            'orderNumber'         => (string) $order->get_order_number(),
        ];
        if ($from_office !== '') { $label['senderOfficeCode'] = $from_office; }

        // Econt prices on volumetric weight and sizeUnder60cm; blank dimensions make it assume.
        $dims = BGCouriers_Settings::box_dims($order);
        $label['shipmentDimensionsL'] = (float) $dims['length'];
        $label['shipmentDimensionsW'] = (float) $dims['width'];
        $label['shipmentDimensionsH'] = (float) $dims['height'];
        $label['sizeUnder60cm']       = max($dims) < 60;

        $method = (string) $order->get_meta('_bgcouriers_method');
        if ($method === 'office' || $method === 'automat') {
            $label['receiverOfficeCode'] = $office_code;
        } else {
            // Street+num is accepted when the city is right; building details go in `other`, the complex in `quarter`.
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

        // Juridical senders require senderAgent (MOL).
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

        $services = [];
        if (get_option('bgcouriers_econt_sms_notification', 'no') === 'yes') {
            $services['smsNotification'] = true;
        }
        $notify_email = trim((string) get_option('bgcouriers_econt_delivery_email', ''));
        if ($notify_email !== '') {
            $services['emailOnDelivery'] = $notify_email;
        }
        // Never for Econtomat (automat): inspection needs a courier at handover.
        $inspect = BGCouriers_Settings::open_before_pay();
        if ($method !== 'automat' && $inspect !== 'no') {
            $services['payAfterAccept'] = true;
            if ($inspect === 'test') { $services['payAfterTest'] = true; }
        }

        // paymentSenderMethod is never set: 'credit' demands a payer client number the profile lacks; unset bills the API client.
        $payer = self::service_payer('econt', $order);
        if ($payer === 'recipient') {
            $label['paymentReceiverMethod']          = 'cash';
            $label['paymentReceiverAmountIsPercent'] = true;
            $label['paymentReceiverAmount']          = 100;
        }

        // Only for orders actually paid cash-on-delivery, so a prepaid order is never charged again.
        if (get_option('bgcouriers_econt_cod_enabled', 'no') === 'yes' && $order->get_payment_method() === 'cod') {
            $cod = self::cod_for_payer($order, $payer);
            $services['cdAmount']             = $cod;
            $services['cdType']               = 'get';
            $services['cdCurrency']           = $order->get_currency();
            $services['cdPayOptionsTemplate'] = (string) get_option('bgcouriers_econt_cd_num', '');
            // Econt rejects the label unless the contents list totals cdAmount.
        }

        // Partial delivery only alongside COD: Econt reconciles what is kept against the packing list.
        if (get_option('bgcouriers_econt_partial_delivery', 'no') === 'yes' && isset($services['cdAmount'])) {
            $label['partialDelivery'] = true;
        }

        // The contents list goes on every shipment; with COD it must total the collected amount.
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

    /** Building details for Econt's `other` field; street+num are separate. */
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
     * PackingListElement[]: price and weight per unit; with COD the list must total cdAmount,
     * so a balancing line absorbs the remainder.
     */
    private static function packing_list(\WC_Order $order, ?float $cod_total): array {
        $out = []; $i = 0; $sum = 0.0;
        foreach ($order->get_items() as $item) {
            $i++;
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $weight  = ($product && $product->get_weight() !== '') ? (float) wc_get_weight((float) $product->get_weight(), 'kg') : 0.0;
            $qty     = max(1, (int) $item->get_quantity());
            $sku     = $product ? (string) $product->get_sku() : '';
            $unit    = round(((float) $item->get_total() + (float) $item->get_total_tax()) / $qty, 2);
            $sum    += $unit * $qty;
            $out[] = [
                'inventoryNum' => $sku !== '' ? $sku : (string) $i,
                'description'  => (string) $item->get_name(),
                'weight'       => round($weight, 3),
                'count'        => $qty,
                'price'        => $unit,
            ];
        }
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

    public static function parse_shipment_id(array $resp): string {
        return (string) ($resp['label']['shipmentNumber'] ?? '');
    }

    public function create_label(\WC_Order $order): BGCouriers_Label {
        $sender = $this->sender_profile();
        $office_code = $this->office_code(
            (int) $order->get_meta('_bgcouriers_site_id'),
            (int) $order->get_meta('_bgcouriers_office_id')
        );
        $body = self::build_label_body($order, $sender, $office_code, self::dropoff_office_code());
        $resp = $this->post_json($this->base . '/Shipments/LabelService.createLabel.json', $body);
        // The create response already carries the PDF URL, so no second call.
        return new BGCouriers_Label(
            self::parse_shipment_id($resp),
            (string) ($resp['label']['pdfURL'] ?? ''),
            self::check_applied($body, $resp)
        );
    }

    /**
     * What Econt actually applied vs what was asked: the COD is the service row of type 'CD', whose `count` is the amount.
     * @return string[]
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
        $want_recipient = !BGCouriers_Settings::ship_in_total('econt');
        $receiver_due   = (float) ($resp['label']['receiverDueAmount'] ?? 0);
        if ($want_recipient && $receiver_due <= 0) {
            $problems[] = __('The recipient was supposed to pay the delivery, but Econt billed the sender.', 'bg-couriers');
        }
        return $problems;
    }

    public function label_formats(): array { return ['A4']; } // Econt labels are A4-landscape; no size param in the API

    public function get_label_pdf(string $waybill, string $format = ''): string {
        $resp = $this->post_json(
            $this->base . '/Shipments/ShipmentService.getShipmentStatuses.json',
            ['shipmentNumbers' => [$waybill]]
        );
        $url = (string) ($resp['shipmentStatuses'][0]['status']['pdfURL'] ?? '');
        if ($url === '') {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s sent no label link in its answer.', 'bg-couriers'), 'Econt')));
        }
        return $this->fetch_pdf($url, [], 'Econt');
    }

    // Tracking

    public static function parse_tracking(array $resp): BGCouriers_Tracking {
        $st     = $resp['shipmentStatuses'][0]['status'] ?? [];
        $events = array_map(static function (array $e): array {
            return [
                'code' => (string) ($e['destinationType'] ?? ''),
                'name' => (string) ($e['destinationDetailsEn'] ?? ''),
                'date' => (string) ($e['time'] ?? ''),
            ];
        }, $st['trackingEvents'] ?? []);

        $status = (string) ($st['shortDeliveryStatus'] ?? $st['shortDeliveryStatusEn'] ?? '');
        if ($status === '') {
            $last   = end($events);
            $status = $last ? (string) ($last['name'] ?? 'UNKNOWN') : 'UNKNOWN';
        }

        // sendTime is set at handover, deliveryTime when the receiver has it; both null until then.
        $phase    = ((string) ($st['deliveryTime'] ?? '')) !== '' ? 'DELIVERED' : '';
        $handover = ((string) ($st['sendTime'] ?? '')) !== '' || $phase === 'DELIVERED';

        return new BGCouriers_Tracking((string) ($st['shipmentNumber'] ?? ''), $status, $events, $phase, $handover, true);
    }

    public function track(string $waybill): BGCouriers_Tracking {
        $resp = $this->post_json(
            $this->base . '/Shipments/ShipmentService.getShipmentStatuses.json',
            ['shipmentNumbers' => [$waybill]]
        );
        return self::parse_tracking($resp);
    }

    public function tracking_url(string $waybill): string {
        return 'https://www.econt.com/en/services/track-shipment/' . rawurlencode($waybill);
    }

    // Pickup request

    /**
     * Body for requestCourier: times are `Y-m-d H:i:s`, not ISO 8601, and attachShipments takes the waybills as strings.
     * @param string[] $waybills
     */
    public static function build_pickup_body(array $waybills, array $opts, array $sender): array {
        $date = (string) ($opts['date'] ?? '');
        $fmt  = static function ($d, $t) { return $d . ' ' . $t . ':00'; };
        return [
            'requestTimeFrom'   => $fmt($date, (string) ($opts['from'] ?? '09:00')),
            'requestTimeTo'     => $fmt($date, (string) ($opts['to'] ?? '18:00')),
            'shipmentType'      => 'pack',
            'shipmentPackCount' => max(1, (int) ($opts['packs'] ?? count($waybills))),
            'shipmentWeight'    => max(0.1, (float) ($opts['weight_kg'] ?? 1.0)),
            'senderClient'      => [
                'name'   => (string) ($sender['client']['name'] ?? ''),
                'phones' => array_slice($sender['client']['phones'] ?? [], 0, 1),
            ],
            'senderAddress'     => [
                'city'    => ['id' => (int) ($sender['address']['city']['id'] ?? 0)],
                'street'  => (string) ($sender['address']['street'] ?? ''),
                'num'     => (string) ($sender['address']['num'] ?? ''),
                'quarter' => (string) ($sender['address']['quarter'] ?? ''),
                'other'   => (string) ($sender['address']['other'] ?? ''),
            ],
            'attachShipments'   => array_values(array_map('strval', $waybills)),
        ];
    }

    public static function parse_pickup_id(array $resp): string {
        return (string) ($resp['courierRequestID'] ?? '');
    }

    public function request_pickup(array $waybills, array $opts): string {
        if (empty($waybills)) { throw new BGCouriers_Api_Exception(esc_html__('There are no shipments to collect.', 'bg-couriers')); }
        $resp = $this->post_json(
            $this->base . '/Shipments/ShipmentService.requestCourier.json',
            self::build_pickup_body($waybills, $opts, $this->sender_profile())
        );
        $id = self::parse_pickup_id($resp);
        if ($id === '') {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s sent no pickup request number in its answer.', 'bg-couriers'), 'Econt')));
        }
        return $id;
    }

    public function cancel_label(string $waybill): bool {
        $resp = $this->post_json(
            $this->base . '/Shipments/LabelService.deleteLabels.json',
            ['shipmentNumbers' => [$waybill]]
        );
        if (!empty($resp['error'])) { throw new BGCouriers_Api_Exception(esc_html('Econt: ' . self::words($resp['error']))); }
            // "not found" is not a failure: the shipment is already gone.
            foreach (($resp['results'] ?? []) as $res) {
                if (empty($res['error'])) { continue; }
                $m = mb_strtolower((string) ($res['error']['message'] ?? ''));
                if (mb_strpos($m, 'не е откри') !== false || strpos($m, 'not found') !== false) { continue; }
                throw new BGCouriers_Api_Exception(esc_html('Econt: ' . self::words($res['error'])));
            }
        return true;
    }

    /** An Econt error node as words. @param mixed $err */
    private static function words($err): string {
        if (is_string($err)) { return trim($err); }
        if (!is_array($err)) { return ''; }
        $m = trim((string) ($err['message'] ?? ''));
        return $m !== '' ? $m : trim((string) ($err['type'] ?? ''));
    }

    /** Already cancelled if getShipmentStatuses reports a cancelled status or the shipment is gone. */
    public function is_cancelled(string $waybill): bool {
        try {
            $resp = $this->post_json($this->base . '/Shipments/ShipmentService.getShipmentStatuses.json', ['shipmentNumbers' => [$waybill]]);
            $st = $resp['shipmentStatuses'][0] ?? [];
            if (!empty($st['error'])) { return true; }
            $status = mb_strtolower((string) ($st['status']['shortDeliveryStatus'] ?? '') . ' ' . (string) ($st['status']['shortDeliveryStatusEn'] ?? ''));
            if (BGCouriers_Tracking::reads_cancelled($status)) { return true; }
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
