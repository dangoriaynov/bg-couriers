<?php
defined('ABSPATH') || exit;

class BGCouriers_Ajax {
    public function __construct() {
        foreach (['search_cities','offices','city_avail','streets','set_selection','geocode','allmap_cities','allmap_offices','allmap_prices'] as $a) {
            add_action("wp_ajax_bgcouriers_{$a}", [$this, $a]);
            add_action("wp_ajax_nopriv_bgcouriers_{$a}", [$this, $a]);
        }
    }

    /** Best-effort client IP for rate-limiting only (honours a CDN/proxy header, falls back to REMOTE_ADDR). */
    private static function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
            if (empty($_SERVER[$h])) { continue; }
            $ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$h])))[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) { return $ip; }
        }
        return 'unknown';
    }

    /**
     * Lightweight per-IP rate limit for the public endpoints that hit a LIVE courier API. Returns false once
     * the caller exceeds $max requests within $window seconds; the handler then answers BUSY instead of
     * making an outbound call, so anonymous enumeration cannot amplify into many courier API calls.
     *
     * The budget is per IP, and "a real checkout makes only a handful of these calls" turned out to be
     * wrong: one checkout load asks availability and offices for every enabled courier, and a customer
     * who picks a city, switches courier and opens the map spends tens of calls on their own. At 90 a
     * minute the plugin's own end-to-end suite exhausted it, and so would two customers sharing an
     * address - an office, or a mobile carrier behind CGNAT, which is most phone traffic here. 300
     * leaves room for several real customers at once while still being far below what scraping a
     * nomenclature would need.
     *
     * The window is a FIXED sixty seconds, counted from the first request in it. It used to be written
     * back with a fresh expiry on every single call, which made it a window of silence rather than a
     * window of time: a shared address with steady traffic never reached a quiet moment, so its budget
     * never reset until it had been refused long enough to stop asking. Two customers at one office
     * were enough to put the second one in a queue behind the first.
     */
    private static function rate_ok(int $max = 300, int $window = 60): bool {
        $key  = 'bgcouriers_rl_' . md5(self::client_ip());
        $now  = time();
        $slot = get_transient($key);
        // Anything that is not our own shape - including the bare counter this used to store - starts a
        // fresh window rather than being reinterpreted.
        if (!is_array($slot) || (int) ($slot['until'] ?? 0) <= $now) {
            $slot = ['n' => 0, 'until' => $now + $window];
        }
        if ((int) $slot['n'] >= $max) { return false; }
        $slot['n'] = (int) $slot['n'] + 1;
        set_transient($key, $slot, max(1, (int) $slot['until'] - $now));
        return true;
    }

    /**
     * "Not now" - and say so, rather than answering with an empty hand.
     *
     * Every one of these endpoints used to answer a refused request with an empty result, which is the
     * same thing it says when a town genuinely has no offices. The browser cannot tell those apart, so
     * it believed the empty one and cached it:
     *
     *  - city_avail answered {office:false, automat:false}, and the checkout greyed out BOTH delivery
     *    options and remembered that for the rest of the page. The customer was shown a courier that
     *    delivers nowhere in their town, and the town was fine.
     *  - offices answered [], and the office dropdown stayed empty.
     *  - the map's own lookup answered {}, and a town with no points in it is now a town the map drops -
     *    so a refused request could throw away the place the customer had chosen.
     *
     * So a refusal is an HTTP 429 and not a 200 with something in it. That matters more than the body:
     * these five endpoints do not share a shape - offices and streets answer with a LIST, city_avail,
     * the map lookup and the geocoder with an OBJECT - so there is no one "empty answer" that every
     * caller could be handed safely. A flag inside a 200 was worse than the empty hand it replaced: the
     * office dropdown caches what it is given and then calls .filter on it, and the street dropdown
     * calls .map, so an object arriving where a list was expected takes the whole field out with a
     * TypeError rather than merely leaving it empty.
     *
     * Every caller here is jQuery, and jQuery routes a non-2xx away from the success handler - so
     * `$.get(..., fn)`, `.done(fn)` and select2's transport all simply do not run, which is precisely
     * "do not cache this, ask again next time". 429 is also the honest code: the request was fine, the
     * shop was not willing to serve it this second.
     *
     * The body keeps `bgc_busy` so a refusal is recognisable in the network tab and in a log, and so a
     * caller that wants to tell "too many requests" apart from a real error has something to read.
     */
    private static function busy(): void {
        wp_send_json_error(['bgc_busy' => true], 429);
    }

    /**
     * Reverse-geocode a lat/lng to Bulgarian address parts for the checkout address-map picker.
     * Uses Google when the admin has set a Maps API key (better accuracy), else OpenStreetMap Nominatim.
     * Result cached per rounded coordinate. Returns { city, postcode, street, number }.
     */
    public function geocode(): void {
        if (!self::rate_ok()) { self::busy(); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $lat = round((float) wp_unslash($_GET['lat'] ?? 0), 5); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float-cast, no state change
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $lng = round((float) wp_unslash($_GET['lng'] ?? 0), 5); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float-cast, no state change
        if ($lat === 0.0 && $lng === 0.0) { wp_send_json([]); }
        $tkey = 'bgcouriers_geo_' . str_replace(['.', '-'], ['', 'm'], $lat . '_' . $lng);
        $cached = get_transient($tkey);
        if (is_array($cached)) { wp_send_json($cached); }
        $key = trim((string) get_option('bgcouriers_google_maps_key', ''));
        $out = $key !== '' ? self::geocode_google($lat, $lng, $key) : self::geocode_nominatim($lat, $lng);
        if (!empty($out)) { set_transient($tkey, $out, WEEK_IN_SECONDS); }
        wp_send_json($out);
    }

    private static function geocode_nominatim(float $lat, float $lng): array {
        $url = add_query_arg([
            'lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'addressdetails' => 1, 'accept-language' => 'bg',
        ], 'https://nominatim.openstreetmap.org/reverse');
        $r = wp_remote_get($url, ['timeout' => 12, 'headers' => ['User-Agent' => 'bg-couriers WooCommerce plugin']]);
        if (is_wp_error($r)) { return []; }
        $a = (array) (json_decode((string) wp_remote_retrieve_body($r), true)['address'] ?? []);
        if (empty($a)) { return []; }
        return [
            'city'     => (string) ($a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? ''),
            'postcode' => (string) ($a['postcode'] ?? ''),
            'street'   => (string) ($a['road'] ?? ''),
            'number'   => (string) ($a['house_number'] ?? ''),
        ];
    }

    private static function geocode_google(float $lat, float $lng, string $key): array {
        $url = add_query_arg([
            'latlng' => $lat . ',' . $lng, 'language' => 'bg', 'key' => $key,
        ], 'https://maps.googleapis.com/maps/api/geocode/json');
        $r = wp_remote_get($url, ['timeout' => 12]);
        if (is_wp_error($r)) { return self::geocode_nominatim($lat, $lng); } // fall back to OSM
        $data = json_decode((string) wp_remote_retrieve_body($r), true);
        $comp = (array) ($data['results'][0]['address_components'] ?? []);
        if (empty($comp)) { return []; }
        $pick = static function ($type) use ($comp) {
            foreach ($comp as $c) { if (in_array($type, (array) ($c['types'] ?? []), true)) { return (string) ($c['long_name'] ?? ''); } }
            return '';
        };
        return [
            'city'     => $pick('locality') ?: $pick('administrative_area_level_2'),
            'postcode' => $pick('postal_code'),
            'street'   => $pick('route'),
            'number'   => $pick('street_number'),
        ];
    }
    public static function address_fields(array $src): array {
        $keys = ['street_name','street_type','street_no','complex','block','entrance','floor','apartment','address_note'];
        $out = [];
        foreach ($keys as $k) { $out[$k] = sanitize_text_field((string) ($src[$k] ?? '')); }
        // The courier's own id for the chosen street, when it came off the list (0 for a typed one).
        $out['street_id'] = max(0, (int) ($src['street_id'] ?? 0));
        return $out;
    }
    /**
     * The country a lookup is asking about.
     *
     * The request carries it because the browser knows it first: the customer changes the country and the
     * dropdown asks for towns in the same breath as the selection is being saved, and answering that from
     * the session would list the towns of the country they have just left. Only a country the courier is
     * actually switched on for is honoured; anything else falls back to the saved selection.
     */
    private static function request_country(string $courier): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $c = strtoupper(sanitize_text_field(wp_unslash($_REQUEST['country'] ?? '')));
        return in_array($c, BGCouriers_Settings::delivery_countries($courier), true)
            ? $c
            : BGCouriers_Pricing::destination_country();
    }

    public static function search_cities_data(): array {
        $courier = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        // No term -> first N cities alphabetically; with a term -> matches, N max (sorted by name).
        // In the destination country only: the customer is choosing where THEIR parcel goes, and a
        // Bulgaria-only shop asks for the one country it has, exactly as before.
        return BGCouriers_Nomenclature::search_cities($courier, $term, BGCouriers_Settings::dropdown_limit(),
                                                      self::request_country($courier));
    }
    public function search_cities(): void { wp_send_json(self::search_cities_data()); }
    public function offices(): void {
        if (!self::rate_ok()) { self::busy(); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $courier = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        $type = sanitize_key(wp_unslash($_GET['type'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $limit = !empty($_GET['all']) ? 100000 : BGCouriers_Settings::dropdown_limit(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- all=1 -> the full city list, for the client cache
        wp_send_json(self::city_offices($courier, $city, $type, $term, $limit, self::request_country($courier)));
    }

    /** Which office types a city has (so the checkout can grey out a delivery option the city lacks). */
    public function city_avail(): void {
        if (!self::rate_ok()) { self::busy(); }
        $courier_id = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        if ($city <= 0) { wp_send_json(['office' => false, 'automat' => false]); }
        wp_send_json(self::city_avail_data($courier_id, $city, self::request_country($courier_id)));
    }

    /**
     * Which delivery options a town has - read off the office dropdown's own list, not asked for again.
     *
     * This used to be its own path to the courier with its own six-hour cache of the same list, and
     * only the dropdown's path had been looked after. The dropdown caches an answer only when there is
     * something in it, so a courier that failed to answer is asked again next time; this one cached
     * whatever it had, so an API that was down for one second greyed out both delivery options for
     * every customer for six hours, in a town that was fine. The dropdown's path catches \Throwable,
     * after an adapter's TypeError once turned a request into a 500; this one still caught
     * \Exception. And a picked town cost the courier two live calls for one list, the second unable to
     * use the first's cache because each kept its own.
     *
     * One list, one cache, one set of guards. Two booleans off a cached array cost nothing, which is
     * why there is no second cache here any more: two caches of one fact are two chances to disagree.
     *
     * @return array{office:bool,automat:bool}
     */
    public static function city_avail_data(string $courier_id, int $city, string $country): array {
        $office = false; $automat = false;
        // Every type, every row: 100000 is what the dropdown itself asks for with all=1.
        foreach (self::city_offices($courier_id, $city, '', '', 100000, $country) as $o) {
            $t = $o['type'] ?? '';
            if ($t === 'office') { $office = true; } elseif ($t === 'automat') { $automat = true; }
        }
        return ['office' => $office, 'automat' => $automat];
    }

    /**
     * Office/automat list for one city - fetched LIVE per-city (the country-wide nomenclature
     * is capped by Speedy and misses most cities), filtered by type + search term, sorted by
     * office number, limited to N. Falls back to the cached nomenclature when the API is down.
     *
     * The country is asked for and not assumed. A courier's office endpoint is scoped to a country, and a
     * request that leaves it out is answered for the courier's home one - so a Romanian town produced an
     * empty list rather than an error, which reads exactly like a town with no offices in it. Empty of
     * offices is a legitimate answer for a small place, so nothing downstream could tell the two apart.
     */
    /** The stamp of the courier's last nomenclature sync - '' before the first; see city_offices(). */
    public static function nomenclature_generation(string $courier_id): string {
        return (string) get_option('bgcouriers_nomgen_' . $courier_id, '');
    }

    public static function city_offices(string $courier_id, int $city, string $type, string $term = '', int $limit = 5, string $country = ''): array {
        $rows = [];
        if ($city > 0) {
            // Cache the (live) office list per courier+city - offices change rarely, so this turns the first
            // fetch into an instant response for everyone after, killing the checkout's biggest round-trip.
            // The key carries the courier's nomenclature generation (BGCouriers_Sync writes a new one on
            // every run), so a sync retires every town's cached list at once. Transients cannot be
            // deleted by prefix, and on a shop with an object cache they are not even in the database;
            // measured on dev on 2026-09-13, a town kept answering with the list a previous build had
            // cached for it for the rest of the six hours, sync or no sync.
            $tkey   = 'bgcouriers_off_' . $courier_id . '_' . $country . '_' . $city . '_' . self::nomenclature_generation($courier_id);
            $cached = get_transient($tkey);
            if (is_array($cached)) {
                $rows = $cached;
            } else {
                try {
                    $courier = BGCouriers_Couriers::get($courier_id);
                    if (!$courier) { return []; } // honor the array return type; the AJAX handler sends the empty JSON
                    $rows = $courier->fetch_offices($city, $country);
                    if (!empty($rows)) { set_transient($tkey, $rows, 6 * HOUR_IN_SECONDS); }
                // \Throwable, not \Exception: a courier adapter that hits a TypeError - or any Error -
                // used to walk straight out of here and turn the whole request into a 500, when the
                // synced table beside it could have answered. Nothing about a broken adapter should
                // cost the customer their office list.
                } catch (\Throwable $e) { $rows = BGCouriers_Nomenclature::offices($courier_id, $city); }
            }
        }
        if ($type !== '') {
            $rows = array_filter($rows, static function ($o) use ($type) { return ($o['type'] ?? '') === $type; });
        }
        if ($term !== '') {
            $t = function_exists('mb_strtolower') ? mb_strtolower($term) : strtolower($term);
            $rows = array_filter($rows, static function ($o) use ($t) {
                $name = function_exists('mb_strtolower') ? mb_strtolower((string) ($o['name'] ?? '')) : strtolower((string) ($o['name'] ?? ''));
                return strpos($name, $t) !== false || strpos((string) ($o['office_id'] ?? ''), $t) !== false;
            });
        }
        $rows = array_values($rows);
        usort($rows, static function ($a, $b) { return ((int) ($a['office_id'] ?? 0)) <=> ((int) ($b['office_id'] ?? 0)); });
        return array_slice($rows, 0, max(1, $limit));
    }
    /**
     * Places for the combined map's city picker, gathered across EVERY enabled courier rather than one
     * of them, so a town that only one courier lists is still a candidate. Distinct by name + post code,
     * because that pair is what identifies a place to the other couriers.
     *
     * The dedup key is lower-cased: couriers spell the same place with different casing (Speedy's
     * nomenclature is upper-case, e.g. "SOFIA" vs another courier's "Sofia"), and comparing the raw
     * name would show the customer the same city twice. The label keeps whichever spelling was seen
     * FIRST, so the list still reads as one real courier's own wording, not a synthetic normalisation.
     *
     * The final sort is case-insensitive for the same reason: plain strcmp() is byte order, so every
     * upper-case spelling would sort as its own block ahead of (or behind) the lower-case ones instead of
     * interleaving alphabetically - and since the result is THEN sliced to 30, a single-courier place
     * could be pushed out of that slice purely by how one courier capitalises it, not because 30 genuinely
     * earlier places exist. This does not raise the 30-item cap itself: with more than 30 real candidates
     * for a term, the alphabetically-last ones are still dropped, same as before.
     */
    public function allmap_cities(): void {
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $seen = [];
        $out  = [];
        $country = BGCouriers_Pricing::destination_country();
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            if (get_option('bgcouriers_' . $cid . '_enabled', 'no') !== 'yes') { continue; }
            foreach (BGCouriers_Nomenclature::search_cities($cid, $term, 30, $country) as $row) {
                $lower = function_exists('mb_strtolower') ? mb_strtolower($row['name'], 'UTF-8') : strtolower($row['name']);
                $key = $lower . '|' . $row['post_code'];
                if (isset($seen[$key])) { continue; }
                $seen[$key] = true;
                $out[] = ['name' => $row['name'], 'post_code' => $row['post_code'], 'region' => $row['region'] ?? '', 'sort' => $lower];
            }
        }
        usort($out, static function ($a, $b) { return strcmp($a['sort'], $b['sort']); });
        wp_send_json(array_slice(array_map(static function ($r) {
            unset($r['sort']);
            return $r;
        }, $out), 0, 30));
    }

    /**
     * Every enabled courier's pickup points for ONE place, in one request. Each courier is asked with
     * the city id IT issued (see BGCouriers_Nomenclature::match_city) - a shared id does not exist.
     */
    public function allmap_offices(): void {
        // ONE charge for the whole request, deliberately. This does fan out to a lookup per enabled
        // courier, but that count is a small fixed bound - five couriers exist - not something a caller
        // can grow, which is what the limiter is for. Charging per courier instead made a single map
        // opening cost six units of a ninety-per-minute budget shared by everyone behind one IP, and a
        // shop's customers on the same office or mobile network then got an empty map. The client also
        // caches per place, so looking at a city twice costs nothing.
        if (!self::rate_ok()) { self::busy(); }
        $name = sanitize_text_field(wp_unslash($_GET['name'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $code = sanitize_text_field(wp_unslash($_GET['post_code'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $type = sanitize_key(wp_unslash($_GET['type'] ?? 'both')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!in_array($type, ['office', 'automat', 'both'], true)) { wp_send_json([]); }
        // 'both' is the normal case: a customer looking for somewhere to collect from does not care
        // whether the place is a staffed office or a locker, so the map shows both and each point says
        // which it is. Every point carries its own type because that is what the checkout must be set
        // to when the point is chosen - it is per point, not per dialog.
        $types = $type === 'both' ? ['office', 'automat'] : [$type];
        $out = self::allmap_collect($name, $code, $types, false);
        // Nothing at all from the local nomenclature means this shop has never run a sync - not that the
        // place is empty. Only then is the live path worth its cost, and only then does one slow courier
        // matter, because by definition there is nothing to lose.
        if (!$out) { $out = self::allmap_collect($name, $code, $types, true); }
        wp_send_json($out);
    }

    /**
     * Every enabled courier's points for one place.
     *
     * Reads the SYNCED tables, not the couriers' live endpoints. The live path is what
     * BGCouriers_Ajax::city_offices() does, and it is right for a single courier's own picker - but this
     * endpoint fans out across every enabled courier and both delivery types, which is up to eight live
     * API calls inside one request. That reliably killed the request whenever the 6-hour per-city
     * transient was cold: measured on dev, clearing the transients for Plovdiv turned this endpoint from
     * 200 in ~2s into a 500 with an empty body in ~6s, and the customer got a blank map with no error.
     * One slow courier should not be able to take down a map of five.
     *
     * The tables carry the same thing: the same run gave 37+50 / 36+3 / 16+0 / 0+90 points for Plovdiv
     * against the live 37+50 / 36+3 / 16+0 / 0+91 - a single locker added since the last sync, which the
     * next sync picks up. A day-old office list is the right trade for a map that always answers.
     *
     * @param string $name  Place name as the customer's chosen suggestion spells it.
     * @param string $code  Post code, '' when unknown.
     * @param array  $types Delivery types wanted, e.g. ['office','automat'].
     * @param bool   $live  Ask the couriers directly instead of reading the synced tables.
     * @return array courier id => ['city_id' => int, 'offices' => array]
     */
    /**
     * The live price for ONE courier in ONE town, asked for after the map is already on screen.
     *
     * Split out of allmap_offices deliberately. Quoting inside that request made the first open of a new
     * town wait on up to three courier calls per courier before a single pin was drawn - correct numbers
     * on a map that had not arrived yet. The map now opens on the cached reference and each courier's
     * real figure replaces it as the answer lands, one request per courier, exactly the way the
     * distances behave.
     *
     * One quote per delivery TYPE, never per office: these couriers price by the city pair, so every
     * office in a town costs the same and quoting each would be hundreds of calls for one answer.
     */
    public function allmap_prices(): void {
        if (!self::rate_ok()) { self::busy(); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only price lookup, no state change
        $cid  = isset($_GET['courier']) ? sanitize_key(wp_unslash($_GET['courier'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only price lookup, no state change
        $name = isset($_GET['name']) ? sanitize_text_field(wp_unslash($_GET['name'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only price lookup, no state change
        $code = isset($_GET['post_code']) ? sanitize_text_field(wp_unslash($_GET['post_code'])) : '';
        $obj  = $cid !== '' ? BGCouriers_Couriers::get($cid) : null;
        if (!$obj || !BGCouriers_Settings::courier_offerable($cid)) { wp_send_json_success(['prices' => [], 'saves' => []]); }
        // A town name and post code are only unique inside a country: "1000" is Sofia and Bucharest.
        $country = BGCouriers_Pricing::destination_country();
        $city = BGCouriers_Nomenclature::match_city($cid, $name, $code, $country);
        if (!$city) { wp_send_json_success(['prices' => [], 'saves' => []]); }

        // The same parcel the shipping methods are priced for - one definition, so the map cannot
        // advertise a price the checkout will not charge.
        $packed = BGCouriers_Pricing::cart_parcel();
        $prices = []; $raw = [];
        foreach (BGCouriers_Settings::enabled_methods($cid) as $t) {
            try {
                $q = BGCouriers_Pricing::checkout_quote($obj, $t, (int) $city['city_id'], 0, $packed, get_woocommerce_currency(), $country);
                // Printed exactly the way the shipping row beside it is printed, because the map is
                // what feeds that row - and the two numbers being different is the fault this line
                // exists to prevent. Which sum that is depends on WHO gets paid: a delivery charged
                // with the order is the shop's own price and follows the shop's display setting, while
                // one paid at the door is the courier's cash and carries its tax whatever the shop
                // chooses to show. Both branches are the same call the shipping method makes.
                if (!$q) {
                    $v = 0.0;
                } elseif (BGCouriers_Settings::ship_in_total($cid)) {
                    $v = BGCouriers_Pricing::display_price(BGCouriers_Pricing::rate_cost($q));
                } else {
                    $v = BGCouriers_Pricing::door_price($q);
                }
            } catch (\Throwable $e) { $v = 0.0; }   // one unreachable courier must not empty the map
            if ($v > 0) {
                $raw[$t]    = $v;
                $prices[$t] = html_entity_decode(wp_strip_all_tags(wc_price($v)), ENT_QUOTES, 'UTF-8');
            }
        }
        $saves = [];
        if (isset($raw['address'])) {
            foreach (['office', 'automat'] as $t) {
                if (isset($raw[$t]) && $raw['address'] > $raw[$t]) {
                    $saves[$t] = html_entity_decode(wp_strip_all_tags(wc_price($raw['address'] - $raw[$t])), ENT_QUOTES, 'UTF-8');
                }
            }
        }
        wp_send_json_success(['courier' => $cid, 'prices' => $prices, 'saves' => $saves]);
    }

    private static function allmap_collect(string $name, string $code, array $types, bool $live): array {
        $out = [];
        $country = BGCouriers_Pricing::destination_country();
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            if (get_option('bgcouriers_' . $cid . '_enabled', 'no') !== 'yes') { continue; }
            $carries = BGCouriers_Settings::enabled_methods($cid);
            $wanted = array_values(array_intersect($types, $carries));
            if (!$wanted) { continue; }
            $city = BGCouriers_Nomenclature::match_city($cid, $name, $code, $country);
            if (!$city) { continue; }
            $rows = [];
            foreach ($wanted as $t) {
                $found = $live
                    ? self::city_offices($cid, (int) $city['city_id'], $t, '', 100000, $country)
                    : BGCouriers_Nomenclature::offices($cid, (int) $city['city_id'], $t);
                foreach ($found as $office) {
                    $office['type'] = $t;
                    $rows[] = $office;
                }
            }
            if (!$rows) { continue; }
            // A price PER DELIVERY TYPE, because a courier does not charge one. The map used to label
            // every point of a courier with whatever its rate row happened to be showing, which is the
            // price of the type currently selected: with Speedy on "to office" its lockers were
            // advertised at 2.64 instead of 1.52, and the moment the customer switched to a locker its
            // offices were advertised at 1.52 instead. Whichever tab you were on, the other one lied.
            // The map opens on the CACHED figure and never waits for a courier. Quoting live here made
            // the first open of a new town slow enough that the tiles had not painted yet - and a map
            // that is correct but arrives late is a worse map. The live number follows a moment later
            // through bgcouriers_allmap_prices, per courier, the same way the distances do.
            // A price PER DELIVERY TYPE, because a courier does not charge one. The map used to label
            // every point of a courier with whatever its rate row happened to be showing, which is the
            // price of the type currently selected: with Speedy on "to office" its lockers were
            // advertised at 2.64 instead of 1.52, and the moment the customer switched to a locker its
            // offices were advertised at 1.52 instead. Whichever tab you were on, the other one lied.
            // Every method this courier offers, not only the two the map plots. The address price is not
            // a point on the map, but it is the number the whole map is being compared AGAINST.
            $prices = []; $raw = [];
            foreach (BGCouriers_Settings::enabled_methods($cid) as $t) {
                $v = BGCouriers_Pricing::estimate($cid, $t);
                // Decoded, not merely stripped: wc_price() spells the amount with &nbsp; and &euro;, and
                // the map escapes whatever it is handed before printing it - so the entities would reach
                // the customer as the literal text "1,52&nbsp;&euro;".
                if ($v !== null) {
                    $raw[$t]    = (float) $v;
                    $prices[$t] = html_entity_decode(wp_strip_all_tags(wc_price((float) $v)), ENT_QUOTES, 'UTF-8');
                }
            }
            // What collecting from a point SAVES against having it brought to the door - formatted here,
            // where both numbers and the shop's own currency formatter are. Working it out in the browser
            // would mean parsing "~ 1,57 €" back into a number and re-formatting it, which breaks on the
            // first shop with a different separator or symbol position.
            $saves = [];
            if (isset($raw['address'])) {
                foreach (['office', 'automat'] as $t) {
                    if (!isset($raw[$t]) || $raw[$t] >= $raw['address']) { continue; }
                    $saves[$t] = html_entity_decode(
                        wp_strip_all_tags(wc_price($raw['address'] - $raw[$t])), ENT_QUOTES, 'UTF-8');
                }
            }
            $out[$cid] = ['city_id' => (int) $city['city_id'], 'prices' => $prices,
                          'saves' => $saves, 'offices' => $rows];
        }
        return $out;
    }
    public function streets(): void {
        if (!self::rate_ok()) { self::busy(); }
        $courier_id = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $out = [];
        if ($city > 0 && $term !== '') {
            try {
                $courier = BGCouriers_Couriers::get($courier_id);
                if (!$courier) { wp_send_json([]); }
                if (method_exists($courier, 'search_streets')) {
                    $out = array_slice(self::rank_streets($courier->search_streets($city, $term, self::request_country($courier_id)), $term), 0, BGCouriers_Settings::dropdown_limit());
                }
            } catch (\Throwable $e) { $out = []; }   // same guard as the office lookup, same reason
        }
        wp_send_json($out);
    }

    /**
     * The streets that BEGIN with what was typed first, then the ones with a word that does, then the
     * rest - in the courier's own order within each.
     *
     * The list is cut to the dropdown limit after this, and a courier's own order is not a ranking:
     * Pigeon answers "Витоша" for Sofia with "улица 600-НА (ВИТОША)" and thirty more numbered streets
     * before "улица Витоша" and "булевард ВИТОША" (measured 2026-09-13), which put both past the twenty
     * the dropdown shows - so a customer typing the street's whole name did not find it. The same
     * ordering the town search applies (bgcMatchRank in bgc-checkout.js), on the bare name.
     *
     * @param array[] $rows  Parsed street rows ({id,name,type,label}).
     */
    public static function rank_streets(array $rows, string $term): array {
        $t = self::fold($term);
        if ($t === '') { return $rows; }
        $ranked = [];
        foreach (array_values($rows) as $i => $r) {
            $n = self::fold((string) ($r['name'] ?? ''));
            // Character offsets, not byte offsets: the names are Cyrillic.
            $p = function_exists('mb_strpos') ? mb_strpos($n, $t, 0, 'UTF-8') : strpos($n, $t);
            if ($p === false) { $rank = 3; }
            elseif ($p === 0) { $rank = 0; }
            elseif (preg_match('/[\s\-.,()\/"\']/u', function_exists('mb_substr') ? mb_substr($n, $p - 1, 1, 'UTF-8') : substr($n, $p - 1, 1))) { $rank = 1; }
            else { $rank = 2; }
            $ranked[] = [$rank, $i, $r];
        }
        usort($ranked, static function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; });
        return array_map(static function ($x) { return $x[2]; }, $ranked);
    }

    private static function fold(string $s): string {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
    public function set_selection(): void {
        check_ajax_referer('bgcouriers_checkout', 'nonce');
        $method = sanitize_key(wp_unslash($_POST['method'] ?? 'office'));
        if (!in_array($method, ['address', 'office', 'automat'], true)) { $method = 'office'; }
        WC()->session->set('bgcouriers_method', $method);
        WC()->session->set('bgcouriers_selection_courier', sanitize_key(wp_unslash($_POST['courier'] ?? ''))); // which courier this selection belongs to
        // Only a country this courier is actually switched on for. Anything else - a stale one left in a
        // tab, a hand-made POST - falls back to the shop's own country rather than reaching an API that
        // would have to guess a service for it.
        $country = strtoupper(sanitize_text_field(wp_unslash($_POST['country'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $allowed = BGCouriers_Settings::delivery_countries(sanitize_key(wp_unslash($_POST['courier'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $country = in_array($country, $allowed, true) ? $country : BGCouriers_Settings::home_country();
        WC()->session->set('bgcouriers_country', $country);
        // WooCommerce decides which shipping ZONE a cart is in from the customer's own shipping country,
        // not from anything of ours - so a courier switched on for Romania is still never asked for a
        // price until the customer is in Romania as far as WooCommerce is concerned. Written only when it
        // actually differs: this endpoint is called on every field save, and a shop that delivers at home
        // only never reaches the write at all.
        if (function_exists('WC') && WC()->customer
            && strtoupper((string) WC()->customer->get_shipping_country()) !== $country) {
            WC()->customer->set_shipping_country($country);
            WC()->customer->save();
        }
        WC()->session->set('bgcouriers_site_id', (int) wp_unslash($_POST['site_id'] ?? 0)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
        WC()->session->set('bgcouriers_office_id', (int) wp_unslash($_POST['office_id'] ?? 0)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
        WC()->session->set('bgcouriers_post_code', sanitize_text_field(wp_unslash($_POST['post_code'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        foreach (self::address_fields($_POST) as $k => $v) { WC()->session->set('bgcouriers_addr_' . $k, $v); }
        self::remember_for_courier(
            sanitize_key(wp_unslash($_POST['courier'] ?? '')), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
            $method,
            (int) wp_unslash($_POST['site_id'] ?? 0), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
            (int) wp_unslash($_POST['office_id'] ?? 0) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
        );
        wp_send_json_success(['ok' => true]);
    }

    /**
     * Remember what was chosen FOR THIS COURIER, beside the single "current selection" the session
     * already holds.
     *
     * The session keeps one selection - courier, method, city, office - and BGCouriers_Pricing::
     * selection_for() hands it back only to the courier it belongs to. Everybody else was quoted for
     * their own FIRST enabled method, so the moment a customer touched a second courier, the first one
     * forgot the delivery type they had picked in it and its row silently reverted to the price of
     * something else: Sameday showed 1.30 as a locker, then 2.87 the instant Speedy was touched, because
     * Sameday fell back to "to address". The customer was reading a price for a delivery they had not
     * chosen.
     *
     * This is a memory per courier, written alongside the current selection and never instead of it, so
     * ordering, validation and the created order all keep reading exactly what they read before.
     */
    public static function remember_for_courier(string $courier, string $method, int $site_id, int $office_id): void {
        if ($courier === '' || !function_exists('WC') || !WC()->session) { return; }
        $all = (array) WC()->session->get('bgcouriers_sel_by_courier', []);
        $all[$courier] = ['method' => $method, 'site_id' => $site_id, 'office_id' => $office_id];
        WC()->session->set('bgcouriers_sel_by_courier', $all);
    }
}
