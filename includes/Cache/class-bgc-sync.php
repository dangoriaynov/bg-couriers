<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Sync {
    const HOOK = 'bgc_weekly_sync';

    public static function standard_shipment(string $method): array {
        return ['method' => $method, 'site_id' => 68134, 'office_id' => 0,
                'weight_kg' => 2.0, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10,
                'cod_amount' => 0.0, 'currency' => 'BGN'];
    }

    public static function run(BGC_Courier_Interface $courier): array {
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

        $caps = $courier->capabilities();
        $methods = array_values(array_filter(['address', 'office', 'automat'],
            function ($m) use ($caps) { return in_array($m, $caps, true); }));
        if ($methods) {
            try {
                // One representative quote (address to a major city) seeds the standard-rate
                // fallback for every enabled method. Office/automat need no live office id here,
                // and quoting them with office_id=0 would fail — so we use the address shape once.
                $q = $courier->quote(self::standard_shipment('address'));
                foreach ($methods as $method) {
                    BGC_Rates::set($id, $method, $q->total(), $q->currency);
                    $out['rates']++;
                }
            } catch (\Exception $e) {
                BGC_Logger::debug('sync: standard rate quote failed', ['courier' => $id]);
            }
        }
        return $out;
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'weekly', self::HOOK);
        }
    }
    public static function cron(): void {
        // Resolve enabled couriers from settings (Task 14); v1: Speedy only.
        $cfg = BGC_Settings::courier_config('speedy');
        if ($cfg) { self::run(new BGC_Speedy($cfg)); }
    }
}
