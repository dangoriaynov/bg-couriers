<?php
defined('ABSPATH') || exit;

class BGCouriers_Sync {
    const HOOK       = 'bgcouriers_weekly_sync'; // full nomenclature sync (heavy) - weekly
    const RATES_HOOK = 'bgcouriers_daily_rates'; // reference-price refresh (light) - daily

    /** Courier id -> its own id for Sofia, as far as this request has looked it up. See sofia_city(). */
    private static $sofia_ids = [];

    /**
     * First city alphabetically from a courier's cached cities in one country (the reference origin).
     *
     * The country is not optional in practice, only in signature: with two countries in the table the
     * sort runs across both - Latin "Bucuresti" against Cyrillic "Sofia" - and the collation would pick
     * which country every Bulgarian shopper's pre-town price is quoted against. That is not a decision
     * anyone made, so '' means the shop's own country, never "any".
     */
    public static function first_city(string $courier, string $country = ''): int {
        // Two rows, not one: the first of them is the reference origin for the COUNTRY zone, and the
        // capital must not be it. Alphabetically it never is (Cyrillic С sorts late among five thousand
        // towns), but "never in the data we have" is not the same as "cannot", and a COUNTRY reference
        // quoted inside Sofia would be the cheap price advertised to the whole country.
        $rows = BGCouriers_Nomenclature::search_cities($courier, '', 2, self::country_or_home($country)); // term '' -> all, ORDER BY name
        foreach ($rows as $r) {
            if (BGCouriers_Zones::is_sofia_name((string) ($r['name'] ?? ''), (string) ($r['name_lat'] ?? ''))) { continue; }
            return (int) $r['city_id'];
        }
        // Nothing but the capital in the list: 0, meaning this courier has no out-of-Sofia route to
        // measure. Handing Sofia back instead would file its price as the country one, and a checkout
        // reads that figure before it knows where the parcel is going - so every customer in the country
        // would be quoted the city tariff, and the shop would pay the difference on each of them.
        return 0;
    }

    /**
     * The courier's own id for Sofia, or 0 when it does not list it.
     *
     * Searched by name rather than by post code: 1000 is the capital in Bulgaria and a Bucharest sector in
     * Romania, and the name test is the one BGCouriers_Zones reads a destination with - the origin of a
     * reference price and the destinations it will be read for have to agree about what Sofia is, or a
     * shop would cache a zone nothing ever asks for. The post code is the fallback for a courier that
     * spells the name in a way the test does not know yet; it is filtered through the same test, so a
     * Romanian 1000 cannot answer.
     */
    public static function sofia_city(string $courier): int {
        // Asked for by every reference route, both zones of it (the country one needs the id to avoid),
        // so the lookup is remembered for the rest of the request - and forgotten by a sync that has just
        // rewritten the towns it reads (a first sync runs in the same request as the seeding that follows
        // it, and would otherwise seed every Sofia price against a town list that was empty when asked).
        if (isset(self::$sofia_ids[$courier])) { return self::$sofia_ids[$courier]; }
        self::$sofia_ids[$courier] = 0;
        $home = BGCouriers_Settings::home_country();
        foreach (BGCouriers_Nomenclature::search_cities($courier, 'София', 10, $home) as $r) {
            if (BGCouriers_Zones::is_sofia_name((string) ($r['name'] ?? ''), (string) ($r['name_lat'] ?? ''))) {
                return self::$sofia_ids[$courier] = (int) $r['city_id'];
            }
        }
        $row = BGCouriers_Nomenclature::city_by_postcode($courier, '1000', $home);
        if ($row && BGCouriers_Zones::is_sofia_name((string) ($row['name'] ?? ''), (string) ($row['name_lat'] ?? ''))) {
            return self::$sofia_ids[$courier] = (int) $row['city_id'];
        }
        return 0;
    }

