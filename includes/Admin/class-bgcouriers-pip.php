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
 *
 * WHAT THIS FILE WILL NOT DO: lay out somebody else's document. Type sizes, column order, what the
 * heading says, where the fold falls - that is the shop's own taste, it differs per shop, and a courier
 * plugin that every other shop installs has no business restyling their paperwork. The only styling
 * here is on the elements this file itself emits. A shop that wants its printed sheet arranged its own
 * way does that in its own site code, where courier_block() is a public entry point it can call.
 */
class BGCouriers_PIP {

    public function __construct() {
        add_filter('wc_pip_document_shipping_method', [$this, 'shipping_method'], 10, 3);
        // The invoice/packing list prints its "Доставка" line from WooCommerce's own order TOTALS, not
        // from the shipping-method block above - so the filter that reads well in the docs is not the one
        // that renders on the page. Both are hooked; whichever the document uses, the courier shows up.
        add_filter('wc_pip_document_table_footer', [$this, 'table_footer'], 20, 3);
        add_action('wc_pip_before_footer', [$this, 'print_styles']);
    }

    /**
     * Replace the shipping-method cell with the courier and where it is going.
     *
     * @param string    $method Whatever PIP was going to print.
     * @param string    $type   Document type (invoice / packing-list / pick-list).
     * @param \WC_Order $order  The order.
     * @return string
     */
    public function shipping_method($method, $type = '', $order = null) {
        if (!$order instanceof \WC_Order) { return $method; }
        $block = self::courier_block($order, (string) $order->get_meta('_bgcouriers_waybill'));
        return $block !== '' ? $block : $method;
    }

    /**
     * The courier, the delivery type and - when asked for - the waybill, as one line of HTML.
     *
     * Public and static because the sheet this ends up on is not ours. A shop that wants the courier
     * somewhere its own print template puts it - next to the document number, say, because that is the
     * half that stays face-up once the sheet is folded onto the parcel - calls this rather than
     * reinventing what a courier is supposed to look like.
     *
     * @param \WC_Order $order   The order.
     * @param string    $waybill Appended after the delivery type when given, so one line can say
     *                           courier, destination type and shipment number.
     * @return string HTML, or '' when the order has no courier of ours.
     */
    public static function courier_block(\WC_Order $order, string $waybill = ''): string {
        $courier = BGCouriers_Labels::order_courier($order);
        if (!$courier) { return ''; }

        $logo = BGCouriers_Couriers::logo_url($courier->id());
        // Height only, and no width: courier logos differ wildly in proportion, and a fixed box either
        // squashes them or leaves a hole. Printers ignore lazy-loading, so nothing is deferred.
        $out = '<span class="bgc-pip">';
        if ($logo !== '') {
            // alt="" on purpose: the courier's name is printed immediately after it, and a printer or
            // PDF renderer that does not fetch the image falls back to the alt text - which read
            // "Speedy Speedy - До автомат" on paper.
            $out .= '<img class="bgc-pip-logo" src="' . esc_url($logo) . '" alt="" height="14">';
        }
        $out .= '<strong class="bgc-pip-name">' . esc_html($courier->label()) . '</strong>';

        $m = (string) $order->get_meta('_bgcouriers_method');
        if ($m !== '') {
            $out .= '<span class="bgc-pip-type"> - ' . esc_html(BGCouriers_Icons::method_label($m)) . '</span>';
        }
        if ($waybill !== '') {
            $out .= '<span class="bgc-pip-num"> - ' . esc_html($waybill) . '</span>';
        }
        // Deliberately NOT the destination address: it is already printed in full in the recipient block
        // at the top of the document, and repeating it here only pushed the totals column into a
        // seven-line tower.
        return $out . '</span>';
    }

    /**
     * Replace the "Shipping" row of the document totals with the courier block.
     *
     * @param array  $rows     Footer rows, keyed by total id ('shipping', 'order_total', ...).
     * @param string $type     Document type.
     * @param int    $order_id Order id.
     * @return array
     */
    public function table_footer($rows, $type = '', $order_id = 0) {
        if (!is_array($rows) || empty($rows['shipping'])) { return $rows; }
        $order = wc_get_order((int) $order_id);
        if (!$order instanceof \WC_Order) { return $rows; }
        // With the waybill: this row is the only place the plugin itself puts it on the document, and a
        // printed sheet is matched against a box by that number. A shop whose own template prints it
        // somewhere better - next to the document number, on the half that stays face-up once the sheet
        // is folded - simply gets it twice, on the half that is folded away.
        $block = self::courier_block($order, (string) $order->get_meta('_bgcouriers_waybill'));
        if ($block === '') { return $rows; }
        // Keep the row's own label cell and shape - only the value becomes ours, so the document's
        // column widths and styling are left exactly as PIP built them.
        $rows['shipping']['value'] = $block;
        return $rows;
    }

    /**
     * Print styling for the elements above, and nothing else on the sheet.
     *
     * Inline on purpose: PIP renders a standalone document and enqueued stylesheets do not reliably
     * reach it, least of all through a PDF renderer.
     */
    public function print_styles(): void {
        echo '<style>'
            // nowrap on the whole thing: the totals column is narrow, and without it the logo alone
            // took a line and the courier name another.
            . '.bgc-pip{white-space:nowrap;}'
            . '.bgc-pip-logo{height:13px;width:auto;vertical-align:-2px;margin-right:4px;}'
            . '.bgc-pip-name{font-weight:700;}'
            . '.bgc-pip-num{color:#555;letter-spacing:.02em;}'
            . '</style>';
    }
}
