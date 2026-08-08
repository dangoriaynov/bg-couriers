<?php
/**
 * dobavki.club - print layout for Print Invoices/Packing Lists.
 * ---------------------------------------------------------------------------------------------------
 * NOT PART OF THE bg-couriers PLUGIN. This file is kept in the bg-couriers repository only so that it
 * survives, and stays reviewable, between PIP updates. It belongs to the shop, and it lives inside the
 * PIP plugin because it does one thing: arrange the sheet PIP prints.
 *
 * WHERE IT GOES
 *   wp-content/plugins/woocommerce-pip/dobavki-print-layout.php
 * plus one line at the end of wp-content/plugins/woocommerce-pip/woocommerce-pip.php:
 *
 *   // dobavki.club: shop print layout, kept in the bg-couriers repo at docs/pip/. See that file.
 *   require_once __DIR__ . '/dobavki-print-layout.php';
 *
 * AFTER A PIP UPDATE both are gone - the updater replaces the whole plugin folder. Copy this file back
 * and re-add that one require. Nothing else in PIP is touched, so that is the entire re-apply.
 *
 * WHAT IT DOES, and why each piece is here rather than in the courier plugin:
 *   1. Stops the document heading printing twice   - a PIP bug, see below.
 *   2. Folds the document date into its title      - two lines where PIP prints four.
 *   3. Puts the courier and waybill in the heading - so the folded half of the sheet carries it.
 *   4. Quantity column before unit price, wider    - what the person packing reads first.
 *   5. Dispatch date onto the shipping row         - it was a stray paragraph under the table.
 *   6. Type sizes for the addresses and small print.
 * The courier block itself comes from bg-couriers (BGCouriers_PIP::courier_block) - what a courier
 * looks like is that plugin's business; where it sits on this shop's sheet is this file's.
 */

defined('ABSPATH') || exit;

/**
 * 1. One heading per document.
 *
 * PIP hooks its own document_header() on wc_pip_header from EVERY document object it builds, and never
 * unhooks the ones it throws away. wc_pip()->get_document() keeps just one object cached, so anything
 * that asks it for a different order mid-request - PIP's own "generate the invoice number when a paid
 * order is saved" callback does exactly that - leaves the previous object hooked and alive. That object
 * then prints the heading a second time for the order it was built for, which is why one sheet of a
 * bulk print came out with its title, date and courier line stamped on it twice.
 *
 * Both copies are byte-identical (document_header reads the order being rendered, not the one its
 * object remembers), so nothing is lost by dropping the strays: only the document actually being
 * rendered is allowed to print its own header.
 */
add_action('wc_pip_header', function ($type, $action, $document, $order) {
    global $wp_filter;
    if (!isset($wp_filter['wc_pip_header'])) { return; }
    // foreach copies the array, so removing from the live hook while walking it is safe.
    foreach ($wp_filter['wc_pip_header']->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $callback) {
            $fn = $callback['function'];
            if (is_array($fn) && isset($fn[0], $fn[1]) && is_object($fn[0])
                && $fn[1] === 'document_header'
                && $fn[0] instanceof WC_PIP_Document
                && $fn[0] !== $document) {
                remove_action('wc_pip_header', $fn, $priority);
            }
        }
    }
}, 0, 4);

/**
 * 2 + 3. Two lines where PIP printed four, over the half of the sheet that stays face-up.
 *
 * PIP emits the document title as one heading and its date as another beneath it; the waybill and the
 * courier made two more, and the four of them stacked into a block taller than the table they
 * introduce. The date belongs with the number it dates - "Стокова разписка 6246 от 03/08/26" - and
 * courier, destination type and shipment number are one fact about one parcel, not three.
 *
 * The date is taken from the order rather than scraped out of the markup: the rendered string is
 * localised ("Дата: 03/08/2026") and reading it back would mean parsing PIP's own output. Short year -
 * the sheet is read the week it is printed.
 */