    /** Forget the remembered Sofia ids - a sync has just rewritten the towns they came from. */
    public static function forget_sofia(): void { self::$sofia_ids = []; }

    /** '' = the shop's own country. Never "any country" - see first_city(). */
    private static function country_or_home(string $country): string {
        $c = strtoupper(trim($country));
        return $c !== '' ? $c : BGCouriers_Settings::home_country();
    }

    /**
     * Reference shipment for a method in one price zone: a route inside Sofia, or one to the courier's
     * first cached city outside it + (for office/automat) a representative office of that city.
     * Returns [] if the method can't be referenced yet in that zone.
     */
    public static function reference_shipment(string $courier, string $method, string $country = '', string $zone = BGCouriers_Zones::DEFAULT_ZONE): array {
        $store   = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
        $country = self::country_or_home($country);
        // Zones are a home-country idea (see BGCouriers_Zones): abroad every reference route is simply
        // "that country", and asking for its Sofia one would quote a Bulgarian route under a foreign label.
        $zone  = BGCouriers_Settings::is_intl($country) ? BGCouriers_Zones::COUNTRY : BGCouriers_Zones::sanitize($zone);
        $base  = ['method' => $method, 'cod_amount' => 0.0, 'currency' => $store, 'country' => $country,
                  'weight_kg' => 2.0, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10];
        // Sofia's own id is needed either way: as the route for its zone, and as the town the COUNTRY
        // route must avoid.
        $sofia = BGCouriers_Settings::is_intl($country) ? 0 : self::sofia_city($courier);
        $want_sofia = $zone === BGCouriers_Zones::SOFIA;
        // Asked for Sofia and the courier does not list it: no route, so no reference. Returning the
        // country one instead would file a longer route's price under 'sofia', and every Sofia checkout
        // would then read it as if it had been measured.
        if ($want_sofia && $sofia <= 0) { return []; }
        if ($method === 'address') {
            $city = $want_sofia ? $sofia : self::first_city($courier, $country);
            if ($city <= 0) { return []; }
            return array_merge($base, ['site_id' => $city, 'office_id' => 0, 'office_code' => '',
                                       'street_name' => 'Тест', 'street_no' => '1']);
        }
        // office / automat - a representative office of that type: in Sofia for that zone, otherwise the
        // first city alphabetically that has one (the first city overall is often a village with no
        // Econtomat/locker). Sofia has offices and lockers of every kind, so the search there is the
        // city's own list.
        $off = $want_sofia
            ? (BGCouriers_Nomenclature::offices($courier, $sofia, $method)[0] ?? [])
            : (array) BGCouriers_Nomenclature::first_office($courier, $method, $country, $sofia);
        if (empty($off['office_id'])) { return []; }
        return array_merge($base, ['site_id' => (int) ($off['city_id'] ?? $sofia), 'office_id' => (int) $off['office_id'],
                                   'office_code' => (string) ($off['code'] ?? '')]);
    }

