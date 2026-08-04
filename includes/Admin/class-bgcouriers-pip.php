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
     * @param string    $method  Whatever PIP was going to print.
     * @param string    $type    Document type (invoice / packing-list / pick-list).
     * @param \WC_Order $order   The order.
     * @param string    $waybill Appended after the delivery type when given, so the heading can say
     *                           courier, destination type and shipment number on ONE line.
     * @return string
     */
    public function shipping_method($method, $type = '', $order = null, string $waybill = '') {
        if (!$order instanceof \WC_Order) { return $method; }
        $courier = BGCouriers_Labels::order_courier($order);
        if (!$courier) { return $method; }

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
     * Two lines where PIP printed four.
     *
     * PIP emits the document title as one heading and its date as another beneath it; we then added a
     * waybill line and a courier line, and the four of them stacked into a block taller than the table
     * they introduce. The date belongs with the number it dates - "Стокова разписка 6246 от 03/08/26" -
     * and courier, destination type and shipment number are one fact about one parcel, not three.
     *
     * @param string    $heading The heading HTML PIP built.
     * @param string    $type    Document type.
     * @param string    $action  'print' or 'send_email'.
     * @param \WC_Order $order   The order.
     * @return string
     */
    public function heading($heading, $type = '', $action = '', $order = null) {
        if (!$order instanceof \WC_Order) { return $heading; }

        // Fold PIP's separate date heading into the title. The date is taken from the order rather than
        // scraped out of the markup: the rendered string is localised ("Дата: 03/08/2026") and reading it
        // back would be parsing our own output. Short year - the sheet is read the week it is printed.
        $date = $order->get_date_created('edit');
        if ($date) {
            $heading = (string) preg_replace('#<h5[^>]*class="[^"]*order-date[^"]*"[^>]*>.*?</h5>#s', '', $heading);
            $heading = (string) preg_replace_callback(
                '#(<h3[^>]*class="[^"]*order-info[^"]*"[^>]*>)(.*?)(</h3>)#s',
                static function (array $m) use ($date): string {
                    /* translators: %s: the document's date, appended to its title - "Invoice 6246 of 03/08/26" */
                    return $m[1] . rtrim($m[2]) . ' ' . esc_html(sprintf(__('of %s', 'bg-couriers'), $date->date_i18n('d/m/y'))) . $m[3];
                },
                $heading,
                1
            );
        }

        // One line for the parcel: who carries it, where it is going, and its number. The courier is
        // repeated here (it is also in the totals) because the totals sit on the half that gets folded
        // away, and whoever holds the folded sheet has to know which pile the parcel joins.
        $waybill = (string) $order->get_meta('_bgcouriers_waybill');
        $line = ($waybill !== '' || BGCouriers_Labels::order_courier($order))
            ? '<div class="bgc-pip-courier">' . $this->shipping_method('', $type, $order, $waybill) . '</div>'
            : '';

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
            . '.bgc-pip-num{color:#555;letter-spacing:.02em;}'
            // The document number, its date and the waybill sit over the LEFT HALF rather than the middle
            // of the page: the sheet is folded before it goes on the parcel, and this is the part that
            // ends up face-up. Centred on the page, half of it disappears into the fold.
            // Anything inside our heading block centres on that block, not on the page.
            . '.bgc-pip-head, .bgc-pip-head *{text-align:center;}'
            . '.bgc-pip-courier{margin-top:2px;font-size:12px;}'
            // The title now carries the date too, so it is the only heading left in this block - give it
            // its own line and nothing more. PIP derives every heading size from one setting, which put
            // this at 22px: it was the largest thing on a sheet whose job is the table below it. Sized
            // here so the block reads as a hierarchy - title 14, courier 12, addresses and notes 10.
            . '.bgc-pip-head h3{font-size:14px;margin:0 0 .1em;line-height:1.2;}'
            . '.bgc-pip-head h3, .bgc-pip-head p{margin-left:0;margin-right:0;}'

            . self::header_css()
            . '</style>';
    }

    /**
     * Shrink the header - the recipient's address, the sender's, and the gap they sit in.
     *
     * Both addresses are wrapped in <h5>, and PIP sizes EVERY heading from one setting: with the shop's
     * 26 that makes h5 14px at 150% line-height against 12px body text, plus 0.5em margins on each line.
     * Two addresses of six or seven lines then filled a quarter of the sheet before the first product -
     * on the half that stays face-up once the sheet is folded onto the parcel.
     *
     * Applied to BOTH document types on purpose. The Bulgarian "Стокова разписка" this shop hands to the
     * courier is PIP's INVOICE type - the title is just how "Invoice" is translated - so scoping this to
     * .packing-list-header, which is what the name suggests, styled a document the shop never prints.
     *
     * @return string
     */
    private static function header_css(): string {
        // Written once and applied to each document type, rather than repeating the block per type.
        $scopes = ['.invoice-header', '.packing-list-header'];
        $rule = static function (string $selectors, string $decls) use ($scopes): string {
            $out = [];
            foreach ($scopes as $s) {
                foreach (explode(',', $selectors) as $sel) { $out[] = $s . ' ' . trim($sel); }
            }
            return implode(',', $out) . '{' . $decls . '}';
        };

        return
            // The addresses themselves, the company subtitle/VAT lines and the shipping method.
            $rule('.customer-address h5, .company-title h5, .company-subtitle, .company-vat-number, .company-address, em.shipping-method',
                  'font-size:10px;line-height:1.25;margin:0 0 .1em;')
            // Nothing in either address is emphasis - they are both just an address, and <h5> renders
            // bold by default, which is what made the sender's block shout across the top of the sheet.
            . $rule('.customer-address h5, .company-title h5, .company-subtitle, .company-vat-number', 'font-weight:400;')
            . $rule('.shipping-method h3', 'font-size:10px;margin:.25em 0 .1em;')
            // PIP sets this one INLINE in its own template, so nothing but !important reaches it.
            . $rule('.company-information', 'margin-bottom:.3em !important;')
            // And the standing 2em above and below the document title, which put another empty third of
            // an inch between the addresses and the thing they belong to.
            . '.document-heading{margin:.6em 0 .4em !important;}'

            // ---- the small print BELOW the table -------------------------------------------------
            // The VAT-exemption note, who drew the document up, who it was handed to, and the delivery
            // date row - all of it reference text nobody reads while packing, printed at full body size
            // under a table that has already said everything. Same 10px as the header, so the sheet has
            // one size for its data and one for its notes.
            // Not scoped by document type: this stylesheet only ever prints inside a PIP document.
            . '.document-colophon,.document-colophon *,'
            . '.customer-details-wrapper,.customer-details,.customer-details li,'
            . '.customer-note,.customer-note blockquote'
            . '{font-size:10px;line-height:1.3;}'
            . '.customer-details-wrapper h3{font-size:10px;margin:.4em 0 .1em;}'
            . '.customer-details{margin:0;padding-left:0;list-style:none;}'
            . '.document-colophon{margin-top:.5em;}';
    }
}
