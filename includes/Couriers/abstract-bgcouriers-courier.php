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
                if (!is_array($data)) {
                    /* translators: %s: the API address that answered. */
                    throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The answer from %s is not valid JSON.', 'bg-couriers'), $url)));
                }
                return $data;
            }
            $last = 'HTTP ' . $code . ': ' . substr($raw, 0, 1000); // keep enough of the body for field-level API errors
            // Don't retry client errors (4xx): the request won't succeed on retry, and retrying a POST that
            // already had a side effect risks a duplicate. Only transport blips and 5xx are worth a second try.
            if ($code >= 400 && $code < 500) { break; }
        }
        /* translators: %s: the courier's own error text, or the HTTP status. */
        throw new BGCouriers_Api_Exception(esc_html(sprintf(__('The request failed: %s', 'bg-couriers'), $last)));
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

    /**
     * Must a street come from THIS courier's own list, or may the customer type one?
     *
     * Typing one is right for most: Speedy and Econt take an address as text and deliver to it. Express
     * One does not - it refuses a street it was not given an id for, and the only way to have that id is
     * to have picked the street off its list. Where that is true the checkout must stop offering the
     * free-typed option, because the refusal otherwise arrives at the packing table, hours after the
     * customer has gone.
     */
    public function street_list_only(): bool { return false; }

    /**
     * Which halves of the credential pair this courier actually issues.
     *
     * Every courier here but one hands out two things - a username and a password, a client id and a
     * secret, a key and a secret - so the default is both. Evropat issues ONE API key from the
     * merchant's own cabinet and no username at all, and a shop cannot be made to invent the other half
     * to satisfy a check: creds_present() would otherwise refuse to enable a courier that is perfectly
     * configured, and the settings tab would ask for a field that does not exist.
     *
     * @return string[] subset of ['username','password']
     */
    public function credential_fields(): array { return ['username', 'password']; }

    /**
     * Delivery kinds this courier CANNOT collect cash on.
     *
     * Cash on delivery is a service of the courier, not of the shop, and a courier may offer it to a
     * person and not to a machine: Express One does not collect cash on delivery at an EXOBOX locker
     * (their own words, 2026-08-26), while BOX NOW's lockers and Econt's automats do. So this is asked
     * of each courier per delivery kind rather than assumed of every locker.
     *
     * Empty for every courier by default. What it returns is enforced in three places, because a rule
     * held in only one of them is a rule that leaks: the checkout takes the cash-on-delivery gateway
     * away while such a delivery is chosen, the quote stops paying for a collection that cannot happen,
     * and the waybill refuses to be booked at all. Without the last one an order edited in the admin
     * would still print a locker label carrying money nobody can hand over.
     *
     * @return string[] subset of capabilities(), e.g. ['automat']
     */
    public function no_cod_methods(): array { return []; }

    /**
     * Does creating a waybill, on its own, bring this courier to the door?
     *
     * For most of them it does not: a waybill is data, and the visit is a separate request the shop
     * makes when it is ready (see request_pickup()). Sameday is not like that - an AWB enters its pickup
     * point's collection list and a courier comes for it, measured within two hours on 2026-08-26.
     *
     * Which decides ONE thing: whether a shop that has said nothing gets its labels issued automatically
     * for this courier. A waybill created the moment an order is paid is harmless where nobody acts on
     * it, and where somebody does it summons a van to a parcel that is not packed - the courier finds an
     * empty counter, voids the waybill, and the shop learns of it hours later from a quiet order note.
     * That is not a setting a merchant should have to discover by losing a collection.
     */
    public function books_pickup_on_create(): bool { return false; }

    /**
     * Does this courier's API take a parcel count and a declared value, and APPLY them?
     *
     * False for most of them, and membership is earned by measurement rather than by the field
     * existing in a document: Express One joined after a shipment booked with PACK_COUNT 3 and
     * INSURANCE 60 came back listing three parcels, a declared 60.00, and a price that had risen for
     * it (2026-08-25, its test account).
     *
     * Asked of the courier because that is where the measurement was made. It used to be a list of
     * courier ids in BGCouriers_Order, a file away from every courier it named - one more hardcoded
     * courier list to remember when the eighth courier arrives, and there is already a note in this
     * project's history about the five settings Express One was given that no list had been told about.
     */
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

    /**
     * Fetch a label and prove it IS a label.
     *
     * Four couriers had written this out: GET, check for a transport error, check the body starts with
     * %PDF, throw otherwise - each with its own wording, its own timeout, and one of them without the
     * transport check at all, so a network blip reached the merchant as "the label is not a PDF".
     *
     * What every one of them must do, and what only Evropat did, is keep the URL out of the message:
     * its label link carries the account's API key in the query string, and an exception text travels
     * into order notes and logs. So no caller may quote it, and this one never does.
     *
     * @throws BGCouriers_Api_Exception
     */
    protected function fetch_pdf(string $url, array $headers = [], string $who = ''): string {
        $who = $who !== '' ? $who : $this->id();
        // Two of these links are not ours: Econt answers getShipmentStatuses with a pdfURL and Evropat's
        // /printshipment answers with a link, and both are then fetched by this server. The rest build
        // their URL from a constant base. So the one thing worth refusing is a link that points back
        // inside the shop's own network - it would be a blind request (the answer only ever leaves here
        // as a PDF), but a blind request to 169.254.169.254 is still how a cloud instance's credentials
        // are read. Public host over https, or nothing.
        if (!self::is_public_https($url)) {
            /* translators: %s: courier name. */
            throw new BGCouriers_Api_Exception(esc_html(sprintf(__('%s: the label link does not point anywhere public.', 'bg-couriers'), $who)));
        }
        $res = $this->http_get($url, $headers);
        if (is_wp_error($res)) {
            // Courier name plus the courier's own words: nothing here is English, so nothing needs translating.
            throw new BGCouriers_Api_Exception(esc_html($who . ': ' . $res->get_error_message()));
        }
        return self::assert_pdf((string) wp_remote_retrieve_body($res), $who);
    }

    /**
     * The same proof, for a label that arrives by some other route - Express One hands it back
     * base64-encoded inside a JSON envelope, Speedy answers its print endpoint with the bytes directly.
     * Deliberately quotes nothing but the courier's name: the body is either a PDF or an error page,
     * and an error page can carry a key, a token or a customer's address.
     *
     * @throws BGCouriers_Api_Exception
     */
    /**
     * An https URL naming a host on the public internet.
     *
     * Deliberately not an allowlist of the couriers' own domains: their label links are served from
     * hosts this plugin has never been told about, and a list of them would break a label the day a
     * courier moved one. What is refused instead is everything a courier has no business naming -
     * another scheme, a bare IP, a name with no dot in it (localhost, a container name, an intranet
     * short name), and every private, loopback, link-local or otherwise reserved address.
     */
    protected static function is_public_https(string $url): bool {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') { return false; }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || $host === 'localhost') { return false; }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // An IP literal is allowed only if it is a public one. A courier naming a bare address at
            // all is already odd; naming a private one is the case this exists for.
            return (bool) filter_var($host, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        // A registrable name has a dot in it. Anything without one is a machine on the local network.
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
     * What is still between this courier and a customer seeing it. An empty list means it is ready.
     *
     * It used to REFUSE the enable toggle, and that made the first step impossible: credentials come
     * from the courier, and two of these fields (Express One's collection address, Evropat's sender
     * file) can only be picked off a list the API returns - so a courier could not be switched on until
     * it was configured, and could not be configured meaningfully until it was on. Switching one on is a
     * decision the merchant is allowed to make first; this list is what the tab shows them afterwards,
     * and BGCouriers_Settings::courier_offerable() is what actually withholds the courier from the
     * checkout until the list is empty.
     *
     * Reads SAVED options. Couriers override it to add their own required fields on top of these.
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
        // Not credentials, and not this courier's own fields: the last two things between a courier that
        // is fully set up and a customer seeing it. Both were silent - a courier with every delivery
        // option switched off still quoted (selection_for() falls back to 'office'), and one that had
        // never been added to a shipping zone simply never appeared, with nothing anywhere saying why.
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
