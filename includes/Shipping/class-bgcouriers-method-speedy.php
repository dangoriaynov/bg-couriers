<?php
defined('ABSPATH') || exit;

class BGCouriers_Method_Speedy extends WC_Shipping_Method {
    public function __construct($instance_id = 0) {
        $this->id = 'bgcouriers_speedy';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Speedy', 'bg-couriers');
        $this->method_description = __('Speedy shipping (BG Couriers)', 'bg-couriers');
        $this->supports = ['shipping-zones', 'instance-settings'];
        $this->enabled = 'yes';
        $this->title = __('Speedy', 'bg-couriers');
        $this->init_instance_settings();
    }

    /** Free shipping when enabled and the goods total (w/o shipping) reaches the threshold. */
    public static function is_free(float $goods_total, array $cfg): bool {
        return !empty($cfg['enabled'])
            && (float) ($cfg['threshold'] ?? 0) > 0
            && $goods_total >= (float) $cfg['threshold'];
    }

    public function calculate_shipping($package = []) {
        // Price against THIS courier's own selection (the session's single selection is tagged with the
        // courier it was made for; city/office ids are per-courier and must not leak across couriers).
        $sel     = BGCouriers_Pricing::selection_for('speedy');
        $method  = $sel['method'];
        $site_id = $sel['site_id'];
        $office  = $sel['office_id'];
        $packed  = BGCouriers_Pricing::package_parcel($package); // the shop's own weight unit, converted once

        // Before a city is chosen use the fast cached daily reference (no live API) so switching couriers
        // stays snappy and the customer can start entering the address; checkout_quote does the exact live
        // quote once a real city is picked.
        $courier = BGCouriers_Couriers::get('speedy');
        $quote = BGCouriers_Pricing::checkout_quote($courier, $method, $site_id, $office, $packed, get_woocommerce_currency());
        $cost  = $quote->price;

        $included = BGCouriers_Settings::ship_in_total('speedy');
        $info     = 0.0;
        // Free delivery is checked FIRST and beats "the recipient pays": the shop absorbing the cost is
        // the whole point of a free-shipping threshold, so the customer must not be quoted a price to
        // pay the courier at the door on an order that was promised free.
        $free_now = WC()->cart && self::is_free((float) WC()->cart->get_subtotal(), BGCouriers_Settings::free_shipping('speedy', $method));
        if ($free_now) {
            $cost = 0.0;
        } elseif (!$included) {
            // "Delivery in the order total" is off: nothing is charged with the order - the customer
            // pays the courier's own fee on delivery. Keep the estimate (display-gross, like charged
            // rates render) so the method label can still show it for information.
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