add_filter('wc_pip_document_heading', function ($heading, $type = '', $action = '', $order = null) {
    if (!$order instanceof WC_Order) { return $heading; }

    $date = $order->get_date_created('edit');
    if ($date) {
        $heading = (string) preg_replace('#<h5[^>]*class="[^"]*order-date[^"]*"[^>]*>.*?</h5>#s', '', $heading);
        $heading = (string) preg_replace_callback(
            '#(<h3[^>]*class="[^"]*order-info[^"]*"[^>]*>)(.*?)(</h3>)#s',
            static function (array $m) use ($date): string {
                return $m[1] . rtrim($m[2]) . ' от ' . esc_html($date->date_i18n('d/m/y')) . $m[3];
            },
            $heading,
            1
        );
    }

    // One line for the parcel: who carries it, where it is going, and its number. The courier is
    // repeated here (it is also in the totals) because the totals sit on the half that gets folded
    // away, and whoever holds the folded sheet has to know which pile the parcel joins.
    $line = '';
    if (class_exists('BGCouriers_PIP')) {
        $block = BGCouriers_PIP::courier_block($order, (string) $order->get_meta('_bgcouriers_waybill'));
        if ($block !== '') { $line = '<div class="bgc-pip-courier">' . $block . '</div>'; }
    }

    // Geometry INLINE rather than left to a stylesheet rule. The document number, its date and the
    // waybill have to sit over the left half - that is the part that stays face-up once the sheet is
    // folded onto the parcel - and PIP's own centring rules kept winning that fight. An inline style on
    // an element of our own settles it without a specificity war.
    return '<div class="bgc-pip-head" style="width:50%;margin:0 auto 0 0;text-align:center;">'
        . $heading . $line . '</div>';
}, 10, 4);

/**
 * 4. Move the quantity column in front of the unit price.
 *
 * How many of a thing is in the box is what the person packing reads first; what one costs is
 * paperwork. PIP draws the header row, the widths and every cell row from the same array order, so all
 * three get the same reordering - reorder one and the columns lose their labels.
 */
function dobavki_pip_reorder_columns($cols) {
    if (!is_array($cols) || !isset($cols['quantity'], $cols['unit_price'])) { return $cols; }
    $out = [];
    foreach ($cols as $key => $value) {
        if ($key === 'quantity') { continue; }          // placed by its neighbour, below
        if ($key === 'unit_price') { $out['quantity'] = $cols['quantity']; }
        $out[$key] = $value;
    }
    return $out;
}
add_filter('wc_pip_document_table_headers', 'dobavki_pip_reorder_columns', 10, 1);
add_filter('wc_pip_document_table_row_cells', 'dobavki_pip_reorder_columns', 10, 1);

/**
 * Give the product and quantity columns the LEFT HALF of the sheet.
 *
 * The sheet gets folded, and the top-left quarter is what shows. Whoever is filling the box reads
 * exactly two things there - what goes in and how many - so those two columns have to end at the
 * halfway line; the money can live on the half that is folded away.
 */
add_filter('wc_pip_document_column_widths', static function ($widths) {
    if (!is_array($widths)) { return $widths; }
    $widths = dobavki_pip_reorder_columns($widths);
    if (isset($widths['product'], $widths['quantity'])) {
        $widths['product']  = 38;
        $widths['quantity'] = 12;   // 50 together: everything to check the parcel by, before the fold
    }
    foreach (['unit_price' => 22, 'price' => 28] as $k => $v) {
        if (isset($widths[$k])) { $widths[$k] = $v; }
    }
    return $widths;
}, 10, 1);

/**
 * 5. The dispatch date rides on the left of the shipping row.
 *
 * It is one line about the same shipment as the courier beside it, and Order Delivery Date was printing
 * it as its own paragraph under the table.
 */
add_filter('wc_pip_document_table_footer', static function ($rows, $type = '', $order_id = 0) {
    if (!is_array($rows) || empty($rows['shipping'])) { return $rows; }
    $order = wc_get_order((int) $order_id);
    if (!$order instanceof WC_Order) { return $rows; }

    $dispatch = dobavki_pip_dispatch_note($order);
    if ($dispatch === '') { return $rows; }

    $keys  = array_keys($rows['shipping']);
    $label = $keys[0];                       // the wide, right-aligned cell PIP puts the caption in
    $rows['shipping'][$label] = '<span class="bgc-pip-dispatch">' . $dispatch . '</span>' . $rows['shipping'][$label];
    // Order Delivery Date prints this itself right after the table; having moved it, stop it saying the
    // same thing twice. Removed here rather than at load time because this only holds for the document
    // being built.
    remove_action('wc_pip_after_body', ['orddd_integration', 'orddd_plugin_woocommerce_pip'], 10);
    return $rows;
}, 30, 3);   // after bg-couriers (20) has put the courier in the value cell

/**
 * The "Изпращане в: сряда 5 август" line, taken from Order Delivery Date's own data.
 *
 * Built from that plugin's data rather than captured from its output so the two cannot drift apart, and
 * so removing its paragraph is safe: this returns '' unless the date is the ONLY thing it would have
 * printed. When a pickup location or a time slot is also set, its paragraph is left alone and the row
 * stays as it was - moving one of three lines and dropping the other two would lose data.
 */
