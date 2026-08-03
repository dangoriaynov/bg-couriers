<?php
defined('ABSPATH') || exit;

/**
 * Print Invoices & Packing Lists (PIP) integration.
 *
 * A packing list is read by whoever is putting parcels into piles, and PIP prints the raw WooCommerce
 * shipping-method title there - "Speedy: ~ 2,58 €", or worse, whatever the rate happened to be called.
 * That tells the person packing nothing they need: which courier the box goes to, and where.
 *
 * So the same block the plugin shows everywhere else goes on the document: the courier's own logo and
 * name, the delivery type, the office or address it is going to, and the waybill number - which is what
 * a printed pile is actually matched against.
 *
 * Hooks a filter rather than a template: PIP publishes wc_pip_document_shipping_method for exactly this,
 * so nothing has to be overridden and their templates can change freely.
 */
class BGCouriers_PIP {

    public function __construct() {
        add_filter('wc_pip_document_shipping_method', [$this, 'shipping_method'], 10, 3);
        add_action('wc_pip_before_footer', [$this, 'print_styles']);
    }

    /**
     * Replace the shipping-method cell with the courier, where it is going, and the waybill.
     *
     * @param string    $method Whatever PIP was going to print.
     * @param string    $type   Document type (invoice / packing-list / pick-list).
     * @param \WC_Order $order  The order.
     * @return string
     */
    public function shipping_method($method, $type, $order) {
        $courier = BGCouriers_Labels::order_courier($order);
        if (!$courier) { return $method; }

        $logo = BGCouriers_Couriers::logo_url($courier->id());
        // Height only, and no width: courier logos differ wildly in proportion, and a fixed box either
        // squashes them or leaves a hole. Printers ignore lazy-loading, so nothing is deferred.
        $out = '<span class="bgc-pip">';
        if ($logo !== '') {
            $out .= '<img class="bgc-pip-logo" src="' . esc_url($logo) . '" alt="' . esc_attr($courier->label()) . '" height="14">';
        }
        $out .= '<strong class="bgc-pip-name">' . esc_html($courier->label()) . '</strong>';

        $m = (string) $order->get_meta('_bgcouriers_method');
        if ($m !== '') {
            $out .= '<span class="bgc-pip-type"> - ' . esc_html(BGCouriers_Icons::method_label($m)) . '</span>';
        }

        $where = self::destination($order, $courier->id(), $m);
        if ($where !== '') {
            $out .= '<br><span class="bgc-pip-where">' . esc_html($where) . '</span>';
        }

        $waybill = (string) $order->get_meta('_bgcouriers_waybill');
        if ($waybill !== '') {
            /* translators: %s: waybill number, printed on the packing list */
            $out .= '<br><span class="bgc-pip-wb">' . esc_html(sprintf(__('Waybill %s', 'bg-couriers'), $waybill)) . '</span>';
        }
        return $out . '</span>';
    }

    /**
     * Where the parcel is going, in one line: the office/locker by name, or the street address.
     *
     * @param \WC_Order $order   The order.
     * @param string    $courier Courier id.
     * @param string    $method  office|address|automat.
     * @return string Empty when there is nothing worth printing.
     */
    private static function destination(\WC_Order $order, string $courier, string $method): string {
        if ($method === 'office' || $method === 'automat') {
            $office = BGCouriers_Nomenclature::office_by_id($courier, (int) $order->get_meta('_bgcouriers_office_id'));
            if (!$office) { return ''; }
            $parts = array_filter([(string) ($office['name'] ?? ''), (string) ($office['address'] ?? '')]);
            return implode(', ', $parts);
        }
        // To an address: the shipping address already prints in its own block on the document, so repeat
        // only what is NOT there - the building details the checkout collects separately.
        $extra = [];
        foreach (['_bgcouriers_block' => 'бл.', '_bgcouriers_entrance' => 'вх.',
                  '_bgcouriers_floor' => 'ет.', '_bgcouriers_apartment' => 'ап.'] as $meta => $prefix) {
            $v = trim((string) $order->get_meta($meta));
            if ($v !== '') { $extra[] = $prefix . ' ' . $v; }
        }
        return implode(', ', $extra);
    }

    /**
     * Print styling. Inline on purpose: PIP renders a standalone document and enqueued stylesheets do
     * not reliably reach it, least of all through a PDF renderer.
     */
    public function print_styles(): void {
        echo '<style>'
            . '.bgc-pip-logo{height:14px;width:auto;vertical-align:-2px;margin-right:5px;}'
            . '.bgc-pip-name{font-weight:700;}'
            . '.bgc-pip-type{white-space:nowrap;}'
            . '.bgc-pip-where{display:inline-block;margin-top:2px;}'
            . '.bgc-pip-wb{display:inline-block;margin-top:2px;font-family:monospace;font-weight:700;}'
            . '</style>';
    }
}
