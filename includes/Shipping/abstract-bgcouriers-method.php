<?php
defined('ABSPATH') || exit;

/**
 * One WooCommerce shipping method, for every courier that quotes a price for a destination.
 *
 * There were six of these files and they were the same file. Measured before this class existed:
 * normalising the courier's id and name out of them left ZERO differing lines of code between Speedy,
 * Econt, Pigeon, Sameday and Evropat, and one line - the class name - between Speedy and Express One.
 * Six copies is six places to fix a pricing bug in, five of which get forgotten; the free-shipping
 * rule, the "who pays the delivery" rule and the international rule below have each been changed more
 * than once since the plugin shipped.
 *
 * What stays per courier is a subclass with nothing in it but its id and its name. That is deliberate:
 * WooCommerce stores the CLASS NAME in the woocommerce_shipping_methods filter and the method id in
 * every shipping zone a merchant has set up, so both have to go on existing exactly as they are. A
 * shop's zones, and the rate ids on orders already placed, cannot tell this refactor happened.
 *
 * BOX NOW is not one of these: it has no price API at all and its own method quotes a flat rate.
 */
abstract class BGCouriers_Abstract_Method extends WC_Shipping_Method {
    /** The courier id this method quotes for - 'speedy', 'econt', ... Set by the subclass. */
    protected $courier_id = '';

    /**
     * @param int    $instance_id WooCommerce's zone-instance id.
     * @param string $courier     Courier id, as registered with BGCouriers_Couriers.
     * @param string $label       The courier's name, already translated.
     */
    public function __construct($instance_id, string $courier, string $label) {
        $this->courier_id         = $courier;
        $this->id                 = 'bgcouriers_' . $courier;
        $this->instance_id        = absint($instance_id);
        $this->method_title       = $label;
        /* translators: %s: courier name, e.g. "Speedy" */
        $this->method_description = sprintf(__('%s shipping (BG Couriers)', 'bg-couriers'), $label);
        $this->supports           = ['shipping-zones', 'instance-settings'];
        $this->enabled            = 'yes';
        $this->title              = $label;
        $this->init_instance_settings();
    }

    /** Free shipping when enabled and the goods total (w/o shipping) reaches the threshold. */
    public static function is_free(float $goods_total, array $cfg): bool {
        return !empty($cfg['enabled'])
            && (float) ($cfg['threshold'] ?? 0) > 0
            && $goods_total >= (float) $cfg['threshold'];
    }

