<?php
defined('ABSPATH') || exit;

/** Renders the shipment panel (waybill + generate/print/track) at the TOP of a BG Couriers order. */
class BGC_Order_Metabox {
    public function __construct() {
        // The order-data panel (after the shipping address) - visible at the top, both HPOS + legacy.
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'render'], 10, 1);
    }

    public function render($order): void {
        $courier = BGC_Labels::order_courier($order); // any BG Couriers order, not just Speedy
        if (!$courier) { return; }
        $id      = $order->get_id();
        $waybill = (string) $order->get_meta('_bgc_waybill');
        $method  = (string) $order->get_meta('_bgc_method');
        $base    = admin_url('admin-post.php');

        $mlabels = ['office' => __('To office', 'bg-couriers'), 'address' => __('To address', 'bg-couriers'), 'automat' => __('To APS', 'bg-couriers')];
        $mlabel  = $mlabels[$method] ?? ucfirst($method ?: 'office');
        echo '<style>'
            . '.bgc-order-panel{margin-top:12px;padding:16px;border:1px solid #e2e6ea;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06);}'
            . '.bgc-order-panel .bgc-hd{display:flex;align-items:center;gap:8px;margin:0 0 14px;}'
            . '.bgc-order-panel .bgc-hd b{font-size:14px;color:#1d2327;}'
            . '.bgc-order-panel .bgc-chip{display:inline-block;padding:3px 11px;border-radius:999px;background:#eef2f7;color:#3c434a;font-size:12px;font-weight:600;}'
            . '.bgc-order-panel .bgc-wbline{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px;}'
            . '.bgc-order-panel .bgc-wbline strong{color:#50575e;}'
            . '.bgc-order-panel .bgc-wb{padding:7px 12px;border-radius:9px;background:#f0f6fc;border:1px solid #d7e3f1;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:#1d2327;}'
            // One cohesive button system for the panel; !important to beat the admin theme's own button styles.
            . '.bgc-order-panel .button{height:38px!important;min-height:38px!important;box-sizing:border-box!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;margin:0!important;padding:0 16px!important;border:1px solid #c3c9d0!important;border-radius:9px!important;background:#fff!important;color:#2b3440!important;font-size:13px!important;font-weight:600!important;line-height:1!important;box-shadow:none!important;text-decoration:none!important;transition:background .12s,border-color .12s,box-shadow .12s;}'
            . '.bgc-order-panel .button:hover{background:#f6f7f9!important;border-color:#a7b0ba!important;box-shadow:0 1px 2px rgba(0,0,0,.06)!important;}'
            . '.bgc-order-panel .button:focus{box-shadow:0 0 0 2px rgba(34,113,177,.35)!important;}'
            . '.bgc-order-panel .button.button-primary{background:#2271b1!important;border-color:#2271b1!important;color:#fff!important;}'
            . '.bgc-order-panel .button.button-primary:hover{background:#1c5d92!important;border-color:#1c5d92!important;}'
            . '.bgc-order-panel .bgc-la{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}'
            . '.bgc-order-panel .bgc-icon{width:38px!important;padding:0!important;}'
            . '.bgc-order-panel .bgc-icon .dashicons{font-size:18px;width:18px;height:18px;line-height:1;}'
            . '.bgc-order-panel .bgc-void{color:#b32d2e!important;border-color:#e6a2a5!important;}'
            . '.bgc-order-panel .bgc-void:hover{background:#fcecec!important;border-color:#cf6a6f!important;color:#8a1f2b!important;}'
            . '.bgc-order-panel .bgc-wb-copy.done{color:#1a7f37!important;border-color:#9ad3ab!important;}'
            . '.bgc-order-panel .bgc-ed{margin-top:14px;border-top:1px solid #eef0f2;padding-top:12px;}'
            . '</style>';
        echo '<div class="bgc-order-panel">';
        echo '<p class="bgc-hd"><b>' . esc_html($courier->label()) . '</b> <span class="bgc-chip">' . esc_html($mlabel) . '</span></p>';

        // Surface the last generation error (handle_generate stores it in a transient, then redirects here),
        // so a failing create_label no longer looks like "nothing happened".
        $err = get_transient('bgc_admin_error_' . $id);
        if ($err) {
            delete_transient('bgc_admin_error_' . $id);
            /* translators: %s: error message from the courier */
            echo '<div style="margin:0 0 8px;padding:8px 10px;border-radius:6px;background:#fcf0f1;border:1px solid #e6a2a5;color:#8a1f2b;">'
                . esc_html(sprintf(__('Label generation failed: %s', 'bg-couriers'), $err)) . '</div>';
        }

        $nonce_url = static function (string $action, string $nonce) use ($base, $id): string {
            return esc_url(wp_nonce_url($base . '?action=' . $action . '&order_id=' . $id, $nonce . $id));
        };
        $confirm = static function (string $msg): string { return ' onclick="return confirm(\'' . esc_js($msg) . '\')"'; };

        if ($waybill === '') {
            $gen = $nonce_url('bgc_generate_label', 'bgc_generate_label_');
            echo '<div class="bgc-la"><a class="button button-primary" href="' . $gen . '">' . esc_html__('Generate label', 'bg-couriers') . '</a></div>';
        } else {
            $paper  = strtolower(BGC_Settings::label_paper_size($courier->id()));
            $print  = esc_url(wp_nonce_url($base . '?action=bgc_print_batch&order_id=' . $id . '&paper=' . $paper, 'bgc_print_batch'));
            $track  = $nonce_url('bgc_track', 'bgc_track_');
            $cancel = $nonce_url('bgc_cancel_label', 'bgc_cancel_label_');
            $hint   = esc_attr__('Cancel (void) this shipment label', 'bg-couriers');
            $copy_hint = esc_attr__('Copy waybill number', 'bg-couriers');
            echo '<div class="bgc-wbline"><strong>' . esc_html__('Waybill', 'bg-couriers') . ':</strong> <span class="bgc-wb">' . esc_html($waybill) . '</span>'
                . '<button type="button" class="button bgc-icon bgc-wb-copy" data-wb="' . esc_attr($waybill) . '" title="' . $copy_hint . '" aria-label="' . $copy_hint . '">'
                . '<span class="dashicons dashicons-clipboard"></span></button></div>';
            echo '<div class="bgc-la">';
            echo '<a class="button button-primary" target="_blank" href="' . $print . '">' . esc_html__('Print label', 'bg-couriers') . '</a>';
            echo '<a class="button" target="_blank" href="' . $track . '">' . esc_html__('Track', 'bg-couriers') . '</a>';
            echo '<a class="button bgc-icon bgc-void" href="' . $cancel . '" title="' . $hint . '" aria-label="' . $hint . '"'
                . $confirm(__('Cancel (void) this shipment label?', 'bg-couriers')) . '><span class="dashicons dashicons-no-alt"></span></a>';
            echo '</div>';
        }
        $this->render_editor($order);
        echo '</div>';
    }

    /** Collapsible checkout-like editor for the order's delivery details (courier switch, city/office/address). */
    private function render_editor(\WC_Order $order): void {
        $cur_courier = (string) $order->get_meta('_bgc_courier');
        $cur_method  = (string) $order->get_meta('_bgc_method');
        $site_id     = (int) $order->get_meta('_bgc_site_id');
        $office_id   = (int) $order->get_meta('_bgc_office_id');
        $has_waybill = (string) $order->get_meta('_bgc_waybill') !== '';

        // Enabled couriers (+ the order's current one even if now disabled), with their delivery options.
        $caps = []; $opts = '';
        foreach (BGC_Couriers::all() as $cid => $clabel) {
            if (get_option('bgc_' . $cid . '_enabled', 'no') !== 'yes' && $cid !== $cur_courier) { continue; }
            $co = BGC_Couriers::get($cid);
            $caps[$cid] = $co ? array_values(array_diff($co->capabilities(), ['live_quote'])) : ['office', 'address', 'automat'];
            $opts .= '<option value="' . esc_attr($cid) . '"' . selected($cid, $cur_courier, false) . '>' . esc_html($clabel) . '</option>';
        }
        $city_opt = '';
        if ($site_id && ($city = BGC_Nomenclature::city_by_id($cur_courier, $site_id))) {
            $pc = !empty($city['post_code']) ? ' (' . $city['post_code'] . ')' : '';
            $city_opt = '<option value="' . esc_attr($site_id) . '" selected>' . esc_html($city['name'] . $pc) . '</option>';
        }
        $office_opt = '';
        if ($office_id && ($o = BGC_Nomenclature::office_by_id($cur_courier, $office_id))) {
            $office_opt = '<option value="' . esc_attr($office_id) . '" selected>' . esc_html($o['name'] ?? '') . '</option>';
        }
        $street = (string) $order->get_meta('_bgc_street_name');
        $street_opt = $street !== '' ? '<option value="' . esc_attr($street) . '" selected>' . esc_html($street) . '</option>' : '';
        $v = static function ($k) use ($order) { return esc_attr((string) $order->get_meta('_bgc_' . $k)); };

        wp_enqueue_style('select2');
        wp_enqueue_script('selectWoo');
        $ver = @filemtime(BGC_PATH . 'assets/js/bgc-order-admin.js') ?: '1';
        wp_enqueue_script('bgc-order-admin', BGC_URL . 'assets/js/bgc-order-admin.js', ['jquery', 'selectWoo'], $ver, true);
        wp_localize_script('bgc-order-admin', 'BGC_ED', [
            'ajax'    => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bgc_order_delivery'),
            'orderId' => $order->get_id(),
            'caps'    => $caps,
            'methodLabels' => ['office' => __('To office', 'bg-couriers'), 'address' => __('To address', 'bg-couriers'), 'automat' => __('To APS', 'bg-couriers')],
            'i18n'    => ['city' => __('City', 'bg-couriers'), 'office' => __('Office / APS', 'bg-couriers'), 'street' => __('Street', 'bg-couriers'),
                          'saving' => __('Saving…', 'bg-couriers'), 'err' => __('Could not save.', 'bg-couriers')],
        ]);

        echo '<div class="bgc-ed">';
        echo '<a href="#" class="button bgc-ed-toggle"><span class="dashicons dashicons-edit"></span> ' . esc_html__('Edit delivery details', 'bg-couriers') . '</a>';
        echo '<div class="bgc-ed-form" style="display:none;margin-top:10px;max-width:520px;">';
        echo '<p><label>' . esc_html__('Courier', 'bg-couriers') . '</label><br><select class="bgc-ed-courier" style="min-width:240px;">' . $opts . '</select></p>';
        echo '<p><label>' . esc_html__('Delivery option', 'bg-couriers') . '</label><br><select class="bgc-ed-method" data-current="' . esc_attr($cur_method) . '" style="min-width:240px;"></select></p>';
        echo '<p class="bgc-ed-city-row"><label>' . esc_html__('City', 'bg-couriers') . '</label><br><select class="bgc-ed-city" style="min-width:300px;"><option></option>' . $city_opt . '</select><input type="hidden" class="bgc-ed-postcode" value="' . $v('post_code') . '"></p>';
        echo '<p class="bgc-ed-office-row"><label>' . esc_html__('Office / APS', 'bg-couriers') . '</label><br><select class="bgc-ed-office" style="min-width:300px;"><option></option>' . $office_opt . '</select></p>';
        echo '<div class="bgc-ed-address">';
        echo '<p><label>' . esc_html__('Street', 'bg-couriers') . '</label><br><select class="bgc-ed-street" style="min-width:220px;"><option></option>' . $street_opt . '</select> '
            . '<label>' . esc_html__('No.', 'bg-couriers') . '</label> <input class="bgc-ed-streetno" value="' . $v('street_no') . '" style="width:70px;"></p>';
        echo '<p><label>' . esc_html__('Quarter / complex', 'bg-couriers') . '</label> <input class="bgc-ed-complex" value="' . $v('complex') . '"></p>';
        echo '<p><label>' . esc_html__('Bl.', 'bg-couriers') . '</label> <input class="bgc-ed-block" value="' . $v('block') . '" style="width:60px;"> '
            . '<label>' . esc_html__('Entr.', 'bg-couriers') . '</label> <input class="bgc-ed-entrance" value="' . $v('entrance') . '" style="width:60px;"> '
            . '<label>' . esc_html__('Floor', 'bg-couriers') . '</label> <input class="bgc-ed-floor" value="' . $v('floor') . '" style="width:60px;"> '
            . '<label>' . esc_html__('Apt.', 'bg-couriers') . '</label> <input class="bgc-ed-apartment" value="' . $v('apartment') . '" style="width:60px;"></p>';
        echo '<p><label>' . esc_html__('Note', 'bg-couriers') . '</label> <input class="bgc-ed-note" value="' . $v('address_note') . '" style="width:100%;"></p>';
        echo '</div>';
        echo '<div class="bgc-ed-boxnow">';
        echo '<p><label>' . esc_html__('Locker id', 'bg-couriers') . '</label> <input class="bgc-ed-boxnow-id" value="' . ($cur_courier === 'boxnow' ? esc_attr((string) $office_id) : '') . '" style="width:110px;"> '
            . '<label>' . esc_html__('Locker name', 'bg-couriers') . '</label> <input class="bgc-ed-boxnow-name" value="' . $v('boxnow_name') . '"></p>';
        echo '<p><label>' . esc_html__('Locker address', 'bg-couriers') . '</label> <input class="bgc-ed-boxnow-addr" value="' . $v('boxnow_addr') . '" style="width:100%;"></p>';
        echo '</div>';
        echo '<p><button type="button" class="button button-primary bgc-ed-save">' . esc_html__('Save delivery', 'bg-couriers') . '</button> <span class="bgc-ed-msg"></span></p>';
        if ($has_waybill) {
            echo '<p class="description">' . esc_html__('A waybill exists. If auto-generate is on, saving voids it and re-issues a new one; otherwise void it with × above and Generate again.', 'bg-couriers') . '</p>';
        }
        echo '</div></div>';
    }
}
