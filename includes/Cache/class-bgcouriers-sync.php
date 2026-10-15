<?php
defined('ABSPATH') || exit;

class BGCouriers_Sync {
    const HOOK       = 'bgcouriers_weekly_sync';
    const RATES_HOOK = 'bgcouriers_daily_rates';

    private static $sofia_ids = [];

    /** First city alphabetically in one country; '' means the shop's own country, never "any" (collation would pick). */
    public static function first_city(string $courier, string $country = ''): int {
        // Two rows: the first is the country zone's reference origin and must not be the capital.
        $rows = BGCouriers_Nomenclature::search_cities($courier, '', 2, self::country_or_home($country));
        foreach ($rows as $r) {
            if (BGCouriers_Zones::is_sofia_name((string) ($r['name'] ?? ''), (string) ($r['name_lat'] ?? ''))) { continue; }
            return (int) $r['city_id'];
        }
        // Only the capital listed: 0, never Sofia, or the city tariff would be filed as the country price.
        return 0;
    }

    /** The courier's own id for Sofia, 0 when unlisted; by name, since post code 1000 is also a Bucharest sector. */
    public static function sofia_city(string $courier): int {
        // Remembered for the request; forgotten by a sync that has just rewritten the towns.
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

    public static function forget_sofia(): void { self::$sofia_ids = []; }

    private static function country_or_home(string $country): string {
        $c = strtoupper(trim($country));
        return $c !== '' ? $c : BGCouriers_Settings::home_country();
    }

    /** Reference shipment for a method in one price zone, [] if it cannot be referenced yet. */
    public static function reference_shipment(string $courier, string $method, string $country = '', string $zone = BGCouriers_Zones::DEFAULT_ZONE): array {
        $store   = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
        $country = self::country_or_home($country);
        // Zones are a home-country idea: abroad the reference route is simply that country.
        $zone  = BGCouriers_Settings::is_intl($country) ? BGCouriers_Zones::COUNTRY : BGCouriers_Zones::sanitize($zone);
        $base  = ['method' => $method, 'cod_amount' => 0.0, 'currency' => $store, 'country' => $country,
                  'weight_kg' => 2.0, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10];
        $sofia = BGCouriers_Settings::is_intl($country) ? 0 : self::sofia_city($courier);
        $want_sofia = $zone === BGCouriers_Zones::SOFIA;
        // No Sofia in the courier's list: no route, never the country one under the 'sofia' label.
        if ($want_sofia && $sofia <= 0) { return []; }
        if ($method === 'address') {
            $city = $want_sofia ? $sofia : self::first_city($courier, $country);
            if ($city <= 0) { return []; }
            return array_merge($base, ['site_id' => $city, 'office_id' => 0, 'office_code' => '',
                                       'street_name' => 'Тест', 'street_no' => '1']);
        }
        $off = $want_sofia
            ? (BGCouriers_Nomenclature::offices($courier, $sofia, $method)[0] ?? [])
            : (array) BGCouriers_Nomenclature::first_office($courier, $method, $country, $sofia);
        if (empty($off['office_id'])) { return []; }
        return array_merge($base, ['site_id' => (int) ($off['city_id'] ?? $sofia), 'office_id' => (int) $off['office_id'],
                                   'office_code' => (string) ($off['code'] ?? '')]);
    }

    /** Seed the reference prices per enabled method and price zone (Sofia / outside); returns how many were written. */
    public static function seed_rates(BGCouriers_Courier_Interface $courier, ?string &$failure = null): int {
        $id   = $courier->id();
        $caps    = $courier->capabilities();
        $methods = array_values(array_filter(['address', 'office', 'automat'],
            static function ($m) use ($caps) { return in_array($m, $caps, true); }));
        $n = 0;
        foreach ($methods as $method) {
            foreach (BGCouriers_Zones::all() as $zone) {
                $shipment = self::reference_shipment($id, $method, '', $zone);
                $store    = (string) ($shipment['currency'] ?? '');
                // A zone with no route is skipped; BGCouriers_Rates::get falls back to the country figure at read time.
                if (!$shipment) { continue; }
                try {
                    $q = $courier->quote($shipment);
                    // NET (WooCommerce taxes a rate's cost on top), in the currency the courier answered in, not the one asked for.
                    if ($q->currency !== '' && $store !== '' && $q->currency !== $store) {
                        BGCouriers_Logger::debug('seed_rates: quoted in another currency, so this shop has no reference for it', [
                            'courier' => $id, 'method' => $method, 'asked' => $store, 'answered' => $q->currency]);
                    }
                    BGCouriers_Rates::set($id, $method, $zone, $q->price, $q->currency);
                    $n++;
                } catch (\Throwable $e) {
                    // The first refusal, so the caller can say why there are no rates.
                    if ($failure === null) { $failure = $e->getMessage(); }
                    BGCouriers_Logger::debug('seed_rates: quote failed', ['courier' => $id, 'method' => $method, 'zone' => $zone, 'err' => $e->getMessage()]);
                }
            }
        }
        return $n;
    }

    /** Stamp every row with the country it was fetched for; the parsers return only what the API said. */
    private static function tag(string $iso, array $rows): array {
        foreach ($rows as &$r) { $r['country'] = $iso; }
        unset($r);
        return $rows;
    }

    public static function run(BGCouriers_Courier_Interface $courier): array {
        // Heavy batch (Econt's getCities decodes to ~130MB): lift the web limits.
        if (function_exists('set_time_limit')) { @set_time_limit(0); } // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- needed for long nomenclature sync
        @ini_set('memory_limit', '512M'); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- needed for long nomenclature sync

        $id  = $courier->id();
        $run = uniqid('run', true);
        $out = ['cities' => 0, 'offices' => 0, 'pruned' => 0, 'rates' => 0];

        // Fetched independently: BOX NOW legitimately has no cities, and its offices must still be requested.
        $home   = BGCouriers_Settings::home_country();
        $cities = $offices = [];
        $got_cities = $got_offices = [];
        // A courier that refuses lands here; one that answers with nothing (BOX NOW towns) does not.
        $failed = [];
        try { $cities = self::tag($home, $courier->fetch_cities()); $got_cities[] = $home; }
        catch (\Throwable $e) { $failed[] = $e->getMessage(); BGCouriers_Logger::debug('sync: city fetch failed', ['courier' => $id, 'err' => $e->getMessage()]); }
        try { $offices = self::tag($home, $courier->fetch_offices(0)); $got_offices[] = $home; }
        catch (\Throwable $e) { $failed[] = $e->getMessage(); BGCouriers_Logger::debug('sync: office fetch failed', ['courier' => $id, 'err' => $e->getMessage()]); }

        // Each extra country is fetched and tagged separately, so one failing leaves the others alone.
        $intl = BGCouriers_Settings::intl_countries($id, $courier);
        foreach ($intl as $iso) {
            try { $cities = array_merge($cities, self::tag($iso, $courier->fetch_cities($iso))); $got_cities[] = $iso; }
            catch (\Throwable $e) { BGCouriers_Logger::debug('sync: city fetch failed', ['courier' => $id, 'country' => $iso, 'err' => $e->getMessage()]); }
            try { $offices = array_merge($offices, self::tag($iso, $courier->fetch_offices(0, $iso))); $got_offices[] = $iso; }
            catch (\Throwable $e) { BGCouriers_Logger::debug('sync: office fetch failed', ['courier' => $id, 'country' => $iso, 'err' => $e->getMessage()]); }
        }

        // Nothing at all came back = a failed fetch, not an empty country: never prune on that.
        if (!$cities && !$offices) {
            BGCouriers_Logger::debug('sync: empty fetch, skipping prune', ['courier' => $id]);
            if ($failed) { $out['error'] = $failed[0]; }
            return $out;
        }
        if ($failed) { $out['warning'] = $failed[0]; }
        if ($cities)  { $out['cities']  = BGCouriers_Nomenclature::upsert_cities($id, $cities, $run); self::forget_sofia(); }
        if ($offices) { $out['offices'] = BGCouriers_Nomenclature::upsert_offices($id, $offices, $run); }
        // A table that did not take everything offered is left unpruned, or the prune would delete towns the courier still has.
        $wrote_cities  = $out['cities']  === count($cities);
        $wrote_offices = $out['offices'] === count($offices);
        if (!$wrote_cities || !$wrote_offices) {
            BGCouriers_Logger::debug('sync: a write was short, so that table is not pruned', [
                'courier' => $id,
                'cities'  => $out['cities'] . '/' . count($cities),
                'offices' => $out['offices'] . '/' . count($offices),
            ]);
        }
        // Prune only the tables and countries this run actually refreshed.
        $out['pruned'] = BGCouriers_Nomenclature::prune($id, $run, $cities && $wrote_cities, $offices && $wrote_offices,
                                                        $intl ? $got_cities : [], $intl ? $got_offices : []);
        delete_transient('bgcouriers_typecnt_' . $id);
        delete_transient('bgcouriers_cityidx_' . $id);
        foreach (array_merge([BGCouriers_Settings::home_country()], $intl) as $iso) {
            delete_transient('bgcouriers_cityidx_' . $id . '_' . strtolower($iso));
        }
        // Per-town office lists are keyed by this stamp, so a new one retires them all.
        update_option('bgcouriers_nomgen_' . $id, $run);

        $out['rates'] = self::seed_rates($courier, $rate_failure);
        if ($out['rates'] === 0 && $rate_failure !== null && !isset($out['warning'])) { $out['warning'] = $rate_failure; }
        return $out;
    }

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
            wp_schedule_event(time() + 120, 'daily', self::RATES_HOOK);
        }
    }

    /** One full sync soon (a plugin update asks for it); an event already waiting is left alone. */
    public static function schedule_once(int $in = MINUTE_IN_SECONDS): void {
        $next = wp_next_scheduled(self::HOOK);
        if ($next !== false && $next <= time() + $in) { return; }
        wp_schedule_single_event(time() + $in, self::HOOK);
    }

    /**
     * A courier switched on gets its towns and offices now, not next week.
     * update_option_X hands over (old, new), add_option_X (option, value): the second argument is the new value either way.
     */
    public static function on_courier_enabled($first = null, $value = null): void {
        if ($value !== 'yes' || $first === 'yes') { return; }
        self::schedule_once();
    }

    public static function cron(): void {
        foreach (self::enabled_couriers() as $courier) { self::run($courier); }
    }

    public static function refresh_rates(): void {
        foreach (self::enabled_couriers() as $courier) { self::seed_rates($courier); }
    }
}
