<?php
defined('ABSPATH') || exit;

abstract class BGC_Abstract_Courier implements BGC_Courier_Interface {
    /** POST JSON, parse JSON, one retry, throw on failure. */
    protected function post_json(string $url, array $body): array {
        $last = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $res = $this->http_post($url, $body);
            if (is_wp_error($res)) { $last = 'transport error'; continue; }
            $code = (int) wp_remote_retrieve_response_code($res);
            $raw  = (string) wp_remote_retrieve_body($res);
            // Accept ANY 2xx as success - POST create endpoints return 201 Created, not just 200. This is
            // critical: a create that returned 201 must NOT fall through to the retry, or a second identical
            // POST would create a DUPLICATE shipment (these endpoints are not idempotent).
            if ($code >= 200 && $code < 300) {
                $data = json_decode($raw, true);
                if (!is_array($data)) { throw new BGC_Api_Exception(esc_html('Invalid JSON from ' . $url)); }
                return $data;
            }
            $last = 'HTTP ' . $code . ': ' . substr($raw, 0, 1000); // keep enough of the body for field-level API errors
            // Don't retry client errors (4xx): the request won't succeed on retry, and retrying a POST that
            // already had a side effect risks a duplicate. Only transport blips and 5xx are worth a second try.
            if ($code >= 400 && $code < 500) { break; }
        }
        throw new BGC_Api_Exception(esc_html('Request failed: ' . $last));
    }

    /** Seam: overridden in tests; real impl calls wp_remote_post. */
    protected function http_post(string $url, array $body) {
        return wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
    }

    /**
     * Crucial-settings check run before this courier may be enabled. Returns a list of problems, each
     * ['msg' => what is wrong, 'fix' => how to resolve it]; an empty list means it is ready to enable.
     * Reads SAVED options - the merchant should Save (and Validate credentials) before enabling.
     * Couriers override this to add their own required fields on top of the base credential check.
     *
     * @return array<int,array{msg:string,fix:string}>
     */
    public function enable_problems(): array {
        $id = $this->id();
        $problems = [];
        if (!BGC_Settings::creds_present($id)) {
            $problems[] = [
                'msg' => __('API credentials are missing.', 'bg-couriers'),
                'fix' => __('Enter the username/key and password/secret, then click “Save changes”.', 'bg-couriers'),
            ];
        } elseif (get_option('bgc_' . $id . '_validated', 'no') !== 'yes') {
            $problems[] = [
                'msg' => __('The API credentials have not been validated.', 'bg-couriers'),
                'fix' => __('Click “Validate credentials” and make sure the check succeeds.', 'bg-couriers'),
            ];
        }
        return $problems;
    }

    /**
     * Whether the courier reports this waybill as ALREADY cancelled / not found - so a failed cancel_label()
     * is really "already done" and it's safe to drop our local record. Default is conservative (false): a
     * failed cancel stays a failure. Couriers override with a real status check.
     */
    public function is_cancelled(string $waybill): bool { return false; }

    /**
     * Delivery methods to actually OFFER, driven by the courier's real synced nomenclature: an office/automat
     * type the courier has ZERO synced points for is dropped (e.g. Pigeon has offices but no APS lockers, so
     * "to APS" must not be offered anywhere). If the courier syncs no points at all here (total 0 - BOX NOW is
     * widget-based, Sameday may be un-synced), we cannot prove a type is empty, so the declared capabilities
     * pass through untouched. 'address' (not a point-type) and 'live_quote' (a pricing flag) are never pruned.
     *
     * @return string[] subset of capabilities()
     */
    public function available_methods(): array {
        $caps   = $this->capabilities();
        $counts = BGC_Nomenclature::type_counts($this->id());
        if (($counts['total'] ?? 0) <= 0) { return $caps; }
        return array_values(array_filter($caps, static function ($m) use ($counts) {
            return ($m === 'office' || $m === 'automat') ? (($counts[$m] ?? 0) > 0) : true;
        }));
    }

    /**
     * The order's parcel weight in kg for a waybill: an explicit _bgc_weight_kg override if set, else the sum
     * of the line items' product weights (converted to kg) x quantity, else the fallback (default 1 kg). This
     * is what every courier should send as the shipment weight - the raw meta is never populated on its own.
     */
    public static function order_weight_kg(\WC_Order $order, float $fallback = 1.0): float {
        $manual = (float) $order->get_meta('_bgc_weight_kg');
        if ($manual > 0) { return $manual; }
        $total = 0.0;
        foreach ($order->get_items() as $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if (!$product) { continue; }
            $w = $product->get_weight();
            if ($w === '' || $w === null) { continue; }
            $total += (float) wc_get_weight((float) $w, 'kg') * max(1, (int) $item->get_quantity());
        }
        return $total > 0 ? round($total, 3) : $fallback;
    }

    /** Helper for overrides: append a problem when a saved option is empty. */
    protected function need_option(array &$problems, string $option, string $msg, string $fix): void {
        if (trim((string) get_option($option, '')) === '') {
            $problems[] = ['msg' => $msg, 'fix' => $fix];
        }
    }

    /**
     * 'sender' (default) or 'recipient' - who pays the courier delivery fee, per courier setting.
     *
     * @param string $courier Courier id (e.g. 'speedy', 'pigeon', 'sameday').
     * @return string 'sender' or 'recipient'.
     */
    protected static function service_payer(string $courier): string {
        return get_option('bgc_' . $courier . '_service_payer', 'sender') === 'recipient' ? 'recipient' : 'sender';
    }

    /**
     * COD amount to collect: full order total when the SENDER pays delivery (merchant already charged
     * shipping at checkout), or goods-only (total - shipping - shipping tax) when the RECIPIENT pays
     * delivery at the door.
     *
     * @param \WC_Order $order  The WooCommerce order.
     * @param string    $payer  'sender' or 'recipient'.
     * @return float            Amount to collect via COD.
     */
    protected static function cod_for_payer(\WC_Order $order, string $payer): float {
        $total = (float) $order->get_total();
        if ($payer === 'recipient') {
            return max(0.0, round($total - (float) $order->get_shipping_total() - (float) $order->get_shipping_tax(), 2));
        }
        return max(0.0, round($total, 2));
    }
}
