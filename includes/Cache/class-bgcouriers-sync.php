<?php
defined('ABSPATH') || exit;

class BGCouriers_Sync {
    const HOOK       = 'bgcouriers_weekly_sync'; // full nomenclature sync (heavy) - weekly
    const RATES_HOOK = 'bgcouriers_daily_rates'; // reference-price refresh (light) - daily

    /**
     * First city alphabetically from a courier's cached cities in one country (the reference origin).
     *
     * The country is not optional in practice, only in signature: with two countries in the table the
     * sort runs across both - Latin "Bucuresti" against Cyrillic "София" - and the collation would pick
     * which country every Bulgarian shopper's pre-town price is quoted against. That is not a decision
     * anyone made, so '' means the shop's own country, never "any".
     */
    public static function first_city(string $courier, string $country = ''): int {
        $rows = BGCouriers_Nomenclature::search_cities($courier, '', 1, self::country_or_home($country)); // term '' -> all, ORDER BY name
        return (int) ($rows[0]['city_id'] ?? 0);
    }

    /** '' = the shop's own country. Never "any country" - see first_city(). */
    private static function country_or_home(string $country): string {
        $c = strtoupper(trim($country));
        return $c !== '' ? $c : BGCouriers_Settings::home_country();
    }

    /**
     * Reference shipment for a method, from the courier's first cached city + (for office/automat)
     * a representative office of that city. Returns [] if the method can't be referenced yet.
     */
    public static function reference_shipment(string $courier, string $method, string $country = ''): array {
        $store   = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
        $country = self::country_or_home($country);
        $base  = ['method' => $method, 'cod_amount' => 0.0, 'currency' => $store, 'country' => $country,
                  'weight_kg' => 2.0, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10];
        if ($method === 'address') {
            $city = self::first_city($courier, $country);
            if ($city <= 0) { return []; }
            return array_merge($base, ['site_id' => $city, 'office_id' => 0, 'office_code' => '',
                                       'street_name' => 'Тест', 'street_no' => '1']);
        }
        // office / automat - first office of that type, in the alphabetically-first city that has one
        // (the first city overall is often a village with no Econtomat/locker).
        $off = BGCouriers_Nomenclature::first_office($courier, $method, $country);
        if (empty($off['office_id'])) { return []; }
        return array_merge($base, ['site_id' => (int) $off['city_id'], 'office_id' => (int) $off['office_id'],
                                   'office_code' => (string) ($off['code'] ?? '')]);
    }

    /**
     * Seed the reference (standard-rate fallback) prices per enabled delivery method, quoting the
     * courier's first alphabetical city. Stored in BGCouriers_Rates and shown at checkout BEFORE the
     * customer picks a destination; if a method can't be quoted the configured default price applies.
     */
    public static function seed_rates(BGCouriers_Courier_Interface $courier): int {
        $id   = $courier->id();
        $caps    = $courier->capabilities();
        $methods = array_values(array_filter(['address', 'office', 'automat'],
            static function ($m) use ($caps) { return in_array($m, $caps, true); }));
        $n = 0;
        foreach ($methods as $method) {
            $shipment = self::reference_shipment($id, $method);
            if (!$shipment) { continue; }
            try {
                $q = $courier->quote($shipment);
                // NET. This is read back as a shipping rate's cost, and a rate's cost is taxed by
                // WooCommerce on top - storing the gross total charged the VAT twice.
                BGCouriers_Rates::set($id, $method, $q->price, $q->currency);
                $n++;
            } catch (\Exception $e) {
                BGCouriers_Logger::debug('seed_rates: quote failed', ['courier' => $id, 'method' => $method]);
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
        try { $cities = self::tag($home, $courier->fetch_cities()); $got_cities[] = $home; }
        catch (\Exception $e) { BGCouriers_Logger::debug('sync: city fetch failed', ['courier' => $id, 'err' => $e->getMessage()]); }
        try { $offices = self::tag($home, $courier->fetch_offices(0)); $got_offices[] = $home; } // 0 = all offices in one call (country-wide)
        catch (\Exception $e) { BGCouriers_Logger::debug('sync: office fetch failed', ['courier' => $id, 'err' => $e->getMessage()]); }

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
            catch (\Exception $e) { BGCouriers_Logger::debug('sync: city fetch failed', ['courier' => $id, 'country' => $iso, 'err' => $e->getMessage()]); }
            try { $offices = array_merge($offices, self::tag($iso, $courier->fetch_offices(0, $iso))); $got_offices[] = $iso; }
            catch (\Exception $e) { BGCouriers_Logger::debug('sync: office fetch failed', ['courier' => $id, 'country' => $iso, 'err' => $e->getMessage()]); }
        }

        // GUARD: nothing at all came back = a failed fetch, not an empty country. Never prune on that.
        if (!$cities && !$offices) {
            BGCouriers_Logger::debug('sync: empty fetch, skipping prune', ['courier' => $id]);
            return $out;
        }
        if ($cities)  { $out['cities']  = BGCouriers_Nomenclature::upsert_cities($id, $cities, $run); }
        if ($offices) { $out['offices'] = BGCouriers_Nomenclature::upsert_offices($id, $offices, $run); }
        // Prune ONLY what this run actually refreshed. Pruning both tables whenever either succeeded
        // would wipe a courier's offices the one time its office endpoint times out - and would delete
        // BOX NOW's lockers on every run, since it never has cities to refresh.
        // Restricted to the countries that answered: a shop syncing two countries must not lose one of
        // them because the other's fetch was the one that worked. A single-country shop is unchanged -
        // ['BG'] restricts a table that only holds BG rows to exactly nothing.
        $out['pruned'] = BGCouriers_Nomenclature::prune($id, $run, (bool) $cities, (bool) $offices,
                                                        $intl ? $got_cities : [], $intl ? $got_offices : []);
        // Nomenclature changed - drop the per-courier caches derived from it (which delivery types exist, and
        // the preloaded city index) so the checkout/editor immediately reflect the fresh point counts.
        delete_transient('bgcouriers_typecnt_' . $id);
        delete_transient('bgcouriers_cityidx_' . $id);
        foreach (array_merge([BGCouriers_Settings::home_country()], $intl) as $iso) {
            delete_transient('bgcouriers_cityidx_' . $id . '_' . strtolower($iso));
        }

        $out['rates'] = self::seed_rates($courier); // reference price per method, first city
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

    /** Weekly: full nomenclature sync (cities + offices + reference rates) for every enabled courier. */
    public static function cron(): void {
        foreach (self::enabled_couriers() as $courier) { self::run($courier); }
    }

    /** Daily: refresh just the reference prices (light - first city + a quote per method). */
    public static function refresh_rates(): void {
        foreach (self::enabled_couriers() as $courier) { self::seed_rates($courier); }
    }
}