    public function calculate_shipping($package = []) {
        $id = $this->courier_id;
        // Switched on, credentials saved and validated, and at least one delivery option left on.
        // A zone entry is ordinary WooCommerce furniture and a merchant may well leave one in place
        // while a courier is being set up, or after switching it off; it must not quote in the
        // meantime. Every other place already asked this (the cart estimate, the map, the office
        // lookups, the sync); the shipping method - the one that actually puts the courier in front of
        // a customer - did not, so "Enable Speedy" was a switch that changed everything except the
        // checkout. See BGCouriers_Settings::courier_offerable().
        if (!BGCouriers_Settings::courier_offerable($id)) { return; }
        // Price against THIS courier's own selection (the session's single selection is tagged with the
        // courier it was made for; city/office ids are per-courier and must not leak across couriers).
        $sel     = BGCouriers_Pricing::selection_for($id);
        $method  = $sel['method'];
        $site_id = $sel['site_id'];
        $office  = $sel['office_id'];
        $packed  = BGCouriers_Pricing::package_parcel($package); // the shop's own weight unit, converted once

        // Before a city is chosen use the fast cached daily reference (no live API) so switching couriers
        // stays snappy and the customer can start entering the address; checkout_quote does the exact live
        // quote once a real city is picked.
        $courier = BGCouriers_Couriers::get($id);
        // Where the parcel is going, and whether this courier goes there at all. One that does not is
        // simply not offered - no rate is better than a domestic price on a parcel leaving the country.
        $country = BGCouriers_Pricing::destination_country($package);
        if (!BGCouriers_Settings::ships_to($id, $country, $courier)) { return; }
        $abroad  = BGCouriers_Settings::is_intl($country);
        try {
            $quote = BGCouriers_Pricing::checkout_quote($courier, $method, $site_id, $office, $packed, get_woocommerce_currency(), $country);
        } catch (\Exception $e) {
            // Only an international quote ever reaches here: domestically there is always a fallback
            // price, and abroad a missing live price means no delivery is offered at all.
            BGCouriers_Logger::debug('no rate offered', ['courier' => $id, 'country' => $country]);
            return;
        }
        // Net where WooCommerce will add the shipping tax on top, and what the courier will actually
        // charge where it will not - see BGCouriers_Pricing::rate_cost().
        $cost = BGCouriers_Pricing::rate_cost($quote);

        // Abroad the courier bills the shop whatever the "delivery in the order total" toggle says: the
        // international service refuses a recipient payer outright, so there is no fee at the door to
        // point the customer at.
        $included = $abroad ? true : BGCouriers_Settings::ship_in_total($id);
        $info     = 0.0;
        // Free delivery is checked FIRST and beats "the recipient pays": the shop absorbing the cost is
        // the whole point of a free-shipping threshold, so the customer must not be quoted a price to
        // pay the courier at the door on an order that was promised free.
        // Not abroad: a free-shipping threshold is one number per courier, set against domestic prices.
        // Honouring it on an international parcel would make the shop absorb a rate it never quoted, on
        // an order it never priced that way. Free delivery abroad is a decision, not a side effect.
        $free_now = !$abroad && WC()->cart && self::is_free((float) WC()->cart->get_subtotal(), BGCouriers_Settings::free_shipping($id, $method));
        if ($free_now) {
            $cost = 0.0;
        } elseif (!$included) {
            // "Delivery in the order total" is off: nothing is charged with the order - the customer
            // pays the courier's own fee on delivery (Econt's label carries paymentReceiverMethod,
            // Express One's PAYER 1, and so on). What the row shows is therefore what the courier
            // COLLECTS, tax and all, and not how this shop happens to display its own prices - see
            // BGCouriers_Pricing::door_price().
            $info = BGCouriers_Pricing::door_price($quote);
            $cost = 0.0;
        }

        // Tagged with the courier it belongs to. WooCommerce prices EVERY method in the zone on every
        // recalculation, so one shared key held whichever courier happened to run last - and the order
        // then carried that number and that source whoever the customer had actually picked. Same shape
        // of fault as the shared selection key, which was tagged for the same reason.
        if (WC()->session) {
            WC()->session->set('bgcouriers_quote_price_' . $id, $cost);
            WC()->session->set('bgcouriers_quote_source_' . $id, $quote->source);
        }

        $label = $this->title;
        $free  = BGCouriers_Settings::free_shipping_label();
        if ($included && $cost <= 0 && $free !== '') { $label = $free; }

        $rid = $this->get_rate_id();
        $this->add_rate([
            'id'    => $rid,
            'label' => $label,
            'cost'  => $cost,
            'taxes' => '', // '' = let WC calculate shipping tax; only false disables it
            'meta_data' => ['_bgcouriers_source' => $quote->source, '_bgcouriers_method' => $method, '_bgcouriers_info_price' => $info],
        ]);
        // The checkout BLOCK prints a rate as its name and its price, and nothing of the label filters
        // the classic checkout dresses the row with - so a delivery the customer pays the courier for at
        // the door, whose rate is 0, read "Speedy  БЕЗПЛАТНО" there (measured 2026-09-13). The one text
        // the block prints beside the price is the rate's delivery_time (WooCommerce 9.2+, after a dash):
        // "БЕЗПЛАТНО - ~3,06 € се плащат на куриера при получаване". The classic checkout never reads it.
        if ($info > 0 && isset($this->rates[$rid]) && method_exists($this->rates[$rid], 'set_delivery_time')) {
            $price = html_entity_decode(wp_strip_all_tags(wc_price($info)), ENT_QUOTES, 'UTF-8');
            /* translators: %s: the delivery price, e.g. "3,06 €" */
            $this->rates[$rid]->set_delivery_time(sprintf(__('~%s paid to the courier on delivery', 'bg-couriers'), $price));
        }
    }
}
