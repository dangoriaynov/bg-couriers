<?php
defined('ABSPATH') || exit;

abstract class BGCouriers_Abstract_Courier implements BGCouriers_Courier_Interface {
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
                if (!is_array($data)) { throw new BGCouriers_Api_Exception(esc_html('Invalid JSON from ' . $url)); }
                return $data;
            }
            $last = 'HTTP ' . $code . ': ' . substr($raw, 0, 1000); // keep enough of the body for field-level API errors
            // Don't retry client errors (4xx): the request won't succeed on retry, and retrying a POST that
            // already had a side effect risks a duplicate. Only transport blips and 5xx are worth a second try.
            if ($code >= 400 && $code < 500) { break; }
        }
        throw new BGCouriers_Api_Exception(esc_html('Request failed: ' . $last));
    }

    /**
     * Countries this courier can deliver to BESIDES the shop's own, as ISO-3166 alpha-2 codes.
     *
     * Empty for every courier by default, deliberately: a courier that has not been measured against a
     * foreign destination does not get to guess at one. A courier overrides this only with countries
     * whose towns and offices it actually publishes AND whose service the account can book - both of
     * which are questions about the courier, not about the shop. What the SHOP has switched on is
     * BGCouriers_Settings::intl_countries(), which can only ever be a subset of this.
     *
     * @return string[]
     */
    public function intl_countries(): array { return []; }

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
        if (!BGCouriers_Settings::creds_present($id)) {
            $problems[] = [
                'code' => 'creds_missing',
                'msg' => __('API credentials are missing.', 'bg-couriers'),
                'fix' => __('Enter the username/key and password/secret, then click “Save changes”.', 'bg-couriers'),
            ];
        // Same default as the credentials tint in render_actions(): credentials saved before this flag
        // existed count as valid until something says otherwise. The two disagreed, so a courier
        // configured on an older version showed a green "credentials valid" panel while this check
        // simultaneously refused to enable it for not being validated. Anything saved through the
        // current code sets the flag outright (sanitize_keep / sanitize_password), so only genuinely
        // legacy installs land on the default.
        } elseif (get_option('bgcouriers_' . $id . '_validated', 'yes') !== 'yes') {
            $problems[] = [
                // Tagged so the enable check can say what actually happened when it has JUST tried the
                // credentials and the courier refused them - "not validated yet" would then be a lie,
                // and it would send the merchant to press a button that fails the same way.
                'code' => 'creds_unvalidated',
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
     * Ask the courier to come and collect THESE waybills, and return its own id for the request.
     *
     * A waybill only says a parcel exists. The courier comes for it on a request that names the
     * shipments and a day - Speedy's own schema puts EXPLICIT_SHIPMENT_ID_LIST first among its scopes,
     * and Econt attaches the numbers to the request the same way. Couriers with no such API keep this
     * default and must not be offered the action at all; throwing is what stops a silent no-op that
     * would leave a merchant waiting for a courier nobody called.
     *
     * @param string[] $waybills The shipments to collect.
     * @param array    $opts     date (Y-m-d), from/to (H:i), contact, phone, weight_kg, packs.
     * @throws BGCouriers_Api_Exception
     */
    public function request_pickup(array $waybills, array $opts): string {
        throw new BGCouriers_Api_Exception(esc_html__('This courier has no pickup-request service.', 'bg-couriers'));
    }

    /**
     * The moments this courier will still accept a collection for, on the given date - [] when it does
     * not say, in which case the caller offers its own hours rather than inventing the courier's.
     *
     * @return string[] Date-time strings as the courier returns them.
     */
    public function pickup_terms(string $date): array { return []; }

    /**
     * Paper formats this courier can produce a label in on demand. Default is a single FIXED native format
     * (empty list): the courier returns one PDF and get_label_pdf() ignores $format. Couriers whose API lets
     * us request a size (Speedy paperSize, Sameday type) override this with ['A6','A4'] so the setting/batch
     * choice can ask for the right size - printing then never scales.
     *
     * @return string[]
     */
    public function label_formats(): array { return []; }

    /**
     * A PDF with the labels for MANY waybills, for batch printing. Default: fetch each label individually and
     * concatenate the pages at native size (no re-packing). Couriers with a native multi-label print endpoint
     * (Speedy lays out its own A4) override this to use it, so the sheet matches how they print 1-by-1.
     *
     * @param string[] $waybills
     */
    /** Whether the courier has a native multi-label print endpoint (so batch_label_pdf() should be used). */
    public function has_native_batch(): bool { return false; }

    public function batch_label_pdf(array $waybills, string $format = ''): string {
        $pdfs = [];
        foreach ($waybills as $wb) {
            $wb = (string) $wb;
            if ($wb === '') { continue; }
            try { $b = $this->get_label_pdf($wb, $format); if ($b !== '') { $pdfs[] = $b; } } catch (\Exception $e) { /* skip */ }
        }
        if (!$pdfs) { return ''; }
        return count($pdfs) === 1 ? $pdfs[0] : BGCouriers_Label_Packer::concat($pdfs);
    }

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
        $counts = BGCouriers_Nomenclature::type_counts($this->id());
        if (($counts['total'] ?? 0) <= 0) { return $caps; }
        return array_values(array_filter($caps, static function ($m) use ($counts) {
            return ($m === 'office' || $m === 'automat') ? (($counts[$m] ?? 0) > 0) : true;
        }));
    }

    /**
     * The order's parcel weight in kg for a waybill: an explicit _bgcouriers_weight_kg override if set, else the sum
     * of the line items' product weights (converted to kg) x quantity, else the shop-wide default from
     * Settings. This is what every courier should send as the shipment weight - the raw meta is never
     * populated on its own. The result is clamped to 0.1 kg: courier APIs reject lighter parcels, and a
     * gram-priced shop can legitimately total less than that (2 x 10 g = 0.02 kg).
     */
    public static function order_weight_kg(\WC_Order $order): float {
        $manual = (float) $order->get_meta('_bgcouriers_weight_kg');
        if ($manual > 0) { return max(0.1, round($manual, 3)); }
        $total = 0.0;
        foreach ($order->get_items() as $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if (!$product) { continue; }
            $w = $product->get_weight();
            if ($w === '' || $w === null) { continue; }
            $total += (float) wc_get_weight((float) $w, 'kg') * max(1, (int) $item->get_quantity());
        }
        if ($total <= 0) {
            return class_exists('BGCouriers_Settings') ? BGCouriers_Settings::default_weight_kg() : 1.0;
        }
        return max(0.1, round($total, 3));
    }

    /** Helper for overrides: append a problem when a saved option is empty. */
    protected function need_option(array &$problems, string $option, string $msg, string $fix): void {
        if (trim((string) get_option($option, '')) === '') {
            $problems[] = ['msg' => $msg, 'fix' => $fix];
        }
    }

    /**
     * 'sender' (default) or 'recipient' - who pays the courier delivery fee. Derived from the
     * "Delivery in the order total" toggle so the waybill payer, the COD amount and the checkout
     * rate cost can never disagree: charged with the order = sender pays the courier; not charged
     * = the recipient pays the courier's own fee on delivery.
     *
     * @param string $courier Courier id (e.g. 'speedy', 'pigeon', 'sameday').
     * @return string 'sender' or 'recipient'.
     */
    protected static function service_payer(string $courier, ?\WC_Order $order = null): string {
        // Free delivery is the MERCHANT absorbing the cost. Without this the customer was told "free
        // over 40" at checkout and then asked to pay the courier at the door anyway - the shop charged
        // nothing and nobody absorbed anything.
        if ($order && BGCouriers_Settings::free_for_order($order, $courier)) { return 'sender'; }
        return BGCouriers_Settings::ship_in_total($courier) ? 'sender' : 'recipient';
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