function dobavki_pip_dispatch_note(WC_Order $order): string {
    if (!class_exists('orddd_common') || !method_exists('orddd_common', 'orddd_get_order_delivery_date')) {
        return '';
    }
    $location = (string) get_option('orddd_location_field_label', '');
    if ($location !== '' && (string) $order->get_meta($location, true) !== '') { return ''; }
    if (method_exists('orddd_common', 'orddd_get_order_timeslot')
        && (string) orddd_common::orddd_get_order_timeslot($order->get_id()) !== '') { return ''; }

    $date = (string) orddd_common::orddd_get_order_delivery_date($order->get_id());
    if ($date === '') { return ''; }
    $label = (string) get_option('orddd_delivery_date_field_label', '');
    return ($label !== '' ? '<strong>' . esc_html($label) . ': </strong>' : '') . esc_html($date);
}

/**
 * 6. Type sizes: shrink the header - the recipient's address, the sender's, and the gap they sit in -
 * and the small print under the table.
 *
 * Both addresses are wrapped in <h5>, and PIP sizes EVERY heading from one setting: with the shop's 26
 * that makes h5 14px at 150% line-height against 12px body text, plus 0.5em margins on each line. Two
 * addresses of six or seven lines then filled a quarter of the sheet before the first product - on the
 * half that stays face-up once the sheet is folded onto the parcel.
 *
 * Applied to BOTH document types on purpose. The Bulgarian "Стокова разписка" this shop hands to the
 * courier is PIP's INVOICE type - the title is just how "Invoice" is translated - so scoping this to
 * .packing-list-header, which is what the name suggests, styled a document the shop never prints.
 */
add_action('wc_pip_before_footer', static function (): void {
    // Written once and applied to each document type, rather than repeating the block per type.
    $scopes = ['.invoice-header', '.packing-list-header'];
    $rule = static function (string $selectors, string $decls) use ($scopes): string {
        $out = [];
        foreach ($scopes as $s) {
            foreach (explode(',', $selectors) as $sel) { $out[] = $s . ' ' . trim($sel); }
        }
        return implode(',', $out) . '{' . $decls . '}';
    };

    echo '<style>'
        // ---- the heading block ---------------------------------------------------------------------
        // Anything inside it centres on that block, not on the page.
        . '.bgc-pip-head, .bgc-pip-head *{text-align:center;}'
        . '.bgc-pip-courier{margin-top:2px;font-size:12px;}'
        // The title now carries the date too, so it is the only heading left in this block - give it its
        // own line and nothing more. PIP derives every heading size from one setting, which put this at
        // 22px: it was the largest thing on a sheet whose job is the table below it. Sized here so the
        // block reads as a hierarchy - title 14, courier 12, addresses and notes 10.
        . '.bgc-pip-head h3{font-size:14px;margin:0 0 .1em;line-height:1.2;}'
        . '.bgc-pip-head h3, .bgc-pip-head p{margin-left:0;margin-right:0;}'

        // ---- the two addresses at the top ------------------------------------------------------------
        . $rule('.customer-address h5, .company-title h5, .company-subtitle, .company-vat-number, .company-address, em.shipping-method',
                'font-size:12px;line-height:1.3;margin:0 0 .1em;')
        // Nothing in either address is emphasis - they are both just an address, and <h5> renders bold
        // by default, which is what made the sender's block shout across the top of the sheet.
        . $rule('.customer-address h5, .company-title h5, .company-subtitle, .company-vat-number', 'font-weight:400;')
        . $rule('.shipping-method h3', 'font-size:12px;margin:.25em 0 .1em;')
        // PIP sets this one INLINE in its own template, so nothing but !important reaches it.
        . $rule('.company-information', 'margin-bottom:.3em !important;')
        // And the standing 2em above and below the document title, which put another empty third of an
        // inch between the addresses and the thing they belong to.
        . '.document-heading{margin:1.4em 0 .5em !important;}'

        // ---- the small print BELOW the table ---------------------------------------------------------
        // The VAT-exemption note, who drew the document up, who it was handed to, and the delivery date
        // row - all of it reference text nobody reads while packing, printed at full body size under a
        // table that has already said everything. Same 10px throughout, so the sheet has one size for
        // its data and one for its notes.
        . '.document-colophon,.document-colophon *,'
        . '.customer-details-wrapper,.customer-details,.customer-details li,'
        . '.customer-note,.customer-note blockquote'
        . '{font-size:10px;line-height:1.3;}'
        . '.customer-details-wrapper h3{font-size:10px;margin:.4em 0 .1em;}'
        . '.customer-details{margin:0;padding-left:0;list-style:none;}'
        . '.document-colophon{margin-top:.5em;}'
        // Floated because the cell it shares is right-aligned: the caption keeps the right, the dispatch
        // date takes the left, and the row stays one row.
        . '.bgc-pip-dispatch{float:left;font-weight:400;text-align:left;}'
        . '</style>';
});
