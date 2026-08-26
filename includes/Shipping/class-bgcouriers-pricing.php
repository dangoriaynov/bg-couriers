<?php
defined('ABSPATH') || exit;

class BGCouriers_Pricing {
    /**
     * The parcel the checkout is pricing - ONE definition of it, for every caller.
     *
     * The shipping methods are handed $package['contents_weight'] by WooCommerce; the map's price
     * endpoint has no package and used to ask WC()->cart->get_cart_contents_weight() instead. Those two
     * are not the same number: the cart's total counts everything in the basket, the package counts only
     * what actually gets shipped. A virtual item - a bundle container, a downloadable - is in one and not
     * the other, and the map then advertised a price the checkout would not charge.
     *
     * Reading the shipping packages here IS what the shipping method is given, so both paths now start
     * from the same figure and convert it the same way.
     */
    public static function cart_parcel(): array {
        $store_weight = 0.0;
        $has = false;
        if (function_exists('WC') && WC() && WC()->cart) {
            foreach ((array) WC()->cart->get_shipping_packages() as $pkg) {
                $store_weight += (float) ($pkg['contents_weight'] ?? 0);
                $has = $has || !empty($pkg['contents']);
            }
        }
        return self::weigh($store_weight, $has);
    }

    /** The parcel for the one package a shipping method was handed. Same figure, same conversion. */
    public static function package_parcel(array $package): array {
        return self::weigh((float) ($package['contents_weight'] ?? 0), !empty($package['contents']));
    }

    /**
     * The parcel to quote for, given what the shop says it weighs.
     *
     * A package that HAS something in it and still weighs nothing is not a 0.1 kg parcel - it is a parcel
     * nobody has weighed, because the products carry no weight. The LABEL has always read it that way:
     * with nothing to add up, BGCouriers_Abstract_Courier::order_weight_kg() falls to the shop's default
     * weight. The checkout fell to the 0.1 kg floor instead, so the customer was quoted for a tenth of a
     * kilogram and the courier was then handed the shop's default - two different parcels for one order,
     * with the difference coming out of the shop. Found on dev 2026-08-25, driving Express One through a
     * real checkout: quoted 2.70 for a basket whose waybill went out declaring 1 kg, which costs 3.38.
     *
     * The floor still stands for a weight that really is small (a gram-priced shop's two 10 g items have
     * been weighed), and for a cart with nothing to ship at all, where there is no parcel to price.
     *
     * @param float $store_weight The weight in the shop's own unit.
     * @param bool  $has_contents Whether there is anything in the package to weigh in the first place.
     */
    private static function weigh(float $store_weight, bool $has_contents): array {
        if ($store_weight <= 0 && $has_contents && class_exists('BGCouriers_Settings')) {
            return BGCouriers_Packer::from_weight(BGCouriers_Settings::default_weight_kg());
        }
        return BGCouriers_Packer::from_store_weight($store_weight);
    }

    /**
     * A net price as the customer will see it printed.
     *
     * Every quote in this plugin is NET - the shipping rate is added with 'taxes' => '', which asks
     * WooCommerce to work the shipping tax out and add it on top. Anything that prints a price outside a
     * shipping rate (the map, the "pays at the door" line) therefore has to do the same sum itself, or
     * it shows a smaller number than the row beside it on a shop that displays prices with tax.
     */
    public static function display_price(float $net): float {
        if ($net <= 0 || !class_exists('WC_Tax') || get_option('woocommerce_tax_display_cart') !== 'incl') { return $net; }
        return round($net + array_sum(WC_Tax::calc_shipping_tax($net, WC_Tax::get_shipping_tax_rates())), 2);
    }