    /**
     * Seed the reference (standard-rate fallback) prices per enabled delivery method AND price zone -
     * one quote inside Sofia, one for a town outside it (BGCouriers_Zones). Stored in BGCouriers_Rates and
     * shown at checkout BEFORE the customer picks a destination; if a method can't be quoted the
     * configured default price applies.
     *
     * Two zones is twice the quotes: for seven couriers with three methods each that is 42 calls on the
     * daily run instead of 21, spread over one cron event that nobody is waiting for. The checkout is what
     * this buys - it reads a price measured for the zone the customer is actually in, so the number stops
     * changing under them when they name their town.
     *
     * @return int How many prices were written (methods x zones that could be quoted).
     */
    public static function seed_rates(BGCouriers_Courier_Interface $courier, ?string &$failure = null): int {
        $id   = $courier->id();
        $caps    = $courier->capabilities();
        $methods = array_values(array_filter(['address', 'office', 'automat'],
            static function ($m) use ($caps) { return in_array($m, $caps, true); }));
        $n = 0;
        foreach ($methods as $method) {
            foreach (BGCouriers_Zones::all() as $zone) {
                $shipment = self::reference_shipment($id, $method, '', $zone);
                $store    = (string) ($shipment['currency'] ?? '');   // what this shop asked to be quoted in
                // A zone with no route of its own is skipped, not filled in from the other one: a courier that
                // does not list Sofia has no Sofia price, and BGCouriers_Rates::get says so by falling back to
                // the country figure at read time, where the fallback is visible.
                if (!$shipment) { continue; }
                try {
                    $q = $courier->quote($shipment);
                    // NET. This is read back as a shipping rate's cost, and a rate's cost is taxed by
                    // WooCommerce on top - storing the gross total charged the VAT twice.
                    //
                    // Stored with the currency the courier ANSWERED in, not the one it was asked for. They
                    // are normally the same - the shipment above names the shop's currency and every
                    // adapter passes it on - and where they are not, the row says what the number really
                    // is. Writing the shop's currency over a figure quoted in another one is precisely the
                    // fault this column exists to prevent. A row like that is unreadable to the shop by
                    // design, so it is worth a line saying why rather than a reference that silently never
                    // appears.
                    if ($q->currency !== '' && $store !== '' && $q->currency !== $store) {
                        BGCouriers_Logger::debug('seed_rates: quoted in another currency, so this shop has no reference for it', [
                            'courier' => $id, 'method' => $method, 'asked' => $store, 'answered' => $q->currency]);
                    }
                    BGCouriers_Rates::set($id, $method, $zone, $q->price, $q->currency);
                    $n++;
                } catch (\Throwable $e) {
                    // The first refusal, for the caller that wants to say why there are no rates: a
                    // nomenclature that synced beside quotes a courier refused is "0 rates" in green
                    // otherwise, which is what a wrong password looked like on the settings screen.
                    if ($failure === null) { $failure = $e->getMessage(); }
                    BGCouriers_Logger::debug('seed_rates: quote failed', ['courier' => $id, 'method' => $method, 'zone' => $zone, 'err' => $e->getMessage()]);
                }
            }
        }
        return $n;
    }

    /**
     * Stamp every row of a fetch with the country it was fetched for. The couriers' own parsers return
     * what the API said and nothing more - which country was asked for is the caller's knowledge, so it
     * is the caller that writes it down.
     *
     * @param string $iso  ISO alpha-2.
     * @param array  $rows Rows from a fetch_cities()/fetch_offices() call.
     */
    private static function tag(string $iso, array $rows): array {
        foreach ($rows as &$r) { $r['country'] = $iso; }
        unset($r);
        return $rows;
    }

