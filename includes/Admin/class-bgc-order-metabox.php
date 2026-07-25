<?php
defined('ABSPATH') || exit;

/** Renders the shipment panel (waybill + generate/print/track) at the TOP of a BG Couriers order. */
class BGC_Order_Metabox {
    public function __construct() {
        // The order-data panel (after the shipping address) - visible at the top, both HPOS + legacy.
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'render'], 10, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_panel_style']);
    }

    /** Panel stylesheet, enqueued early (head) on the order screens so the panel never flashes unstyled. */
    public function enqueue_panel_style(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['woocommerce_page_wc-orders', 'shop_order'], true)) { return; }
        $css = BGC_PATH . 'assets/css/bgc-order-panel.css';
        wp_enqueue_style('bgc-order-panel', BGC_URL . 'assets/css/bgc-order-panel.css', [], is_file($css) ? (string) filemtime($css) : BGC_VERSION);
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
        // Icon-only action group (no text): Generate when unlabelled, else print/track; plus edit, and cancel
        // last when a waybill exists. All go on the SAME row as the logo/type/waybill.
        if ($waybill === '') {
            $gen = $nonce_url('bgc_generate_label', 'bgc_generate_label_');
            $actions = $act('a', 'tag', __('Generate label', 'bg-couriers'), 'href="' . $gen . '"', 'bgc-primary')
                . $act('button', 'edit', $edit_tip, 'type="button"', 'bgc-ed-toggle');
        } else {
            $paper  = strtolower(BGC_Settings::label_paper_size($courier->id()));
            $print  = esc_url(wp_nonce_url($base . '?action=bgc_print_batch&order_id=' . $id . '&paper=' . $paper, 'bgc_print_batch'));
            $track  = $nonce_url('bgc_track', 'bgc_track_');
            $cancel = $nonce_url('bgc_cancel_label', 'bgc_cancel_label_');
            $actions = $act('a', 'printer', __('Print label', 'bg-couriers'), 'href="' . $print . '" target="_blank"', 'bgc-primary')
                . $act('a', 'location', __('Track shipment', 'bg-couriers'), 'href="' . $track . '" target="_blank"')
                . $act('button', 'edit', $edit_tip, 'type="button"', 'bgc-ed-toggle')
                . $act('button', 'no-alt', __('Cancel (void) label', 'bg-couriers'), 'type="button" data-cancel-url="' . $cancel . '"', 'bgc-danger bgc-cancel');
        }

        // ONE row, icons only: courier logo (hint) + delivery-type icon (hint) + waybill copy + action icons.
        $body = '<div class="bgc-hd">'
            . ($logo
                ? '<span class="bgc-tile bgc-logo-tile" data-tip="' . esc_attr($courier->label()) . '"><img class="bgc-logo" src="' . esc_url($logo) . '" alt="' . esc_attr($courier->label()) . '"></span>'
                : '<b>' . esc_html($courier->label()) . '</b>')
            . (BGC_Icons::method($method, 18) !== ''
                ? '<span class="bgc-tile bgc-mtype" data-tip="' . esc_attr($mlabel) . '" aria-label="' . esc_attr($mlabel) . '">' . BGC_Icons::method($method, 18) . '</span>'
                : '<span class="bgc-chip">' . esc_html($mlabel) . '</span>');
        if ($waybill !== '') {
            /* translators: %s: waybill number */
            $copy_lbl = sprintf(__('Copy waybill %s', 'bg-couriers'), $waybill);
            $body .= '<button type="button" class="bgc-wb bgc-wb-copy" data-wb="' . esc_attr($waybill) . '" data-tip="' . esc_attr($waybill)
                . '" aria-label="' . esc_attr($copy_lbl) . '"><span class="dashicons dashicons-admin-page"></span></button>';
        }
        $body .= '<span class="bgc-hd-acts">' . $actions . '</span></div>';

        // Surface the last generation error (handle_generate stores it in a transient, then redirects here).
        $err = get_transient('bgc_admin_error_' . $id);
        if ($err) {
            delete_transient('bgc_admin_error_' . $id);
            /* translators: %s: error message from the courier */
            $err_msg = esc_html(sprintf(__('Label generation failed: %s', 'bg-couriers'), $err));
            $body .= '<div class="bgc-err" style="margin:8px 0 0;padding:8px 10px;border-radius:6px;background:#fcf0f1;border:1px solid #e6a2a5;color:#8a1f2b;">'
                . $err_msg . '</div>';
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
        'img'    => ['class' => true, 'src' => true, 'alt' => true, 'data-tip' => true],
        'a'      => ['class' => true, 'href' => true, 'target' => true, 'rel' => true, 'aria-label' => true, 'data-tip' => true],
        'button' => ['type' => true, 'class' => true, 'aria-label' => true, 'data-tip' => true, 'data-wb' => true, 'data-cancel-url' => true],
        'svg'    => ['class' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true],
        'path'   => ['d' => true],
        'rect'   => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true],
        'line'   => ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true],
        'circle' => ['cx' => true, 'cy' => true, 'r' => true],
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
            . '<div class="bgc-ed-row bgc-ed-street-row">'
            . '<div class="bgc-ed-fld bgc-ed-grow"><label>' . esc_html__('Street', 'bg-couriers') . '</label><select class="bgc-ed-street"><option></option>' . $street_opt . '</select></div>'
            . '<div class="bgc-ed-fld bgc-ed-no"><label>' . esc_html__('No.', 'bg-couriers') . '</label><input class="bgc-ed-streetno" value="' . $v('street_no') . '"></div>'
            . '<div class="bgc-ed-fld bgc-ed-mapcell"><label aria-hidden="true">&nbsp;</label><button type="button" class="button bgc-ed-addr-map bgc-ed-mapbtn" title="' . esc_attr__('Pick the address on the map', 'bg-couriers') . '" aria-label="' . esc_attr__('Pick the address on the map', 'bg-couriers') . '"><span class="dashicons dashicons-location-alt"></span></button></div>'
            . '</div>'
            . '<div class="bgc-ed-fld"><label>' . esc_html__('Quarter / complex', 'bg-couriers') . '</label><input class="bgc-ed-complex" value="' . $v('complex') . '"></div>'
            . '<div class="bgc-ed-row bgc-ed-grid4">'
            . '<div class="bgc-ed-fld"><label>' . esc_html__('Bl.', 'bg-couriers') . '</label><input class="bgc-ed-block" value="' . $v('block') . '"></div>'
            . '<div class="bgc-ed-fld"><label>' . esc_html__('Entr.', 'bg-couriers') . '</label><input class="bgc-ed-entrance" value="' . $v('entrance') . '"></div>'
            . '<div class="bgc-ed-fld"><label>' . esc_html__('Floor', 'bg-couriers') . '</label><input class="bgc-ed-floor" value="' . $v('floor') . '"></div>'
            . '<div class="bgc-ed-fld"><label>' . esc_html__('Apt.', 'bg-couriers') . '</label><input class="bgc-ed-apartment" value="' . $v('apartment') . '"></div>'
            . '</div>'
            . '<div class="bgc-ed-fld"><label>' . esc_html__('Note', 'bg-couriers') . '</label><input class="bgc-ed-note" value="' . $v('address_note') . '"></div>'
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
            'label'  => ['class' => true, 'aria-hidden' => true],
            'br'     => [],
            'select' => ['class' => true, 'style' => true, 'data-current' => true],
            'option' => ['value' => true, 'selected' => true],
            'input'  => ['type' => true, 'class' => true, 'value' => true, 'style' => true],
            'button' => ['type' => true, 'class' => true, 'title' => true, 'aria-label' => true],
            'span'   => ['class' => true],
        ]);
    }
}
