<?php
defined('ABSPATH') || exit;

class BGC_Order_Columns {
    public function __construct() {
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'col']);
        add_filter('manage_edit-shop_order_columns', [$this, 'col']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render'], 10, 2);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_legacy'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
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
            'bulkCancel' => BGC_Bulk_Labels::CANCEL,
            'bulk' => [
                'title' => __('Cancel the selected waybills?', 'bg-couriers'),
                'body'  => __('This voids the shipment label with the courier for every selected order. This cannot be undone.', 'bg-couriers'),
                'yes'   => __('Yes, cancel them', 'bg-couriers'),
                'no'    => __('Keep them', 'bg-couriers'),
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
