<?php
defined('ABSPATH') || exit;

/** Renders the shipment panel (waybill + generate/print/track) at the TOP of a BG Couriers order. */
class BGCouriers_Order_Metabox {
    public function __construct() {
        // The order-data panel (after the shipping address) - visible at the top, both HPOS + legacy.
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'render'], 10, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_panel_style']);
    }

    /** Panel stylesheet, enqueued early (head) on the order screens so the panel never flashes unstyled. */
    public function enqueue_panel_style(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['woocommerce_page_wc-orders', 'shop_order'], true)) { return; }
        $css = BGCOURIERS_PATH . 'assets/css/bgc-order-panel.css';
        wp_enqueue_style('bgc-order-panel', BGCOURIERS_URL . 'assets/css/bgc-order-panel.css', [], is_file($css) ? (string) filemtime($css) : BGCOURIERS_VERSION);
        BGCouriers_Tips::enqueue(); // the panel's hover hints (data-tip)
    }

    public function render($order): void {
        $courier = BGCouriers_Labels::order_courier($order); // any BG Couriers order, not just Speedy
        if (!$courier) { return; }
        $id      = $order->get_id();
        $waybill = (string) $order->get_meta('_bgcouriers_waybill');
        $method  = (string) $order->get_meta('_bgcouriers_method');
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
        // Once the courier holds the parcel, cancelling / re-issuing / editing are refused by the server
        // anyway - so they are shown dimmed and inert rather than left to be clicked and turned down. The
        // hint on each says why AND how to get them back, because the way out is not obvious: put the
        // order back to Processing or Pending payment. Print and Track stay live: reading is always fine.
        $locked     = BGCouriers_Labels::is_locked($order);
        $locked_msg = BGCouriers_Labels::locked_message();
        $off        = $locked ? ' bgc-off' : '';
        $off_attrs  = $locked ? ' aria-disabled="true" tabindex="-1"' : '';
        $tip_of     = static function (string $tip) use ($locked, $locked_msg): string {
            return $locked ? $locked_msg : $tip;
        };

        // Header: courier logo + delivery-type label + (when issued) the waybill number, which is itself the
        // copy button - clicking the field copies the number (see bgc-order-admin.js).
        $logo = BGCouriers_Couriers::logo_url($courier->id());
        // Icon-only action group (no text): Generate when unlabelled, else print/track; plus edit, and cancel
        // last when a waybill exists. All go on the SAME row as the logo/type/waybill.
        if ($waybill === '') {
            $gen = $nonce_url('bgcouriers_generate_label', 'bgcouriers_generate_label_');
            $actions = $act('a', 'tag', __('Generate label', 'bg-couriers'), 'href="' . $gen . '"', 'bgc-primary')
                . $act('button', 'edit', $edit_tip, 'type="button"', 'bgc-ed-toggle');
        } else {
            $paper  = strtolower(BGCouriers_Settings::label_paper_size($courier->id()));
            $print  = esc_url(wp_nonce_url($base . '?action=bgcouriers_print_batch&order_id=' . $id . '&paper=' . $paper, 'bgcouriers_print_batch'));
            $track  = $nonce_url('bgcouriers_track', 'bgcouriers_track_');
            $cancel = $nonce_url('bgcouriers_cancel_label', 'bgcouriers_cancel_label_');
            // Re-issue: one click voids the current waybill and issues a fresh one from the order's CURRENT
            // details/settings (weights, parcel dims, contents), instead of cancel-then-generate. Sits first
            // in the action group so it follows the waybill-copy button. JS confirms (bgc-order-admin.js).
            $regen  = $nonce_url('bgcouriers_regenerate', 'bgcouriers_regenerate_');
            // The URLs are withheld while locked, not just hidden behind a class: the JS handlers read
            // them, so a control with nothing to act on cannot fire even if the styling is overridden.
            $regen_attr  = 'type="button"' . ($locked ? '' : ' data-regen-url="' . $regen . '"') . $off_attrs;
            $cancel_attr = 'type="button"' . ($locked ? '' : ' data-cancel-url="' . $cancel . '"') . $off_attrs;
            $actions = $act('button', 'update', $tip_of(__('Re-issue waybill (voids the current one)', 'bg-couriers')), $regen_attr, 'bgc-regen' . $off)
                . $act('a', 'printer', __('Print label', 'bg-couriers'), 'href="' . $print . '" target="_blank"', 'bgc-primary')
                . $act('a', 'location', __('Track shipment', 'bg-couriers'), 'href="' . $track . '" target="_blank"')
                . $act('button', 'edit', $edit_tip, 'type="button"', 'bgc-ed-toggle')
                . $act('button', 'no-alt', $tip_of(__('Cancel (void) label', 'bg-couriers')), $cancel_attr, 'bgc-danger bgc-cancel' . $off);
        }

        // ONE row, icons only: courier logo (hint) + delivery-type icon (hint) + waybill copy + action icons.
        $body = '<div class="bgc-hd">'
            . ($logo
                ? '<span class="bgc-tile bgc-logo-tile" data-tip="' . esc_attr($courier->label()) . '"><img class="bgc-logo" src="' . esc_url($logo) . '" alt="' . esc_attr($courier->label()) . '"></span>'
                : '<b>' . esc_html($courier->label()) . '</b>')
            . (BGCouriers_Icons::method($method, 18) !== ''
                ? '<span class="bgc-tile bgc-mtype" data-tip="' . esc_attr($mlabel) . '" aria-label="' . esc_attr($mlabel) . '">' . BGCouriers_Icons::method($method, 18) . '</span>'
                : '<span class="bgc-chip">' . esc_html($mlabel) . '</span>');
        if ($waybill !== '') {
            /* translators: %s: waybill number */
            $copy_lbl = sprintf(__('Copy waybill %s', 'bg-couriers'), $waybill);
            $body .= '<button type="button" class="bgc-wb bgc-wb-copy" data-wb="' . esc_attr($waybill) . '" data-tip="' . esc_attr($waybill)
                . '" aria-label="' . esc_attr($copy_lbl) . '"><span class="dashicons dashicons-admin-page"></span></button>';
        }
        $body .= '<span class="bgc-hd-acts">' . $actions . '</span></div>';

        // Where the shipment actually is, on the order itself. It used to live only in the orders list, so
        // the one screen a merchant opens to look at a single order was the one place that did not say.
        $stage = (string) $order->get_meta('_bgcouriers_track_stage');
        $text  = trim((string) $order->get_meta('_bgcouriers_track_text'));
        if ($stage !== '' || $text !== '') {
            $when = (int) $order->get_meta('_bgcouriers_track_updated');
            $body .= '<div class="bgc-shipstate bgc-stage-' . esc_attr(sanitize_html_class($stage ?: 'transit')) . '">'
                . '<span class="bgc-track-dot" style="background:' . esc_attr(BGCouriers_Order_Columns::STAGE_COLORS[$stage] ?? '#6b7280') . '"></span>'
                . '<strong>' . esc_html(BGCouriers_Tracking::stage_label($stage)) . '</strong> '
                . ($text !== '' ? '<span class="bgc-shipstate-txt">' . esc_html($text) . '</span>' : '')
                . ($when > 0
                    /* translators: %s: human-readable time difference, e.g. "2 hours" */
                    ? '<span class="bgc-shipstate-when">' . esc_html(sprintf(__('updated %s ago', 'bg-couriers'), human_time_diff($when, time()))) . '</span>'
                    : '')
                . (BGCouriers_Labels::is_locked($order)
                    ? '<span class="bgc-lock dashicons dashicons-lock" data-tip="' . esc_attr(BGCouriers_Labels::locked_message()) . '"></span>'
                    : '')
                // Ask the courier again, for THIS order, without waiting for the next scheduled poll -
                // which is what someone standing on this screen actually wants.
                . '<button type="button" class="bgc-ship-refresh" data-id="' . (int) $id . '"'
                . ' data-tip="' . esc_attr__('Update from the courier now', 'bg-couriers') . '"'
                . ' aria-label="' . esc_attr__('Update from the courier now', 'bg-couriers') . '">'
                . '<span class="dashicons dashicons-update"></span></button>'
                . '</div>';
        }

        // Surface the last generation error (handle_generate stores it in a transient, then redirects here).
        $err = get_transient('bgcouriers_admin_error_' . $id);
        if ($err) {
            delete_transient('bgcouriers_admin_error_' . $id);
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
        'span'   => ['class' => true, 'style' => true, 'data-wb' => true, 'data-tip' => true, 'role' => true, 'tabindex' => true, 'aria-label' => true],
        'strong' => ['class' => true],
        'b'      => [],
        'img'    => ['class' => true, 'src' => true, 'alt' => true, 'data-tip' => true],
        'a'      => ['class' => true, 'href' => true, 'target' => true, 'rel' => true, 'aria-label' => true, 'data-tip' => true],
        'button' => ['type' => true, 'class' => true, 'aria-label' => true, 'data-tip' => true, 'data-wb' => true, 'data-cancel-url' => true, 'data-regen-url' => true, 'data-id' => true,
                        // The dimmed state of a blocked control: without these on the allowlist kses strips
                        // them at output and the button looks disabled while still being focusable.
                        'aria-disabled' => true, 'tabindex' => true],
        'svg'    => ['class' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true],
        'path'   => ['d' => true],
        'rect'   => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true],
        'line'   => ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true],
        'circle' => ['cx' => true, 'cy' => true, 'r' => true],
    ];

    /** Allowed tags/attributes for the delivery editor passed through wp_kses. */
    const EDITOR_TAGS = [
        'div'    => ['class' => true, 'style' => true],
        'p'      => ['class' => true, 'style' => true],
        'label'  => ['class' => true, 'aria-hidden' => true],
        'br'     => [],
        // `disabled` is on every control's list, not just the button's: it is what makes a locked editor
        // read-only (see disable_controls), and kses drops an attribute it was not told about in silence.
        'select' => ['class' => true, 'style' => true, 'data-current' => true, 'disabled' => true],
        'option' => ['value' => true, 'selected' => true],
        'input'  => ['type' => true, 'class' => true, 'value' => true, 'style' => true, 'disabled' => true],
        'button' => ['type' => true, 'class' => true, 'title' => true, 'aria-label' => true, 'disabled' => true],
        'span'   => ['class' => true],
    ];

    /**
     * Turn every control in the editor's markup inert.
     *
     * Applied to the whole form rather than field by field, because a lock that has to be remembered at
     * each of nineteen controls is a lock that will be half-applied - it already was. What stood in for
     * this was a stylesheet naming `select` and `input`, and city, street and office are the three that
     * selectWoo replaces with a span of its own: no rule reached them, so a collected parcel could be
     * re-addressed - dropdowns, clear ×, map picker and all - inside a form whose Save was greyed out.
     * One rule over the finished markup cannot miss a field, and covers whatever is added later.
     *
     * Safe as a regex because it runs on markup this class has just built, in which every merchant-supplied
     * value has already been through esc_attr() - so no `<` survives inside an attribute to be matched.
     *
     * @param string $html The editor's markup, as built by render_editor().
     * @return string The same markup with `disabled` on every select, input and button.
     */
    public static function disable_controls(string $html): string {
        return (string) preg_replace('/<(select|input|button)(?=[\s>])/i', '<$1 disabled', $html);
    }

    /** Collapsible checkout-like editor for the order's delivery details (courier switch, city/office/address). */
    private function render_editor(\WC_Order $order): void {
        $cur_courier = (string) $order->get_meta('_bgcouriers_courier');
        $cur_method  = (string) $order->get_meta('_bgcouriers_method');
        $site_id     = (int) $order->get_meta('_bgcouriers_site_id');
        $office_id   = (int) $order->get_meta('_bgcouriers_office_id');
        $has_waybill = (string) $order->get_meta('_bgcouriers_waybill') !== '';

        // Enabled couriers (+ the order's current one even if now disabled), with their delivery options.
        $caps = []; $opts = '';
        foreach (BGCouriers_Couriers::all() as $cid => $clabel) {
            if (get_option('bgcouriers_' . $cid . '_enabled', 'no') !== 'yes' && $cid !== $cur_courier) { continue; }
            $co = BGCouriers_Couriers::get($cid);
            // available_methods() prunes types the courier has no synced points for (e.g. Pigeon APS), so the
            // delivery-option dropdown can't offer something the courier doesn't actually do.
            $caps[$cid] = $co ? array_values(array_diff($co->available_methods(), ['live_quote'])) : ['office', 'address', 'automat'];
            $opts .= '<option value="' . esc_attr($cid) . '"' . selected($cid, $cur_courier, false) . '>' . esc_html($clabel) . '</option>';
        }
        $city_opt = '';
        if ($site_id && ($city = BGCouriers_Nomenclature::city_by_id($cur_courier, $site_id))) {
            $pc = !empty($city['post_code']) ? ' (' . $city['post_code'] . ')' : '';
            $city_opt = '<option value="' . esc_attr($site_id) . '" selected>' . esc_html($city['name'] . $pc) . '</option>';
        }
        $office_opt = '';
        if ($office_id && ($o = BGCouriers_Nomenclature::office_by_id($cur_courier, $office_id))) {
            $office_opt = '<option value="' . esc_attr($office_id) . '" selected>' . esc_html($o['name'] ?? '') . '</option>';
        }
        $street = (string) $order->get_meta('_bgcouriers_street_name');
        $street_opt = $street !== '' ? '<option value="' . esc_attr($street) . '" selected>' . esc_html($street) . '</option>' : '';
        $v = static function ($k) use ($order) { return esc_attr((string) $order->get_meta('_bgcouriers_' . $k)); };

        wp_enqueue_style('select2');
        wp_enqueue_script('selectWoo');
        // Leaflet (bundled) + the checkout CSS (for the .bgc-map-* modal styles) so the editor can offer the
        // same office/APS + address map picker as checkout. The checkout rules are class-scoped to .bgc-fields
        // and don't touch the admin editor; only the fixed-overlay map modal is reused.
        wp_enqueue_style('bgc-leaflet', BGCOURIERS_URL . 'assets/lib/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_script('bgc-leaflet', BGCOURIERS_URL . 'assets/lib/leaflet/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('bgc-checkout-map', BGCOURIERS_URL . 'assets/css/bgc-checkout.css', ['bgc-leaflet'], BGCOURIERS_VERSION);
        $ver = @filemtime(BGCOURIERS_PATH . 'assets/js/bgc-order-admin.js') ?: '1';
        wp_enqueue_script('bgc-order-admin', BGCOURIERS_URL . 'assets/js/bgc-order-admin.js', ['jquery', 'selectWoo', 'bgc-leaflet'], $ver, true);
        wp_localize_script('bgc-order-admin', 'BGCOURIERS_ED', [
            'ajax'    => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bgcouriers_order_delivery'),
            // Separate nonce: the tracking refresh is an admin action, not part of saving delivery details.
            'adminNonce' => wp_create_nonce('bgcouriers_admin'),
            'orderId' => $order->get_id(),
            'caps'    => $caps,
            'leaflet_images' => BGCOURIERS_URL . 'assets/lib/leaflet/images/',
            'methodLabels' => ['office' => __('To office', 'bg-couriers'), 'address' => __('To address', 'bg-couriers'), 'automat' => __('To APS', 'bg-couriers')],
            'i18n'    => ['city' => __('City', 'bg-couriers'), 'office' => __('Office / APS', 'bg-couriers'), 'street' => __('Street', 'bg-couriers'),
                          'saving' => __('Saving…', 'bg-couriers'), 'err' => __('Could not save.', 'bg-couriers'),
                          'copied' => __('Copied to clipboard', 'bg-couriers'),
                          'trackRefreshing' => __('Asking the courier…', 'bg-couriers'),
                          'trackFailed'     => __('Could not reach the courier.', 'bg-couriers'),
                          'cancelTitle' => __('Cancel this waybill?', 'bg-couriers'),
                          'cancelBody'  => __('This voids the shipment label with the courier. This cannot be undone.', 'bg-couriers'),
                          'cancelYes'   => __('Yes, cancel it', 'bg-couriers'),
                          'cancelNo'    => __('Keep it', 'bg-couriers'),
                          'regenTitle'  => __('Re-issue this waybill?', 'bg-couriers'),
                          'regenBody'   => __('The current waybill is voided with the courier and a new one is issued from this order\'s current delivery details, products and settings. This cannot be undone.', 'bg-couriers'),
                          'regenYes'    => __('Yes, re-issue it', 'bg-couriers'),
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
        // A collected parcel cannot be re-addressed, so the form is shown READ-ONLY rather than hidden:
        // the merchant still needs to see where the box is going while they ring the courier about it.
        // Read-only means every control carries `disabled` (disable_controls, applied to the finished
        // markup below) - not merely a greyer stylesheet, which a keyboard walks straight past. Saving is
        // refused by the server too, whatever the browser does.
        $locked = BGCouriers_Labels::is_locked($order);
        $ins = BGCouriers_Order::insurance($order);
        $form = '<div class="bgc-ed"><div class="bgc-ed-form' . ($locked ? ' bgc-ed-locked' : '') . '" style="display:none;margin-top:10px;max-width:520px;">'
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
            // Not address fields - they are facts about the SHIPMENT, so they sit on their own row after
            // the address and before Save, where they read as "and how is it going" rather than "where".
            . '<div class="bgc-ed-row">'
            . '<div class="bgc-ed-fld"><label>' . esc_html__('Parcels', 'bg-couriers') . '</label>'
            . '<input type="number" min="1" max="99" step="1" class="bgc-ed-parcels" value="' . esc_attr((string) BGCouriers_Order::parcels($order)) . '"></div>'
            . '<div class="bgc-ed-fld"><label>' . esc_html__('Insure for', 'bg-couriers') . '</label>'
            . '<input type="number" min="0" step="0.01" class="bgc-ed-insurance" placeholder="0" value="' . esc_attr($ins > 0 ? (string) $ins : '') . '"></div>'
            . '</div>'
            . '<p><button type="button" class="button button-primary bgc-ed-save">'
            . esc_html__('Save delivery', 'bg-couriers') . '</button> <span class="bgc-ed-msg"></span></p>';
        if ($locked) {
            $form .= '<p class="description bgc-ed-lockmsg">' . esc_html(BGCouriers_Labels::locked_message()) . '</p>';
        } elseif ($has_waybill) {
            $form .= '<p class="description">' . esc_html__('A waybill exists. Saving voids it and issues a new one matching the new details.', 'bg-couriers') . '</p>';
        }
        $form .= '</div></div>';
        if ($locked) { $form = self::disable_controls($form); }

        echo wp_kses($form, self::EDITOR_TAGS); // every value escaped above; kses is the output-escaping gate
    }
}
