<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Sync {
    const HOOK       = 'bgc_weekly_sync'; // full nomenclature sync (heavy) - weekly
    const RATES_HOOK = 'bgc_daily_rates'; // reference-price refresh (light) - daily

    /** First city alphabetically from a courier's cached cities (the reference origin). */
    public static function first_city(string $courier): int {
        $rows = BGC_Nomenclature::search_cities($courier, '', 1); // term '' -> all, ORDER BY name
        return (int) ($rows[0]['city_id'] ?? 0);
    }

    /**
     * Reference shipment for a method, from the courier's first cached city + (for office/automat)
     * a representative office of that city. Returns [] if the method can't be referenced yet.
     */
    public static function reference_shipment(string $courier, string $method): array {
        $store = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
        $base  = ['method' => $method, 'cod_amount' => 0.0, 'currency' => $store,
                  'weight_kg' => 2.0, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10];
        if ($method === 'address') {
            $city = self::first_city($courier);
            if ($city <= 0) { return []; }
            return array_merge($base, ['site_id' => $city, 'office_id' => 0, 'office_code' => '',
                                       'street_name' => 'Тест', 'street_no' => '1']);
        }
        // office / automat - first office of that type, in the alphabetically-first city that has one
        // (the first city overall is often a village with no Econtomat/locker).
        $off = BGC_Nomenclature::first_office($courier, $method);
        if (empty($off['office_id'])) { return []; }
        return array_merge($base, ['site_id' => (int) $off['city_id'], 'office_id' => (int) $off['office_id'],
                                   'office_code' => (string) ($off['code'] ?? '')]);
    }

    /**
     * Seed the reference (standard-rate fallback) prices per enabled delivery method, quoting the
     * courier's first alphabetical city. Stored in BGC_Rates and shown at checkout BEFORE the
     * customer picks a destination; if a method can't be quoted the configured default price applies.
     */
    public static function seed_rates(BGC_Courier_Interface $courier): int {
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
                BGC_Rates::set($id, $method, $q->total(), $q->currency);
                $n++;
            } catch (\Exception $e) {
                BGC_Logger::debug('seed_rates: quote failed', ['courier' => $id, 'method' => $method]);
            }
        }
        return $n;
    }

    public static function run(BGC_Courier_Interface $courier): array {
        // Nomenclature sync is a heavy batch op (Econt's getCities decodes to ~130MB and thousands
        // of rows are upserted), so lift the default web limits or it OOMs / times out mid-sync.
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        @ini_set('memory_limit', '512M');

        $id  = $courier->id();
        $run = uniqid('run', true);
        $out = ['cities' => 0, 'offices' => 0, 'pruned' => 0, 'rates' => 0];

        $cities = $courier->fetch_cities();
        if (empty($cities)) {
            BGC_Logger::debug('sync: empty city fetch, skipping prune', ['courier' => $id]);
            return $out; // GUARD: never prune on empty fetch
        }
        $out['cities'] = BGC_Nomenclature::upsert_cities($id, $cities, $run);

        $offices = $courier->fetch_offices(0); // 0 = all offices in one call (country-wide)
        if ($offices) { $out['offices'] = BGC_Nomenclature::upsert_offices($id, $offices, $run); }
        $out['pruned'] = BGC_Nomenclature::prune($id, $run);

        $out['rates'] = self::seed_rates($courier); // reference price per method, first city
        return $out;
    }

    /** All registered couriers that have credentials configured. */
    private static function enabled_couriers(): array {
        $out = [];
        foreach (array_keys(BGC_Couriers::all()) as $cid) {
            $courier = BGC_Couriers::get($cid);
            if ($courier && BGC_Settings::courier_config($cid)) { $out[] = $courier; }
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
