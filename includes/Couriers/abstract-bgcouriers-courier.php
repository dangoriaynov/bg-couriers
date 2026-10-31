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
            // Any 2xx is success; letting a 201 fall through to the retry would create a duplicate shipment.
            if ($code >= 200 && $code < 300) {
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    /* translators: %s: the API address that answered. */
                    throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The answer from %s is not valid JSON.', 'bg-couriers'), $url)));
                }
                return $data;
            }
            $last = 'HTTP ' . $code . ': ' . self::error_text($raw);
            // 4xx will not succeed on retry, and a repeated POST risks a duplicate.
            if ($code >= 400 && $code < 500) { break; }
        }
        /* translators: %s: the courier's own error text, or the HTTP status. */
        throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The request failed: %s', 'bg-couriers'), $last)));
    }

    /** Words of a refused request: JSON message / error.message / error, else the raw body. */
    protected static function error_text(string $raw): string {
        $words = self::error_words($raw);
        return $words !== '' ? $words : substr($raw, 0, 1000);
    }

    /** The message in a JSON error body, or '' when there is none - for a sentence that already says what happened. */
    protected static function error_words(string $raw): string {
        $j = json_decode($raw, true);
        if (!is_array($j)) { return ''; }
        foreach ([$j['message'] ?? null, $j['error']['message'] ?? null, $j['error_description'] ?? null, $j['error'] ?? null] as $m) {
            $t = self::leaf_text($m);
            if ($t !== '') { return $t; }
        }
        // Econt nests the words in innerErrors, often with an empty outer message.
        return self::inner_words($j['innerErrors'] ?? null, 0);
    }

    /** The messages of Econt's nested innerErrors, joined; '' when there are none. @param mixed $errors */
    private static function inner_words($errors, int $depth): string {
        if (!is_array($errors) || $depth > 4) { return ''; }
        $out = [];
        foreach ($errors as $e) {
            if (!is_array($e)) { continue; }
            $t = self::leaf_text($e['message'] ?? null);
            if ($t === '') { $t = self::inner_words($e['innerErrors'] ?? null, $depth + 1); }
            if ($t !== '') { $out[] = $t; }
        }
        return implode('; ', array_unique($out));
    }

    /** A string as it is; a list or object of strings joined; anything else nothing. @param mixed $m */
    private static function leaf_text($m): string {
        if (is_string($m) || is_numeric($m)) { return trim((string) $m); }
        if (!is_array($m)) { return ''; }
        $out = [];
        foreach ($m as $v) { $t = self::leaf_text($v); if ($t !== '') { $out[] = $t; } }
        return implode('; ', $out);
    }

    /** @return string[] countries besides the shop's own this courier delivers to, ISO-3166 alpha-2. */
    public function intl_countries(): array { return []; }

    /** True when a street must be picked off this courier's own list (it refuses one without its id). */
    public function street_list_only(): bool { return false; }

    /** @return string[] subset of ['username','password'] - which halves this courier issues. */
    public function credential_fields(): array { return ['username', 'password']; }

    /** @return array{intro?:string,steps?:string[],receive?:string,url_label?:string,url?:string} how a merchant gets credentials. */
    public function credential_hint(): array { return []; }

    /** True when the courier pushes tracking events itself, so the poller leaves its orders alone. */
    public function pushes_tracking(): bool { return false; }

    /** Can the courier bill its delivery fee to the recipient at the door? */
    public function recipient_can_pay_delivery(): bool { return true; }

    /** Default for the per-courier ppp_payout setting (cash on delivery paid out by postal money order). */
    public function ppp_payout_by_default(): bool { return false; }

    /** @return string[] delivery kinds (subset of capabilities()) the courier cannot collect cash on. */
    public function no_cod_methods(): array { return []; }

    /** True when creating a waybill alone summons the courier, so labels must not be issued automatically. */
    public function books_pickup_on_create(): bool { return false; }

    /** True when the shop is set to hand its parcels in at an office: there is no courier to call. */
    public function hands_in_at_office(): bool { return false; }

    /** True when the API takes and applies a parcel count and declared value. */
    public function multi_parcel(): bool { return false; }

    /** Seam: overridden in tests; real impl calls wp_remote_post. */
    protected function http_post(string $url, array $body) {
        return wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
    }

    /** GET, with whatever headers the courier's auth needs. Also the seam the parser tests replace. */
    protected function http_get(string $url, array $headers = [], int $timeout = 40) {
        return wp_remote_get($url, ['timeout' => $timeout, 'headers' => $headers]);
    }

    /** GET a label and prove it is a PDF. Never quotes the URL: it may carry the account's API key. */
    protected function fetch_pdf(string $url, array $headers = [], string $who = ''): string {
        $who = $who !== '' ? $who : $this->id();
        // Refuse links back into the shop's own network: public host over https, or nothing.
        if (!self::is_public_https($url)) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s: the label link does not point anywhere public.', 'bg-couriers'), $who)));
        }
        $res = $this->http_get($url, $headers);
        if (is_wp_error($res)) {
            throw new BGCouriers_Api_Exception(esc_html($who . ': ' . $res->get_error_message()));
        }
        return self::assert_pdf((string) wp_remote_retrieve_body($res), $who);
    }

    /** An https URL naming a public host: no private or bare IPs, no dotless names. Not an allowlist on purpose. */
    protected static function is_public_https(string $url): bool {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') { return false; }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || $host === 'localhost') { return false; }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return strpos($host, '.') !== false && substr($host, -6) !== '.local';
    }

    protected static function assert_pdf(string $raw, string $who): string {
        if (strncmp($raw, '%PDF', 4) !== 0) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s: the label did not come back as a PDF.', 'bg-couriers'), $who)));
        }
        return $raw;
    }

    /**
     * What still keeps this courier from the checkout; [] when ready. Reads saved options, couriers add their own.
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
        // Credentials saved before the flag existed count as valid, same default as render_actions().
        } elseif (get_option('bgcouriers_' . $id . '_validated', 'yes') !== 'yes') {
            $problems[] = [
                'code' => 'creds_unvalidated',
                'msg' => __('The API credentials have not been validated.', 'bg-couriers'),
                'fix' => __('Click “Validate credentials” and make sure the check succeeds.', 'bg-couriers'),
            ];
        }
        if (!BGCouriers_Settings::enabled_methods($id)) {
            $problems[] = [
                'code' => 'no_methods',
                'msg' => __('Every delivery option is switched off.', 'bg-couriers'),
                'fix' => __('Switch at least one of them on - the tabs below the courier settings.', 'bg-couriers'),
            ];
        }
        if (!BGCouriers_Settings::in_a_shipping_zone($id)) {
            $problems[] = [
                'code' => 'no_zone',
                'msg' => __('This courier is not in any shipping zone.', 'bg-couriers'),
                'fix' => __('Add it in WooCommerce → Settings → Shipping, to the zone your customers are in.', 'bg-couriers'),
            ];
        }
        return $problems;
    }

    /** True when the courier reports the waybill as already cancelled or gone; default false, couriers override. */
    public function is_cancelled(string $waybill): bool { return false; }

    /**
     * Ask the courier to collect these waybills; returns its own request id. Default throws: no pickup API.
     * @param array $opts date (Y-m-d), from/to (H:i), contact, phone, weight_kg, packs.
     */
    public function request_pickup(array $waybills, array $opts): string {
        throw new BGCouriers_Api_Exception(esc_html__('This courier has no pickup-request service.', 'bg-couriers'));
    }

    /** @return string[] collection moments the courier still accepts on $date, as it returns them; [] when it does not say. */
    public function pickup_terms(string $date): array { return []; }

    /** @return string[] paper formats available on demand (e.g. ['A6','A4']); [] = one fixed native format. */
    public function label_formats(): array { return []; }

    /** Whether the courier has a native multi-label print endpoint. */
    public function has_native_batch(): bool { return false; }

    /** Labels for many waybills in one PDF; default concatenates single labels at native size. @param string[] $waybills */
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

    /** @return string[] capabilities() minus office/automat types with zero synced points (untouched when nothing is synced). */
    public function available_methods(): array {
        $caps   = $this->capabilities();
        $counts = BGCouriers_Nomenclature::type_counts($this->id());
        if (($counts['total'] ?? 0) <= 0) { return $caps; }
        return array_values(array_filter($caps, static function ($m) use ($counts) {
            return ($m === 'office' || $m === 'automat') ? (($counts[$m] ?? 0) > 0) : true;
        }));
    }

    /** Parcel weight in kg: _bgcouriers_weight_kg override, else product weights x qty plus packaging, else the default; min 0.1. */
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
            // The default already includes packaging.
            return class_exists('BGCouriers_Settings') ? BGCouriers_Settings::default_weight_kg() : 1.0;
        }
        if (class_exists('BGCouriers_Settings')) { $total += BGCouriers_Settings::packaging_kg(); }
        return max(0.1, round($total, 3));
    }

    /** Helper for overrides: append a problem when a saved option is empty. */
    protected function need_option(array &$problems, string $option, string $msg, string $fix): void {
        if (trim((string) get_option($option, '')) === '') {
            $problems[] = ['msg' => $msg, 'fix' => $fix];
        }
    }

    /** 'sender' or 'recipient': who pays the courier fee, derived from the "delivery in the order total" toggle. */
    protected static function service_payer(string $courier, ?\WC_Order $order = null): string {
        // Free delivery is the merchant absorbing the cost, not the recipient paying at the door.
        if ($order && BGCouriers_Settings::free_for_order($order, $courier)) { return 'sender'; }
        return BGCouriers_Settings::ship_in_total($courier) ? 'sender' : 'recipient';
    }

    /** COD amount: full total when the sender pays delivery, goods only (total minus shipping) when the recipient does. */
    protected static function cod_for_payer(\WC_Order $order, string $payer): float {
        $total = (float) $order->get_total();
        if ($payer === 'recipient') {
            return max(0.0, round($total - (float) $order->get_shipping_total() - (float) $order->get_shipping_tax(), 2));
        }
        return max(0.0, round($total, 2));
    }
}
