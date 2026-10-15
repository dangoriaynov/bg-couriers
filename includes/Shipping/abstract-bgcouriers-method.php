<?php
defined('ABSPATH') || exit;

/** One WooCommerce shipping method for every courier that quotes a price; subclasses only set id and name, both of which WooCommerce stores. */
abstract class BGCouriers_Abstract_Method extends WC_Shipping_Method {
    protected $courier_id = '';

    public function __construct($instance_id, string $courier, string $label) {
        $this->courier_id         = $courier;
        $this->id                 = 'bgcouriers_' . $courier;
        $this->instance_id        = absint($instance_id);
        $this->method_title       = $label;
        /* translators: %s: courier name, e.g. "Speedy" */
        $this->method_description = sprintf(__('%s shipping (BG Couriers)', 'bg-couriers'), $label);
        $this->supports           = ['shipping-zones', 'instance-settings'];
        $this->enabled            = 'yes';
        $this->title              = $label;
        $this->init_instance_settings();
    }

    public static function is_free(float $goods_total, array $cfg): bool {
        return !empty($cfg['enabled'])
            && (float) ($cfg['threshold'] ?? 0) > 0
            && $goods_total >= (float) $cfg['threshold'];
    }

    public function calculate_shipping($package = []) {
        $id = $this->courier_id;
        // Not offerable (switched off, no credentials, no delivery option) = no quote, whatever the zone holds.
        if (!BGCouriers_Settings::courier_offerable($id)) { return; }
        $sel     = BGCouriers_Pricing::selection_for($id);
        $method  = $sel['method'];
        $site_id = $sel['site_id'];
        $office  = $sel['office_id'];
        $packed  = BGCouriers_Pricing::package_parcel($package);

        $courier = BGCouriers_Couriers::get($id);
        $country = BGCouriers_Pricing::destination_country($package);
        if (!BGCouriers_Settings::ships_to($id, $country, $courier)) { return; }
        $abroad  = BGCouriers_Settings::is_intl($country);
        try {
            $quote = BGCouriers_Pricing::checkout_quote($courier, $method, $site_id, $office, $packed, get_woocommerce_currency(), $country);
        } catch (\Exception $e) {
            // Only an international quote throws: abroad, no live price means no delivery offered.
            BGCouriers_Logger::debug('no rate offered', ['courier' => $id, 'country' => $country]);
            return;
        }
        $cost = BGCouriers_Pricing::rate_cost($quote);

        // Abroad the international service refuses a recipient payer, so the shop always pays.
        $included = $abroad ? true : BGCouriers_Settings::ship_in_total($id);
        $info     = 0.0;
        // Free delivery beats "the recipient pays", and never applies abroad: the threshold is set against domestic prices.
        $free_now = !$abroad && WC()->cart && self::is_free((float) WC()->cart->get_subtotal(), BGCouriers_Settings::free_shipping($id, $method));
        if ($free_now) {
            $cost = 0.0;
        } elseif (!$included) {
            // Nothing charged with the order: the row shows what the courier collects at the door, tax included.
            $info = BGCouriers_Pricing::door_price($quote);
            $cost = 0.0;
        }

        // Keyed per courier: WooCommerce prices every method in the zone on each recalculation.
        if (WC()->session) {
            WC()->session->set('bgcouriers_quote_price_' . $id, $cost);
            WC()->session->set('bgcouriers_quote_source_' . $id, $quote->source);
        }

        $label = $this->title;
        $free  = BGCouriers_Settings::free_shipping_label();
        if ($included && $cost <= 0 && $free !== '') { $label = $free; }

        $rid = $this->get_rate_id();
        $this->add_rate([
            'id'    => $rid,
            'label' => $label,
            'cost'  => $cost,
            'taxes' => '', // '' = let WC calculate shipping tax; only false disables it
            'meta_data' => ['_bgcouriers_source' => $quote->source, '_bgcouriers_method' => $method, '_bgcouriers_info_price' => $info],
        ]);
        // The checkout block prints only the rate name and price; delivery_time (WC 9.2+) is the one extra text it shows.
        if ($info > 0 && isset($this->rates[$rid]) && method_exists($this->rates[$rid], 'set_delivery_time')) {
            $price = html_entity_decode(wp_strip_all_tags(wc_price($info)), ENT_QUOTES, 'UTF-8');
            /* translators: %s: the delivery price, e.g. "3,06 €" */
            $this->rates[$rid]->set_delivery_time(sprintf(__('~%s paid to the courier on delivery', 'bg-couriers'), $price));
        }
    }
}
