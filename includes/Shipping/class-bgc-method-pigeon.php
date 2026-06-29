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
        $dflt    = BGC_Settings::enabled_methods('pigeon')[0] ?? 'office';
        $method  = WC()->session ? ((string) WC()->session->get('bgc_method', '') ?: $dflt) : $dflt;
        $site_id = WC()->session ? (int) WC()->session->get('bgc_site_id', 0) : 0;
        $office  = WC()->session ? (int) WC()->session->get('bgc_office_id', 0) : 0;
        $weight  = (float) ($package['contents_weight'] ?? 0);
        $packed  = BGC_Packer::from_weight($weight);

        // Resolve the office to quote against (representative office for a city without a specific pick).
        // If the chosen city has NO office/APS of this type, the option is unavailable — show that, no price.
        $res = BGC_Pricing::resolve_office('pigeon', $method, $site_id, $office);
        if ($res['unavailable']) {
            if (WC()->session) { WC()->session->set('bgc_quote_price', 0); WC()->session->set('bgc_quote_source', 'unavailable'); }
            $this->add_rate(['id' => $this->get_rate_id(),
                'label' => $this->title . ' — ' . __('not available for this city', 'bg-couriers'),
                'cost' => 0, 'taxes' => false, 'meta_data' => ['bgc_unavailable' => '1', 'bgc_method' => $method]]);
            return;
        }
        $office  = $res['office_id'];
        $site_id = $res['site_id'];
        $shipment = array_merge($packed, [
            'method' => $method, 'site_id' => $site_id, 'office_id' => $office, 'cod_amount' => 0.0, 'currency' => get_woocommerce_currency(),
        ]);
        $courier = BGC_Couriers::get('pigeon');
        $quote = BGC_Pricing::quote($courier, $shipment);
        $cost  = $quote->price;

        // Free shipping (the merchant absorbs it) when the order goods total (w/o shipping,
        // store currency, no conversion) reaches the Pigeon Express method-level threshold.
        if (WC()->cart && self::is_free((float) WC()->cart->get_subtotal(), BGC_Settings::free_shipping('pigeon'))) {
            $cost = 0.0;
        }

        if (WC()->session) {
            WC()->session->set('bgc_quote_price', $cost);
            WC()->session->set('bgc_quote_source', $quote->source);
        }

        $label = $this->title;
        $free  = BGC_Settings::free_shipping_label();
        if ($cost <= 0 && $free !== '') { $label = $free; }

        $this->add_rate([
            'id'    => $this->get_rate_id(),
            'label' => $label,
            'cost'  => $cost,
            'taxes' => '', // '' = let WC calculate shipping tax; only false disables it
            'meta_data' => ['bgc_source' => $quote->source, 'bgc_method' => $method],
        ]);
    }
}
