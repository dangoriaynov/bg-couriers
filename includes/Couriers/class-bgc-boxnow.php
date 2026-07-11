<?php
defined('ABSPATH') || exit;

/**
 * BOX NOW - locker (APM) courier. OAuth2 client-credentials + X-PartnerID header. No price API
 * (flat rate via BGC_Pricing). Delivery is to an APM the customer picks with BoxNow's map widget.
 * Shapes live-verified against the stage API (api-stage.boxnow.bg), Partner API 1.72, 2026-07-07.
 */
class BGC_Boxnow extends BGC_Abstract_Courier implements BGC_Courier_Interface {
    const PROD = 'https://api-production.boxnow.bg';

    private $client_id;
    private $client_secret;
    private $base;
    private $partner_id;
    private $warehouse_id;

    public function __construct(array $config) {
        $this->client_id     = (string) ($config['username'] ?? '');   // stored as bgc_boxnow_username (= Client ID)
        $this->client_secret = (string) ($config['password'] ?? '');   // stored as bgc_boxnow_password (= Client secret, encrypted)
        $this->base          = rtrim((string) ($config['api_url'] ?? self::PROD), '/');
        $this->partner_id    = (string) ($config['partner_id'] ?? '');
        $this->warehouse_id  = (string) ($config['warehouse_id'] ?? '');
    }

    public function id(): string { return 'boxnow'; }
    public function label(): string { return 'BOX NOW'; }
    public function capabilities(): array { return ['automat']; } // locker-only

    public function enable_problems(): array {
        $p = parent::enable_problems();
        $this->need_option($p, 'bgc_boxnow_partner_id',
            __('No Partner ID is set.', 'bg-couriers'),
            __('Enter the Partner ID from your BOX NOW account (a required header on every request).', 'bg-couriers'));
        $this->need_option($p, 'bgc_boxnow_warehouse_id',
            __('No Warehouse ID is set.', 'bg-couriers'),
            __('Enter the origin Warehouse ID parcels ship from (from BOX NOW).', 'bg-couriers'));
        if ((float) get_option('bgc_boxnow_flat_price', 0) <= 0) {
            $p[] = [
                'msg' => __('The flat delivery price is not set.', 'bg-couriers'),
                'fix' => __('Enter a “Delivery price” greater than 0 - BOX NOW has no live rate API.', 'bg-couriers'),
            ];
        }
        return $p;
    }

    /** Cached OAuth2 bearer token (BoxNow expires_in 3600s → refresh at 55 min). */
    protected function token(): string {
        $tok = get_transient('bgc_boxnow_token');
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
        if (is_wp_error($res)) { throw new BGC_Api_Exception('BoxNow auth transport: ' . $res->get_error_message()); }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        $tok  = is_array($body) ? (string) ($body['access_token'] ?? '') : '';
        if ($tok === '') { throw new BGC_Api_Exception('BoxNow authentication failed'); }
        set_transient('bgc_boxnow_token', $tok, 55 * MINUTE_IN_SECONDS);
        return $tok;
    }

    private function headers(bool $json = true): array {
        $h = ['Authorization' => 'Bearer ' . $this->token(), 'X-PartnerID' => $this->partner_id, 'Accept' => 'application/json'];
        if ($json) { $h['Content-Type'] = 'application/json'; }
        return $h;
    }

