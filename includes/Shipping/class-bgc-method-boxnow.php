<?php
defined('ABSPATH') || exit;

/**
 * BOX NOW WC shipping method - locker-only, flat rate (BoxNow has no live price endpoint).
 * Hidden when the cart exceeds the BoxNow parcel limit (20 kg / 36×45×60 cm).
 */
class BGC_Method_Boxnow extends WC_Shipping_Method {
    public function __construct($instance_id = 0) {
        $this->id                 = 'bgc_boxnow';
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
        // BoxNow parcel limit - hide the method for carts it cannot carry.
        if ((float) ($package['contents_weight'] ?? 0) > 20.0) { return; }

        $cost = (float) get_option('bgc_boxnow_flat_price', 0);
        if (WC()->cart && self::is_free((float) WC()->cart->get_subtotal(), BGC_Settings::free_shipping('boxnow'))) {
            $cost = 0.0;
        }
        if (WC()->session) {
            WC()->session->set('bgc_quote_price', $cost);
            WC()->session->set('bgc_quote_source', 'flat');
        }

        $label = $this->title;
        $free  = BGC_Settings::free_shipping_label();
        if ($cost <= 0 && $free !== '') { $label = $free; }

        $this->add_rate([
            'id'        => $this->get_rate_id(),
            'label'     => $label,
            'cost'      => $cost,
            'taxes'     => '', // '' = let WC calculate shipping tax
            'meta_data' => ['bgc_source' => 'flat', 'bgc_method' => 'automat'],
        ]);
    }
}
