<?php
defined('ABSPATH') || exit;
class BGC_Order {
    public static function shipment_from_order(\WC_Order $order): array {
        return [
            'method'       => (string) $order->get_meta('_bgc_method') ?: 'office',
            'site_id'      => (int) $order->get_meta('_bgc_site_id'),
            'office_id'    => (int) $order->get_meta('_bgc_office_id'),
            'weight_kg'    => 2.0, 'currency' => $order->get_currency(),
            'quote_price'  => (float) $order->get_meta('_bgc_quote_price'),
            'quote_source' => (string) $order->get_meta('_bgc_quote_source'),
        ];
    }
}
