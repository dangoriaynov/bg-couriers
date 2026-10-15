<?php
defined('ABSPATH') || exit;

/** BOX NOW locker (APM) courier: OAuth2 client credentials, flat rate, towns derived from its lockers. */
class BGCouriers_Boxnow extends BGCouriers_Abstract_Courier implements BGCouriers_Courier_Interface {
    const PROD  = 'https://api-production.boxnow.bg';
    const STAGE = 'https://api-stage.boxnow.bg';

    private $client_id;
    private $client_secret;
    private $base;
    private $partner_id;
    private $warehouse_id;

    public function __construct(array $config) {
        $this->client_id     = (string) ($config['username'] ?? '');
        $this->client_secret = (string) ($config['password'] ?? '');
        $this->base          = rtrim((string) ($config['api_url'] ?? self::PROD), '/');
        $this->partner_id    = (string) ($config['partner_id'] ?? '');
        $this->warehouse_id  = (string) ($config['warehouse_id'] ?? '');
    }

    public function id(): string { return 'boxnow'; }
    public function label(): string { return 'BOX NOW'; }

    public function credential_hint(): array {
        return [
            'intro' => __('BOX NOW issues OAuth2 credentials through its integration team.', 'bg-couriers'),
            'steps' => [
                __('E-mail integrationsupport@boxnow.bg with your company name, address, tax ID (ЕИК), contact details, and the phone numbers of the people who will use the Partner Portal (needed for OTP SMS login).', 'bg-couriers'),
                __('They issue your OAuth2 Client ID + Client Secret and confirm your Warehouse ID and Partner ID.', 'bg-couriers'),
                __('Enter the Client ID, Client Secret, Partner ID and Warehouse ID below; choose the Production environment (or Stage for testing).', 'bg-couriers'),
            ],
            'receive'   => __('OAuth2 Client ID + Client Secret (+ Partner ID, Warehouse ID)', 'bg-couriers'),
            'url_label' => __('BOX NOW:', 'bg-couriers'),
            'url'       => 'https://www.boxnow.bg',
        ];
    }
    public function capabilities(): array { return ['automat']; }
    public function pushes_tracking(): bool { return true; }
    public function recipient_can_pay_delivery(): bool { return false; }

    public function enable_problems(): array {
        $p = parent::enable_problems();
        $this->need_option($p, 'bgcouriers_boxnow_partner_id',
            __('No Partner ID is set.', 'bg-couriers'),
            __('Enter the Partner ID from your BOX NOW account (a required header on every request).', 'bg-couriers'));
        $this->need_option($p, 'bgcouriers_boxnow_warehouse_id',
            __('No Warehouse ID is set.', 'bg-couriers'),
            __('Enter the origin Warehouse ID parcels ship from (from BOX NOW).', 'bg-couriers'));
        $phone = trim((string) get_option('bgcouriers_boxnow_sender_phone', ''));
        if ($phone === '') {
            $p[] = [
                'msg' => __('No sender phone is set.', 'bg-couriers'),
                'fix' => __('Enter the merchant contact phone below - BOX NOW rejects every shipment without one.', 'bg-couriers'),
            ];
        } elseif (!BGCouriers_Phone::usable($phone)) {
            $p[] = [
                'msg' => __('The sender phone is not a usable number.', 'bg-couriers'),
                'fix' => __('Enter a full mobile number, e.g. +359888123456 or 0888123456.', 'bg-couriers'),
            ];
        }
        if ((float) get_option('bgcouriers_boxnow_flat_price', 0) <= 0) {
            $p[] = [
                'msg' => __('The flat delivery price is not set.', 'bg-couriers'),
                'fix' => __('Enter a “Delivery price” greater than 0 - BOX NOW has no live rate API.', 'bg-couriers'),
            ];
        }
        return $p;
    }

