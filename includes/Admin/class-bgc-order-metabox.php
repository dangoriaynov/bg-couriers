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
            . '.bgc-order-panel{margin-top:8px;padding:10px 11px;border:1px solid #e6e9ec;border-radius:9px;background:#fff;}'
            . '.bgc-order-panel .bgc-hd{display:flex;align-items:center;gap:7px;margin:0 0 8px;flex-wrap:wrap;}'
            . '.bgc-order-panel .bgc-hd b{font-size:13px;color:#1d2327;}'
            . '.bgc-order-panel .bgc-logo{height:17px;width:auto;display:block;}'
            . '.bgc-order-panel .bgc-chip{display:inline-block;padding:2px 9px;border-radius:999px;background:#eef2f7;color:#3c434a;font-size:12px;font-weight:600;}'
            // The waybill number IS the copy button: clicking the field copies it to the clipboard.
            . '.bgc-order-panel .bgc-wb{padding:3px 9px;border-radius:7px;background:#f0f6fc;border:1px solid #d7e3f1;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#1d2327;letter-spacing:.3px;cursor:pointer;transition:all .12s;}'
            . '.bgc-order-panel .bgc-wb:hover{border-color:#9fb6cf;background:#e9f2fb;}'
            . '.bgc-order-panel .bgc-wb.copied{border-color:#8fcea5;background:#eaf7ee;color:#1a7f37;}'
            // One cohesive row of icon-only actions (print / track / edit / cancel), 40px squares.
            . '.bgc-order-panel .bgc-la{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}'
            . '.bgc-order-panel .bgc-act{display:inline-flex;align-items:center;justify-content:center;width:31px;height:31px;padding:0;margin:0;border:1px solid #c9ced6;border-radius:7px;background:#fff;color:#2b3440;cursor:pointer;text-decoration:none;box-shadow:none;transition:all .12s;}'
            . '.bgc-order-panel .bgc-act:hover{background:#f4f6f9;border-color:#a2acb8;box-shadow:0 1px 2px rgba(0,0,0,.07);}'
            . '.bgc-order-panel .bgc-act:focus{outline:none;box-shadow:0 0 0 2px rgba(34,113,177,.35);}'
            . '.bgc-order-panel .bgc-act .dashicons{font-size:16px;width:16px;height:16px;line-height:1;}'
            . '.bgc-order-panel .bgc-act.bgc-primary{background:#2271b1;border-color:#2271b1;color:#fff;}'
            . '.bgc-order-panel .bgc-act.bgc-primary:hover{background:#1c5d92;border-color:#1c5d92;}'
            . '.bgc-order-panel .bgc-act.bgc-danger{color:#b32d2e;border-color:#e6a2a5;}'
            . '.bgc-order-panel .bgc-act.bgc-danger:hover{background:#fcecec;border-color:#cf6a6f;color:#8a1f2b;}'
            . '.bgc-order-panel .bgc-act.bgc-gen{width:auto;padding:0 11px;gap:6px;font-size:12px;font-weight:600;}'
            // Hover hint bubbles for every element carrying data-tip.
            . '.bgc-order-panel [data-tip]{position:relative;}'
            . '.bgc-order-panel [data-tip]:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);white-space:nowrap;background:#1d2327;color:#fff;font-size:11px;font-weight:500;line-height:1;padding:6px 8px;border-radius:6px;pointer-events:none;z-index:30;box-shadow:0 2px 6px rgba(0,0,0,.2);}'
            . '.bgc-order-panel [data-tip]:hover::before{content:"";position:absolute;bottom:calc(100% + 3px);left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#1d2327;z-index:30;}'
            . '.bgc-order-panel .bgc-ed{margin-top:8px;}'
            // Editor form: force a uniform 32px height on native selects + select2 + inputs (WC/theme give
            // the native selects a taller height, so !important + the .bgc-order-panel prefix are needed).
            . '.bgc-order-panel .bgc-ed-form p{margin:0 0 7px;}'
            . '.bgc-order-panel .bgc-ed-form label{color:#50575e;font-weight:600;font-size:12px;}'
            . '.bgc-order-panel .bgc-ed-form select,.bgc-order-panel .bgc-ed-form input[type=text],.bgc-order-panel .bgc-ed-form input:not([type]){height:32px!important;min-height:32px!important;box-sizing:border-box!important;margin:0!important;padding:0 8px!important;font-size:13px!important;line-height:1.2!important;vertical-align:middle;}'
            // The four full-row dropdowns (courier / option / city / office) all fill the form width so their
            // widths match - the native courier/option selects were narrower than the full-width select2
            // city/office (which already init at width:100%). Scoped so the inline street+No. row is untouched.
            . '.bgc-order-panel .bgc-ed-form .bgc-ed-courier,.bgc-order-panel .bgc-ed-form .bgc-ed-method{width:100%!important;min-width:0!important;}'
            . '.bgc-order-panel .bgc-ed-form .bgc-ed-city-row .select2-container{width:100%!important;}'
            // Office row: leave room for the inline map icon so it sits to the RIGHT of the select, not below.
            . '.bgc-order-panel .bgc-ed-form .bgc-ed-office-row .select2-container{width:calc(100% - 42px)!important;display:inline-block!important;}'
            . '.bgc-order-panel .bgc-ed-form .select2-container{vertical-align:middle;min-height:32px;margin:0!important;}'
            . '.bgc-order-panel .bgc-ed-form .select2-selection--single{height:32px!important;min-height:32px!important;box-sizing:border-box!important;}'
            . '.bgc-order-panel .bgc-ed-form .select2-selection--single .select2-selection__rendered{line-height:30px!important;font-size:13px!important;padding-left:8px!important;}'
            . '.bgc-order-panel .bgc-ed-form .select2-selection--single .select2-selection__arrow{height:30px!important;}'
            . '.bgc-order-panel .bgc-ed-form .bgc-ed-mapbtn{height:32px!important;min-height:32px!important;width:34px!important;min-width:34px!important;padding:0!important;margin:0 0 0 4px!important;vertical-align:middle;display:inline-flex!important;align-items:center;justify-content:center;}'
            . '.bgc-order-panel .bgc-ed-form .bgc-ed-mapbtn .dashicons{font-size:17px;width:17px;height:17px;line-height:1;}'
            . '.bgc-order-panel .bgc-ed-form .bgc-ed-save.button-primary{height:34px!important;min-height:34px!important;width:auto!important;min-width:0!important;max-width:100%;display:inline-block!important;flex:none;box-sizing:border-box;margin-top:10px!important;border-radius:7px!important;padding:0 22px!important;font-weight:600;}'
            . '.bgc-order-panel .bgc-ed-form .bgc-ed-avail{display:none;margin:2px 0 0;color:#b32d2e;font-size:12px;}'
            . '.bgc-order-panel .bgc-ed-form .description{margin:6px 0 0;font-size:12px;color:#646970;}'
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

        // Header: courier logo + delivery-type label + (when issued) the waybill number, which is itself the
        // copy button - clicking the field copies the number (see bgc-order-admin.js).
        $logo = BGC_Couriers::logo_url($courier->id());
        $body = '<div class="bgc-hd">'
            . ($logo
                ? '<img class="bgc-logo" src="' . esc_url($logo) . '" alt="' . esc_attr($courier->label()) . '">'
                : '<b>' . esc_html($courier->label()) . '</b>')
            . '<span class="bgc-chip">' . esc_html($mlabel) . '</span>';
        if ($waybill !== '') {
            $body .= '<span class="bgc-wb bgc-wb-copy" data-wb="' . esc_attr($waybill) . '" role="button" tabindex="0" data-tip="'
                . esc_attr__('Click to copy', 'bg-couriers') . '">' . esc_html($waybill) . '</span>';
        }
        $body .= '</div>';

        // Surface the last generation error (handle_generate stores it in a transient, then redirects here).
        $err = get_transient('bgc_admin_error_' . $id);
        if ($err) {
            delete_transient('bgc_admin_error_' . $id);
            /* translators: %s: error message from the courier */
            $err_msg = esc_html(sprintf(__('Label generation failed: %s', 'bg-couriers'), $err));
            $body .= '<div class="bgc-err" style="margin:0 0 8px;padding:8px 10px;border-radius:6px;background:#fcf0f1;border:1px solid #e6a2a5;color:#8a1f2b;">'
                . $err_msg . '</div>';
        }

        if ($waybill === '') {
            $gen = $nonce_url('bgc_generate_label', 'bgc_generate_label_');
            $body .= '<div class="bgc-la">'
                . '<a class="bgc-act bgc-primary bgc-gen" href="' . $gen . '"><span class="dashicons dashicons-tag"></span> ' . esc_html__('Generate label', 'bg-couriers') . '</a>'
                . $act('button', 'edit', $edit_tip, 'type="button"', 'bgc-ed-toggle')
                . '</div>';
        } else {
            $paper  = strtolower(BGC_Settings::label_paper_size($courier->id()));
            $print  = esc_url(wp_nonce_url($base . '?action=bgc_print_batch&order_id=' . $id . '&paper=' . $paper, 'bgc_print_batch'));
            $track  = $nonce_url('bgc_track', 'bgc_track_');
            $cancel = $nonce_url('bgc_cancel_label', 'bgc_cancel_label_');
            // One row: print, track, edit, cancel (destructive last), all icon-only with hover hints.
            $body .= '<div class="bgc-la">'
                . $act('a', 'printer', __('Print label', 'bg-couriers'), 'href="' . $print . '" target="_blank"', 'bgc-primary')
                . $act('a', 'location', __('Track shipment', 'bg-couriers'), 'href="' . $track . '" target="_blank"')
                . $act('button', 'edit', $edit_tip, 'type="button"', 'bgc-ed-toggle')
                . $act('button', 'no-alt', __('Cancel (void) label', 'bg-couriers'), 'type="button" data-cancel-url="' . $cancel . '"', 'bgc-danger bgc-cancel')
                . '</div>';
        }

        echo '<div class="bgc-order-panel">';
        echo wp_kses($body, self::PANEL_TAGS); // each field escaped above; kses is the output-escaping gate
        $this->render_editor($order);
        echo '</div>';
    }

    /** Allowed tags/attributes for the shipment-panel body passed through wp_kses. */
    const PANEL_TAGS = [
        'div'    => ['class' => true, 'style' => true],
        'span'   => ['class' => true, 'data-wb' => true, 'data-tip' => true, 'role' => true, 'tabindex' => true, 'aria-label' => true],
        'strong' => ['class' => true],
        'b'      => [],
        'img'    => ['class' => true, 'src' => true, 'alt' => true],
        'a'      => ['class' => true, 'href' => true, 'target' => true, 'rel' => true, 'aria-label' => true, 'data-tip' => true],
        'button' => ['type' => true, 'class' => true, 'aria-label' => true, 'data-tip' => true, 'data-cancel-url' => true],
    ];

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
            // available_methods() prunes types the courier has no synced points for (e.g. Pigeon APS), so the
            // delivery-option dropdown can't offer something the courier doesn't actually do.
            $caps[$cid] = $co ? array_values(array_diff($co->available_methods(), ['live_quote'])) : ['office', 'address', 'automat'];
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
        // Leaflet (bundled) + the checkout CSS (for the .bgc-map-* modal styles) so the editor can offer the
        // same office/APS + address map picker as checkout. The checkout rules are class-scoped to .bgc-fields
        // and don't touch the admin editor; only the fixed-overlay map modal is reused.
        wp_enqueue_style('bgc-leaflet', BGC_URL . 'assets/lib/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_script('bgc-leaflet', BGC_URL . 'assets/lib/leaflet/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('bgc-checkout-map', BGC_URL . 'assets/css/bgc-checkout.css', ['bgc-leaflet'], BGC_VERSION);
        $ver = @filemtime(BGC_PATH . 'assets/js/bgc-order-admin.js') ?: '1';
        wp_enqueue_script('bgc-order-admin', BGC_URL . 'assets/js/bgc-order-admin.js', ['jquery', 'selectWoo', 'bgc-leaflet'], $ver, true);
        wp_localize_script('bgc-order-admin', 'BGC_ED', [
            'ajax'    => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bgc_order_delivery'),
            'orderId' => $order->get_id(),
            'caps'    => $caps,
            'leaflet_images' => BGC_URL . 'assets/lib/leaflet/images/',
            'methodLabels' => ['office' => __('To office', 'bg-couriers'), 'address' => __('To address', 'bg-couriers'), 'automat' => __('To APS', 'bg-couriers')],
            'i18n'    => ['city' => __('City', 'bg-couriers'), 'office' => __('Office / APS', 'bg-couriers'), 'street' => __('Street', 'bg-couriers'),
                          'saving' => __('Saving…', 'bg-couriers'), 'err' => __('Could not save.', 'bg-couriers'),
                          'copied' => __('Copied to clipboard', 'bg-couriers'),
                          'cancelTitle' => __('Cancel this waybill?', 'bg-couriers'),
                          'cancelBody'  => __('This voids the shipment label with the courier. This cannot be undone.', 'bg-couriers'),
                          'cancelYes'   => __('Yes, cancel it', 'bg-couriers'),
                          'cancelNo'    => __('Keep it', 'bg-couriers'),
                          'map_title'   => __('Pick from the map', 'bg-couriers'),
                          'map_choose'  => __('Choose this location', 'bg-couriers'),
                          'map_locate'  => __('Show my location', 'bg-couriers'),
                          'map_none'    => __('No offices with a map location for this city yet - use the list.', 'bg-couriers'),
                          'office_ph'   => __('Search…', 'bg-couriers'),
                          'close'       => __('Close', 'bg-couriers'),
                          'addr_map_title' => __('Choose the address on the map', 'bg-couriers'),
                          'addr_map_hint'  => __('Click the map or drag the pin to the address.', 'bg-couriers'),
                          'addr_use'    => __('Use this address', 'bg-couriers'),
                          'addr_none'   => __('No address found here - try another spot.', 'bg-couriers'),
                          'map_btn'     => __('Map', 'bg-couriers'),
                          'no_office'   => __('This courier has no office in this city - pick another city or delivery option.', 'bg-couriers'),
                          'no_automat'  => __('This courier has no APS/locker in this city - pick another city or delivery option.', 'bg-couriers')],
        ]);

        $boxnow_id = $cur_courier === 'boxnow' ? esc_attr((string) $office_id) : '';
        $form = '<div class="bgc-ed"><div class="bgc-ed-form" style="display:none;margin-top:10px;max-width:520px;">'
            . '<p><label>' . esc_html__('Courier', 'bg-couriers') . '</label><br><select class="bgc-ed-courier" style="min-width:240px;">' . $opts . '</select></p>'
            . '<p><label>' . esc_html__('Delivery option', 'bg-couriers') . '</label><br><select class="bgc-ed-method" data-current="' . esc_attr($cur_method) . '" style="min-width:240px;"></select></p>'
            . '<p class="bgc-ed-city-row"><label>' . esc_html__('City', 'bg-couriers') . '</label><br><select class="bgc-ed-city" style="min-width:300px;"><option></option>' . $city_opt . '</select><input type="hidden" class="bgc-ed-postcode" value="' . $v('post_code') . '"></p>'
            . '<p class="bgc-ed-office-row"><label>' . esc_html__('Office / APS', 'bg-couriers') . '</label><br><select class="bgc-ed-office" style="min-width:258px;"><option></option>' . $office_opt . '</select><button type="button" class="button bgc-ed-map bgc-ed-mapbtn" title="' . esc_attr__('Pick from the map', 'bg-couriers') . '" aria-label="' . esc_attr__('Pick from the map', 'bg-couriers') . '"><span class="dashicons dashicons-location-alt"></span></button></p>'
            . '<p class="bgc-ed-avail"></p>'
            . '<div class="bgc-ed-address">'
            . '<p><label>' . esc_html__('Street', 'bg-couriers') . '</label><br><select class="bgc-ed-street" style="min-width:220px;"><option></option>' . $street_opt . '</select> '
            . '<label>' . esc_html__('No.', 'bg-couriers') . '</label> <input class="bgc-ed-streetno" value="' . $v('street_no') . '" style="width:70px;"><button type="button" class="button bgc-ed-addr-map bgc-ed-mapbtn" title="' . esc_attr__('Pick the address on the map', 'bg-couriers') . '" aria-label="' . esc_attr__('Pick the address on the map', 'bg-couriers') . '"><span class="dashicons dashicons-location-alt"></span></button></p>'
            . '<p><label>' . esc_html__('Quarter / complex', 'bg-couriers') . '</label> <input class="bgc-ed-complex" value="' . $v('complex') . '"></p>'
            . '<p><label>' . esc_html__('Bl.', 'bg-couriers') . '</label> <input class="bgc-ed-block" value="' . $v('block') . '" style="width:60px;"> '
            . '<label>' . esc_html__('Entr.', 'bg-couriers') . '</label> <input class="bgc-ed-entrance" value="' . $v('entrance') . '" style="width:60px;"> '
            . '<label>' . esc_html__('Floor', 'bg-couriers') . '</label> <input class="bgc-ed-floor" value="' . $v('floor') . '" style="width:60px;"> '
            . '<label>' . esc_html__('Apt.', 'bg-couriers') . '</label> <input class="bgc-ed-apartment" value="' . $v('apartment') . '" style="width:60px;"></p>'
            . '<p><label>' . esc_html__('Note', 'bg-couriers') . '</label> <input class="bgc-ed-note" value="' . $v('address_note') . '" style="width:100%;"></p>'
            . '</div>'
            . '<div class="bgc-ed-boxnow">'
            . '<p><label>' . esc_html__('Locker id', 'bg-couriers') . '</label> <input class="bgc-ed-boxnow-id" value="' . $boxnow_id . '" style="width:110px;"> '
            . '<label>' . esc_html__('Locker name', 'bg-couriers') . '</label> <input class="bgc-ed-boxnow-name" value="' . $v('boxnow_name') . '"></p>'
            . '<p><label>' . esc_html__('Locker address', 'bg-couriers') . '</label> <input class="bgc-ed-boxnow-addr" value="' . $v('boxnow_addr') . '" style="width:100%;"></p>'
            . '</div>'
            . '<p><button type="button" class="button button-primary bgc-ed-save">' . esc_html__('Save delivery', 'bg-couriers') . '</button> <span class="bgc-ed-msg"></span></p>';
        if ($has_waybill) {
            $form .= '<p class="description">' . esc_html__('A waybill exists. Saving voids it and issues a new one matching the new details.', 'bg-couriers') . '</p>';
        }
        $form .= '</div></div>';

        echo wp_kses($form, [
            'div'    => ['class' => true, 'style' => true],
            'p'      => ['class' => true, 'style' => true],
            'label'  => ['class' => true],
            'br'     => [],
            'select' => ['class' => true, 'style' => true, 'data-current' => true],
            'option' => ['value' => true, 'selected' => true],
            'input'  => ['type' => true, 'class' => true, 'value' => true, 'style' => true],
            'button' => ['type' => true, 'class' => true],
            'span'   => ['class' => true],
        ]);
    }
}
