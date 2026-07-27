<?php
defined('ABSPATH') || exit;
class BGCouriers_Order {
    public static function shipment_from_order(\WC_Order $order): array {
        return [
            'method'       => (string) $order->get_meta('_bgcouriers_method') ?: 'office',
            'site_id'      => (int) $order->get_meta('_bgcouriers_site_id'),
            'office_id'    => (int) $order->get_meta('_bgcouriers_office_id'),
            'weight_kg'    => BGCouriers_Abstract_Courier::order_weight_kg($order), 'currency' => $order->get_currency(),
            'quote_price'  => (float) $order->get_meta('_bgcouriers_quote_price'),
            'quote_source' => (string) $order->get_meta('_bgcouriers_quote_source'),
        ];
    }
}