    /**
     * What the courier would be collecting at the door for the basket in front of us, or 0.
     *
     * Cash on delivery is not free: the courier charges for collecting the money, and that charge is in
     * the price it quotes. Measured live on 2026-08-18 for a 50 EUR collection - Econt +1.54, Pigeon
     * +0.75, Sameday +0.50, Speedy +0.40 - while the checkout asked every courier to price a shipment
     * with no cash on it, and then charged the customer that number.
     *
     * The basis is the goods total, NOT goods + delivery. On an order where the merchant pays the
     * delivery the courier does collect both, but the delivery is the very thing being priced here and a
     * price cannot depend on itself. The fee is banded, so the few stotinki of delivery inside the basis
     * do not move it - and the LABEL still collects the exact amount (see cod_for_payer()).
     */
    public static function cart_cod_amount(string $courier = '', string $method = ''): float {
        if (!function_exists('WC') || !WC() || !WC()->cart || !WC()->session) { return 0.0; }
        if ((string) WC()->session->get('chosen_payment_method', '') !== 'cod') { return 0.0; }
        // A collection this courier cannot make is not a collection to pay for. The checkout does take
        // the cash-on-delivery gateway away while such a delivery is chosen, but the session still reads
        // 'cod' during the very recalculation that removes it - and shipping is priced before the payment
        // box is re-rendered. Without this line an Express One locker row would be quoted with a
        // collection fee AND the declared value that always accompanies one, for a shipment that is about
        // to become prepaid: an overcharge on the customer, on every such order.
        if ($courier !== '' && !BGCouriers_Settings::cod_allowed_for($courier, $method)) { return 0.0; }
        $goods = (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax();
        return max(0.0, round($goods, 2));
    }

    /**
     * Resolve the office to quote against, given the customer's session selection.
     * office/automat with a chosen office → use it. Otherwise quote a representative office (of the
     * chosen city, or - when no city is picked, or the city has none of that type - the first such
     * office anywhere) so the price is a live quote at the REAL cart weight. The checkout greys out a
     * delivery option the chosen city lacks, so the customer never actually selects that combination.
     *
     * @return array{office_id:int, site_id:int}
     */
    public static function resolve_office(string $courier, string $method, int $site_id, int $office, string $country = ''): array {
        if ($office <= 0 && in_array($method, ['office', 'automat'], true)) {
            $rep = $site_id > 0 ? BGCouriers_Nomenclature::offices($courier, $site_id, $method) : [];
            if (!empty($rep[0]['office_id'])) {
                $office = (int) $rep[0]['office_id'];
            } else {
                // In the DESTINATION country: a representative office is only representative of the
                // place the parcel is going, and the courier prices a route, not an office. '' would
                // mean "any country" to the repository, and with two of them in the table the collation
                // would pick which - so it resolves to the shop's own, never to whatever sorts first.
                $first = BGCouriers_Nomenclature::first_office($courier, $method, self::country_or_home($country));
                if (!empty($first['office_id'])) { $office = (int) $first['office_id']; $site_id = (int) $first['city_id']; }
            }
        }
        return ['office_id' => $office, 'site_id' => $site_id];
    }

    /** '' means the shop's own country here, never "any country" - see resolve_office(). */
    private static function country_or_home(string $country): string {
        $c = strtoupper(trim($country));
        return $c !== '' ? $c : BGCouriers_Settings::home_country();
    }

    /**
     * Where this package is going, as ISO alpha-2.
     *
     * The delivery box's own answer first - the customer chose it there, and the town and office ids in
     * the session were looked up against it - then WooCommerce's package destination, which is what a
     * cart-page estimate has before anyone has touched the delivery box, then the shop's own country.
     */
    public static function destination_country(array $package = []): string {
        $s = (function_exists('WC') && WC()->session) ? WC()->session : null;
        $c = $s ? strtoupper(trim((string) $s->get('bgcouriers_country', ''))) : '';
        if ($c === '') { $c = strtoupper(trim((string) ($package['destination']['country'] ?? ''))); }
        return $c !== '' ? $c : BGCouriers_Settings::home_country();
    }

    /**
     * The checkout selection to price THIS courier against. The session holds ONE selection, tagged with the
     * courier it was made for (bgcouriers_selection_courier). City ids and office ids are per-courier (each courier
     * has its own nomenclature), so another courier's ids must never be reused - doing so quotes a courier
     * against a foreign city/office and the price jumps when it later becomes the active selection.
     *  - active (selected) courier -> its own stored method / city / office;
     *  - every other listed courier -> the SAME destination city resolved in ITS OWN nomenclature via the
     *    shared postcode (office 0 = a representative office), so its listed price is stable and correct.
     *
     * @return array{method:string, site_id:int, office_id:int, country:string}
     */
    public static function selection_for(string $courier_id): array {
        $default = BGCouriers_Settings::enabled_methods($courier_id)[0] ?? 'office';
        $s = (function_exists('WC') && WC()->session) ? WC()->session : null;
        if (!$s) { return ['method' => $default, 'site_id' => 0, 'office_id' => 0, 'country' => '']; }
        // One destination for the whole basket - the customer is one person at one address - so unlike
        // the city and office ids this is NOT per courier and does not need re-resolving for each.
        $country = self::destination_country();
        if ((string) $s->get('bgcouriers_selection_courier', '') === $courier_id) {
            return [
                'method'    => (string) $s->get('bgcouriers_method', '') ?: $default,
                'site_id'   => (int) $s->get('bgcouriers_site_id', 0),
                'office_id' => (int) $s->get('bgcouriers_office_id', 0),
                'country'   => $country,
            ];
        }
        $site_id  = 0;
        $postcode = (string) $s->get('bgcouriers_post_code', '');
        if ($postcode !== '') {
            // With the post code alone, "1000" is Sofia and Bucharest at once; the country decides which.
            $city = BGCouriers_Nomenclature::city_by_postcode($courier_id, $postcode, $country);
            if ($city) { $site_id = (int) $city['city_id']; }
        }
        // What the customer last chose IN THIS courier, if they have been in it. Without this a courier
        // they had set to a locker was re-quoted for its first enabled method the moment they touched a
        // different one - so its row advertised the price of a delivery they had not asked for.
        $mine = (array) $s->get('bgcouriers_sel_by_courier', []);
        if (isset($mine[$courier_id]) && is_array($mine[$courier_id])) {
            $m = $mine[$courier_id];
            $method = (string) ($m['method'] ?? '');
            if (in_array($method, BGCouriers_Settings::enabled_methods($courier_id), true)) {
                // The city still comes from the post code above when this courier has none of its own:
                // ids belong to the courier that issued them, and a remembered one may be stale.
                $own = (int) ($m['site_id'] ?? 0);
                return [
                    'method'    => $method,
                    'site_id'   => $own > 0 ? $own : $site_id,
                    'office_id' => $own > 0 ? (int) ($m['office_id'] ?? 0) : 0,
                    'country'   => $country,
                ];
            }
        }
        return ['method' => $default, 'site_id' => $site_id, 'office_id' => 0, 'country' => $country];
    }

    /**
     * Price for the checkout shipping row. Before the customer picks a city we return the FAST cached daily
     * reference (no API call) - so switching couriers stays snappy and the customer can start entering the
     * address immediately. Once a real city is chosen we do the exact live quote against the resolved office.
     */
    public static function checkout_quote(BGCouriers_Courier_Interface $courier, string $method, int $site_id, int $office, array $packed, string $currency, string $country = ''): BGCouriers_Quote {
        // Abroad, every number that is not a live quote for THIS destination is a Bulgarian number:
        // the fixed price the merchant typed for domestic delivery, the daily reference quoted against a
        // Bulgarian office, the flat 6.99 last resort. None of them is what a parcel to another country
        // costs, and showing one is worse than showing no price at all - the shop would eat the
        // difference on every order without ever seeing it. So abroad it is a live price or nothing.
        $abroad = BGCouriers_Settings::is_intl($country);
        // 'fixed' mode: a predefined flat price, regardless of address - never call the API or cache.
        if (!$abroad && BGCouriers_Settings::price_mode($courier->id(), $method) === 'fixed') {
            $price = (float) BGCouriers_Settings::method_config($courier->id(), $method)['price'];
            return new BGCouriers_Quote($price > 0 ? round($price, 2) : 6.99, 0.0, $currency, 'fixed');
        }
        if ($site_id <= 0) {
            // The reference route is resolved in the destination country, so a customer who has said
            // "Romania" but not yet which town sees a Romanian price, not a Bulgarian one.
            $est = self::reference_for_weight($courier, $method, $packed, $currency, $country);
            if ($est !== null) { return new BGCouriers_Quote(round($est, 2), 0.0, $currency, 'reference'); }
            if (!$abroad) {
                $est = self::estimate($courier->id(), $method);
                if ($est !== null) { return new BGCouriers_Quote(round($est, 2), 0.0, $currency, 'reference'); }
            }
        }
        // Cache the live quote per courier+method+city+weight+COD. The city now carries across couriers,
        // so without this every switch would re-hit the courier API; with it, a seen combo is instant.
        // COD belongs in the key: it changes the price (measured 2026-08-18 - Econt +1.54, Pigeon +0.75,
        // Sameday +0.50, Speedy +0.40 on a 50 EUR collection), so a cash-on-delivery basket must not read
        // a prepaid one's price out of the cache.
        $cod  = self::cart_cod_amount($courier->id(), $method);
        $w    = round((float) ($packed['weight_kg'] ?? 0), 2);
        // The country joins the key only when it is not home, so every domestic key stays exactly what
        // it was - a shop that never ships abroad does not re-quote everything the day it updates.
        $tkey = 'bgcouriers_q_' . $courier->id() . '_' . $method . '_' . $site_id . '_'
              . str_replace('.', '', (string) $w) . ($cod > 0 ? '_cod' . str_replace('.', '', (string) round($cod, 2)) : '')
              . ($abroad ? '_' . strtolower($country) : '');
        $cached = get_transient($tkey);
        if (is_array($cached) && isset($cached['p'])) {
            return new BGCouriers_Quote((float) $cached['p'], 0.0, (string) ($cached['c'] ?? $currency), 'cached');
        }
        $res = self::resolve_office($courier->id(), $method, $site_id, $office, $country);
        $shipment = array_merge($packed, [
            'method' => $method, 'site_id' => $res['site_id'], 'office_id' => $res['office_id'],
            'cod_amount' => $cod, 'currency' => $currency, 'country' => $country,
        ]);
        $q = self::quote($courier, $shipment);
        if ($q->source === 'live') { set_transient($tkey, ['p' => $q->price, 'c' => $q->currency], 3 * HOUR_IN_SECONDS); }
        return $q;
    }

    public static function quote(BGCouriers_Courier_Interface $courier, array $shipment): BGCouriers_Quote {
        $method  = (string) ($shipment['method'] ?? 'address');
        $mode    = BGCouriers_Settings::price_mode($courier->id(), $method);
        $store   = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $default = (float) BGCouriers_Settings::method_config($courier->id(), $method)['price'];
        $abroad  = BGCouriers_Settings::is_intl((string) ($shipment['country'] ?? ''));
        // Live API for 'live' and 'fallback' (not 'fixed').
        if ($mode !== 'fixed' && in_array('live_quote', $courier->capabilities(), true)) {
            try { return $courier->quote($shipment); }
            catch (\Exception $e) {
                BGCouriers_Logger::debug('live quote failed -> fallback', ['courier' => $courier->id()]);
                // Abroad there is nothing below this line to fall back TO: every one of those prices was
                // set or measured for a domestic parcel. The failure is passed on and the caller offers
                // no rate, so the shop finds out at the checkout rather than in its courier invoice.
                if ($abroad) { throw $e; }
            }
        }
        if ($abroad) {
            throw new BGCouriers_Api_Exception(esc_html(sprintf('%s: no live price for %s',
                $courier->id(), (string) ($shipment['country'] ?? ''))));
        }
        // No live price (fixed mode, or the API failed). 'fixed'/'fallback' prefer the configured price;
        // 'live' prefers the daily cached reference. All amounts are already in the store currency.
        if (($mode === 'fixed' || $mode === 'fallback') && $default > 0) {
            return new BGCouriers_Quote(round($default, 2), 0.0, $store, 'fixed');
        }
        $cached = BGCouriers_Rates::get($courier->id(), $method);
        if ($cached !== null) { return new BGCouriers_Quote($cached, 0.0, $store, 'standard'); }
        $amount = $default > 0 ? $default : 6.99;
        return new BGCouriers_Quote(round($amount, 2), 0.0, $store, 'flat');
    }

    /**
     * A no-API price estimate for a courier+method (the cart-page estimate): the cached daily reference,
     * else the configured default price, else null (no estimate available). Store currency, net.
     */
    /**
     * The weight a reference price is quoted for: the cart's own, rounded UP to the next half kilo.
     *
     * Up, never down - a price quoted for less than the parcel weighs understates what the customer
     * will pay, which is the failure this whole path exists to stop. Bucketed, because a reference does
     * not need to tell 3.01 kg from 3.04 kg and a key per exact gram would miss the cache on nearly
     * every cart, putting a live courier call in front of a page load.
     *
     * @param float $weight_kg Cart weight.
     * @return float The bucket, never below half a kilo.
     */
    public static function reference_weight(float $weight_kg): float {
        if ($weight_kg <= 0) { return 0.5; }
        return max(0.5, ceil($weight_kg * 2) / 2);
    }

    /** Transient key for a reference price. Carries the weight, or a heavy cart reads a light one's price. */
    public static function reference_key(string $courier, string $method, float $weight_kg, float $cod = 0.0, string $country = ''): string {
        return 'bgcouriers_ref_' . $courier . '_' . $method . '_' . str_replace('.', '', (string) self::reference_weight($weight_kg))
             . ($cod > 0 ? '_cod' . str_replace('.', '', (string) round($cod, 2)) : '')
             // Home keeps the bare key it always had; another country gets its own, or the two would
             // read each other's price out of the cache.
             . (BGCouriers_Settings::is_intl($country) ? '_' . strtolower($country) : '');
    }

    /**
     * A reference price for the cart's ACTUAL weight, before any city is chosen.
     *
     * What used to be shown here was a daily figure quoted for a hardcoded 2 kg parcel whatever the
     * cart held (see BGCouriers_Sync::reference_shipment), so a 10 kg order advertised the 2 kg price
     * until the customer picked a city. Quoting the same reference route with the real weight costs one
     * live call per courier, method and weight bucket, cached for three hours.
     *
     * Returns null when the courier cannot be quoted at all, so the caller falls back to the old daily
     * figure rather than showing nothing.
     */
    private static function reference_for_weight(BGCouriers_Courier_Interface $courier, string $method, array $packed, string $currency, string $country = ''): ?float {
        $w    = self::reference_weight((float) ($packed['weight_kg'] ?? 0));
        $cod  = self::cart_cod_amount($courier->id(), $method);
        $tkey = self::reference_key($courier->id(), $method, $w, $cod, $country);
        $hit  = get_transient($tkey);
        if (is_array($hit) && isset($hit['p'])) { return (float) $hit['p']; }
        if (!class_exists('BGCouriers_Sync')) { return null; }
        $ref = BGCouriers_Sync::reference_shipment($courier->id(), $method, $country);
        if (!$ref) { return null; }
        // The route stays the reference one - there is no destination yet, that is the whole situation -
        // and only the parcel becomes the customer's: their weight, and their box, since a courier
        // prices volume too. Nothing about where it is going comes from the cart.
        $shipment = array_merge($ref, [
            'method'     => $method,
            'weight_kg'  => $w,
            // From the reference route, which resolved it: '' here means the shop's own country.
            'country'    => (string) ($ref['country'] ?? $country),
            'length_cm'  => $packed['length_cm'] ?? $ref['length_cm'],
            'width_cm'   => $packed['width_cm']  ?? $ref['width_cm'],
            'height_cm'  => $packed['height_cm'] ?? $ref['height_cm'],
            'currency'   => $currency,
            'cod_amount' => $cod,
        ]);
        try {
            $q = self::quote($courier, $shipment);
        } catch (\Exception $e) {
            return null;
        }
        if ($q->source !== 'live') { return null; }
        // NET, like every other price here. It was the gross total, and it is handed straight to the
        // shipping rate's cost - which WooCommerce then taxes again. The delivery was therefore quoted
        // ~20% high until the customer chose a town, and visibly dropped the moment they did.
        set_transient($tkey, ['p' => $q->price], 3 * HOUR_IN_SECONDS);
        return $q->price;
    }

    public static function estimate(string $courier, string $method): ?float {
        $mc = BGCouriers_Settings::method_config($courier, $method);
        // 'fixed' mode shows its fixed price everywhere; otherwise the daily cached reference, then the default.
        if (BGCouriers_Settings::price_mode($courier, $method) === 'fixed') {
            return $mc['price'] > 0 ? (float) $mc['price'] : null;
        }
        $cached = BGCouriers_Rates::get($courier, $method);
        if ($cached !== null) { return (float) $cached; }
        return $mc['price'] > 0 ? (float) $mc['price'] : null;
    }
}
