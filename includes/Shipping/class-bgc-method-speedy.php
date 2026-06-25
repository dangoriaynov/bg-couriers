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

    /** Free shipping when enabled and the goods total (w/o shipping) reaches the threshold. */
    public static function is_free(float $goods_total, array $cfg): bool {
        return !empty($cfg['enabled'])
            && (float) ($cfg['threshold'] ?? 0) > 0
            && $goods_total >= (float) $cfg['threshold'];
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
            $courier = new BGC_Speedy($cfg ?: []);
        }
        $quote = BGC_Pricing::quote($courier, $shipment);
        $cost  = $quote->price;

        // Free shipping (the merchant absorbs it) when the order goods total (w/o shipping,
        // store currency, no conversion) reaches the Speedy method-level threshold.
        if (WC()->cart && self::is_free((float) WC()->cart->get_subtotal(), BGC_Settings::free_shipping('speedy'))) {
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
