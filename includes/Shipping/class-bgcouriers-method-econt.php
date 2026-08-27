<?php
defined('ABSPATH') || exit;

class BGCouriers_Method_Econt extends WC_Shipping_Method {
    public function __construct($instance_id = 0) {
        $this->id = 'bgcouriers_econt';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Econt', 'bg-couriers');
        $this->method_description = __('Econt shipping (BG Couriers)', 'bg-couriers');
        $this->supports = ['shipping-zones', 'instance-settings'];
        $this->enabled = 'yes';
        $this->title = __('Econt', 'bg-couriers');
        $this->init_instance_settings();
    }

    /** Free shipping when enabled and the goods total (w/o shipping) reaches the threshold. */
    public static function is_free(float $goods_total, array $cfg): bool {
        return !empty($cfg['enabled'])
            && (float) ($cfg['threshold'] ?? 0) > 0
            && $goods_total >= (float) $cfg['threshold'];
    }

    public function calculate_shipping($package = []) {
        // Switched off on its settings tab = not offered, whatever the shipping zone still holds. Every
        // other place already asked this (the cart estimate, the map, the office lookups, the sync); the
        // shipping method - the one that actually puts the courier in front of a customer - did not, so
        // "Enable Econt" was a switch that changed everything except the checkout. A zone entry is
        // ordinary WooCommerce furniture and a merchant may well leave one in place while a courier is
        // being set up, or after switching it off; it must not quote in the meantime.
        if (BGCouriers_Settings::courier_config('econt') === null) { return; }
        // Price against THIS courier's own selection (the session's single selection is tagged with the
        // courier it was made for; city/office ids are per-courier and must not leak across couriers).
        $sel     = BGCouriers_Pricing::selection_for('econt');
        $method  = $sel['method'];
        $site_id = $sel['site_id'];
        $office  = $sel['office_id'];
        $packed  = BGCouriers_Pricing::package_parcel($package); // the shop's own weight unit, converted once

        // Before a city is chosen use the fast cached daily reference (no live API) so switching couriers
        // stays snappy and the customer can start entering the address; checkout_quote does the exact live
        // quote once a real city is picked.
        $courier = BGCouriers_Couriers::get('econt');
        // Where the parcel is going, and whether this courier goes there at all. One that does not is
        // simply not offered - no rate is better than a domestic price on a parcel leaving the country.
        $country = BGCouriers_Pricing::destination_country($package);
        if (!BGCouriers_Settings::ships_to('econt', $country, $courier)) { return; }
        $abroad  = BGCouriers_Settings::is_intl($country);
        try {
            $quote = BGCouriers_Pricing::checkout_quote($courier, $method, $site_id, $office, $packed, get_woocommerce_currency(), $country);
        } catch (\Exception $e) {
            // Only an international quote ever reaches here: domestically there is always a fallback
            // price, and abroad a missing live price means no delivery is offered at all.
            BGCouriers_Logger::debug('no rate offered', ['courier' => 'econt', 'country' => $country]);
            return;
        }
        $cost  = $quote->price;

        // Abroad the courier bills the shop whatever the "delivery in the order total" toggle says: the
        // international service refuses a recipient payer outright, so there is no fee at the door to
        // point the customer at.
        $included = $abroad ? true : BGCouriers_Settings::ship_in_total('econt');
        $info     = 0.0;
        // Free delivery is checked FIRST and beats "the recipient pays": the shop absorbing the cost is
        // the whole point of a free-shipping threshold, so the customer must not be quoted a price to
        // pay the courier at the door on an order that was promised free.
        // Not abroad: a free-shipping threshold is one number per courier, set against domestic prices.
        // Honouring it on an international parcel would make the shop absorb a rate it never quoted, on
        // an order it never priced that way. Free delivery abroad is a decision, not a side effect.
        $free_now = !$abroad && WC()->cart && self::is_free((float) WC()->cart->get_subtotal(), BGCouriers_Settings::free_shipping('econt', $method));
        if ($free_now) {
            $cost = 0.0;
        } elseif (!$included) {
            // "Delivery in the order total" is off: nothing is charged with the order - the customer pays
            // Econt's own fee on delivery (the label carries paymentReceiverMethod). Keep the estimate
            // (display-gross, like charged rates render) so the method label can still show it.
            $info = BGCouriers_Pricing::display_price((float) $cost);
            $cost = 0.0;
        }

        if (WC()->session) {
            WC()->session->set('bgcouriers_quote_price', $cost);
            WC()->session->set('bgcouriers_quote_source', $quote->source);
        }

        $label = $this->title;
        $free  = BGCouriers_Settings::free_shipping_label();
        if ($included && $cost <= 0 && $free !== '') { $label = $free; }

        $this->add_rate([
            'id'    => $this->get_rate_id(),
            'label' => $label,
            'cost'  => $cost,
            'taxes' => '', // '' = let WC calculate shipping tax; only false disables it
            'meta_data' => ['_bgcouriers_source' => $quote->source, '_bgcouriers_method' => $method, '_bgcouriers_info_price' => $info],
        ]);
    }
}
