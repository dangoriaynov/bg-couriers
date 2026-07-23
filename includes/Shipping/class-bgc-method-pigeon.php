<?php
defined('ABSPATH') || exit;

class BGC_Method_Pigeon extends WC_Shipping_Method {
    public function __construct($instance_id = 0) {
        $this->id = 'bgc_pigeon';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Pigeon Express', 'bg-couriers');
        $this->method_description = __('Pigeon Express shipping (BG Couriers)', 'bg-couriers');
        $this->supports = ['shipping-zones', 'instance-settings'];
        $this->enabled = 'yes';
        $this->title = __('Pigeon Express', 'bg-couriers');
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
        $sel     = BGC_Pricing::selection_for('pigeon');
        $method  = $sel['method'];
        $site_id = $sel['site_id'];
        $office  = $sel['office_id'];
        $weight  = (float) ($package['contents_weight'] ?? 0);
        $packed  = BGC_Packer::from_weight($weight);

        // Before a city is chosen use the fast cached daily reference (no live API) so switching couriers
        // stays snappy and the customer can start entering the address; checkout_quote does the exact live
        // quote once a real city is picked.
        $courier = BGC_Couriers::get('pigeon');
        $quote = BGC_Pricing::checkout_quote($courier, $method, $site_id, $office, $packed, get_woocommerce_currency());
        $cost  = $quote->price;

        $included = BGC_Settings::ship_in_total('pigeon');
        $info     = 0.0;
        if (!$included) {
            // "Delivery in the order total" is off: nothing is charged with the order - the customer
            // pays the courier's own fee on delivery. Keep the estimate (display-gross, like charged
            // rates render) so the method label can still show it for information.
            $info = (float) $cost;
            if ($info > 0 && get_option('woocommerce_tax_display_cart') === 'incl') {
                $info += array_sum(WC_Tax::calc_shipping_tax($info, WC_Tax::get_shipping_tax_rates()));
            }
            $cost = 0.0;
        } elseif (WC()->cart && self::is_free((float) WC()->cart->get_subtotal(), BGC_Settings::free_shipping('pigeon', $method))) {
            // Free shipping (the merchant absorbs it) when the order goods total (w/o shipping,
            // store currency, no conversion) reaches the Pigeon Express method-level threshold.
            $cost = 0.0;
        }

        if (WC()->session) {
            WC()->session->set('bgc_quote_price', $cost);
            WC()->session->set('bgc_quote_source', $quote->source);
        }

        $label = $this->title;
        $free  = BGC_Settings::free_shipping_label();
        if ($included && $cost <= 0 && $free !== '') { $label = $free; }

        $this->add_rate([
            'id'    => $this->get_rate_id(),
            'label' => $label,
            'cost'  => $cost,
            'taxes' => '', // '' = let WC calculate shipping tax; only false disables it
            'meta_data' => ['_bgc_source' => $quote->source, '_bgc_method' => $method, '_bgc_info_price' => $info],
        ]);
    }
}