    protected function get_json(string $path, array $query = []): array {
        $url = $this->base . $path . (!empty($query) ? '?' . http_build_query($query) : '');
        $res = wp_remote_get($url, ['timeout' => 30, 'headers' => $this->headers(false)]);
        if (is_wp_error($res)) { throw new BGC_Api_Exception('BoxNow GET transport: ' . $res->get_error_message()); }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($data)) { throw new BGC_Api_Exception('BoxNow invalid JSON from ' . $url); }
        return $data;
    }

    /** POST a path (not a full URL) with the BoxNow auth headers. */
    private function bn_post(string $path, array $body): array {
        $res = wp_remote_post($this->base . $path, ['timeout' => 40, 'headers' => $this->headers(true), 'body' => wp_json_encode($body)]);
        if (is_wp_error($res)) { throw new BGC_Api_Exception('BoxNow POST transport: ' . $res->get_error_message()); }
        $raw  = (string) wp_remote_retrieve_body($res);
        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode($raw, true);
        if ($code >= 400) { throw new BGC_Api_Exception('BoxNow HTTP ' . $code . ': ' . substr($raw, 0, 300)); }
        if (!is_array($data)) { throw new BGC_Api_Exception('BoxNow invalid JSON (HTTP ' . $code . ')'); }
        return $data;
    }

    public function check_credentials(): bool {
        try { return $this->token() !== ''; } catch (\Exception $e) { return false; }
    }

    public function fetch_cities(): array { return []; } // geo/APM - no city nomenclature

    /** All BoxNow APM lockers (geo-based; checkout uses the map widget, not a city→office dropdown). */
    public function fetch_offices(int $city_id = 0): array {
        return self::parse_destinations($this->get_json('/api/v1/destinations'));
    }

    /** destinations[] -> office rows. Captures lat/lng for the future unified-map phase. */
    public static function parse_destinations(array $resp): array {
        $rows = $resp['data'] ?? (isset($resp[0]) ? $resp : []);
        $out  = [];
        foreach ($rows as $o) {
            if (empty($o['id'])) { continue; }
            $addr = trim(((string) ($o['addressLine1'] ?? '')) . ' ' . ((string) ($o['addressLine2'] ?? '')));
            $out[] = [
                'office_id' => (int) $o['id'],
                'code'      => (string) $o['id'],
                'type'      => 'automat',
                'name'      => (string) ($o['name'] ?? $o['title'] ?? ''),
                'address'   => $addr,
                'lat'       => (float) ($o['lat'] ?? 0),
                'lng'       => (float) ($o['lng'] ?? 0),
                'post_code' => (string) ($o['postalCode'] ?? ''),
            ];
        }
        return $out;
    }

    /** No live price endpoint → throw so BGC_Pricing uses the configured flat rate. */
    public function quote(array $shipment): BGC_Quote {
        throw new BGC_Api_Exception('BoxNow has no live price endpoint (flat rate)');
    }

    public function create_label(\WC_Order $order): BGC_Label {
        $resp = $this->bn_post('/api/v1/delivery-requests', self::build_delivery_request($order, $this->warehouse_id));
        return new BGC_Label(self::parse_parcel_id($resp));
    }

    /**
     * delivery-request body. destination.locationId = the chosen APM (_bgc_office_id); origin.locationId
     * = the merchant warehouse. COD when the order's payment method is cash-on-delivery. Values are
     * strings with exactly 2 decimals; item value is tax-inclusive; compartmentSize required for APM.
     */
    public static function build_delivery_request(\WC_Order $order, string $origin_id): array {
        $total  = number_format((float) $order->get_total(), 2, '.', '');
        $is_cod = $order->get_payment_method() === 'cod';
        return [
            'orderNumber'         => (string) $order->get_order_number(),
            'invoiceValue'        => $total,
            'paymentMode'         => $is_cod ? 'cod' : 'prepaid',
            'amountToBeCollected' => $is_cod ? $total : '0.00',
            'allowReturn'         => false,
            'origin'              => [
                'contactName'   => get_bloginfo('name'),
                'contactEmail'  => (string) get_option('admin_email'),
                'contactNumber' => (string) $order->get_billing_phone(),
                'locationId'    => $origin_id,
            ],
            'destination'         => [
                'contactName'   => $order->get_formatted_billing_full_name(),
                'contactEmail'  => BGC_Settings::label_email($order),
                'contactNumber' => (string) $order->get_billing_phone(),
                'locationId'    => (string) ($order->get_meta('_bgc_office_id') ?: ''),
            ],
            'items'               => self::items($order),
        ];
    }

    private static function items(\WC_Order $order): array {
        $out = []; $i = 0;
        foreach ($order->get_items() as $item) {
            $i++;
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $weight  = ($product && $product->get_weight() !== '') ? (float) wc_get_weight((float) $product->get_weight(), 'kg') : 0.0;
            $out[] = [
                'id'              => (string) ($product && $product->get_sku() !== '' ? $product->get_sku() : $i),
                'name'            => (string) $item->get_name(),
                'value'           => number_format((float) $item->get_total() + (float) $item->get_total_tax(), 2, '.', ''),
                'weight'          => round(max(0.001, $weight * max(1, (int) $item->get_quantity())), 3),
                'compartmentSize' => 2, // M
            ];
        }
        if (empty($out)) {
            $out[] = ['id' => 'order', 'name' => 'Order', 'value' => number_format((float) $order->get_total(), 2, '.', ''), 'weight' => 1.0, 'compartmentSize' => 2];
        }
        return $out;
    }

    public static function parse_parcel_id(array $resp): string {
        return (string) ($resp['parcels'][0]['id'] ?? '');
    }

    public function get_label_pdf(string $waybill): string {
        $res = wp_remote_get($this->base . '/api/v1/parcels/' . rawurlencode($waybill) . '/label.pdf', ['timeout' => 40, 'headers' => $this->headers(false)]);
        if (is_wp_error($res)) { throw new BGC_Api_Exception('BoxNow label transport: ' . $res->get_error_message()); }
        $pdf = (string) wp_remote_retrieve_body($res);
        if (strpos($pdf, '%PDF') !== 0) { throw new BGC_Api_Exception('BoxNow label is not a PDF'); }
        return $pdf;
    }

    public function track(string $waybill): BGC_Tracking {
        $resp   = $this->get_json('/api/v1/parcels', ['id' => $waybill]);
        $rows   = $resp['data'] ?? (isset($resp[0]) ? $resp : []);
        $parcel = [];
        foreach ($rows as $p) { if ((string) ($p['id'] ?? '') === $waybill) { $parcel = $p; break; } }
        if (empty($parcel) && !empty($rows)) { $parcel = $rows[0]; }
        return self::parse_tracking($parcel, $waybill);
    }

    public static function parse_tracking(array $parcel, string $waybill): BGC_Tracking {
        $status = (string) ($parcel['state'] ?? 'unknown');
        $events = [];
        foreach (($parcel['events'] ?? []) as $e) {
            $events[] = ['name' => (string) ($e['type'] ?? ''), 'time' => (string) ($e['createTime'] ?? '')];
        }
        return new BGC_Tracking($waybill, $status, $events);
    }

    public function tracking_url(string $waybill): string {
        return 'https://tracker.boxnow.bg/' . rawurlencode($waybill);
    }

    public function cancel_label(string $waybill): bool {
        try { $this->bn_post('/api/v1/parcels/' . rawurlencode($waybill) . ':cancel', []); return true; }
        catch (\Exception $e) { return false; }
    }
}
