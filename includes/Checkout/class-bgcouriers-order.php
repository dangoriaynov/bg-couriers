<?php
defined('ABSPATH') || exit;
class BGCouriers_Order {
    /** @return string[] couriers whose API applies a parcel count and a declared value */
    public static function multi_parcel_couriers(): array {
        $out = [];
        foreach (array_keys(BGCouriers_Couriers::all()) as $id) {
            $co = BGCouriers_Couriers::get($id);
            if ($co && method_exists($co, 'multi_parcel') && $co->multi_parcel()) { $out[] = $id; }
        }
        return $out;
    }

    public static function shipment_from_order(\WC_Order $order): array {
        return [
            'method'       => (string) $order->get_meta('_bgcouriers_method') ?: 'office',
            'site_id'      => (int) $order->get_meta('_bgcouriers_site_id'),
            'office_id'    => (int) $order->get_meta('_bgcouriers_office_id'),
            'weight_kg'    => BGCouriers_Abstract_Courier::order_weight_kg($order), 'currency' => $order->get_currency(),
            'quote_price'  => (float) $order->get_meta('_bgcouriers_quote_price'),
            'quote_source' => (string) $order->get_meta('_bgcouriers_quote_source'),
            'parcels'      => self::parcels($order),
            'insurance'    => self::insurance($order),
        ];
    }

    /** Parcels the order ships as; capped at 99 so a typo never prints a thousand labels. */
    public static function parcels(\WC_Order $order): int {
        $n = (int) $order->get_meta('_bgcouriers_parcels');
        return $n > 1 ? min($n, 99) : 1;
    }

    /** Declared value to insure, 0 = none; opt-in per order, never defaulted to the total. */
    public static function insurance(\WC_Order $order): float {
        $v = (float) $order->get_meta('_bgcouriers_insurance');
        return $v > 0 ? round($v, 2) : 0.0;
    }

    /** @return array<int,float> even split, remainder on the first so the parts add up to the total */
    public static function parcel_weights(float $total_kg, int $parcels): array {
        $parcels = max(1, $parcels);
        $total   = max(0.1, $total_kg);
        if ($parcels === 1) { return [round($total, 3)]; }
        $each = floor(($total / $parcels) * 1000) / 1000;   // down, so the remainder is never negative
        $out  = array_fill(0, $parcels, $each);
        $out[0] = round($total - $each * ($parcels - 1), 3);
        return $out;
    }
}
