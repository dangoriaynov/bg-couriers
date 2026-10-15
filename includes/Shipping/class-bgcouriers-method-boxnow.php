<?php
defined('ABSPATH') || exit;

/** BOX NOW shipping method: locker-only, flat rate, no price API. */
class BGCouriers_Method_Boxnow extends BGCouriers_Abstract_Method {
    public function __construct($instance_id = 0) {
        parent::__construct($instance_id, 'boxnow', __('BOX NOW', 'bg-couriers'));
        $this->method_description = __('BOX NOW locker (APM) delivery (BG Couriers)', 'bg-couriers');
    }

    public function calculate_shipping($package = []) {
        // Not offerable (switched off, no credentials, no delivery option) = no quote, whatever the zone holds.
        if (!BGCouriers_Settings::courier_offerable('boxnow')) { return; }
        // BOX NOW parcel limit; the cart weight is in the shop's unit, so convert before comparing.
        if (BGCouriers_Pricing::package_parcel($package)['weight_kg'] > 20.0) { return; }

        if (!BGCouriers_Settings::ships_to('boxnow', BGCouriers_Pricing::destination_country($package))) { return; }

        $cost = (float) get_option('bgcouriers_boxnow_flat_price', 0);
        if (WC()->cart && self::is_free((float) WC()->cart->get_subtotal(), BGCouriers_Settings::free_shipping('boxnow'))) {
            $cost = 0.0;
        }
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
