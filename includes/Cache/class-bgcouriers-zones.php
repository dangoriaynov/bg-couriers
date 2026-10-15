<?php
defined('ABSPATH') || exit;

/** The two price zones a Bulgarian courier quotes: inside Sofia, and the rest of the country. */
class BGCouriers_Zones {
    const SOFIA   = 'sofia';
    const COUNTRY = 'country';

    const DEFAULT_ZONE = self::COUNTRY;

    public static function all(): array {
        return [self::COUNTRY, self::SOFIA];
    }

    public static function sanitize(string $zone): string {
        return in_array($zone, self::all(), true) ? $zone : self::DEFAULT_ZONE;
    }

    /** Compared whole, never as a substring: Софийци and Софрониево are villages. */
    public static function is_sofia_name(string $name, string $name_lat = ''): bool {
        $strip = static function (string $s): string {
            $s = trim($s);
            $s = (string) preg_replace('/^(гр|с|ГР|С)\.\s*/u', '', $s);
            return trim($s);
        };
        $cyr = function_exists('mb_strtoupper') ? mb_strtoupper($strip($name), 'UTF-8') : $strip($name);
        if ($cyr === 'СОФИЯ') { return true; }
        return strtoupper($strip($name_lat)) === 'SOFIA';
    }

    /** An unknown city is COUNTRY: guessing the cheaper zone would under-quote. */
    public static function for_city(string $courier, int $city_id, string $country = ''): string {
        if ($city_id <= 0) { return self::DEFAULT_ZONE; }
        if ($country !== '' && BGCouriers_Settings::is_intl($country)) { return self::COUNTRY; }
        $row = BGCouriers_Nomenclature::city_by_id($courier, $city_id);
        if (!$row) { return self::COUNTRY; }
        // Romania has a Sofia too (a Dolj village).
        if (BGCouriers_Settings::is_intl((string) ($row['country'] ?? ''))) { return self::COUNTRY; }
        return self::is_sofia_name((string) ($row['name'] ?? ''), (string) ($row['name_lat'] ?? ''))
            ? self::SOFIA : self::COUNTRY;
    }

    public static function for_shipment(string $courier, array $shipment): string {
        return self::for_city($courier, (int) ($shipment['site_id'] ?? 0), (string) ($shipment['country'] ?? ''));
    }
}
