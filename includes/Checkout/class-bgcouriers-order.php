<?php
defined('ABSPATH') || exit;
class BGCouriers_Order {
    /**
     * Couriers whose parcel-count and insurance field names are VERIFIED - Speedy against its own
     * schema (api.speedy.bg/v1/schema), Sameday against a payload we already send successfully.
     *
     * The order editor hides both boxes for anyone else. Econt, Pigeon and BOX NOW are not here because
     * their names for these are unknown, and a field that accepts "3 parcels" and then ships one is a
     * lie the merchant only discovers at the depot.
     */
    const MULTI_PARCEL_COURIERS = ['speedy', 'sameday'];

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

    /**
     * How many physical parcels this order is being sent as.
     *
     * One unless the merchant says otherwise. It used to be one FULL STOP - `parcelsCount => 1` was a
     * literal in the Speedy body and `packageNumber => 1` in Sameday's - so a shop sending three boxes
     * got one waybill for one box and had to make the other two by hand, outside the plugin.
     *
     * Capped at 99: the number comes from a human typing into a box, and a courier API answering an
     * accidental 1000 with a thousand labels is not a failure anyone wants to discover afterwards.
     */
    public static function parcels(\WC_Order $order): int {
        $n = (int) $order->get_meta('_bgcouriers_parcels');
        return $n > 1 ? min($n, 99) : 1;
    }

    /**
     * The value to insure this shipment for, in the order's own currency; 0 means no insurance.
     *
     * Deliberately NOT defaulted to the order total. Insurance costs the sender money on most tariffs,
     * so it is opt-in per order - a plugin that quietly insured everything would be spending the
     * merchant's money on their behalf.
     */
    public static function insurance(\WC_Order $order): float {
        $v = (float) $order->get_meta('_bgcouriers_insurance');
        return $v > 0 ? round($v, 2) : 0.0;
    }

    /**
     * One entry per parcel, each carrying its share of the weight.
     *
     * Split evenly and the remainder given to the first, so the parts always add back up to the total -
     * a courier that re-weighs at the depot bills the difference, and "roughly" is not good enough.
     *
     * @return array<int,float> per-parcel weight in kg, in sequence
     */
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
