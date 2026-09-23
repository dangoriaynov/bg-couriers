<?php
defined('ABSPATH') || exit;

/**
 * The two prices a Bulgarian courier quotes for the same parcel: inside Sofia, and the rest of the country.
 *
 * Every courier here prices a delivery that starts and ends in Sofia below one that leaves the city, and the
 * reference price the checkout shows BEFORE a town is chosen used to be a single figure quoted against the
 * courier's alphabetically-first town - a village, for all of them. So the row advertised one number and the
 * moment a customer picked their town it became another, up or down depending on which village the sync had
 * happened to quote. That is the "prices jump after choosing a city" this exists to stop: one cached
 * reference per zone, and the zone a destination belongs to decides which is read.
 *
 * COUNTRY is the default on purpose. Most orders are not Sofia ones, so it is the number most customers see
 * confirmed rather than replaced; and where it is replaced it moves DOWN, which is the direction a customer
 * forgives. Showing the Sofia price first would advertise the cheaper delivery to everybody outside it.
 *
 * Zones are a home-country idea. Abroad there is no cached reference at all (see BGCouriers_Pricing::quote),
 * so everything international is COUNTRY and never consults the name below.
 */
class BGCouriers_Zones {
    const SOFIA   = 'sofia';
    const COUNTRY = 'country';

    /** What a checkout shows before it knows where the parcel is going. */
    const DEFAULT_ZONE = self::COUNTRY;

    /** @return string[] Both zones, in the order a seeding run should walk them. */
    public static function all(): array {
        return [self::COUNTRY, self::SOFIA];
    }

    /** A zone that came from outside (a stored row, a filter) or the default when it is not one of ours. */
    public static function sanitize(string $zone): string {
        return in_array($zone, self::all(), true) ? $zone : self::DEFAULT_ZONE;
    }

    /**
     * Is this town Sofia, as the couriers spell it?
     *
     * Kept a pure name test so it can be read without a database. Measured against the real nomenclature
     * on 2026-09-23: each courier lists the capital exactly once, as `СОФИЯ` or `София`, with post code
     * 1000 and no sub-entries (no "София - Банкя"), and name_lat is `Sofia`/`SOFIA` where a courier fills
     * it in at all (Sameday and Express One leave it empty). So the Cyrillic name is the signal and the
     * Latin one is a fallback, not the other way round.
     *
     * Compared whole, never as a substring: `Софийци` and `Софрониево` are villages, and a substring test
     * would price them as the capital.
     */
    public static function is_sofia_name(string $name, string $name_lat = ''): bool {
        $strip = static function (string $s): string {
            // The prefix a courier puts in front of a place: "гр." for a town, "с." for a village. Not all
            // of them do, and the ones that do are not consistent about the space after the dot.
            $s = trim($s);
            $s = (string) preg_replace('/^(гр|с|ГР|С)\.\s*/u', '', $s);
            return trim($s);
        };
        $cyr = function_exists('mb_strtoupper') ? mb_strtoupper($strip($name), 'UTF-8') : $strip($name);
        if ($cyr === 'СОФИЯ') { return true; }
        return strtoupper($strip($name_lat)) === 'SOFIA';
    }

    /**
     * The zone a courier's own city id falls in.
     *
     * City ids belong to the courier that issued them, so the courier is part of the question. A city that
     * is not in the cache is COUNTRY: an unknown town is far likelier to be one of the five thousand
     * outside Sofia than the one inside it, and guessing the cheaper zone would under-quote every one of them.
     */
    public static function for_city(string $courier, int $city_id, string $country = ''): string {
        if ($city_id <= 0) { return self::DEFAULT_ZONE; }
        if ($country !== '' && BGCouriers_Settings::is_intl($country)) { return self::COUNTRY; }
        $row = BGCouriers_Nomenclature::city_by_id($courier, $city_id);
        if (!$row) { return self::COUNTRY; }
        // A row of another country cannot be the capital of this one, whatever it is called: Romania has a
        // Sofia too (a Dolj village), and it is not priced by Sofia's tariff.
        if (BGCouriers_Settings::is_intl((string) ($row['country'] ?? ''))) { return self::COUNTRY; }
        return self::is_sofia_name((string) ($row['name'] ?? ''), (string) ($row['name_lat'] ?? ''))
            ? self::SOFIA : self::COUNTRY;
    }

    /** The zone of a shipment as the pricing path passes it around. */
    public static function for_shipment(string $courier, array $shipment): string {
        return self::for_city($courier, (int) ($shipment['site_id'] ?? 0), (string) ($shipment['country'] ?? ''));
    }

    /** Name for a screen: the admin rates table and the sync report both say which zone a price is for. */
    public static function label(string $zone): string {
        return $zone === self::SOFIA
            ? __('Sofia', 'bg-couriers')
            : __('Outside Sofia', 'bg-couriers');
    }
}