    public static function run(BGCouriers_Courier_Interface $courier): array {
        // Nomenclature sync is a heavy batch op (Econt's getCities decodes to ~130MB and thousands
        // of rows are upserted), so lift the default web limits or it OOMs / times out mid-sync.
        if (function_exists('set_time_limit')) { @set_time_limit(0); } // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- needed for long nomenclature sync
        @ini_set('memory_limit', '512M'); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- needed for long nomenclature sync

        $id  = $courier->id();
        $run = uniqid('run', true);
        $out = ['cities' => 0, 'offices' => 0, 'pruned' => 0, 'rates' => 0];

        // Fetched independently, and one failing must not cancel the other. An empty city list used to
        // return early, which was right for "the API hiccuped" and wrong for "this courier HAS no
        // cities": BOX NOW is geo-based and legitimately returns none, so its 886 lockers were never
        // even requested and every sync reported a cheerful 0 / 0 / 0 against valid credentials.
        $home   = BGCouriers_Settings::home_country();
        $cities = $offices = [];
        // Which countries actually answered, per table. Only these may be pruned - see prune_table().
        $got_cities = $got_offices = [];
        // What a fetch that threw said. A courier that answers with nothing (BOX NOW has no towns) is
        // not in here; a courier that refuses is - and that is what the screen has to be told, or it
        // paints "0 cities, 0 offices" green over a login the courier has just turned down.
        $failed = [];
        try { $cities = self::tag($home, $courier->fetch_cities()); $got_cities[] = $home; }
        catch (\Throwable $e) { $failed[] = $e->getMessage(); BGCouriers_Logger::debug('sync: city fetch failed', ['courier' => $id, 'err' => $e->getMessage()]); }
        try { $offices = self::tag($home, $courier->fetch_offices(0)); $got_offices[] = $home; } // 0 = all offices in one call (country-wide)
        catch (\Throwable $e) { $failed[] = $e->getMessage(); BGCouriers_Logger::debug('sync: office fetch failed', ['courier' => $id, 'err' => $e->getMessage()]); }

        // Then every country the merchant has switched this courier on for. Each is fetched and tagged
        // separately - a country whose fetch fails leaves the others alone, and a country switched off
        // simply stops being refreshed, so the prune below removes it on this very run.
        //
        // The extra argument only ever reaches a courier that declares a country to deliver to, which is
        // the same courier that accepts it; a courier with no international countries is called exactly
        // as it always was.
        $intl = BGCouriers_Settings::intl_countries($id, $courier);
        foreach ($intl as $iso) {
            try { $cities = array_merge($cities, self::tag($iso, $courier->fetch_cities($iso))); $got_cities[] = $iso; }
            catch (\Throwable $e) { BGCouriers_Logger::debug('sync: city fetch failed', ['courier' => $id, 'country' => $iso, 'err' => $e->getMessage()]); }
            try { $offices = array_merge($offices, self::tag($iso, $courier->fetch_offices(0, $iso))); $got_offices[] = $iso; }
            catch (\Throwable $e) { BGCouriers_Logger::debug('sync: office fetch failed', ['courier' => $id, 'country' => $iso, 'err' => $e->getMessage()]); }
        }

        // GUARD: nothing at all came back = a failed fetch, not an empty country. Never prune on that.
        if (!$cities && !$offices) {
            BGCouriers_Logger::debug('sync: empty fetch, skipping prune', ['courier' => $id]);
            if ($failed) { $out['error'] = $failed[0]; }
            return $out;
        }
        // One table came back and the other threw: a run that did its half, and says which half it did not.
        if ($failed) { $out['warning'] = $failed[0]; }
        if ($cities)  { $out['cities']  = BGCouriers_Nomenclature::upsert_cities($id, $cities, $run); self::forget_sofia(); }
        if ($offices) { $out['offices'] = BGCouriers_Nomenclature::upsert_offices($id, $offices, $run); }
        // Rows are written a few hundred at a time, so a statement the database refuses takes a whole
        // batch with it - and the prune below deletes exactly what this run did not write. A short write
        // followed by a prune would delete towns the courier still has and the checkout still needs, so
        // a table that did not take everything offered is left alone until the next run.
        $wrote_cities  = $out['cities']  === count($cities);
        $wrote_offices = $out['offices'] === count($offices);
        if (!$wrote_cities || !$wrote_offices) {
            BGCouriers_Logger::debug('sync: a write was short, so that table is not pruned', [
                'courier' => $id,
                'cities'  => $out['cities'] . '/' . count($cities),
                'offices' => $out['offices'] . '/' . count($offices),
            ]);
        }
        // Prune ONLY what this run actually refreshed. Pruning both tables whenever either succeeded
        // would wipe a courier's offices the one time its office endpoint times out - and would delete
        // BOX NOW's lockers on every run, since it never has cities to refresh.
        // Restricted to the countries that answered: a shop syncing two countries must not lose one of
        // them because the other's fetch was the one that worked. A single-country shop is unchanged -
        // ['BG'] restricts a table that only holds BG rows to exactly nothing.
        $out['pruned'] = BGCouriers_Nomenclature::prune($id, $run, $cities && $wrote_cities, $offices && $wrote_offices,
                                                        $intl ? $got_cities : [], $intl ? $got_offices : []);
        // Nomenclature changed - drop the per-courier caches derived from it (which delivery types exist, and
        // the preloaded city index) so the checkout/editor immediately reflect the fresh point counts.
        delete_transient('bgcouriers_typecnt_' . $id);
        delete_transient('bgcouriers_cityidx_' . $id);
        foreach (array_merge([BGCouriers_Settings::home_country()], $intl) as $iso) {
            delete_transient('bgcouriers_cityidx_' . $id . '_' . strtolower($iso));
        }
        // And the per-town office lists the checkout caches for six hours: they are keyed by this stamp
        // (BGCouriers_Ajax::city_offices), so a new one retires all of them at once.
        update_option('bgcouriers_nomgen_' . $id, $run);

        $out['rates'] = self::seed_rates($courier, $rate_failure); // reference price per method AND zone
        if ($out['rates'] === 0 && $rate_failure !== null && !isset($out['warning'])) { $out['warning'] = $rate_failure; }
        return $out;
    }

