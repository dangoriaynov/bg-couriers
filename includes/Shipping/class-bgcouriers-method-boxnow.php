<?php
defined('ABSPATH') || exit;

/**
 * BOX NOW WC shipping method - locker-only, flat rate (BoxNow has no live price endpoint).
 * Hidden when the cart exceeds the BoxNow parcel limit (20 kg / 36×45×60 cm).
 */
class BGCouriers_Method_Boxnow extends WC_Shipping_Method {
    public function __construct($instance_id = 0) {
        $this->id                 = 'bgcouriers_boxnow';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = __('BOX NOW', 'bg-couriers');
        $this->method_description = __('BOX NOW locker (APM) delivery (BG Couriers)', 'bg-couriers');
        $this->supports           = ['shipping-zones', 'instance-settings'];
        $this->enabled            = 'yes';
        $this->title              = __('BOX NOW', 'bg-couriers');
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
        // "Enable BOX NOW" was a switch that changed everything except the checkout. A zone entry is
        // ordinary WooCommerce furniture and a merchant may well leave one in place while a courier is
        // being set up, or after switching it off; it must not quote in the meantime.
        if (BGCouriers_Settings::courier_config('boxnow') === null) { return; }
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
        if (WC()->session) {
            WC()->session->set('bgcouriers_quote_price', $cost);
            WC()->session->set('bgcouriers_quote_source', 'flat');
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
