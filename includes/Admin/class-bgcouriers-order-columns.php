<?php
defined('ABSPATH') || exit;

class BGCouriers_Order_Columns {
    /** Default row colour per courier; saturated, the row gets a pale version of it (TINT_ALPHA). */
    const ROW_COLORS = [
        'speedy'  => '#d63638', // red
        'econt'   => '#2271b1', // blue
        'pigeon'  => '#00a32a', // green
        'boxnow'  => '#8c4bd6', // violet
        'sameday' => '#e08a00', // amber
        'expressone' => '#E0189B', // magenta
        'evropat' => '#FFCC00', // yellow
    ];
    const TINT_ALPHA       = 0.13;
    const TINT_ALPHA_HOVER = 0.20;

    public function __construct() {
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'col']);
        add_filter('manage_edit-shop_order_columns', [$this, 'col']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render'], 10, 2);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_legacy'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('woocommerce_shop_order_list_table_order_css_classes', [$this, 'row_classes'], 10, 2);
        add_filter('post_class', [$this, 'legacy_row_classes'], 10, 3);
    }

    public function row_classes($classes, $order) {
        $cid = $order instanceof \WC_Order ? (string) $order->get_meta('_bgcouriers_courier') : '';
        if ($cid !== '') { $classes[] = 'bgc-courier-' . sanitize_html_class($cid); }
        return $classes;
    }

    /** post_class also fires for every front-end post: bail unless this is an admin shop_order. */
    public function legacy_row_classes($classes, $class = '', $post_id = 0) {
        if (!is_admin() || !$post_id || get_post_type($post_id) !== 'shop_order') { return $classes; }
        $order = function_exists('wc_get_order') ? wc_get_order($post_id) : null;
        $cid   = $order instanceof \WC_Order ? (string) $order->get_meta('_bgcouriers_courier') : '';
        if ($cid !== '') { $classes[] = 'bgc-courier-' . sanitize_html_class($cid); }
        return $classes;
    }

    /** Per-courier row tints as CSS; on the cells, not the row, because WP striping paints the <tr>. */
    public static function row_tint_css(): string {
        if (get_option('bgcouriers_row_tint', 'yes') !== 'yes') { return ''; }
        $css = '';
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            $rgb = self::hex_to_rgb((string) get_option('bgcouriers_' . $cid . '_row_color', self::ROW_COLORS[$cid] ?? ''));
            if ($rgb === null) { continue; }
            $sel  = '.wp-list-table tr.bgc-courier-' . sanitize_html_class($cid);
            $rgb  = implode(',', $rgb);
            $css .= $sel . ' > td,' . $sel . ' > th{background-color:rgba(' . $rgb . ',' . self::TINT_ALPHA . ');}';
            $css .= $sel . ':hover > td,' . $sel . ':hover > th{background-color:rgba(' . $rgb . ',' . self::TINT_ALPHA_HOVER . ');}';
        }
        return $css;
    }

    /**
     * [r,g,b] for #rgb / #rrggbb, null otherwise; also the escaping gate for the inline stylesheet.
     * @return int[]|null
     */
    private static function hex_to_rgb(string $hex): ?array {
        $hex = trim($hex);
        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $hex)) { return null; }
        $h = substr($hex, 1);
        if (strlen($h) === 3) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
    }
    public function col($cols) { $cols['bgcouriers_shipping'] = __('Waybill', 'bg-couriers'); return $cols; }


    /** @var array<string,string> Stage colours; unclaimed is the brighter red because it still needs action. */
    const STAGE_COLORS = ['registered' => '#8c8f94', 'transit' => '#2271b1', 'ready' => '#b26b00',
        'unclaimed' => '#d63638', 'delivered' => '#00814f', 'returning' => '#b32d2e', 'returned' => '#b32d2e',
        'cancelled' => '#6b7280'];

    public static function status_html(\WC_Order $order): string {
        $text  = trim((string) $order->get_meta('_bgcouriers_track_text'));
        $stage = (string) $order->get_meta('_bgcouriers_track_stage');
        if ($stage === '' && $text === '') { return ''; }
        // The stamp is when the courier's answer last changed, not when it was last asked.
        $tip = BGCouriers_Tracking::stage_tip($stage, $text, (int) $order->get_meta('_bgcouriers_track_updated'));
        return self::stage_icon($stage, $tip);
    }

    /** The one stage indicator both screens draw; colour from the class, never inline (kses strips style). */
    public static function stage_icon(string $stage, string $tip = '', int $size = 15): string {
        $cls   = 'bgc-track-ico bgc-stage-' . sanitize_html_class($stage ?: 'transit');
        $glyph = BGCouriers_Icons::stage($stage, $size);
        if ($glyph === '') { $glyph = BGCouriers_Icons::stage('transit', $size); }
        $attrs = $tip !== ''
            ? ' data-tip="' . esc_attr($tip) . '" aria-label="' . esc_attr($tip) . '"'
            : ' aria-hidden="true"';
        return '<span class="' . esc_attr($cls) . '"' . $attrs . '>' . $glyph . '</span>';
    }

    /** .bgc-stage-* colour rules from STAGE_COLORS; a class survives kses, an inline style does not. */
    public static function stage_color_css(): string {
        $out = '';
        foreach (self::STAGE_COLORS as $stage => $color) {
            $out .= '.bgc-track-ico.bgc-stage-' . sanitize_html_class($stage) . '{color:' . $color . ';}';
        }
        return $out;
    }

    /** Red for a waybill the order has outgrown; a class so it survives kses. */
    public static function state_color_css(): string {
        return '.bgc-wb-flag{margin-left:2px;}'
            . '.bgc-wb-flag.bgc-wb-stale{color:#dc2626;}';
    }

    public static function cell_html(string $waybill, string $print_url, string $track_url, string $generate_url, int $order_id = 0, string $cancel_nonce = '', string $generate_nonce = '', string $courier_label = '', string $courier_logo = '', string $regenerate_url = '', $order = null): string {
        $locked = ($order instanceof \WC_Order) && BGCouriers_Labels::is_locked($order);
        $status = ($order instanceof \WC_Order) ? self::status_html($order) : '';
        $state_ico = '';
        $unprinted = false;
        if ($order instanceof \WC_Order) {
            $state = BGCouriers_Labels::label_state($order);
            if ($state === BGCouriers_Labels::LABEL_STATE_STALE) {
                $smsg = BGCouriers_Labels::label_state_message($order, $state);
                $state_ico = '<span class="bgc-wb-flag bgc-wb-' . esc_attr($state) . '" data-tip="' . esc_attr($smsg)
                    . '" aria-label="' . esc_attr($smsg) . '"><span class="dashicons dashicons-warning"></span></span>';
            }
            $unprinted = $state === BGCouriers_Labels::LABEL_STATE_UNPRINTED;
        }
        $logo_tile = $courier_logo !== ''
            ? '<span class="bgc-ltile" data-tip="' . esc_attr($courier_label) . '"><img class="bgc-clogo" src="' . esc_url($courier_logo) . '" alt="' . esc_attr($courier_label) . '"></span>'
            : '';
        $edit_ico = '';
        if ($order_id && function_exists('wc_get_order')) {
            $o = wc_get_order($order_id);
            if ($o) {
                $edit_tip = $locked ? BGCouriers_Labels::locked_message() : __('Edit delivery details', 'bg-couriers');
                $edit_ico = '<a class="bgc-ico bgc-edit-lnk' . ($locked ? ' bgc-off' : '') . '"'
                    . ($locked ? ' aria-disabled="true"' : ' href="' . esc_url($o->get_edit_order_url() . '#bgc-edit') . '"')
                    . ' data-tip="' . esc_attr($edit_tip) . '" aria-label="' . esc_attr($edit_tip) . '">'
                    . '<span class="dashicons dashicons-edit"></span></a>';
            }
        }
        $regen_ico = '';
        if ($waybill !== '' && $regenerate_url !== '' && !$locked) {
            $regen_tip = __('Re-issue waybill (voids the current one)', 'bg-couriers');
            $regen_ico = '<a class="bgc-ico bgc-regen" href="' . esc_url($regenerate_url) . '" data-tip="' . esc_attr($regen_tip) . '" aria-label="' . esc_attr($regen_tip) . '"><span class="dashicons dashicons-update"></span></a>';
        }
        // The JS cancel-swap replaces row 2 and must also drop .bgc-regen from row 1.
        $row1 = '<span class="bgc-row">' . $logo_tile . $edit_ico . $regen_ico . $status . $state_ico . '</span>';
        if ($waybill === '') {
            return '<span class="bgc-cell">' . $row1
                . '<span class="bgc-row"><a class="button button-small bgc-gen" href="' . esc_url($generate_url) . '">' . esc_html__('Generate', 'bg-couriers') . '</a></span></span>';
        }
        /* translators: %s: waybill number */
        $copy_label = sprintf(__('Copy waybill %s', 'bg-couriers'), $waybill);
        return '<span class="bgc-cell">' . $row1 . '<span class="bgc-row">'
            . '<button type="button" class="bgc-ico bgc-copy" data-wb="' . esc_attr($waybill) . '" data-tip="' . esc_attr($waybill) . '" aria-label="' . esc_attr($copy_label) . '"><span class="dashicons dashicons-admin-page"></span></button>'
            . '<a class="bgc-ico bgc-primary' . ($unprinted ? ' bgc-unprinted' : '') . '" target="_blank" href="' . esc_url($print_url) . '" data-tip="' . esc_attr(BGCouriers_Labels::print_tip($unprinted)) . '" aria-label="' . esc_attr(BGCouriers_Labels::print_tip($unprinted)) . '"><span class="dashicons dashicons-printer"></span></a>'
            . '<a class="bgc-ico" target="_blank" href="' . esc_url($track_url) . '" data-tip="' . esc_attr__('Track shipment', 'bg-couriers') . '" aria-label="' . esc_attr__('Track shipment', 'bg-couriers') . '"><span class="dashicons dashicons-location"></span></a>'
            . ($locked ? '' : '<a href="#" class="bgc-ico bgc-danger bgc-wb-cancel" data-id="' . (int) $order_id . '" data-nonce="' . esc_attr($cancel_nonce) . '" data-gennonce="' . esc_attr($generate_nonce) . '" data-tip="' . esc_attr__('Cancel waybill', 'bg-couriers') . '" aria-label="' . esc_attr__('Cancel waybill', 'bg-couriers') . '"><span class="dashicons dashicons-no-alt"></span></a>')
            . '</span></span>';
    }

    public function enqueue_assets(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['woocommerce_page_wc-orders', 'edit-shop_order'], true)) { return; }
        $css = BGCOURIERS_PATH . 'assets/css/bgc-orders-list.css';
        $js  = BGCOURIERS_PATH . 'assets/js/bgc-orders-list.js';
        wp_enqueue_style('bgc-orders-list', BGCOURIERS_URL . 'assets/css/bgc-orders-list.css', [], is_file($css) ? (string) filemtime($css) : BGCOURIERS_VERSION);
        BGCouriers_Tips::enqueue();
        $tint = self::row_tint_css() . self::stage_color_css() . self::state_color_css();
        if ($tint !== '') { wp_add_inline_style('bgc-orders-list', $tint); }
        wp_enqueue_script('bgc-orders-list', BGCOURIERS_URL . 'assets/js/bgc-orders-list.js', [], is_file($js) ? (string) filemtime($js) : BGCOURIERS_VERSION, true);
        wp_localize_script('bgc-orders-list', 'BGCOURIERS_LIST', [
            'i18n' => [
                'confirmTitle' => __('Cancel this waybill?', 'bg-couriers'),
                'confirmBody'  => __('This voids the shipment label with the courier. This cannot be undone.', 'bg-couriers'),
                'yes'          => __('Yes, cancel it', 'bg-couriers'),
                'no'           => __('Keep it', 'bg-couriers'),
                'cancelled'    => __('Waybill cancelled', 'bg-couriers'),
                'copied'       => __('Copied to clipboard', 'bg-couriers'),
                'gen'          => __('Generate', 'bg-couriers'),
                'print'        => BGCouriers_Labels::print_tip(false),
                'err'          => __('Could not cancel.', 'bg-couriers'),
                'regenTitle'   => __('Re-issue this waybill?', 'bg-couriers'),
                'regenBody'    => __('The current waybill is voided with the courier and a new one is issued from this order\'s current delivery details, products and settings. This cannot be undone.', 'bg-couriers'),
                'regenYes'     => __('Yes, re-issue it', 'bg-couriers'),
            ],
            'group' => ['label' => 'BG Couriers', 'actions' => BGCouriers_Bulk_Labels::actions()],
            'printActions' => BGCouriers_Bulk_Labels::print_actions(),
            'confirmBulk' => [
                BGCouriers_Bulk_Labels::CANCEL => [
                    'title' => __('Cancel the selected waybills?', 'bg-couriers'),
                    'body'  => __('This voids the shipment label with the courier for every selected order. This cannot be undone.', 'bg-couriers'),
                    'yes'   => __('Yes, cancel them', 'bg-couriers'),
                    'no'    => __('Keep them', 'bg-couriers'),
                ],
                BGCouriers_Bulk_Labels::REGEN => [
                    'title' => __('Re-issue the selected waybills?', 'bg-couriers'),
                    'body'  => __('Every selected order ends up with a fresh waybill, issued from that order\'s current delivery details, products and settings. Where a waybill already exists it is voided with the courier first. This cannot be undone.', 'bg-couriers'),
                    'yes'   => __('Yes, re-issue them', 'bg-couriers'),
                    'no'    => __('Keep them', 'bg-couriers'),
                ],
            ],
        ]);
    }

    public function render($column, $order): void {
        if ($column !== 'bgcouriers_shipping' || !$order) { return; }
        $courier = BGCouriers_Labels::order_courier($order);
        if (!$courier) { echo '-'; return; }
        $id = $order->get_id();
        $base = admin_url('admin-post.php');
        $print = wp_nonce_url($base . '?action=bgcouriers_print_batch&order_id=' . $id, 'bgcouriers_print_batch');
        $track = wp_nonce_url($base . '?action=bgcouriers_track&order_id=' . $id, 'bgcouriers_track_' . $id);
        $gen   = wp_nonce_url($base . '?action=bgcouriers_generate_label&order_id=' . $id, 'bgcouriers_generate_label_' . $id);
        $regen = wp_nonce_url($base . '?action=bgcouriers_regenerate&order_id=' . $id, 'bgcouriers_regenerate_' . $id);
        // wp_kses with our own allow-list: wp_kses_post would strip the data-* the buttons need.
        echo wp_kses(self::cell_html(
            (string) $order->get_meta('_bgcouriers_waybill'), $print, $track, $gen, $id,
            wp_create_nonce('bgcouriers_cancel_label_' . $id), wp_create_nonce('bgcouriers_generate_label_' . $id),
            $courier->label(), BGCouriers_Couriers::logo_url($courier->id()), $regen, $order
        ), BGCouriers_Kses::admin_actions());
    }
    public function render_legacy($column, $post_id): void {
        if ($column !== 'bgcouriers_shipping') { return; }
        $this->render('bgcouriers_shipping', wc_get_order($post_id));
    }
}