    /** All registered couriers that have credentials configured. */
    private static function enabled_couriers(): array {
        $out = [];
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            $courier = BGCouriers_Couriers::get($cid);
            if ($courier && BGCouriers_Settings::courier_config($cid)) { $out[] = $courier; }
        }
        return $out;
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'weekly', self::HOOK);
        }
        if (!wp_next_scheduled(self::RATES_HOOK)) {
            wp_schedule_event(time() + 120, 'daily', self::RATES_HOOK); // 'daily' is a WP built-in schedule
        }
    }

    /**
     * One full sync, soon - what a plugin update asks for. A new version can read a courier's
     * nomenclature differently (0.4.8 gives BOX NOW towns, read off its lockers, where the tables
     * held none), and until the next weekly run that courier would be offered with no town to pick.
     * A single event on the weekly hook, a minute out; one already waiting is left alone.
     */
    public static function schedule_once(int $in = MINUTE_IN_SECONDS): void {
        $next = wp_next_scheduled(self::HOOK);
        if ($next !== false && $next <= time() + $in) { return; }
        wp_schedule_single_event(time() + $in, self::HOOK);
    }

    /**
     * A courier has just been switched on: ask for its towns and offices now, not next week.
     *
     * Switching one on is the moment a shop expects it to work. Until this existed, nothing asked:
     * the weekly run picks up whatever is enabled WHEN IT COMES ROUND, so a courier turned on the day
     * after one ran sat on the checkout for six more days with an empty town box and no price - which
     * reads as "the plugin is broken", not as "the data has not arrived yet". Reported by the shop
     * owner after switching Express One and Европът on, 2026-09-25.
     *
     * The one-off event is the same one a plugin update schedules (schedule_once), so two couriers
     * switched on a minute apart cost one sync, not two.
     *
     * Both hooks that can fire are wired to this: `update_option_X` hands over (old, new) and
     * `add_option_X` hands over (option, value) - the second argument is the new value either way, and
     * the first save of an option that never existed fires the one nobody remembers.
     *
     * @param mixed $first  old value, or the option name
     * @param mixed $value  the value being saved
     */
    public static function on_courier_enabled($first = null, $value = null): void {
        if ($value !== 'yes' || $first === 'yes') { return; }
        self::schedule_once();
    }

    /** Weekly: full nomenclature sync (cities + offices + reference rates) for every enabled courier. */
    public static function cron(): void {
        foreach (self::enabled_couriers() as $courier) { self::run($courier); }
    }

    /** Daily: refresh just the reference prices (light - first city + a quote per method). */
    public static function refresh_rates(): void {
        foreach (self::enabled_couriers() as $courier) { self::seed_rates($courier); }
    }
}
