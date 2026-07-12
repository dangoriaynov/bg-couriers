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
            . '.bgc-order-panel .bgc-wb{padding:6px 11px;border-radius:8px;background:#f0f6fc;border:1px solid #d7e3f1;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:#1d2327;letter-spacing:.3px;}'
            // Small inline copy button that sits immediately to the right of the waybill number.
            . '.bgc-order-panel .bgc-copy{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;padding:0;border:1px solid #d0d5dc;border-radius:7px;background:#fff;color:#646970;cursor:pointer;transition:all .12s;}'
            . '.bgc-order-panel .bgc-copy:hover{background:#f2f6fb;border-color:#9fb6cf;color:#2271b1;}'
            . '.bgc-order-panel .bgc-copy svg{width:15px;height:15px;display:block;}'
            . '.bgc-order-panel .bgc-copy.done{background:#eaf7ee;border-color:#8fcea5;color:#1a7f37;}'
            // One cohesive row of icon-only actions (print / track / edit / cancel), 40px squares.
            . '.bgc-order-panel .bgc-la{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}'
            . '.bgc-order-panel .bgc-act{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;padding:0;margin:0;border:1px solid #c9ced6;border-radius:10px;background:#fff;color:#2b3440;cursor:pointer;text-decoration:none;box-shadow:none;transition:all .12s;}'
            . '.bgc-order-panel .bgc-act:hover{background:#f4f6f9;border-color:#a2acb8;box-shadow:0 1px 2px rgba(0,0,0,.07);}'
            . '.bgc-order-panel .bgc-act:focus{outline:none;box-shadow:0 0 0 2px rgba(34,113,177,.35);}'
            . '.bgc-order-panel .bgc-act .dashicons{font-size:19px;width:19px;height:19px;line-height:1;}'
            . '.bgc-order-panel .bgc-act.bgc-primary{background:#2271b1;border-color:#2271b1;color:#fff;}'
            . '.bgc-order-panel .bgc-act.bgc-primary:hover{background:#1c5d92;border-color:#1c5d92;}'
            . '.bgc-order-panel .bgc-act.bgc-danger{color:#b32d2e;border-color:#e6a2a5;}'
            . '.bgc-order-panel .bgc-act.bgc-danger:hover{background:#fcecec;border-color:#cf6a6f;color:#8a1f2b;}'
            . '.bgc-order-panel .bgc-act.bgc-gen{width:auto;padding:0 16px;gap:7px;font-size:13px;font-weight:600;}'
            // Hover hint bubbles for every element carrying data-tip.
            . '.bgc-order-panel [data-tip]{position:relative;}'
            . '.bgc-order-panel [data-tip]:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);white-space:nowrap;background:#1d2327;color:#fff;font-size:11px;font-weight:500;line-height:1;padding:6px 8px;border-radius:6px;pointer-events:none;z-index:30;box-shadow:0 2px 6px rgba(0,0,0,.2);}'
            . '.bgc-order-panel [data-tip]:hover::before{content:"";position:absolute;bottom:calc(100% + 3px);left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#1d2327;z-index:30;}'
            . '.bgc-order-panel .bgc-ed{margin-top:14px;border-top:1px solid #eef0f2;padding-top:12px;}'
            // Copied-to-clipboard toast + custom cancel confirmation dialog (global, not panel-scoped).
            . '.bgc-toast{position:fixed;z-index:100001;background:#1d2327;color:#fff;font-size:13px;font-weight:500;padding:9px 14px;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.25);opacity:0;transform:translateY(6px);transition:opacity .18s,transform .18s;pointer-events:none;}'
            . '.bgc-toast.show{opacity:1;transform:translateY(0);}'
            . '.bgc-modal-ov{position:fixed;inset:0;background:rgba(20,24,28,.5);z-index:100000;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s;}'
            . '.bgc-modal-ov.show{opacity:1;}'
            . '.bgc-modal{background:#fff;border-radius:14px;max-width:400px;width:calc(100% - 40px);padding:22px 22px 18px;box-shadow:0 12px 40px rgba(0,0,0,.3);transform:translateY(8px) scale(.98);transition:transform .15s;}'
            . '.bgc-modal-ov.show .bgc-modal{transform:none;}'
            . '.bgc-modal h3{margin:0 0 8px;font-size:16px;color:#1d2327;display:flex;align-items:center;gap:8px;}'
            . '.bgc-modal h3 .dashicons{color:#b32d2e;font-size:22px;width:22px;height:22px;}'
            . '.bgc-modal p{margin:0 0 18px;color:#50575e;font-size:13px;line-height:1.5;}'
            . '.bgc-modal-actions{display:flex;justify-content:flex-end;gap:10px;}'
            . '.bgc-modal .button{border-radius:8px;height:36px;display:inline-flex;align-items:center;padding:0 16px;}'
            . '.bgc-modal .bgc-btn-danger{background:#b32d2e!important;border-color:#b32d2e!important;color:#fff!important;box-shadow:none!important;}'
            . '.bgc-modal .bgc-btn-danger:hover{background:#8a1f2b!important;border-color:#8a1f2b!important;}'
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
        // Icon-only action button: an <a> for links, a <button> for JS-driven actions. Each carries a
        // data-tip hover hint (see CSS) instead of visible text.
        $act = static function (string $tag, string $icon, string $tip, string $attrs, string $extra_class = ''): string {
            $cls = trim('bgc-act ' . $extra_class);
            return '<' . $tag . ' class="' . esc_attr($cls) . '" data-tip="' . esc_attr($tip) . '" aria-label="' . esc_attr($tip) . '" ' . $attrs . '>'
                . '<span class="dashicons dashicons-' . esc_attr($icon) . '"></span></' . $tag . '>';
        };
        $edit_tip = __('Edit delivery details', 'bg-couriers');

        if ($waybill === '') {
            $gen = $nonce_url('bgc_generate_label', 'bgc_generate_label_');
            echo '<div class="bgc-la">';
            echo '<a class="bgc-act bgc-primary bgc-gen" href="' . $gen . '"><span class="dashicons dashicons-tag"></span> ' . esc_html__('Generate label', 'bg-couriers') . '</a>';
            echo $act('button', 'edit', $edit_tip, 'type="button"', 'bgc-ed-toggle');
            echo '</div>';
        } else {
            $paper  = strtolower(BGC_Settings::label_paper_size($courier->id()));
            $print  = esc_url(wp_nonce_url($base . '?action=bgc_print_batch&order_id=' . $id . '&paper=' . $paper, 'bgc_print_batch'));
            $track  = $nonce_url('bgc_track', 'bgc_track_');
            $cancel = $nonce_url('bgc_cancel_label', 'bgc_cancel_label_');
            $copy_hint = esc_attr__('Copy waybill number', 'bg-couriers');
            // Waybill number with the copy button sitting immediately to its right (feather "copy" glyph).
            $copy_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                . '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
            echo '<div class="bgc-wbline"><strong>' . esc_html__('Waybill', 'bg-couriers') . ':</strong> <span class="bgc-wb">' . esc_html($waybill) . '</span>'
                . '<button type="button" class="bgc-copy" data-wb="' . esc_attr($waybill) . '" data-tip="' . $copy_hint . '" aria-label="' . $copy_hint . '">'
                . $copy_svg . '</button></div>';
            // One row: print, track, edit, cancel (destructive last), all icon-only with hover hints.
            echo '<div class="bgc-la">';
            echo $act('a', 'printer', __('Print label', 'bg-couriers'), 'href="' . $print . '" target="_blank"', 'bgc-primary');
            echo $act('a', 'location', __('Track shipment', 'bg-couriers'), 'href="' . $track . '" target="_blank"');
            echo $act('button', 'edit', $edit_tip, 'type="button"', 'bgc-ed-toggle');
            echo $act('button', 'no-alt', __('Cancel (void) label', 'bg-couriers'), 'type="button" data-cancel-url="' . $cancel . '"', 'bgc-danger bgc-cancel');
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
                          'saving' => __('Saving…', 'bg-couriers'), 'err' => __('Could not save.', 'bg-couriers'),
                          'copied' => __('Copied to clipboard', 'bg-couriers'),
                          'cancelTitle' => __('Cancel this waybill?', 'bg-couriers'),
                          'cancelBody'  => __('This voids the shipment label with the courier. This cannot be undone.', 'bg-couriers'),
                          'cancelYes'   => __('Yes, cancel it', 'bg-couriers'),
                          'cancelNo'    => __('Keep it', 'bg-couriers')],
        ]);

        echo '<div class="bgc-ed">';
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
