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
        // A shop that adds no shipping tax has none to show a price inclusive of, whatever its display
        // setting still says: the number handed here is already everything the row will charge, and
        // adding twenty percent to it would print a price the checkout never asks for.
        if ($net <= 0 || !self::wc_adds_shipping_tax() || get_option('woocommerce_tax_display_cart') !== 'incl') { return $net; }
        return round($net + array_sum(WC_Tax::calc_shipping_tax($net, WC_Tax::get_shipping_tax_rates())), 2);
    }

    /**
     * The money the customer will hand the COURIER at the door.
     *
     * Not the same question as display_price(), and conflating the two is what made the checkout show a
     * number nobody would ever pay. A delivery that is charged with the order is the shop's arithmetic:
     * WooCommerce is handed a net cost, adds the shipping tax and renders it according to
     * `woocommerce_tax_display_cart`. A delivery paid on the doorstep is not in the order at all - the
     * rate costs 0 and the row shows what the courier collects in cash. **The courier charges its VAT
     * whether or not the shop has chosen to show its own prices with tax**, so a setting about the
     * shop-window cannot be what decides that number. It was: on the default 'excl' the customer was
     * told 2,20 and handed the courier 2,64 (measured on the live shop 2026-08-31 - Speedy short by
     * 0.44, Express One by 0.68, Evropat by 0.46, each exactly its own VAT).
     *
     * Where the courier itself reports the tax - Speedy and Econt break it out, Express One and Evropat
     * have it taken back out of a gross total - that is the authority and the sum is simply put back
     * together. Where it does not (Pigeon, Sameday, a fixed or reference price), the plugin's standing
     * rule is that a quote is net, so the shop's own shipping rate stands in: on this market it is the
     * same 20% the couriers that do report it charge.
     */
    public static function door_price(BGCouriers_Quote $q): float {
        if ($q->price <= 0) { return 0.0; }
        if ($q->tax > 0) { return round($q->total(), 2); }
        if (!class_exists('WC_Tax')) { return round($q->price, 2); }
        return round($q->price + array_sum(WC_Tax::calc_shipping_tax($q->price, WC_Tax::get_shipping_tax_rates())), 2);
    }

    /** Whether WooCommerce itself will put the shipping tax on top of a rate cost this shop registers. */
    private static function wc_adds_shipping_tax(): bool {
        if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) { return false; }
        return class_exists('WC_Tax') && !empty(WC_Tax::get_shipping_tax_rates());
    }

    /**
     * The VAT a courier adds to a NET price of its own, for a shop WooCommerce works none out for.
     *
     * The rule is door_price()'s, stated there since 2026-08-31: where the courier does not break its
     * own tax out, a quote is net and the shop's own shipping rate stands in - on this market the same
     * 20% the couriers that do report it charge. What that assumed is a shop with a rate to stand in.
     * A shop can have no rate to stand in: tax calculation off and an empty rate table, which is the
     * live shop this was written for, so WC_Tax answers 0 and the sum came out net however it was written.
     *
     * **Only call this where a DOCUMENT has settled that the courier's figure is net** - Sameday's
     * invoice did, on 2026-09-01. Four of the seven couriers here return a total that looks exactly
     * like a net one and is gross, so a price with no tax reported is presumed to be the money until an
     * invoice or a printed waybill says otherwise. rate_cost() cannot make this mistake by accident: it
     * never adds tax to a quote, it only hands over the tax the quote already carries.
     */
    public static function courier_tax(float $net): float {
        if ($net <= 0) { return 0.0; }
        if (class_exists('WC_Tax')) {
            $rates = WC_Tax::get_shipping_tax_rates();
            if (!empty($rates)) { return round((float) array_sum(WC_Tax::calc_shipping_tax($net, $rates)), 2); }
        }
        return round($net * (float) apply_filters('bgcouriers_courier_vat_rate', 20.0) / 100, 2);
    }

    /**
     * What to hand WooCommerce as the shipping rate's cost.
     *
     * Every rate here is registered with `'taxes' => ''`, which asks WooCommerce to work the shipping
     * tax out and add it on top of a net cost. **A shop with tax calculation turned off adds nothing** -
     * and the courier invoices its VAT to the shop regardless. So the customer was charged the net price
     * while the shop was billed the gross one, and the difference came out of the shop on every order
     * whose delivery is charged in the order total. Nothing in the checkout was ever going to cover it.
     *
     * Measured: order 11260 on the live shop was quoted **1.37** for a Sameday easyBox and Sameday
     * invoiced **1.66** for waybill 1CJALN20743532 - the same figure plus 20%, on the invoice of
     * 2026-09-01 that also settled Sameday's own net-or-gross question.
     *
     * So the net price where WooCommerce will add the tax, and the price the courier will actually
     * charge where it will not. A quote carrying no tax of its own is handed over unchanged, and both
     * kinds that do are meant to be: a figure that already includes VAT (Pigeon, Evropat, Econt on a
     * shop with no rates to split it with) is already what the courier charges, and a flat price the
     * merchant typed into the settings is a decision about what to charge, not a courier's quote.
     */
    public static function rate_cost(BGCouriers_Quote $q): float {
        if ($q->price <= 0) { return 0.0; }
        return round(self::wc_adds_shipping_tax() ? $q->price : $q->total(), 2);
    }

    /**
     * The paid figure to advertise on the map for one office from a LIVE quote - the SAME number the
     * shipping row will charge, so the map can never promise a price the checkout will not honour (the
     * whole reason the map is fed from this class). Which number that is depends on who gets paid,
     * exactly as the shipping method decides it: a delivery in the order total follows the shop window
     * (display_price), one paid at the door is the courier's cash (door_price).
     *
     * A POSITIVE return is the only paid result; null means there is nothing to show (no quote, or a
     * figure that came out at or below zero). That is deliberate and load-bearing: the map caller reads
     * a positive number as "a price to print" and anything else as "drop this option", and treats FREE
     * separately (it reads the cart, needs no quote, and is not decided here). So a zero can never be
     * mistaken for free, and free is never mistaken for an unpriced courier.
     *
     * The quote passed here MUST carry its own tax already (a live quote does). A bare reference number
     * - a stored net figure with no tax on it - must NOT be routed through here: door_price() would see
     * tax == 0, take it for "the courier reported no tax", and add the shop's shipping rate on top of a
     * figure that is not owed one. See courier_tax()'s standing warning. The reference path shows its
     * stored number as it stands and only applies the free rule.
     *
     * @param BGCouriers_Quote|null $q      the LIVE quote (tax included), or null when unreachable
     * @param bool                  $in_total whether the delivery is charged with the order for this courier
     * @return float|null null = nothing to show, >0 = the price
     */
    public static function map_office_price(?BGCouriers_Quote $q, bool $in_total): ?float {
        if (!$q) { return null; }
        $v = $in_total ? self::display_price(self::rate_cost($q)) : self::door_price($q);
        return $v > 0 ? $v : null;
    }

    /**
     * A quote on its way into the cache, and back out again.
     *
     * The cache used to keep the price and drop the tax, and quotes live in it for three hours - so
     * `$quote->tax` was 0 on almost every checkout render even for the couriers whose API reports it,
     * and the door price above would have quietly fallen back to the shop's rate. Worse than being
     * wrong, it would have been wrong INTERMITTENTLY: one number on a cold cache and another on a warm
     * one, for the same parcel.
     *
     * @return array{p:float,t:float,c:string}
     */
    public static function quote_to_cache(BGCouriers_Quote $q): array {
        return ['p' => $q->price, 't' => $q->tax, 'c' => $q->currency];
    }

    /** @param array $c A cache entry; one written before the tax was kept simply has none. */
    public static function quote_from_cache(array $c, string $currency): BGCouriers_Quote {
        return new BGCouriers_Quote((float) $c['p'], (float) ($c['t'] ?? 0), (string) ($c['c'] ?? $currency), 'cached');
    }

    /**
     * A courier price that ALREADY contains VAT, split into the net cost and the tax inside it.
     *
     * The inverse of display_price(), and it exists for one courier: Evropat quotes gross. Its API says
     * nothing about tax - no field, no example, not the word - so the whole plugin was built on the
     * assumption that its `price` was net like everybody else's. The printed waybill settled it:
     * the price block on the waybill is headed **"price with VAT"** and its total is exactly the figure
     * /calculateprice returns (3.31 service + 1.28 fuel = 4.59 EUR, waybill 9107785603, 2026-08-31).
     *
     * Handing that figure to WooCommerce as a net cost would tax it a second time - the 0.3.5 fault,
     * the one this class was written to make impossible. So it is split with the SAME rates WooCommerce
     * will re-add, which makes the round trip exact in every configuration: a shop with no shipping tax
     * gets the gross figure back unchanged and nothing is added to it either.
     *
     * @param float $gross The courier's own figure, tax included.
     * @return array{0:float,1:float} [net, tax]
     */
    public static function split_gross(float $gross): array {
        if ($gross <= 0 || !class_exists('WC_Tax')) { return [round($gross, 2), 0.0]; }
        $tax = (float) array_sum(WC_Tax::calc_inclusive_tax($gross, WC_Tax::get_shipping_tax_rates()));
        // The tax is derived from the ROUNDED net rather than rounded on its own: rounding both halves
        // independently can make them add up to a stotinka more than the courier charged (0.99 gross
        // splits into 0.83 + 0.17), and the two halves of one price have to add up to that price.
        $net = round($gross - $tax, 2);
        return [$net, round($gross - $net, 2)];
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
        //
        // The CURRENCY joins it always, because it is the one thing on this list the price cannot be
        // read without. Everything else here changes what the number is; the currency changes what the
        // number MEANS, and the entry recorded it all along without anyone comparing it. A shop that
        // changed from lev to euro went on quoting lev figures as euros for three hours - 1.95583 times
        // what the delivery costs - and a shop offering two currencies at once let whoever asked first
        // decide what everyone was charged. Unlike the country there is no bare case to preserve: the
        // price of the change is one warm-up, once.
        $tkey = 'bgcouriers_q_' . $courier->id() . '_' . $method . '_' . $site_id . '_'
              . str_replace('.', '', (string) $w) . ($cod > 0 ? '_cod' . str_replace('.', '', (string) round($cod, 2)) : '')
              . ($abroad ? '_' . strtolower($country) : '') . '_' . strtolower($currency);
        $cached = get_transient($tkey);
        // The entry says which currency it is in, so the question is asked here rather than left to the
        // shape of the key above. The key makes a disagreement impossible today; this makes it
        // impossible for a caller that builds the key some other way tomorrow, which is how the
        // currency came to be missing from it in the first place. An entry that disagrees is not a
        // cheap price or a dear one, it is a price in another unit, and a miss is the right answer.
        if (is_array($cached) && isset($cached['p'])
            && (string) ($cached['c'] ?? $currency) === $currency) {
            return self::quote_from_cache($cached, $currency);
        }
        $res = self::resolve_office($courier->id(), $method, $site_id, $office, $country);
        $shipment = array_merge($packed, [
            'method' => $method, 'site_id' => $res['site_id'], 'office_id' => $res['office_id'],
            'cod_amount' => $cod, 'currency' => $currency, 'country' => $country,
        ]);
        $q = self::quote($courier, $shipment);
        if ($q->source === 'live') { set_transient($tkey, self::quote_to_cache($q), 3 * HOUR_IN_SECONDS); }
        return $q;
    }

    /**
     * How long a failed quote may take before the courier is left alone for a while.
     *
     * A quote POST waits 20 seconds and then RETRIES, so a courier whose API is hanging costs the
     * customer forty seconds - each, one courier at a time, for as long as it hangs. The rates are
     * calculated one after another in the request that renders the checkout, so with several couriers
     * enabled a single sick API makes the whole shop look broken.
     *
     * The trigger is the TIME, not the kind of error, and that is deliberate. A courier that refuses in
     * a hundred milliseconds - a destination it does not serve, a parcel it will not take - has cost
     * nobody anything and must keep being asked; the real answer may be different for the next basket.
     * A courier that takes twenty seconds to fail is the one worth not asking again, whatever its reason
     * was, and matching on the reason would mean matching on a message that is now translated.
     */
    const SLOW_QUOTE_SECONDS = 5.0;

    /**
     * How long a slow courier is left alone. Long enough to matter, short enough that a recovery is
     * noticed - and the first live quote that does come back cancels the rest early, so this is a
     * ceiling rather than a wait.
     *
     * The rest is per courier and shop-wide, not per customer, because a courier's API being ill is a
     * fact about the courier and not about whoever happened to ask first. That is the trade a merchant
     * is making: one slow failure prices EVERYONE off this courier's fallback until it answers again.
     * Priced the other way - per customer - the shop would learn the same thing once per visitor and
     * make each of them wait to learn it.
     */
    const SLOW_QUOTE_REST = 300;

    /** Is this courier being left alone after a slow failure? */
    private static function resting(string $courier): bool {
        return (bool) get_transient('bgcouriers_slow_' . $courier);
    }

    /**
     * The threshold, filterable. A shop on a slow line to a courier may want to allow more; a shop that
     * would rather never keep a customer waiting may want less. Also what lets the tests measure this
     * without sleeping five seconds a case.
     */
    private static function slow_seconds(): float {
        return (float) apply_filters('bgcouriers_slow_quote_seconds', self::SLOW_QUOTE_SECONDS);
    }

    /**
     * A failure that took its time means the next customer is served the fallback price straight away.
     *
     * Only ever a fallback, never a missing rate: everything below the live call in quote() is a price
     * this shop configured or measured, so the customer still sees a number and can still check out.
     * That is the whole trade - a price that may be a few cents off, against a checkout that hangs.
     */
    private static function maybe_rest(string $courier, float $took): void {
        if ($took < self::slow_seconds()) { return; }
        $rest = self::slow_rest();
        set_transient('bgcouriers_slow_' . $courier, 1, $rest);
        BGCouriers_Logger::debug('a slow quote - this courier is left alone for a while', [
            'courier' => $courier, 'seconds' => round($took, 1), 'rest' => $rest]);
    }

    /**
     * And the rest itself, filterable beside the threshold. A shop that would rather retry sooner had
     * only half the dial: it could say what counts as slow but was then stuck with five minutes of
     * fallback pricing, which is the half that costs it money.
     */
    private static function slow_rest(): int {
        return max(1, (int) apply_filters('bgcouriers_slow_quote_rest', self::SLOW_QUOTE_REST));
    }

    /** It answered. Ask it again next time, whatever it did last. */
    private static function wake(string $courier): void {
        if (self::resting($courier)) { delete_transient('bgcouriers_slow_' . $courier); }
    }

    public static function quote(BGCouriers_Courier_Interface $courier, array $shipment): BGCouriers_Quote {
        $method  = (string) ($shipment['method'] ?? 'address');
        $mode    = BGCouriers_Settings::price_mode($courier->id(), $method);
        $store   = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $default = (float) BGCouriers_Settings::method_config($courier->id(), $method)['price'];
        $abroad  = BGCouriers_Settings::is_intl((string) ($shipment['country'] ?? ''));
        // Live API for 'live' and 'fallback' (not 'fixed').
        if ($mode !== 'fixed' && in_array('live_quote', $courier->capabilities(), true)
            && ($abroad || !self::resting($courier->id()))) {
            $started = microtime(true);
            try {
                $q = $courier->quote($shipment);
                self::wake($courier->id());   // it answered; if it was resting, it is not any more
                return $q;
            }
            // \Throwable, not \Exception: an adapter that hits a TypeError on an answer it did not
            // expect used to walk straight past the fallback price sitting below and fatal the
            // checkout page. A broken adapter is a failed quote like any other - instant, so it does
            // not rest the courier, and the next basket may be one it parses fine.
            catch (\Throwable $e) {
                self::maybe_rest($courier->id(), microtime(true) - $started);
                BGCouriers_Logger::debug('live quote failed -> fallback', ['courier' => $courier->id()]);
                // Abroad there is nothing below this line to fall back TO: every one of those prices was
                // set or measured for a domestic parcel. The failure is passed on and the caller offers
                // no rate, so the shop finds out at the checkout rather than in its courier invoice.
                if ($abroad) { throw $e; }
            }
        }
        if ($abroad) {
            throw new BGCouriers_Api_Exception(esc_html(sprintf(
                /* translators: 1: courier name, 2: country code. */
                __('%1$s: no live price for %2$s.', 'bg-couriers'),
                $courier->id(), (string) ($shipment['country'] ?? ''))));
        }
        // No live price (fixed mode, or the API failed). 'fixed'/'fallback' prefer the configured price;
        // 'live' prefers the daily cached reference. All amounts are already in the store currency.
        if (($mode === 'fixed' || $mode === 'fallback') && $default > 0) {
            return new BGCouriers_Quote(round($default, 2), 0.0, $store, 'fixed');
        }
        $cached = BGCouriers_Rates::get($courier->id(), $method, $store);
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
    public static function reference_key(string $courier, string $method, float $weight_kg, float $cod = 0.0, string $country = '', string $currency = ''): string {
        return 'bgcouriers_ref_' . $courier . '_' . $method . '_' . str_replace('.', '', (string) self::reference_weight($weight_kg))
             . ($cod > 0 ? '_cod' . str_replace('.', '', (string) round($cod, 2)) : '')
             // Home keeps the bare key it always had; another country gets its own, or the two would
             // read each other's price out of the cache.
             . (BGCouriers_Settings::is_intl($country) ? '_' . strtolower($country) : '')
             // And the currency, for the reason set out over the checkout quote's own key: this entry
             // is a bare number with nothing in it to say what it is measured in, so the key has to.
             . ($currency !== '' ? '_' . strtolower($currency) : '');
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
        $tkey = self::reference_key($courier->id(), $method, $w, $cod, $country, $currency);
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
        } catch (\Throwable $e) {   // abroad, quote() re-throws; whatever it is, no estimate is the answer
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
        // In today's currency, or not at all - see BGCouriers_Rates::get. A row from before a shop
        // changed currency is not a price to show beside a courier's name.
        $store  = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $cached = BGCouriers_Rates::get($courier, $method, $store);
        if ($cached !== null) { return (float) $cached; }
        return $mc['price'] > 0 ? (float) $mc['price'] : null;
    }
}
