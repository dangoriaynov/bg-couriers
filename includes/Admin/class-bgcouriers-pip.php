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
        // The invoice/packing list prints its "Доставка" line from WooCommerce's own order TOTALS, not
        // from the shipping-method block above - so the filter that reads well in the docs is not the one
        // that renders on the page. Both are hooked; whichever the document uses, the courier shows up.
        add_filter('wc_pip_document_table_footer', [$this, 'table_footer'], 20, 3);
        // The waybill belongs with the order number at the top, not buried in the totals: that is the
        // pair someone reads when matching a printed sheet to a box on the bench.
        add_filter('wc_pip_document_heading', [$this, 'heading'], 10, 4);
        // Quantity before unit price. PIP renders headers and cells straight from the ARRAY ORDER, so
        // both have to be reordered together or the columns and their contents come apart.
        add_filter('wc_pip_document_table_headers', [$this, 'reorder_columns'], 10, 1);
        add_filter('wc_pip_document_column_widths', [$this, 'column_widths'], 10, 1);
        add_filter('wc_pip_document_table_row_cells', [$this, 'reorder_columns'], 10, 1);
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
        // Deliberately NOT the destination address: it is already printed in full in the recipient block
        // at the top of the document, and repeating it here only pushed the totals column into a
        // seven-line tower. Nor the waybill - that now sits beside the order number.
        return $out . '</span>';
    }

    /**
     * Move the quantity column in front of the unit price.
     *
     * How many of a thing is in the box is what the person packing reads first; what one costs is
     * paperwork. PIP draws both the header row and every cell row from the array order, so the same
     * reordering is applied to headers, widths and cells - reorder one and the columns lose their labels.
     *
     * @param array $cols Keyed by column id ('quantity', 'unit_price', ...).
     * @return array The same array, quantity moved ahead of unit_price.
     */
    public function reorder_columns($cols) {
        if (!is_array($cols) || !isset($cols['quantity'], $cols['unit_price'])) { return $cols; }
        $out = [];
        foreach ($cols as $key => $value) {
            if ($key === 'quantity') { continue; }          // placed by its neighbour, below
            if ($key === 'unit_price') { $out['quantity'] = $cols['quantity']; }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Give the product and quantity columns the LEFT HALF of the sheet.
     *
     * The sheet gets folded, and the top-left quarter is what shows. Whoever is filling the box reads
     * exactly two things there - what goes in and how many - so those two columns have to end at the
     * halfway line; the money can live on the half that is folded away.
     *
     * @param array $widths Percentages keyed by column id.
     * @return array
     */
    public function column_widths($widths) {
        if (!is_array($widths)) { return $widths; }
        $widths = $this->reorder_columns($widths);
        if (isset($widths['product'], $widths['quantity'])) {
            $widths['product']  = 38;
            $widths['quantity'] = 12;   // 50 together: everything to check the parcel by, before the fold
        }
        foreach (['unit_price' => 22, 'price' => 28] as $k => $v) {
            if (isset($widths[$k])) { $widths[$k] = $v; }
        }
        return $widths;
    }

    /**
     * Put the waybill next to the order number in the document heading, small and quiet.
     *
     * @param string    $heading The heading HTML PIP built.
     * @param string    $type    Document type.
     * @param string    $action  'print' or 'send_email'.
     * @param \WC_Order $order   The order.
     * @return string
     */
    public function heading($heading, $type = '', $action = '', $order = null) {
        if (!$order instanceof \WC_Order) { return $heading; }
        $waybill = (string) $order->get_meta('_bgcouriers_waybill');
        if ($waybill === '') { return $heading; }
        /* translators: %s: waybill number, printed under the document number */
        $line = '<div class="bgc-pip-wb">' . esc_html(sprintf(__('Waybill %s', 'bg-couriers'), $waybill)) . '</div>';
        // Wrapped in our own block with the geometry INLINE rather than left to a stylesheet rule. The
        // document number, its date and the waybill have to sit over the left half - that is the part
        // that stays face-up once the sheet is folded onto the parcel - and PIP's own centring rules kept
        // winning that fight. An inline style on an element we own settles it without a specificity war.
        return '<div class="bgc-pip-head" style="width:50%;margin:0 auto 0 0;text-align:center;">'
            . $heading . $line . '</div>';
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
        $block = $this->shipping_method('', $type, $order);
        if ($block === '') { return $rows; }
        // Keep the row's own label cell and shape - only the value becomes ours, so the document's
        // column widths and styling are left exactly as PIP built them.
        $rows['shipping']['value'] = $block;
        return $rows;
    }

    /**
     * Print styling. Inline on purpose: PIP renders a standalone document and enqueued stylesheets do
     * not reliably reach it, least of all through a PDF renderer.
     */
    public function print_styles(): void {
        echo '<style>'
            // nowrap on the whole thing: the totals column is narrow, and without it the logo alone
            // took a line and the courier name another.
            . '.bgc-pip{white-space:nowrap;}'
            . '.bgc-pip-logo{height:13px;width:auto;vertical-align:-2px;margin-right:4px;}'
            . '.bgc-pip-name{font-weight:700;}'
            . '.bgc-pip-wb{margin-top:2px;font-size:11px;color:#555;letter-spacing:.02em;}'
            // The document number, its date and the waybill sit over the LEFT HALF rather than the middle
            // of the page: the sheet is folded before it goes on the parcel, and this is the part that
            // ends up face-up. Centred on the page, half of it disappears into the fold.
            // Anything inside our heading block centres on that block, not on the page.
            . '.bgc-pip-head, .bgc-pip-head *{text-align:center;}'
            . '.bgc-pip-head h3, .bgc-pip-head p{margin-left:0;margin-right:0;}'
            . '</style>';
    }
}
