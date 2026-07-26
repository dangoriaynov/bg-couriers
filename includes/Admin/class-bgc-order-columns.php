<?php
defined('ABSPATH') || exit;

class BGC_Order_Columns {
    /**
     * Default row colour per courier - distinct hues, deliberately SATURATED: the merchant picks a normal
     * colour and the row is painted with a pale version of it (see TINT_ALPHA), so no one has to hunt for
     * a pastel hex that stays readable behind black text.
     */
    const ROW_COLORS = [
        'speedy'  => '#d63638', // red
        'econt'   => '#2271b1', // blue
        'pigeon'  => '#00a32a', // green
        'boxnow'  => '#8c4bd6', // violet
        'sameday' => '#e08a00', // amber
    ];
    /** How much of the chosen colour actually reaches the row: resting, and under the cursor. */
    const TINT_ALPHA       = 0.13;
    const TINT_ALPHA_HOVER = 0.20;

    public function __construct() {
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'col']);
        add_filter('manage_edit-shop_order_columns', [$this, 'col']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render'], 10, 2);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_legacy'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        // Tag every order row with its courier so the stylesheet can tint it. HPOS has its own filter
        // (WC 7.8+); the legacy post-based table goes through get_post_class().
        add_filter('woocommerce_shop_order_list_table_order_css_classes', [$this, 'row_classes'], 10, 2);
        add_filter('post_class', [$this, 'legacy_row_classes'], 10, 3);
    }

    /** HPOS orders table row classes. */
    public function row_classes($classes, $order) {
        $cid = $order instanceof \WC_Order ? (string) $order->get_meta('_bgc_courier') : '';
        if ($cid !== '') { $classes[] = 'bgc-courier-' . sanitize_html_class($cid); }
        return $classes;
    }

    /**
     * Legacy orders table row classes. post_class also fires for every front-end post, so bail unless this
     * is an admin request for a shop_order - never load an order object on a shop page for nothing.
     */
    public function legacy_row_classes($classes, $class = '', $post_id = 0) {
        if (!is_admin() || !$post_id || get_post_type($post_id) !== 'shop_order') { return $classes; }
        $order = function_exists('wc_get_order') ? wc_get_order($post_id) : null;
        $cid   = $order instanceof \WC_Order ? (string) $order->get_meta('_bgc_courier') : '';
        if ($cid !== '') { $classes[] = 'bgc-courier-' . sanitize_html_class($cid); }
        return $classes;
    }

    /**
     * Per-courier row tints for the orders list, as CSS. Empty when the feature is switched off or no
     * courier has a usable colour. The colour is applied to the cells rather than the row because WP's
     * striping paints the <tr>, and at a low alpha so the row reads as a tint, not a block of colour.
     */
    public static function row_tint_css(): string {
        if (get_option('bgc_row_tint', 'yes') !== 'yes') { return ''; }
        $css = '';
        foreach (array_keys(BGC_Couriers::all()) as $cid) {
            $rgb = self::hex_to_rgb((string) get_option('bgc_' . $cid . '_row_color', self::ROW_COLORS[$cid] ?? ''));
            if ($rgb === null) { continue; } // unusable value -> no rule at all, never a guessed colour
            $sel  = '.wp-list-table tr.bgc-courier-' . sanitize_html_class($cid);
            $rgb  = implode(',', $rgb);
            $css .= $sel . ' > td,' . $sel . ' > th{background-color:rgba(' . $rgb . ',' . self::TINT_ALPHA . ');}';
            $css .= $sel . ':hover > td,' . $sel . ':hover > th{background-color:rgba(' . $rgb . ',' . self::TINT_ALPHA_HOVER . ');}';
        }
        return $css;
    }

    /**
     * [r,g,b] for a #rgb / #rrggbb colour, or null for anything else. This is also the escaping gate for
     * the inline stylesheet - the value comes from an option, so nothing but three integers reaches the CSS.
     *
     * @return int[]|null
     */
    private static function hex_to_rgb(string $hex): ?array {
        $hex = trim($hex);
        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $hex)) { return null; }
        $h = substr($hex, 1);
        if (strlen($h) === 3) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
    }
    public function col($cols) { $cols['bgc_shipping'] = __('Waybill', 'bg-couriers'); return $cols; }

    public static function cell_html(string $waybill, string $print_url, string $track_url, string $generate_url, int $order_id = 0, string $cancel_nonce = '', string $generate_nonce = '', string $courier_label = '', string $courier_logo = '', string $regenerate_url = ''): string {
        // Courier logo tile with a data-tip hover hint, SAME as the order-screen shipment panel header.
        $logo_tile = $courier_logo !== ''
            ? '<span class="bgc-ltile" data-tip="' . esc_attr($courier_label) . '"><img class="bgc-clogo" src="' . esc_url($courier_logo) . '" alt="' . esc_attr($courier_label) . '"></span>'
            : '';
        // The pencil shows in BOTH states (with or without a waybill). #bgc-edit makes the order
        // screen auto-open the delivery-details editor (bgc-order-admin.js), no second click needed.
        $edit_ico = '';
        if ($order_id && function_exists('wc_get_order')) {
            $o = wc_get_order($order_id);
            if ($o) {
                $edit_ico = '<a class="bgc-ico bgc-edit-lnk" href="' . esc_url($o->get_edit_order_url() . '#bgc-edit') . '" data-tip="' . esc_attr__('Edit delivery details', 'bg-couriers') . '" aria-label="' . esc_attr__('Edit delivery details', 'bg-couriers') . '"><span class="dashicons dashicons-edit"></span></a>';
            }
        }
        // Re-issue sits right of the pencil and only exists once a waybill does: one click voids the
        // current waybill and issues a fresh one from the order's CURRENT details and settings, instead
        // of cancel-then-generate. JS confirms first (bgc-orders-list.js) - it voids a real shipment.
        $regen_ico = '';
        if ($waybill !== '' && $regenerate_url !== '') {
            $regen_tip = __('Re-issue waybill (voids the current one)', 'bg-couriers');
            $regen_ico = '<a class="bgc-ico bgc-regen" href="' . esc_url($regenerate_url) . '" data-tip="' . esc_attr($regen_tip) . '" aria-label="' . esc_attr($regen_tip) . '"><span class="dashicons dashicons-update"></span></a>';
        }
        // Two fixed rows: row 1 = courier logo + pencil (always) + re-issue (only with a waybill),
        // row 2 = Generate OR the waybill actions (copy / print / track / cancel). The JS cancel-swap
        // replaces row 2 and must also drop .bgc-regen from row 1, or it outlives its waybill.
        $row1 = '<span class="bgc-row">' . $logo_tile . $edit_ico . $regen_ico . '</span>';
        if ($waybill === '') {
            return '<span class="bgc-cell">' . $row1
                . '<span class="bgc-row"><a class="button button-small bgc-gen" href="' . esc_url($generate_url) . '">' . esc_html__('Generate', 'bg-couriers') . '</a></span></span>';
        }
        // Same tile look and order as the order-screen shipment panel: copy (stands in for the
        // waybill number, which is the panel's copy control) then Print (primary) / Track / Cancel.
        // The cancel link carries the order id + a cancel nonce + a generate nonce; JS voids the
        // waybill over AJAX and swaps row 2 back to Generate.
        /* translators: %s: waybill number */
        $copy_label = sprintf(__('Copy waybill %s', 'bg-couriers'), $waybill);
        return '<span class="bgc-cell">' . $row1 . '<span class="bgc-row">'
            . '<button type="button" class="bgc-ico bgc-copy" data-wb="' . esc_attr($waybill) . '" data-tip="' . esc_attr($waybill) . '" aria-label="' . esc_attr($copy_label) . '"><span class="dashicons dashicons-admin-page"></span></button>'
            . '<a class="bgc-ico bgc-primary" target="_blank" href="' . esc_url($print_url) . '" data-tip="' . esc_attr__('Print label', 'bg-couriers') . '" aria-label="' . esc_attr__('Print label', 'bg-couriers') . '"><span class="dashicons dashicons-printer"></span></a>'
            . '<a class="bgc-ico" target="_blank" href="' . esc_url($track_url) . '" data-tip="' . esc_attr__('Track shipment', 'bg-couriers') . '" aria-label="' . esc_attr__('Track shipment', 'bg-couriers') . '"><span class="dashicons dashicons-location"></span></a>'
            . '<a href="#" class="bgc-ico bgc-danger bgc-wb-cancel" data-id="' . (int) $order_id . '" data-nonce="' . esc_attr($cancel_nonce) . '" data-gennonce="' . esc_attr($generate_nonce) . '" data-tip="' . esc_attr__('Cancel waybill', 'bg-couriers') . '" aria-label="' . esc_attr__('Cancel waybill', 'bg-couriers') . '"><span class="dashicons dashicons-no-alt"></span></a>'
            . '</span></span>';
    }

    /**
     * Orders-list assets: the tile/toast/modal stylesheet + the copy/AJAX-cancel + bulk-confirm
     * script, enqueued only on the order-list screens; all i18n/config passed via BGC_LIST.
     */
    public function enqueue_assets(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['woocommerce_page_wc-orders', 'edit-shop_order'], true)) { return; }
        $css = BGC_PATH . 'assets/css/bgc-orders-list.css';
        $js  = BGC_PATH . 'assets/js/bgc-orders-list.js';
        wp_enqueue_style('bgc-orders-list', BGC_URL . 'assets/css/bgc-orders-list.css', [], is_file($css) ? (string) filemtime($css) : BGC_VERSION);
        // Built here, not at load time: the colours are per-courier options and this is the only place we
        // know the screen is an orders list (and that the handle above is actually enqueued).
        $tint = self::row_tint_css();
        if ($tint !== '') { wp_add_inline_style('bgc-orders-list', $tint); }
        wp_enqueue_script('bgc-orders-list', BGC_URL . 'assets/js/bgc-orders-list.js', [], is_file($js) ? (string) filemtime($js) : BGC_VERSION, true);
        wp_localize_script('bgc-orders-list', 'BGC_LIST', [
            'i18n' => [
                'confirmTitle' => __('Cancel this waybill?', 'bg-couriers'),
                'confirmBody'  => __('This voids the shipment label with the courier. This cannot be undone.', 'bg-couriers'),
                'yes'          => __('Yes, cancel it', 'bg-couriers'),
                'no'           => __('Keep it', 'bg-couriers'),
                'cancelled'    => __('Waybill cancelled', 'bg-couriers'),
                'copied'       => __('Copied to clipboard', 'bg-couriers'),
                'gen'          => __('Generate', 'bg-couriers'),
                'err'          => __('Could not cancel.', 'bg-couriers'),
                'regenTitle'   => __('Re-issue this waybill?', 'bg-couriers'),
                'regenBody'    => __('The current waybill is voided with the courier and a new one is issued from this order\'s current delivery details, products and settings. This cannot be undone.', 'bg-couriers'),
                'regenYes'     => __('Yes, re-issue it', 'bg-couriers'),
            ],
            // Our bulk actions are gathered under one labelled section in the dropdown (see the JS). The
            // exact action values are passed so the JS moves only OUR options, never a prefix guess.
            'group' => ['label' => 'BG Couriers', 'actions' => BGC_Bulk_Labels::actions()],
            // Bulk actions that void live shipments and so must be confirmed before Apply submits,
            // keyed by their action value. Anything not listed here submits straight away.
            'confirmBulk' => [
                BGC_Bulk_Labels::CANCEL => [
                    'title' => __('Cancel the selected waybills?', 'bg-couriers'),
                    'body'  => __('This voids the shipment label with the courier for every selected order. This cannot be undone.', 'bg-couriers'),
                    'yes'   => __('Yes, cancel them', 'bg-couriers'),
                    'no'    => __('Keep them', 'bg-couriers'),
                ],
                BGC_Bulk_Labels::REGEN => [
                    'title' => __('Re-issue the selected waybills?', 'bg-couriers'),
                    'body'  => __('Every selected order ends up with a fresh waybill, issued from that order\'s current delivery details, products and settings. Where a waybill already exists it is voided with the courier first. This cannot be undone.', 'bg-couriers'),
                    'yes'   => __('Yes, re-issue them', 'bg-couriers'),
                    'no'    => __('Keep them', 'bg-couriers'),
                ],
            ],
        ]);
    }

    public function render($column, $order): void {
        if ($column !== 'bgc_shipping' || !$order) { return; }
        $courier = BGC_Labels::order_courier($order); // any BGC courier, not just Speedy
        if (!$courier) { echo '-'; return; }
        $id = $order->get_id();
        $base = admin_url('admin-post.php');
        $print = wp_nonce_url($base . '?action=bgc_print_batch&order_id=' . $id, 'bgc_print_batch');
        $track = wp_nonce_url($base . '?action=bgc_track&order_id=' . $id, 'bgc_track_' . $id);
        $gen   = wp_nonce_url($base . '?action=bgc_generate_label&order_id=' . $id, 'bgc_generate_label_' . $id);
        $regen = wp_nonce_url($base . '?action=bgc_regenerate&order_id=' . $id, 'bgc_regenerate_' . $id);
        // Echoed directly (not wp_kses_post) so the cancel link keeps its data-* attributes; every dynamic
        // field inside cell_html is individually escaped (esc_html / esc_attr / esc_url).
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- cell_html escapes each field internally
        echo self::cell_html(
            (string) $order->get_meta('_bgc_waybill'), $print, $track, $gen, $id,
            wp_create_nonce('bgc_cancel_label_' . $id), wp_create_nonce('bgc_generate_label_' . $id),
            $courier->label(), BGC_Couriers::logo_url($courier->id()), $regen
        );
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    public function render_legacy($column, $post_id): void {
        if ($column !== 'bgc_shipping') { return; }
        $this->render('bgc_shipping', wc_get_order($post_id));
    }
}