    protected function token(): string {
        $tok = get_transient('bgcouriers_boxnow_token');
        if (is_string($tok) && $tok !== '') { return $tok; }
        $res = wp_remote_post($this->base . '/api/v1/auth-sessions', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
            ]),
        ]);
        if (is_wp_error($res)) {
            /* translators: 1: courier name, 2: the error the connection reported. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s could not be reached: %2$s', 'bg-couriers'), 'BOX NOW', $res->get_error_message())));
        }
        $raw  = (string) wp_remote_retrieve_body($res);
        $body = json_decode($raw, true);
        $tok  = is_array($body) ? (string) ($body['access_token'] ?? '') : '';
        if ($tok === '') {
            $words = self::error_words($raw);
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s did not return an access token, so the credentials were refused.', 'bg-couriers'), 'BOX NOW')
                . ($words !== '' ? ' (' . $words . ')' : '')));
        }
        set_transient('bgcouriers_boxnow_token', $tok, 55 * MINUTE_IN_SECONDS);
        return $tok;
    }

    private function headers(bool $json = true): array {
        $h = ['Authorization' => 'Bearer ' . $this->token(), 'X-PartnerID' => $this->partner_id, 'Accept' => 'application/json'];
        if ($json) { $h['Content-Type'] = 'application/json'; }
        return $h;
    }

    protected function get_json(string $path, array $query = []): array {
        $url = $this->base . $path . (!empty($query) ? '?' . http_build_query($query) : '');
        $res = $this->http_get($url, $this->headers(false), 30);
        if (is_wp_error($res)) {
            /* translators: 1: courier name, 2: the error the connection reported. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s could not be reached: %2$s', 'bg-couriers'), 'BOX NOW', $res->get_error_message())));
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($data)) {
            /* translators: %s: the API address that answered. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The answer from %s is not valid JSON.', 'bg-couriers'), $url)));
        }
        return $data;
    }

    private function bn_post(string $path, array $body): array {
        $res = wp_remote_post($this->base . $path, ['timeout' => 40, 'headers' => $this->headers(true), 'body' => wp_json_encode($body)]);
        if (is_wp_error($res)) {
            /* translators: 1: courier name, 2: the error the connection reported. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s could not be reached: %2$s', 'bg-couriers'), 'BOX NOW', $res->get_error_message())));
        }
        $raw  = (string) wp_remote_retrieve_body($res);
        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode($raw, true);
        if ($code >= 400) {
            /* translators: 1: courier name, 2: HTTP status code, 3: the courier's own error text. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%1$s answered HTTP %2$d: %3$s', 'bg-couriers'), 'BOX NOW', $code, self::refusal_text($raw))));
        }
        // A 2xx with an empty body is a success (that is how :cancel answers).
        if (trim($raw) === '') { return []; }
        if (!is_array($data)) {
            /* translators: 1: courier name, 2: HTTP status code. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The answer from %1$s is not valid JSON (HTTP %2$d).', 'bg-couriers'), 'BOX NOW', $code)));
        }
        return $data;
    }

    public function check_credentials(): bool {
        return $this->token() !== '';
    }

    /** A BOX NOW refusal in words: its error codes come with no message, so the documented ones are spelled out. */
    public static function refusal_text(string $raw): string {
        $words = self::error_words($raw);
        if ($words !== '') { return $words; }
        $j    = json_decode($raw, true);
        $code = is_array($j) ? strtoupper(trim((string) ($j['code'] ?? ''))) : '';
        $said = [
            'P400' => __('The request was not accepted as valid.', 'bg-couriers'),
            'P401' => __('The warehouse id in the BOX NOW settings is not a location it knows.', 'bg-couriers'),
            'P402' => __('The chosen locker is not a location it knows.', 'bg-couriers'),
            'P405' => __('A phone number is not in full international form.', 'bg-couriers'),
            'P406' => __('The compartment size has to be 1, 2 or 3.', 'bg-couriers'),
            'P408' => __('Cash on delivery is collected only between 0 and 5000, and this order is outside that.', 'bg-couriers'),
            'P410' => __('This order number has already been used.', 'bg-couriers'),
            'P411' => __('This account is not allowed to collect cash on delivery. Have the order paid in advance, or ask BOX NOW support to switch it on.', 'bg-couriers'),
            'P414' => __('This parcel belongs to another account.', 'bg-couriers'),
            'P420' => __('This parcel can no longer be cancelled: only a new, undelivered parcel can be.', 'bg-couriers'),
            'P421' => __('The parcel weight was not accepted.', 'bg-couriers'),
        ];
        if ($code !== '' && isset($said[$code])) {
            return $said[$code] . ' (' . $code . ')';
        }
        return self::error_text($raw);
    }

    private $destinations = null;
    private function destinations(): array {
        if ($this->destinations === null) {
            $this->destinations = $this->get_json('/api/v1/destinations');
        }
        return is_array($this->destinations) ? $this->destinations : [];
    }

    /** BOX NOW publishes no town list; towns are read off its lockers (addressLine2). */
    public function fetch_cities(): array {
        $towns = self::towns_of(self::parse_destinations($this->destinations()));
        // Key a town by the code the other couriers list it under; a locker's own code is often a district code.
        if (class_exists('BGCouriers_Nomenclature')) {
            foreach ($towns as &$t) {
                $pc = BGCouriers_Nomenclature::post_code_by_name($t['name'], (string) $t['country'], ['boxnow']);
                if ($pc !== '') { $t['post_code'] = $pc; }
            }
            unset($t);
        }
        return $towns;
    }

    public function fetch_offices(int $city_id = 0): array {
        $rows = self::parse_destinations($this->destinations());
        if ($city_id <= 0) { return $rows; }
        return array_values(array_filter($rows, static function ($o) use ($city_id) { return (int) ($o['city_id'] ?? 0) === $city_id; }));
    }

    public static function town_id(string $name): int {
        $key = function_exists('mb_strtolower') ? mb_strtolower(trim($name), 'UTF-8') : strtolower(trim($name));
        return (crc32($key) & 0x7fffffff) ?: 1;
    }

    /** One row per town name, with the lowest postal code seen among its lockers (the town centre's). */
    public static function towns_of(array $offices): array {
        $towns = [];
        foreach ($offices as $o) {
            $name = (string) ($o['town'] ?? '');
            if ($name === '') { continue; }
            $id = (int) ($o['city_id'] ?? 0);
            $pc = (string) ($o['post_code'] ?? '');
            // Some lockers carry "-1000", which would sort below the real code.
            if (!preg_match('/^\d{4}$/', $pc)) { $pc = ''; }
            if (!isset($towns[$id])) {
                $towns[$id] = ['city_id' => $id, 'name' => $name, 'post_code' => $pc, 'region' => '', 'country' => (string) ($o['country'] ?? 'BG')];
            } elseif ($pc !== '' && ($towns[$id]['post_code'] === '' || strcmp($pc, $towns[$id]['post_code']) < 0)) {
                $towns[$id]['post_code'] = $pc;
            }
        }
        return array_values($towns);
    }

    /** destinations[] -> office rows; a destination with no town (the "Any-APM" origin wildcard) is skipped. */
    public static function parse_destinations(array $resp): array {
        $rows = $resp['data'] ?? (isset($resp[0]) ? $resp : []);
        $out  = [];
        foreach ($rows as $o) {
            if (empty($o['id'])) { continue; }
            $town = trim((string) ($o['addressLine2'] ?? ''));
            if ($town === '') { continue; }
            $addr = trim(((string) ($o['addressLine1'] ?? '')) . ' ' . $town);
            $out[] = [
                'office_id' => (int) $o['id'],
                'code'      => (string) $o['id'],
                'city_id'   => self::town_id($town),
                'town'      => $town,
                'type'      => 'automat',
                'name'      => (string) ($o['name'] ?? $o['title'] ?? ''),
                'address'   => $addr,
                'lat'       => (float) ($o['lat'] ?? 0),
                'lng'       => (float) ($o['lng'] ?? 0),
                'post_code' => (string) ($o['postalCode'] ?? ''),
                'country'   => strtoupper((string) ($o['country'] ?? 'BG')) ?: 'BG',
            ];
        }
        return $out;
    }

    /** No live price endpoint → throw so BGCouriers_Pricing uses the configured flat rate. */
    public function quote(array $shipment): BGCouriers_Quote {
        /* translators: %s: courier name. */
        throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s has no live price service, so the fixed price is used.', 'bg-couriers'), 'BOX NOW')));
    }

    public function create_label(\WC_Order $order): BGCouriers_Label {
        // BOX NOW refuses a reused orderNumber (P410) even after cancel, so a re-issue needs a new suffix.
        $attempt = (int) $order->get_meta('_bgcouriers_boxnow_attempt') + 1;
        $order->update_meta_data('_bgcouriers_boxnow_attempt', $attempt);
        $order->save();
        $resp = $this->bn_post('/api/v1/delivery-requests', self::build_delivery_request($order, $this->warehouse_id, $attempt));
        return new BGCouriers_Label(self::parse_parcel_id($resp));
    }

    public static function build_delivery_request(\WC_Order $order, string $origin_id, int $attempt = 1): array {
        $total  = number_format((float) $order->get_total(), 2, '.', '');
        $is_cod = $order->get_payment_method() === 'cod';

        // BOX NOW takes E.164 phone numbers only (P405 otherwise).
        $to_phone   = BGCouriers_Phone::e164((string) $order->get_billing_phone());
        $from_phone = BGCouriers_Phone::e164((string) get_option('bgcouriers_boxnow_sender_phone', ''));
        if ($to_phone === '') {
            throw new BGCouriers_Api_Exception(esc_html__('This order has no usable phone number for the recipient, and BOX NOW rejects a shipment without one.', 'bg-couriers'));
        }
        if ($from_phone === '') {
            throw new BGCouriers_Api_Exception(esc_html__('No sender phone is set for BOX NOW. Enter it in the BOX NOW settings - BOX NOW rejects a shipment without one.', 'bg-couriers'));
        }
        return [
            'orderNumber'         => $attempt > 1
                ? $order->get_order_number() . '-' . $attempt
                : (string) $order->get_order_number(),
            'invoiceValue'        => ($is_cod || get_option('bgcouriers_boxnow_declare_value', 'no') === 'yes')
                ? $total : '0.00',
            'paymentMode'         => $is_cod ? 'cod' : 'prepaid',
            'amountToBeCollected' => $is_cod ? $total : '0.00',
            'allowReturn'         => get_option('bgcouriers_boxnow_allow_returns', 'no') === 'yes',
            'origin'              => [
                'contactName'   => get_bloginfo('name'),
                'contactEmail'  => (string) get_option('admin_email'),
                // The origin is the sender: merchant phone, not the buyer's.
                'contactNumber' => $from_phone,
                'locationId'    => $origin_id,
            ],
            'destination'         => [
                'contactName'   => $order->get_formatted_billing_full_name(),
                'contactEmail'  => BGCouriers_Settings::label_email($order),
                'contactNumber' => $to_phone,
                'locationId'    => (string) ($order->get_meta('_bgcouriers_office_id') ?: ''),
            ],
            'items'               => self::items($order),
        ];
    }

    /** ONE items[] entry: BOX NOW turns every entry into its own parcel, and only parcels[0] is recorded. */
    private static function items(\WC_Order $order): array {
        $value = 0.0;
        $size  = 0;
        $lines = 0;
        foreach ($order->get_items() as $item) {
            $lines++;
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $value  += (float) $item->get_total() + (float) $item->get_total_tax();
            $size    = max($size, self::compartment_size($product));
        }
        // The compartment is for the whole parcel, not its largest item.
        $box  = BGCouriers_Settings::box_dims($order);
        $size = max($size, self::compartment_for((float) $box['length'], (float) $box['width'], (float) $box['height']));
        if ($lines === 0) { $value = (float) $order->get_total(); }
        return [[
            'id'     => (string) $order->get_order_number(),
            /* translators: %s: order number */
            'name'   => sprintf(__('Order %s', 'bg-couriers'), (string) $order->get_order_number()),
            'value'  => number_format($value, 2, '.', ''),
            'weight' => round(max(0.1, self::order_weight_kg($order)), 3),
            'compartmentSize' => $size > 0 ? $size : 2,
        ]];
    }

    /** Compartment for a product, as in the official plugin: footprint within 60x45 cm, height picks 1/2/3. */
    private static function compartment_size($product): int {
        $l = $w = $h = 0.0;
        if ($product) {
            $l = (float) $product->get_length();
            $w = (float) $product->get_width();
            $h = (float) $product->get_height();
        }
        // No dimensions: use the shop default parcel, not a guessed Medium (the compartment is charged for).
        if ($l <= 0 && $w <= 0 && $h <= 0) {
            $d = BGCouriers_Settings::box_dims();
            return self::compartment_for((float) $d['length'], (float) $d['width'], (float) $d['height']);
        }
        $unit = (string) get_option('woocommerce_dimension_unit', 'cm');
        $l = (float) wc_get_dimension($l, 'cm', $unit);
        $w = (float) wc_get_dimension($w, 'cm', $unit);
        $h = (float) wc_get_dimension($h, 'cm', $unit);
        return self::compartment_for($l, $w, $h);
    }

    private static function compartment_for(float $l, float $w, float $h): int {
        if ($l <= 60.0 && $w <= 45.0) {
            if ($h <= 8.0)  { return 1; }
            if ($h <= 17.0) { return 2; }
            if ($h <= 36.0) { return 3; }
        }
        return 3;
    }

    public static function parse_parcel_id(array $resp): string {
        return (string) ($resp['parcels'][0]['id'] ?? '');
    }

    public function get_label_pdf(string $waybill, string $format = ''): string {
        return $this->fetch_pdf($this->base . '/api/v1/parcels/' . rawurlencode($waybill) . '/label.pdf',
            $this->headers(false), 'BOX NOW');
    }

    public function track(string $waybill): BGCouriers_Tracking {
        $resp   = $this->get_json('/api/v1/parcels', ['id' => $waybill]);
        $rows   = $resp['data'] ?? (isset($resp[0]) ? $resp : []);
        $parcel = [];
        foreach ($rows as $p) { if ((string) ($p['id'] ?? '') === $waybill) { $parcel = $p; break; } }
        if (empty($parcel) && !empty($rows)) { $parcel = $rows[0]; }
        return self::parse_tracking($parcel, $waybill);
    }

    public static function parse_tracking(array $parcel, string $waybill): BGCouriers_Tracking {
        $state  = (string) ($parcel['state'] ?? '');
        $events = [];
        foreach (($parcel['events'] ?? []) as $e) {
            $events[] = ['name' => (string) ($e['type'] ?? ''), 'time' => (string) ($e['createTime'] ?? '')];
        }
        $labels = BGCouriers_Boxnow_Webhook::state_labels();
        $human  = $labels[$state] ?? ($state !== '' ? $state : __('unknown', 'bg-couriers'));
        return new BGCouriers_Tracking($waybill, $human, $events, $state !== '' ? 'boxnow_' . $state : '', null, true);
    }

    /** boxnow.bg/track?track= prefills the parcel number; tracker.boxnow.bg does not exist. */
    public function tracking_url(string $waybill): string {
        return 'https://boxnow.bg/track?track=' . rawurlencode($waybill);
    }

    public function cancel_label(string $waybill): bool {
        $this->bn_post('/api/v1/parcels/' . rawurlencode($waybill) . ':cancel', []);
        return true;
    }
}
