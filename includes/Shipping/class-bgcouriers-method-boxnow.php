<?php
defined('ABSPATH') || exit;

/**
 * BOX NOW WC shipping method - locker-only, flat rate (BoxNow has no live price endpoint).
 * Hidden when the cart exceeds the BoxNow parcel limit (20 kg / 36×45×60 cm).
 */
class BGCouriers_Method_Boxnow extends BGCouriers_Abstract_Method {
    public function __construct($instance_id = 0) {
        parent::__construct($instance_id, 'boxnow', __('BOX NOW', 'bg-couriers'));
        // BOX NOW says what it is rather than "BOX NOW shipping": it is the only courier here that is a
        // locker network and nothing else, and the zone screen is where a merchant decides that.
        $this->method_description = __('BOX NOW locker (APM) delivery (BG Couriers)', 'bg-couriers');
    }

    /**
     * The one courier that does NOT take the shared quote: BOX NOW publishes no price endpoint at all,
     * so the rate is the merchant's own flat figure and there is nothing to ask an API for. Everything
     * else - the constructor, the free-shipping rule - comes from the parent.
     */
    public function calculate_shipping($package = []) {
        // Switched off on its settings tab = not offered, whatever the shipping zone still holds. Every
        // other place already asked this (the cart estimate, the map, the office lookups, the sync); the
        // shipping method - the one that actually puts the courier in front of a customer - did not, so
        // "Enable BOX NOW" was a switch that changed everything except the checkout. A zone entry is
        // ordinary WooCommerce furniture and a merchant may well leave one in place while a courier is
        // being set up, or after switching it off; it must not quote in the meantime.
        // Switched on, credentials saved and validated, and at least one delivery option left on.
        // A zone entry is ordinary WooCommerce furniture and a merchant may well leave one in place
        // while a courier is being set up, or after switching it off; it must not quote in the
        // meantime. See BGCouriers_Settings::courier_offerable().
        if (!BGCouriers_Settings::courier_offerable('boxnow')) { return; }
        // BoxNow parcel limit - hide the method for carts it cannot carry. The cart weight arrives in
        // the shop's own unit, so it is converted first: compared raw, a gram-priced shop hid BOX NOW
        // from every basket over 20 grams.
        if (BGCouriers_Pricing::package_parcel($package)['weight_kg'] > 20.0) { return; }

        // BOX NOW is a Bulgarian locker network and offers no other country, so this is only ever a
        // "not this courier" for an address abroad - which is exactly what it should answer.
        if (!BGCouriers_Settings::ships_to('boxnow', BGCouriers_Pricing::destination_country($package))) { return; }

        $cost = (float) get_option('bgcouriers_boxnow_flat_price', 0);
        if (WC()->cart && self::is_free((float) WC()->cart->get_subtotal(), BGCouriers_Settings::free_shipping('boxnow'))) {
            $cost = 0.0;
        }
        // Per courier - see BGCouriers_Abstract_Method::calculate_shipping().
        if (WC()->session) {
            WC()->session->set('bgcouriers_quote_price_boxnow', $cost);
            WC()->session->set('bgcouriers_quote_source_boxnow', 'flat');
        }

        $label = $this->title;
        $free  = BGCouriers_Settings::free_shipping_label();
        if ($cost <= 0 && $free !== '') { $label = $free; }

        $this->add_rate([
            'id'        => $this->get_rate_id(),
            'label'     => $label,
            'cost'      => $cost,
            'taxes'     => '', // '' = let WC calculate shipping tax
            'meta_data' => ['_bgcouriers_source' => 'flat', '_bgcouriers_method' => 'automat'],
        ]);
    }
}
