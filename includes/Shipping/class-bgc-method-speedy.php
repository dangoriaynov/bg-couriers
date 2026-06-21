<?php
defined('ABSPATH') || exit;

class BGC_Method_Speedy extends WC_Shipping_Method {
    public function __construct($instance_id = 0) {
        $this->id = 'bgc_speedy';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Speedy', 'bg-couriers');
        $this->method_description = __('Speedy shipping (BG Couriers)', 'bg-couriers');
        $this->supports = ['shipping-zones', 'instance-settings'];
        $this->enabled = 'yes';
        $this->title = __('Speedy', 'bg-couriers');
        $this->init_instance_settings();
    }

    public function calculate_shipping($package = []) {
        $method  = WC()->session ? (string) WC()->session->get('bgc_method', 'office') : 'office';
        $site_id = WC()->session ? (int) WC()->session->get('bgc_site_id', 0) : 0;
        $office  = WC()->session ? (int) WC()->session->get('bgc_office_id', 0) : 0;
        $weight  = (float) ($package['contents_weight'] ?? 0);
        $packed  = BGC_Packer::from_weight($weight);

        $shipment = array_merge($packed, [
            'method' => $method, 'site_id' => $site_id, 'office_id' => $office, 'cod_amount' => 0.0, 'currency' => get_woocommerce_currency(),
        ]);
        $courier = apply_filters('bgc_courier', null, 'speedy');
        if (!$courier) {
            $cfg = BGC_Settings::courier_config('speedy');
            $courier = new BGC_Speedy($cfg ?: ['env' => 'demo']);
        }
        $quote = BGC_Pricing::quote($courier, $shipment);

        $this->add_rate([
            'id'    => $this->get_rate_id(),
            'label' => $this->title,
            'cost'  => $quote->price,
            'taxes' => '', // taxes handled by WC tax settings on the rate
            'meta_data' => ['bgc_source' => $quote->source, 'bgc_method' => $method],
        ]);
    }
}
