<?php
defined('ABSPATH') || exit;

if (!class_exists('WC_Settings_Page')) { return; }

/**
 * WooCommerce → Settings → "BG Couriers" tab.
 * Level 1 (WP nav-tabs): courier sections (General + Speedy).
 * Level 2 (WP nav-tabs, JS-switched, no reload): per delivery method (office/address/automat).
 * See feedback-settings-architecture - every future courier follows this shape.
 */
class BGCouriers_WC_Settings extends WC_Settings_Page {

    private static $method_labels = [];

    public function __construct() {
        $this->id    = 'bg_couriers';
        $this->label = __('BG Couriers', 'bg-couriers');
        self::$method_labels = [
            'office'  => __('To office', 'bg-couriers'),
            'address' => __('To address', 'bg-couriers'),
            'automat' => __('To APS', 'bg-couriers'),
        ];
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        parent::__construct();
    }

    /** Static stylesheet + behaviors of our settings tab, enqueued on the WC settings screen. */
    public function enqueue_assets(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'woocommerce_page_wc-settings') { return; }
        $css = BGCOURIERS_PATH . 'assets/css/bgc-settings-admin.css';
        $js  = BGCOURIERS_PATH . 'assets/js/bgc-settings-admin.js';
        wp_enqueue_style('bgc-settings-admin', BGCOURIERS_URL . 'assets/css/bgc-settings-admin.css', [], is_file($css) ? (string) filemtime($css) : BGCOURIERS_VERSION);
        wp_enqueue_script('bgc-settings-admin', BGCOURIERS_URL . 'assets/js/bgc-settings-admin.js', ['jquery'], is_file($js) ? (string) filemtime($js) : BGCOURIERS_VERSION, true);
        wp_localize_script('bgc-settings-admin', 'BGCOURIERS_SET', [
            'i18n' => [
                'unsaved' => __('Unsaved changes', 'bg-couriers'),
                // Browsers show their own wording for the leave-page prompt and ignore this; it is here
                // for the few that still honour a custom message.
                'leave'   => __('You have unsaved changes in the BG Couriers settings. Leave without saving?', 'bg-couriers'),
            ],
        ]);
    }

    protected function get_own_sections() { return $this->sections(); }
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own documented filter pattern for a WC_Settings_Page (woocommerce_get_sections_<page id>), required for WC extensions to hook our sections.
    public function get_sections() { return apply_filters('woocommerce_get_sections_' . $this->id, $this->sections()); }

    /**
     * "Auto-generate labels" on a courier's own tab: follow the general setting, or overrule it here.
     *
     * The same row on every tab rather than one written out six times - and it is a three-state select,
     * not a checkbox, because "I have not said" and "no" are different answers: a shop that turns the
     * general setting on later should get it for the couriers it never spoke about.
     */
    private static function autolabel_row(string $courier): array {
        return ['type' => 'select', 'id' => 'bgcouriers_' . $courier . '_autolabel',
            'title' => __('Auto-generate labels', 'bg-couriers'),
            'desc'  => __('Issuing a waybill is what tells some couriers the parcel exists - Sameday sends a courier for it the same day - so a shop that packs in the evening wants this off for those and on for the rest.', 'bg-couriers'),
            'options' => [
                ''    => __('Follow the general setting', 'bg-couriers'),
                'yes' => __('On - as soon as the order reaches the trigger status', 'bg-couriers'),
                'no'  => __('Off - I issue the waybill myself, when the parcel is packed', 'bg-couriers'),
            ],
            'default' => '', 'autoload' => false];
    }

    private function sections(): array {
        return [
            ''       => __('General', 'bg-couriers'),
            'speedy' => __('Speedy', 'bg-couriers'),
            'econt'  => __('Econt', 'bg-couriers'),
            'pigeon' => __('Pigeon Express', 'bg-couriers'),
            'boxnow' => __('BOX NOW', 'bg-couriers'),
            'sameday' => __('Sameday', 'bg-couriers'),
            'expressone' => __('Express One', 'bg-couriers'),
            'about'  => __('About', 'bg-couriers'),
        ];
    }

    /** Full field set for the section - used by save() (save_settings_for_current_section). */
    public function get_settings($section = '') {
        return self::no_autofill($this->build_settings($section));
    }

    /**
     * Keep the browser's password manager out of every courier credential field.
     *
     * It fills a blank "Client ID" with the merchant's own e-mail and a blank "API password" with their
     * site password, and a Save then writes those over the real credentials - which is exactly what
     * happened to the live BOX NOW account. Locking saved credentials behind the ✕ covers a courier that
     * is already configured; this covers the rest, including a courier being set up for the first time.
     *
     * Applied to the finished field list rather than to each declaration, so it holds for every courier
     * and for any added later without anyone having to remember.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    private static function no_autofill(array $fields): array {
        foreach ($fields as &$f) {
            // Every kind of secret, not just the login pair: BOX NOW's webhook secret and the Google
            // Maps key are text fields a password manager will happily fill too.
            // A field listed here must either render its stored value or keep it on a blank save
            // (sanitize_keep / sanitize_password) - readonly submits whatever was rendered, so a field
            // that renders blank without a keep-on-blank sanitizer would be cleared by an untouched save.
            if (!is_array($f) || !preg_match('/^bgcouriers_[a-z0-9_]*(username|password|secret|key)$/', (string) ($f['id'] ?? ''))) {
                continue;
            }
            $attrs = (isset($f['custom_attributes']) && is_array($f['custom_attributes'])) ? $f['custom_attributes'] : [];
            // 'new-password', not 'off': Chrome ignores 'off' on anything it reads as a login field, and
            // these are labelled "API username" / "Client ID" - precisely what it offers to fill.
            $attrs['autocomplete'] = 'new-password';
            // Autofill lands while the page is still loading, before any script of ours runs, and a
            // readonly field is passed over. bgc-settings-admin.js drops this on focus, so the field can
            // still be typed into; readonly (unlike disabled) also still submits, so a field nobody
            // touches posts blank and sanitize_keep() holds on to the stored value.
            $attrs['readonly']         = 'readonly';
            $attrs['data-bgc-nofill']  = '1';
            $f['custom_attributes']    = $attrs;
        }
        unset($f);
        return $fields;
    }

    private function build_settings($section = '') {
        if ($section === 'about') {
            return [
                ['type' => 'bgcouriers_about', 'id' => 'bgcouriers_about'],
                ['type' => 'sectionend', 'id' => 'bgcouriers_about'],
            ];
        }
        if ($section === 'speedy') {
            $f = $this->speedy_courier_fields();
            foreach (self::$method_labels as $m => $label) {
                $f = array_merge($f, $this->method_fields('speedy', $m, $label));
            }
            return $f;
        }
        if ($section === 'econt') {
            $f = $this->econt_courier_fields();
            foreach (self::$method_labels as $m => $label) {
                $f = array_merge($f, $this->method_fields('econt', $m, $label));
            }
            return $f;
        }
        if ($section === 'pigeon') {
            $f = $this->pigeon_courier_fields();
            foreach (self::$method_labels as $m => $label) {
                $f = array_merge($f, $this->method_fields('pigeon', $m, $label));
            }
            return $f;
        }
        if ($section === 'boxnow') {
            return $this->boxnow_courier_fields(); // locker-only, flat-rate → no per-method fields
        }
        if ($section === 'sameday') {
            $f = $this->sameday_courier_fields();
            foreach (self::$method_labels as $m => $label) {
                $f = array_merge($f, $this->method_fields('sameday', $m, $label));
            }
            return $f;
        }
        if ($section === 'expressone') {
            $f = $this->expressone_courier_fields();
            foreach (self::$method_labels as $m => $label) {
                $f = array_merge($f, $this->method_fields('expressone', $m, $label));
            }
            return $f;
        }
        return $this->general_fields();
    }

    protected function get_settings_for_section_core($section_id) { return $this->get_settings($section_id); }

    /** Custom output: WP nav-tab section nav + (for Speedy) per-method sub-tabs. */
    // Suppress WooCommerce's default section nav (the "subsubsub" link row) - we render our own
    // nicer nav-tabs in output(); otherwise the General/Speedy row shows twice.
    public function output_sections() {}

    public function output() {
        global $current_section;
        echo '<div class="bgc-settings">';
        $this->section_nav((string) $current_section);

        echo '<div class="bgc-group">';
        if ($current_section === 'speedy') {
            $this->output_courier('speedy');
        } elseif ($current_section === 'econt') {
            $this->output_courier('econt');
        } elseif ($current_section === 'pigeon') {
            $this->output_courier('pigeon');
        } elseif ($current_section === 'boxnow') {
            $this->output_courier('boxnow');
        } elseif ($current_section === 'sameday') {
            $this->output_courier('sameday');
        } elseif ($current_section === 'expressone') {
            $this->output_courier('expressone');
        } else {
            WC_Admin_Settings::output_fields($this->general_fields());
        }
        echo '</div></div>';

        /*
         * One quiet line at the foot of the plugin's OWN settings screen. Not a notice, not dismissible,
         * not on any other admin page, no image and no second ask - a merchant who has a question should
         * not have to go looking for where to ask it, and the donation link is the same one readme.txt
         * declares to the directory. Deliberately says the money changes nothing about support, because
         * a plugin that hints otherwise is not free in any sense that matters.
         */
        echo wp_kses_post('<p class="bgc-settings-foot">' . sprintf(
            /* translators: 1: link reading "support forum", 2: link reading "support its development" */
            esc_html__('BG Couriers is free and open source. Questions and problems: %1$s. If it saves you work you can %2$s - entirely voluntary, and it changes nothing about the support you get.', 'bg-couriers'),
            '<a href="' . esc_url('https://wordpress.org/support/plugin/bg-couriers/') . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__('the support forum', 'bg-couriers') . '</a>',
            '<a href="' . esc_url('https://revolut.me/danq6lus') . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__('support its development', 'bg-couriers') . '</a>'
        ) . '</p>');

        // Turn every field description into a small (i) that sits inline right after the field label. Text /
        // select / number fields print their description as a <span class="description"> in the value cell; a
        // checkbox prints it as a raw text node inside its <label>. Pull that text out into a (i) on the label
        // and drop the inline copy. Descriptions with a link or <code> (e.g. the webhook URL) are left inline.
        // (implemented in assets/js/bgc-settings-admin.js)

        // AJAX "Save changes" - save without a page reload, with a top-right toast (green ok / red error).
        $save_nonce = esc_js(wp_create_nonce('bgcouriers_save'));
        $ajaxurl    = esc_js(admin_url('admin-ajax.php'));
        $sect       = esc_js((string) $current_section);
        $i_saved    = esc_js(__('Saved', 'bg-couriers'));
        $i_failed   = esc_js(__('Could not save - please try again.', 'bg-couriers'));
        BGCouriers_Settings::inline_js("\n"
            . "(function(\$){\n"
            . "    var ajaxurl='" . $ajaxurl . "', nonce='" . $save_nonce . "', section='" . $sect . "';\n"
            . "    function toast(msg,type,ms){ var c=\$('#bgc-toasts'); if(!c.length){ c=\$('<div id=\"bgc-toasts\"></div>').appendTo('body'); }\n"
            . "        var t=\$('<div class=\"bgc-toast bgc-toast-'+type+'\">'+'</div>').text(msg).appendTo(c);\n"
            . "        requestAnimationFrame(function(){ t.addClass('show'); });\n"
            . "        setTimeout(function(){ t.removeClass('show'); setTimeout(function(){ t.remove(); }, 320); }, ms||3000); }\n"
            // One toast implementation on the page, reused by anything else that needs to say something.
            . "    window.bgcToast = toast;\n"
            . "    var form=\$('#mainform'); if(!form.length){ return; }\n"
            . "    function busy(save,on){ save.prop('disabled',on).toggleClass('is-busy',on); }\n"
            . "    form.on('click', 'button[name=\"save\"], input[name=\"save\"]', function(e){\n"
            . "        e.preventDefault(); e.stopImmediatePropagation();\n"
            . "        var save=\$(this); busy(save,true);\n"
            . "        var data=form.serialize()+'&action=bgcouriers_save_settings&bgcouriers_nonce='+nonce+'&bgcouriers_section='+encodeURIComponent(section)+'&save=1';\n"
            . "        \$.post(ajaxurl,data).done(function(r){\n"
            . "            if(r&&r.success){ toast((r.data&&r.data.msg)||'" . $i_saved . "','ok',2500); \$(document).trigger('bgc:saved',[r.data||{}]); }\n"
            . "            else { toast((r&&r.data&&r.data.msg)||'" . $i_failed . "','err',7000); }\n"
            . "        }).fail(function(){ toast('" . $i_failed . "','err',7000); }).always(function(){ busy(save,false); });\n"
            . "    });\n"
            . "})(jQuery);\n"
        );
    }

    /** Brand colour per courier - original, trademark-safe (not the couriers' logos). */
    /**
     * The country choices for a courier's "Also deliver to" - what the COURIER can do, named the way
     * WooCommerce names countries so the merchant reads one vocabulary across the two screens. Never a
     * free list of the world: a country not in here has not been measured, and offering it would promise
     * a delivery the plugin cannot price.
     *
     * @return array<string,string> ISO alpha-2 => country name.
     */
    private static function intl_country_options(string $courier): array {
        $co = class_exists('BGCouriers_Couriers') ? BGCouriers_Couriers::get($courier) : null;
        $iso = ($co && method_exists($co, 'intl_countries')) ? $co->intl_countries() : [];
        // WC_Countries directly rather than WC()->countries: this list is built while the settings page
        // is assembled, which happens in contexts (and tests) where the WC() singleton is not up yet.
        $names = class_exists('WC_Countries') ? (array) (new WC_Countries())->get_countries() : [];
        $out = [];
        foreach ($iso as $c) { $out[$c] = (string) ($names[$c] ?? $c); }
        return $out;
    }

    /**
     * What the merchant has to know before switching a country on, and only what is true for THIS shop.
     *
     * Three things beyond the tick decide whether a parcel can actually go: a WooCommerce shipping zone
     * that carries this courier, the towns and offices that arrive with the next Sync, and - for a shop
     * whose cash-on-delivery is legal only because the courier does the ППП - a prepaid way to pay, since
     * no courier's ППП crosses the border. The last sentence is left out where it does not apply: a shop
     * with a cash register is not warned about an arrangement it does not use.
     *
     * @param string $courier Courier id.
     * @param string $name    The courier's own name, as it reads in the sentence.
     * @return string
     */
    private static function intl_countries_desc(string $courier, string $name): string {
        $desc = sprintf(
            /* translators: %s: courier name */
            __('Countries besides Bulgaria that %s may deliver to. Leave empty for a Bulgaria-only shop. Two more things have to be true for an order to reach here: the country must be in a WooCommerce shipping zone that carries this courier\'s method, and the towns and offices for it come with the next Sync.', 'bg-couriers'),
            $name
        );
        if (class_exists('BGCouriers_Settings')
            && BGCouriers_Settings::cod_fiscalization() === 'ppp'
            && BGCouriers_Settings::courier_ppp_payout($courier)) {
            $desc .= ' ' . __('Cash on delivery does not travel: ППП is a Bulgarian postal money transfer and the courier refuses it for a foreign address, so international orders here can only be prepaid ones. Add a card or bank-transfer payment method, or they will have no way to pay.', 'bg-couriers');
            // Said as a warning rather than as advice once it is no longer hypothetical: countries are
            // chosen here, and with nothing to prepay with they are chosen for nothing - the checkout
            // offers no delivery price for them at all. A merchant who reads this field is the one who
            // can fix it, and finding out from a customer's empty checkout instead is how it went.
            // The merchant's own choice, read straight from the option rather than through
            // BGCouriers_Settings::intl_countries(): that one intersects with what the courier supports
            // via the registry, and what matters here is only that something was picked.
            $picked = get_option('bgcouriers_' . $courier . '_intl_countries', []);
            if (!BGCouriers_Settings::has_prepaid_gateway() && is_array($picked) && $picked) {
                $desc .= ' <strong>' . esc_html__('Your shop has no prepaid payment method enabled right now, so orders to these countries cannot be paid for at all: no delivery price is offered for them at checkout.', 'bg-couriers') . '</strong>';
            }
        }
        return $desc;
    }

    private static function courier_color(string $id): string {
        $map = ['speedy' => '#E30613', 'econt' => '#0072BC', 'pigeon' => '#F58220', 'boxnow' => '#00B4A0', 'sameday' => '#A50034', 'expressone' => '#E0189B'];
        return $map[$id] ?? '#6b7280';
    }

    /**
     * Courier brand logo shown before the name on its tab. Uses the bundled logo when present,
     * and falls back to an original brand-coloured parcel badge otherwise.
     */
    private static function courier_icon(string $id): string {
        $url = BGCouriers_Couriers::logo_url($id);
        if ($url !== '') {
            return '<img class="bgc-tab-ico" src="' . esc_url($url)
                . '" alt="" aria-hidden="true" width="16" height="16">';
        }
        $c = esc_attr(self::courier_color($id));
        return '<svg class="bgc-tab-ico" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            . '<rect x="1" y="1" width="22" height="22" rx="5" fill="' . $c . '"></rect>'
            . '<path d="M12 5.4l5.6 2.8v7.6L12 18.6 6.4 15.8V8.2L12 5.4z" fill="none" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"></path>'
            . '<path d="M6.6 8.4L12 11.1l5.4-2.7M12 11.1v7.4" fill="none" stroke="#fff" stroke-width="1.2"></path></svg>';
    }

    private function nav_pill(string $id, string $label, string $current, bool $courier): string {
        $url    = admin_url('admin.php?page=wc-settings&tab=bg_couriers' . ($id ? '&section=' . $id : ''));
        $active = $current === $id ? ' nav-tab-active' : '';
        // Green when the courier is enabled AND usable; red when disabled OR currently unusable (e.g. it can't
        // do ППП and the shop has no prepaid method, so it won't appear at checkout).
        $tint = '';
        if ($id !== '') {
            $on       = get_option('bgcouriers_' . $id . '_enabled', 'no') === 'yes';
            $notice   = BGCouriers_Settings::courier_blocker($id);
            $unusable = $notice && $notice['level'] === 'error';
            $tint     = ($on && !$unusable) ? ' bgc-tab-on' : ' bgc-tab-off';
        }
        $cls = 'nav-tab' . $active . $tint . ($courier ? ' bgc-courier-tab' : '');
        $ico    = $courier ? self::courier_icon($id) : '';
        return '<a class="' . $cls . '" href="' . esc_url($url) . '" data-courier="' . esc_attr($id) . '">' . $ico . '<span>' . esc_html($label) . '</span></a>';
    }

    private function section_nav(string $current): void {
        $sections = $this->sections();
        echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper bgc-section-nav">';
        if (array_key_exists('', $sections)) { echo wp_kses($this->nav_pill('', $sections[''], $current, false), BGCouriers_Kses::admin_actions()); }
        echo '<span class="bgc-courier-tabs">'; // draggable couriers, in the saved order
        foreach (BGCouriers_Settings::courier_order() as $cid) {
            if (isset($sections[$cid])) { echo wp_kses($this->nav_pill($cid, $sections[$cid], $current, true), BGCouriers_Kses::admin_actions()); }
        }
        echo '</span></nav>';
        wp_enqueue_script('jquery-ui-sortable');
        $ajax  = esc_js(admin_url('admin-ajax.php'));
        $nonce = esc_js(wp_create_nonce('bgcouriers_admin'));
        BGCouriers_Settings::inline_js("\n"
            . "jQuery(function(\$){\n"
            . "    var c = \$('.bgc-courier-tabs'); if (!c.length || !\$.fn.sortable) { return; }\n"
            . "    var dragged = false;\n"
            . "    c.sortable({ items: '> .bgc-courier-tab', distance: 6, cursor: 'move', tolerance: 'pointer', opacity: .85,\n"
            . "        start: function(){ dragged = true; },\n"
            . "        stop: function(){ setTimeout(function(){ dragged = false; }, 0); },\n"
            . "        update: function(){\n"
            . "            var order = c.children('.bgc-courier-tab').map(function(){ return \$(this).data('courier'); }).get().join(',');\n"
            . "            \$.post('" . $ajax . "', { action: 'bgcouriers_save_order', nonce: '" . $nonce . "', order: order });\n"
            . "        }\n"
            . "    });\n"
            . "    c.on('click', '.bgc-courier-tab', function(e){ if (dragged) { e.preventDefault(); } });\n"
            . "});\n"
        );

        // A square refresh button beside the tracking schedule: the schedule answers "how often,
        // unattended", this answers "I want to know now". Built in JS because WooCommerce renders the
        // select itself and there is no field type for "a control glued to another control".
        $t_now  = esc_js(__('Update tracking now', 'bg-couriers'));
        $t_done = esc_js(__('Tracking updated', 'bg-couriers'));
        $t_fail = esc_js(__('Could not update - please try again.', 'bg-couriers'));
        BGCouriers_Settings::inline_js("\n"
            . "jQuery(function(\$){\n"
            . "    var sel = \$('#bgcouriers_tracking_poll'); if (!sel.length) { return; }\n"
            . "    var b = \$('<button type=\"button\" class=\"button bgc-poll-now\" title=\"" . $t_now . "\" aria-label=\"" . $t_now . "\"><span class=\"dashicons dashicons-update\"></span></button>');\n"
            . "    sel.after(b);\n"
            . "    b.on('click', function(){\n"
            . "        if (b.hasClass('bgc-busy')) { return; }\n"
            . "        b.addClass('bgc-busy').prop('disabled', true);\n"
            . "        \$.post('" . $ajax . "', { action: 'bgcouriers_poll_now', nonce: '" . $nonce . "' })\n"
            . "            .done(function(r){ window.bgcToast ? window.bgcToast((r && r.data && r.data.msg) || '" . $t_done . "', 'ok') : null; })\n"
            . "            .fail(function(){ window.bgcToast ? window.bgcToast('" . $t_fail . "', 'err') : null; })\n"
            . "            .always(function(){ b.removeClass('bgc-busy').prop('disabled', false); });\n"
            . "    });\n"
            . "});\n"
        );
    }

    /**
     * Render the courier settings section (courier fields + per-method sub-tabs).
     * Works for any courier id (speedy, econt, …).
     */
    private function output_courier(string $courier_id): void {
        $fields    = $this->{$courier_id . '_courier_fields'}();
        $enable_id = 'bgcouriers_' . $courier_id . '_enabled';
        // The enable toggle, the ППП notice and the API-credentials hint all render as prominent FULL-WIDTH
        // blocks at the top of the tab (outside the form-table, which otherwise auto-sizes them narrow), so
        // pull them out of the field list here and echo them directly below.
        $fields = array_values(array_filter($fields, static function ($f) use ($enable_id) {
            if (isset($f['id']) && $f['id'] === $enable_id) { return false; }
            $type = $f['type'] ?? '';
            return $type !== 'bgcouriers_ppp_notice' && $type !== 'bgcouriers_cred_hint';
        }));
        $on    = get_option($enable_id, 'no') === 'yes';
        $label = $this->sections()[$courier_id] ?? ucfirst($courier_id);
        $on_t  = esc_html__('enabled', 'bg-couriers');
        $off_t = esc_html__('disabled', 'bg-couriers');
        echo '<div class="bgc-enable-toggle ' . ($on ? 'bgc-enable-on' : 'bgc-enable-off') . '" data-on="' . esc_attr($on_t) . '" data-off="' . esc_attr($off_t) . '">'
            . '<label class="bgc-switch"><input type="checkbox" name="' . esc_attr($enable_id) . '" value="1"' . checked($on, true, false) . '><span class="bgc-slider"></span></label>'
            . '<span class="bgc-enable-text"><strong>' . esc_html($label) . '</strong> - <span class="bgc-enable-state">' . esc_html($on ? $on_t : $off_t) . '</span></span>'
            . '</div>';
        BGCouriers_Settings::ppp_notice_block($courier_id); // full-width, escaped internally
        BGCouriers_Settings::cred_hint_block($courier_id);  // full-width, escaped internally
        WC_Admin_Settings::output_fields($fields);
        $c_id    = esc_js($courier_id);
        $c_ajax  = esc_js(admin_url('admin-ajax.php'));
        $c_save  = esc_js(wp_create_nonce('bgcouriers_save'));
        $c_admin = esc_js(wp_create_nonce('bgcouriers_admin'));
        /* translators: %s: courier name */
        $i_title = esc_js(sprintf(__('“%s” can’t be enabled yet', 'bg-couriers'), $label));
        $i_intro = esc_js(__('Please fix the following, then enable it again:', 'bg-couriers'));
        $i_fix   = esc_js(__('How to fix:', 'bg-couriers'));
        $i_close = esc_js(__('Close', 'bg-couriers'));
        BGCouriers_Settings::inline_js("\n"
            . "(function(\$){\n"
            . "    var courier='" . $c_id . "', ajaxurl='" . $c_ajax . "', saveNonce='" . $c_save . "', adminNonce='" . $c_admin . "', section='" . $c_id . "';\n"
            . "    function esc(s){ return \$('<i>').text(s==null?'':s).html(); }\n"
            . "    function saveForm(cb){ var f=\$('#mainform'); if(!f.length){ if(cb){cb();} return; }\n"
            . "        \$.post(ajaxurl, f.serialize()+'&action=bgcouriers_save_settings&bgcouriers_nonce='+saveNonce+'&bgcouriers_section='+encodeURIComponent(section)).always(function(){ if(cb){cb();} }); }\n"
            . "    function setVis(box,on){ box.toggleClass('bgc-enable-on',on).toggleClass('bgc-enable-off',!on);\n"
            . "        box.find('.bgc-enable-state').text(on?box.data('on'):box.data('off'));\n"
            . "        \$('.bgc-settings .nav-tab-active').toggleClass('bgc-tab-on',on).toggleClass('bgc-tab-off',!on); }\n"
            . "    function showProblems(list){\n"
            . "        var h='<div class=\"bgc-enable-modal\"><div class=\"bgc-enable-box\"><h3>" . $i_title . "</h3><p>" . $i_intro . "</p><ul>';\n"
            . "        (list||[]).forEach(function(p){ h+='<li><strong>'+esc(p.msg)+'</strong>'+(p.fix?'<br><span class=\"bgc-fix\">" . $i_fix . " '+esc(p.fix)+'</span>':'')+'</li>'; });\n"
            . "        h+='</ul><p><button type=\"button\" class=\"button button-primary bgc-enable-close\">" . $i_close . "</button></p></div></div>';\n"
            . "        var m=\$(h).appendTo('body');\n"
            . "        m.on('click',function(e){ if(e.target===this||\$(e.target).hasClass('bgc-enable-close')){ m.remove(); } });\n"
            . "    }\n"
            . "    \$(document).on('change','.bgc-enable-toggle input[type=checkbox]',function(){\n"
            . "        var cb=\$(this), on=this.checked, box=cb.closest('.bgc-enable-toggle');\n"
            . "        setVis(box,on);\n"
            . "        if(!on){ saveForm(); return; }\n"
            . "        cb.prop('disabled',true);\n"
            . "        saveForm(function(){\n"
            . "            \$.post(ajaxurl,{action:'bgcouriers_enable_check',nonce:adminNonce,courier:courier}).done(function(r){\n"
            . "                if(!(r&&r.success)){ cb.prop('checked',false); setVis(box,false); saveForm(); showProblems(r&&r.data&&r.data.problems); }\n"
            . "            }).always(function(){ cb.prop('disabled',false); });\n"
            . "        });\n"
            . "    });\n"
            . "})(jQuery);\n"
        );

        // Delivery-method sub-tabs - only the methods this courier actually offers (available_methods() =
        // capabilities pruned by real synced point counts, so e.g. Pigeon shows no "to APS" tab: no lockers).
        // Skip entirely for single-method / flat-rate couriers (e.g. BoxNow = locker only, one flat price).
        $c       = BGCouriers_Couriers::get($courier_id);
        $caps    = $c ? array_values(array_diff($c->available_methods(), ['live_quote'])) : array_keys(self::$method_labels);
        $methods = array_filter(self::$method_labels, static function ($m) use ($caps) { return in_array($m, $caps, true); }, ARRAY_FILTER_USE_KEY);
        // Show the tabs (and panels) in the merchant's saved drag order.
        $ordered = [];
        foreach (BGCouriers_Settings::method_order($courier_id) as $m) { if (isset($methods[$m])) { $ordered[$m] = $methods[$m]; } }
        if ($ordered) { $methods = $ordered; }
        if (count($methods) > 1) {
            // Level-2 nav-tabs for delivery methods - drag to reorder (saves bgcouriers_<courier>_method_order); JS-switched panels.
            echo '<h2 class="nav-tab-wrapper bgc-method-nav" data-courier="' . esc_attr($courier_id)
                . '" data-nonce="' . esc_attr(wp_create_nonce('bgcouriers_admin')) . '" style="margin-top:1.5em;">';
            $first = true;
            foreach ($methods as $m => $label) {
                $mid = 'bgcouriers_' . $courier_id . '_' . $m . '_enabled';
                $mon = get_option($mid, 'yes') === 'yes';
                echo '<a href="#" class="nav-tab bgc-method-tab' . ($first ? ' nav-tab-active' : '') . ($mon ? ' bgc-tab-on' : ' bgc-tab-off') . '" data-bgc-tab="' . esc_attr($m) . '">'
                    . '<label class="bgc-switch bgc-switch-sm" onclick="event.stopPropagation();"><input type="checkbox" name="' . esc_attr($mid) . '" value="1"' . checked($mon, true, false) . '><span class="bgc-slider"></span></label>'
                    . '<span>' . esc_html($label) . '</span></a>';
                $first = false;
            }
            echo '</h2>';

            $first = true;
            foreach ($methods as $m => $label) {
                // Only the price field renders in the panel - the enable control is the tab toggle (still saved via get_settings()).
                $en = 'bgcouriers_' . $courier_id . '_' . $m . '_enabled';
                $mf = array_values(array_filter($this->method_fields($courier_id, $m, $label), static function ($f) use ($en) {
                    return !(isset($f['id']) && $f['id'] === $en);
                }));
                echo '<div class="bgc-method-panel" data-bgc-panel="' . esc_attr($m) . '"' . ($first ? '' : ' style="display:none;"') . '>';
                WC_Admin_Settings::output_fields($mf);
                echo '</div>';
                $first = false;
            }
        }
        // (method-nav styles + behaviors live in assets/css|js/bgc-settings-admin.*)
    }

    private function general_fields(): array {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : ['wc-processing' => 'Processing'];
        // From the courier REGISTRY, not from sections() - those are the settings TABS, and skipping only
        // '' (General) let the "About" tab through as a selectable default courier. The registry is the
        // single source of truth, so a new courier appears here on its own and no tab can leak in again.
        $courier_opts = ['' => __('Automatic (first / cheapest)', 'bg-couriers')];
        foreach (BGCouriers_Couriers::all() as $cid => $clabel) { $courier_opts[$cid] = $clabel; }
        // One colour picker per courier for the orders-list row tint, generated from the registry so a new
        // courier gets its field automatically. Defaults live on BGCouriers_Order_Columns (single source of truth).
        $row_colors = [];
        foreach (BGCouriers_Couriers::all() as $cid => $clabel) {
            $row_colors[] = ['type' => 'color', 'id' => 'bgcouriers_' . $cid . '_row_color',
                /* translators: %s: courier name */
                'title' => sprintf(__('%s row colour', 'bg-couriers'), $clabel),
                'default' => BGCouriers_Order_Columns::ROW_COLORS[$cid] ?? '#cccccc',
                'css' => 'width:6em;', 'autoload' => false];
        }
        return [
            // --- Checkout experience ---
            ['type' => 'title', 'id' => 'bgcouriers_checkout', 'title' => __('Checkout', 'bg-couriers'),
                'desc' => __('How the checkout delivery picker behaves. Reorder couriers by dragging their tabs above; prices show in the store currency.', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgcouriers_default_courier', 'title' => __('Default courier', 'bg-couriers'),
                'desc' => __('Which courier is pre-selected at checkout (its first delivery option is the default).', 'bg-couriers'),
                'options' => $courier_opts, 'default' => ''],
            ['type' => 'number', 'id' => 'bgcouriers_dropdown_limit', 'title' => __('Checkout dropdown results', 'bg-couriers'),
                'desc' => __('How many matches the city and street searches return. Office lists are never limited - the whole list for the chosen city is loaded. Raise it if customers report not finding their town; very high values make each keystroke slower on phones.', 'bg-couriers'),
                'default' => BGCouriers_Settings::DROPDOWN_LIMIT, 'custom_attributes' => ['min' => '1', 'step' => '1']],
            ['type' => 'checkbox', 'id' => 'bgcouriers_preload_cities', 'title' => __('Preload city lists', 'bg-couriers'),
                'desc' => __('Embed each courier’s office/APS city list so those dropdowns open instantly. Address search stays live. Recommended.', 'bg-couriers'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_allmap', 'title' => __('Interactive map of all couriers', 'bg-couriers'),
                'desc' => __('Adds one map above the shipping methods showing every enabled courier’s offices and lockers for a city at once, with each courier’s price, so a customer can choose by where they will collect rather than by courier. Choosing a point fills in the courier, delivery type and office. Each courier keeps its own map button as well. On by default.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_allmap_nearest', 'title' => __('“Nearest to you” on the map', 'bg-couriers'),
                'desc' => __('On the interactive map, offer to find the customer’s location and then show how far each pickup point is, which one is closest, and what collecting there saves against delivery to the address. The distances are worked out in the customer’s own browser; their position is never stored on the site and is forgotten when they leave the page. The one time the coordinates leave the browser is when the customer presses “find me” before naming a town - those are then reverse-geocoded to fill the town in for them (see the External services notice). On by default.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_address_map', 'title' => __('Address map picker', 'bg-couriers'),
                'desc' => __('Adds a “Choose on map” pin picker to address delivery that fills the address automatically. When a customer drops a pin, its coordinates are sent to OpenStreetMap Nominatim (or Google, if a key is set below) to look up the address. Off by default.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_google_maps_key', 'title' => __('Google Maps API key (optional)', 'bg-couriers'),
                'desc' => __('Used for one thing only: turning a point picked on the map into an address. Leave it empty and OpenStreetMap Nominatim does that free of charge; the maps themselves are OpenStreetMap either way. The key needs Google’s “Geocoding API” and is only ever used from the server.', 'bg-couriers'),
                'default' => '', 'autoload' => false],
            ['type' => 'checkbox', 'id' => 'bgcouriers_own_address_fields', 'title' => __('Use the plugin’s own address fields', 'bg-couriers'),
                'desc' => __('Remove WooCommerce’s Address / City / Region / Post code at checkout - the courier’s city, office or automat chosen here IS the delivery address, and asking twice lets the two disagree. Turn this off if your store also ships some other way and needs those fields. On by default.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_hide_shipping_calc', 'title' => __('Hide the cart shipping calculator', 'bg-couriers'),
                'desc' => __('Hide WooCommerce’s “Calculate shipping” box on the cart. It prices a delivery to a post code, while every price here is for the office, automat or address picked at checkout - so it would show a number the customer never pays. On by default.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_require_email', 'title' => __('Require an e-mail address', 'bg-couriers'),
                'desc' => __('Insist on an e-mail address at checkout. The plugin makes the field optional by default - a courier waybill is built with the phone number, not with an e-mail - so turn this on if your shop needs the address for its own order e-mails or invoices. The phone number is always required. Do not also hide the e-mail field below: a required field nobody can see cannot be filled in, and the order cannot be placed.', 'bg-couriers'),
                'default' => 'no'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_hide_country', 'title' => __('Hide "Country" field', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'textarea', 'id' => 'bgcouriers_hidden_fields', 'title' => __('Hidden checkout fields (CSS selectors)', 'bg-couriers'),
                'desc' => __('Comma-separated CSS selectors to hide on checkout (e.g. #billing_company_field, .cart-subtotal).', 'bg-couriers'),
                'css' => 'min-width:400px;height:90px;', 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgcouriers_checkout'],

            // --- Prices & display ---
            ['type' => 'title', 'id' => 'bgcouriers_pricing', 'title' => __('Prices & display', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_cart_estimate_enabled', 'title' => __('Shipping estimate on the cart', 'bg-couriers'),
                'desc' => __('On the cart page, show a rough cached shipping price per courier/option (the exact price is calculated at checkout). Off by default.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_free_shipping_label', 'title' => __('Free shipping label', 'bg-couriers'),
                'desc' => __('Text shown for the shipping price when a method is free (e.g. “Free shipping”).', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgcouriers_pricing'],

            // --- Orders list ---
            ['type' => 'title', 'id' => 'bgcouriers_orderslist', 'title' => __('Orders list', 'bg-couriers'),
                'desc' => __('Colour each order row by its courier, so the mix of couriers in the list is readable at a glance. Pick a normal, saturated colour - the row is painted with a pale version of it, keeping the text readable. Clear a colour to leave that courier untinted.', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_row_tint', 'title' => __('Colour orders by courier', 'bg-couriers'),
                'desc' => __('Tint each row in the orders list with its courier’s colour.', 'bg-couriers'), 'default' => 'yes'],
            ...$row_colors,
            ['type' => 'sectionend', 'id' => 'bgcouriers_orderslist'],

            ['type' => 'title', 'id' => 'bgcouriers_cod', 'title' => __('Cash on delivery (наложен платеж)', 'bg-couriers'),
                'desc' => __('How you fiscalise collected COD. With ППП (no cash register), a courier that lacks ППП needs prepayment at checkout (or is hidden). Enable ППП per courier on its tab.', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgcouriers_cod_fiscalization', 'title' => __('COD fiscalisation', 'bg-couriers'),
                'options' => [
                    'cash_register' => __('I issue the receipt myself (I have a cash register)', 'bg-couriers'),
                    'ppp'           => __('I rely on the courier\'s postal money transfer / ППП (no cash register)', 'bg-couriers'),
                ],
                'default' => 'cash_register'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_cod'],

            ['type' => 'title', 'id' => 'bgcouriers_labels', 'title' => __('Label generation', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_autolabel_enabled',
                'title' => __('Auto-generate labels', 'bg-couriers'),
                'desc' => __('Automatically generate a shipping label when an order reaches the status below. This is the default for every courier; each courier\'s own tab can overrule it - which matters because issuing a waybill is what tells some couriers a parcel is ready to be collected.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'select', 'id' => 'bgcouriers_autolabel_status', 'title' => __('Trigger status', 'bg-couriers'),
                'options' => $statuses, 'default' => 'wc-processing'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_send_email',
                'title' => __('Share customer email with courier', 'bg-couriers'),
                'desc' => __('Put the customer’s e-mail on the shipment for courier notifications, when provided.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_autoregen_on_change',
                'title' => __('Re-issue the waybill when the order changes', 'bg-couriers'),
                'desc' => __('If an order that already has a waybill is edited in a way the courier needs to know about - the delivery address, the contents, the weight or the amount to collect - void it and issue a new one automatically. Edits that do not affect the shipment (status, notes) never trigger it.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'text', 'id' => 'bgcouriers_shipment_contents', 'title' => __('Shipment contents (description)', 'bg-couriers'),
                'desc' => __('Short description of the parcel contents, printed on every courier\'s waybill (e.g. “Хранителни добавки”). Empty = a generic value.', 'bg-couriers'),
                'default' => '', 'custom_attributes' => ['placeholder' => 'Goods']],
            ['type' => 'number', 'id' => 'bgcouriers_box_length', 'title' => __('Default parcel length (cm)', 'bg-couriers'),
                'desc' => __('Default parcel size sent to every courier whose API takes dimensions (a locker parcel must fit its box), used when an order has none of its own.', 'bg-couriers'),
                'default' => '10', 'custom_attributes' => ['min' => '1', 'step' => '1']],
            ['type' => 'number', 'id' => 'bgcouriers_box_width', 'title' => __('Default parcel width (cm)', 'bg-couriers'),
                'desc' => __('Default parcel size sent to every courier whose API takes dimensions (a locker parcel must fit its box), used when an order has none of its own.', 'bg-couriers'),
                'default' => '10', 'custom_attributes' => ['min' => '1', 'step' => '1']],
            ['type' => 'number', 'id' => 'bgcouriers_box_height', 'title' => __('Default parcel height (cm)', 'bg-couriers'),
                'desc' => __('Default parcel size sent to every courier whose API takes dimensions (a locker parcel must fit its box), used when an order has none of its own.', 'bg-couriers'),
                'default' => '2', 'custom_attributes' => ['min' => '1', 'step' => '1']],
            ['type' => 'number', 'id' => 'bgcouriers_default_weight_kg', 'title' => __('Default parcel weight (kg)', 'bg-couriers'),
                'desc' => __('Weight declared on the waybill when none of the ordered products has a weight set. Set weights on your products to have the real weight sent instead.', 'bg-couriers'),
                'default' => '1', 'custom_attributes' => ['min' => '0.1', 'step' => '0.1']],
            ['type' => 'sectionend', 'id' => 'bgcouriers_labels'],

            ['type' => 'title', 'id' => 'bgcouriers_tracking', 'title' => __('Shipment tracking', 'bg-couriers'),
                'desc' => __('Poll couriers for tracking updates and note them on the order (BOX NOW uses its webhook). Only active shipments from the last 45 days.', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgcouriers_tracking_poll', 'title' => __('Auto-update tracking', 'bg-couriers'),
                'options' => [
                    'off'        => __('Off', 'bg-couriers'),
                    'bgcouriers_30min'  => __('Every 30 minutes', 'bg-couriers'),
                    'hourly'     => __('Hourly', 'bg-couriers'),
                    'bgcouriers_6h' => __('4 times a day', 'bg-couriers'),
                    'twicedaily' => __('Twice a day', 'bg-couriers'),
                    'daily'      => __('Once a day', 'bg-couriers'),
                ],
                'default' => 'twicedaily'],
            ['type' => 'select', 'id' => 'bgcouriers_autostatus_on_shipped', 'title' => __('On pickup, set order to', 'bg-couriers'),
                'desc' => __('Optionally move the order to this status once tracking shows the courier has actually taken the parcel - not when the waybill is created. Off by default. The plugin adds a “Shipped” status for exactly this.', 'bg-couriers'),
                'options' => array_merge(['' => __('Do not change (note only)', 'bg-couriers')], $statuses),
                'default' => ''],
            ['type' => 'select', 'id' => 'bgcouriers_autostatus_on_returned', 'title' => __('On return, set order to', 'bg-couriers'),
                'desc' => __('Optionally move the order to this status once the refused parcel is back WITH YOU - not while it is travelling back. Cancelling is the usual choice: it puts the goods back into stock.', 'bg-couriers'),
                'options' => array_merge(['' => __('Do not change (note only)', 'bg-couriers')], $statuses),
                'default' => ''],
            ['type' => 'select', 'id' => 'bgcouriers_autostatus_on_delivered', 'title' => __('On delivery, set order to', 'bg-couriers'),
                'desc' => __('Optionally move the order to this status when tracking reports delivery. “Do not change” only adds a note.', 'bg-couriers'),
                'options' => array_merge(['' => __('Do not change (note only)', 'bg-couriers')], $statuses),
                'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgcouriers_tracking'],

            ['type' => 'title', 'id' => 'bgcouriers_emergency', 'title' => __('Emergency contact', 'bg-couriers'),
                'desc' => __('After repeated failed checkout attempts, show a one-time help box with a phone link. Empty phone = disabled.', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgcouriers_emergency_phone', 'title' => __('Help phone number', 'bg-couriers'),
                'custom_attributes' => ['placeholder' => '+359888123456']],
            ['type' => 'textarea', 'id' => 'bgcouriers_emergency_message', 'title' => __('Help message', 'bg-couriers'),
                'desc' => __('Shown above the phone link. Leave empty for a default message.', 'bg-couriers'),
                'css' => 'min-width:400px;height:70px;'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_emergency'],
        ];
    }

    private function speedy_courier_fields(): array {
        // "Also deliver to" is left out of the page entirely while international delivery is off (see
        // BGCouriers_Settings::intl_enabled()) - not rendered empty. WooCommerce saves every field the
        // page declares, and a multiselect with no options posts nothing: showing it would quietly wipe
        // the merchant's saved countries on their next Save, and those are meant to survive until the
        // feature is finished.
        $intl = BGCouriers_Settings::intl_enabled() ? [
            ['type' => 'multiselect', 'id' => 'bgcouriers_speedy_intl_countries', 'title' => __('Also deliver to', 'bg-couriers'),
                'options' => self::intl_country_options('speedy'), 'class' => 'wc-enhanced-select', 'css' => 'min-width:300px;',
                'desc' => self::intl_countries_desc('speedy', __('Speedy', 'bg-couriers')),
                'default' => []],
        ] : [];
        return [
            ['type' => 'title', 'id' => 'bgcouriers_speedy', 'title' => ''],
            ['type' => 'bgcouriers_ppp_notice', 'id' => 'bgcouriers_ppp_notice_speedy', 'courier' => 'speedy'],
            ['type' => 'bgcouriers_cred_hint', 'id' => 'bgcouriers_speedy_credhint', 'courier' => 'speedy'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_speedy_enabled', 'title' => __('Enable Speedy', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_speedy_username', 'title' => __('API username', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'password', 'id' => 'bgcouriers_speedy_password', 'title' => __('API password', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgcouriers_actions', 'id' => 'bgcouriers_speedy_actions'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_speedy'],

            ['type' => 'title', 'id' => 'bgcouriers_speedy_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgcouriers_speedy_label_paper_size', 'title' => __('Label paper size', 'bg-couriers'),
                'options' => ['A6' => __('A6 (label printer)', 'bg-couriers'), 'A4' => __('A4 (office printer)', 'bg-couriers')],
                'default' => 'A6'],
            ['type' => 'select', 'id' => 'bgcouriers_speedy_package', 'title' => __('Package type', 'bg-couriers'),
                'options' => [
                    'BOX'      => __('Box', 'bg-couriers'),
                    'ENVELOPE' => __('Envelope', 'bg-couriers'),
                    'PALLET'   => __('Pallet', 'bg-couriers'),
                ],
                'default' => 'BOX'],
            ...$intl,
            self::autolabel_row('speedy'),
            ['type' => 'sectionend', 'id' => 'bgcouriers_speedy_delivery'],

            ['type' => 'title', 'id' => 'bgcouriers_speedy_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_speedy_ship_in_total', 'title' => __('Delivery in the order total', 'bg-couriers'),
                'desc' => __('On: the customer pays delivery together with the order. Off: delivery is not charged at checkout - the estimated price is shown for information and the customer pays the courier on delivery; cash on delivery then collects only the goods total.', 'bg-couriers'),
                'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_speedy_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Ship Speedy free above this goods total (excluding shipping). Set here it applies to ALL delivery options (their own thresholds become inactive); leave empty to set thresholds per delivery option. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'select', 'id' => 'bgcouriers_speedy_declared_value', 'title' => __('Declared value', 'bg-couriers'),
                'desc' => __('Speedy charges a premium for it, and pays a claim only against documents most shops cannot produce. Leave off unless you have agreed the claims process with them. Declaring a value is supported for Speedy and Sameday; the other couriers ignore it.', 'bg-couriers'),
                'options' => [
                    'no'  => __('Do not declare a value', 'bg-couriers'),
                    'cod' => __('Declare the cash-on-delivery amount', 'bg-couriers'),
                ],
                'default' => 'no'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_speedy_return_voucher', 'title' => __('Return waybill', 'bg-couriers'),
                'desc' => __('Send a prepaid return waybill with every shipment, so a customer can send the parcel back without paying or arranging anything. Speedy charges for it. Supported for Speedy only - the other couriers have no equivalent here yet.', 'bg-couriers'),
                'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_speedy_pricing'],


            ['type' => 'title', 'id' => 'bgcouriers_speedy_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgcouriers_open_before_pay', 'title' => __('Open before payment', 'bg-couriers'),
                'desc' => __('What the recipient may do before paying. ONE setting for the whole shop, shown here because Speedy and Econt are the couriers that offer it - changing it on either page changes both, because it is a promise made at checkout and cannot differ per courier. Never applied to locker deliveries: there is nobody there to supervise.', 'bg-couriers'),
                'options' => [
                    'no'   => __('Not allowed', 'bg-couriers'),
                    'open' => __('May open and look', 'bg-couriers'),
                    'test' => __('May open and test', 'bg-couriers'),
                ],
                'default' => 'no'],
['type' => 'checkbox', 'id' => 'bgcouriers_speedy_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your Speedy contract pays COD out via ППП (пощенски паричен превод) - lets you accept COD with no cash register.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_speedy_cod'],
        ];
    }

    private function econt_courier_fields(): array {
        $cd_opts = ['' => __('- none (COD off) -', 'bg-couriers')];
        $sender_opts = ['' => __('- automatic (first profile address) -', 'bg-couriers')];
        if (BGCouriers_Settings::creds_present('econt')) {
            $econt = BGCouriers_Couriers::get('econt');
            if ($econt && method_exists($econt, 'cd_pay_options')) {
                foreach ($econt->cd_pay_options() as $num => $lbl) { $cd_opts[$num] = $lbl; }
            }
            if ($econt && method_exists($econt, 'sender_addresses')) {
                foreach ($econt->sender_addresses() as $id => $lbl) { $sender_opts[$id] = $lbl; }
            }
        }
        return [
            ['type' => 'title', 'id' => 'bgcouriers_econt', 'title' => ''],
            ['type' => 'bgcouriers_ppp_notice', 'id' => 'bgcouriers_ppp_notice_econt', 'courier' => 'econt'],
            ['type' => 'bgcouriers_cred_hint', 'id' => 'bgcouriers_econt_credhint', 'courier' => 'econt'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_econt_enabled', 'title' => __('Enable Econt', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_econt_username', 'title' => __('API username', 'bg-couriers'), 'autoload' => false],
            ['type' => 'password', 'id' => 'bgcouriers_econt_password', 'title' => __('API password', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgcouriers_actions', 'id' => 'bgcouriers_econt_actions'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_econt'],

            ['type' => 'title', 'id' => 'bgcouriers_econt_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgcouriers_econt_sender_address', 'title' => __('Ship-from address', 'bg-couriers'),
                'desc' => __('The ship-from address on the waybill (from your Econt profile). Automatic = the first profile address.', 'bg-couriers'),
                'options' => $sender_opts, 'default' => ''],
            ['type' => 'select', 'id' => 'bgcouriers_econt_label_paper_size', 'title' => __('Label format', 'bg-couriers'),
                'desc' => __('Econt labels are A4-landscape only (fixed by its API). The bulk “Print A4” packs several per sheet without scaling.', 'bg-couriers'),
                'options' => ['A4' => __('A4-landscape (fixed by Econt)', 'bg-couriers')],
                'default' => 'A4'],
            self::autolabel_row('econt'),
            ['type' => 'sectionend', 'id' => 'bgcouriers_econt_delivery'],

            ['type' => 'title', 'id' => 'bgcouriers_econt_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_econt_ship_in_total', 'title' => __('Delivery in the order total', 'bg-couriers'),
                'desc' => __('On: the customer pays delivery together with the order. Off: delivery is not charged at checkout - the estimated price is shown for information and the customer pays the courier on delivery; cash on delivery then collects only the goods total.', 'bg-couriers'),
                'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_econt_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Ship Econt free above this goods total (excluding shipping). Set here it applies to ALL delivery options (their own thresholds become inactive); leave empty to set thresholds per delivery option. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgcouriers_econt_pricing'],

            ['type' => 'title', 'id' => 'bgcouriers_econt_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgcouriers_open_before_pay', 'title' => __('Open before payment', 'bg-couriers'),
                'desc' => __('What the recipient may do before paying. ONE setting for the whole shop, shown here because Speedy and Econt are the couriers that offer it - changing it on either page changes both, because it is a promise made at checkout and cannot differ per courier. Never applied to locker deliveries: there is nobody there to supervise.', 'bg-couriers'),
                'options' => [
                    'no'   => __('Not allowed', 'bg-couriers'),
                    'open' => __('May open and look', 'bg-couriers'),
                    'test' => __('May open and test', 'bg-couriers'),
                ],
                'default' => 'no'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_econt_cod_enabled', 'title' => __('Cash on delivery (наложен платеж)', 'bg-couriers'),
                'desc' => __('Attach наложен платеж (full total + packing list) to every COD Econt order, paid out via the agreement below. Prepaid orders are never charged again.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'select', 'id' => 'bgcouriers_econt_cd_num', 'title' => __('CD pay-out agreement', 'bg-couriers'),
                'desc' => __('The наложен платеж pay-out agreement (from your Econt profile).', 'bg-couriers'),
                'options' => $cd_opts, 'default' => ''],
            ['type' => 'checkbox', 'id' => 'bgcouriers_econt_partial_delivery', 'title' => __('Allow partial delivery', 'bg-couriers'),
                'desc' => __('On a cash-on-delivery order, let the customer open the parcel at the counter and keep only part of it, paying for what they keep - the rest comes back to you. Econt matches what is kept against the packing list this plugin already sends. Off by default: it is your money at the door, and the return journey is yours to pay for.', 'bg-couriers'),
                'default' => 'no'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_econt_sms_notification', 'title' => __('SMS notification', 'bg-couriers'),
                'desc' => __('Send the recipient an SMS notification.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_econt_delivery_email', 'title' => __('E-mail on delivery', 'bg-couriers'),
                'desc' => __('Notify this e-mail when the shipment is delivered (leave empty to disable).', 'bg-couriers'),
                'default' => ''],
            ['type' => 'checkbox', 'id' => 'bgcouriers_econt_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your Econt pay-out agreement above is ППП (пощенски паричен превод) - lets you accept COD with no cash register.', 'bg-couriers'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_econt_cod'],
        ];
    }

    private function pigeon_courier_fields(): array {
        return [
            ['type' => 'title', 'id' => 'bgcouriers_pigeon', 'title' => ''],
            ['type' => 'bgcouriers_ppp_notice', 'id' => 'bgcouriers_ppp_notice_pigeon', 'courier' => 'pigeon'],
            ['type' => 'bgcouriers_cred_hint', 'id' => 'bgcouriers_pigeon_credhint', 'courier' => 'pigeon'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_pigeon_enabled', 'title' => __('Enable Pigeon Express', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_pigeon_username', 'title' => __('API Key', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'password', 'id' => 'bgcouriers_pigeon_password', 'title' => __('API Secret', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgcouriers_actions', 'id' => 'bgcouriers_pigeon_actions'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_pigeon_live', 'title' => __('Live mode', 'bg-couriers'),
                'desc' => __('On = the live Pigeon production account. Off = the demo/test API (api-demo.pigeonexpress.com) with test credentials.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_pigeon'],

            ['type' => 'title', 'id' => 'bgcouriers_pigeon_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_pigeon_pickup_from_address', 'title' => __('Courier collects from my address', 'bg-couriers'),
                'desc' => __('Turn on if your Pigeon contract has the courier come to your premises instead of you dropping parcels at an office. Off means you drop them at the office chosen below.', 'bg-couriers'),
                'default' => 'no'],
            ['type' => 'bgcouriers_pigeon_pickup_city', 'id' => 'bgcouriers_pigeon_pickup_city_id',
                'title' => __('Collection town', 'bg-couriers'),
                'courier' => 'pigeon',
                'desc' => __('The town the courier comes to. Only used when the collection is from your address.', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgcouriers_pigeon_pickup_address', 'title' => __('Collection address', 'bg-couriers'),
                'desc' => __('Street, number and anything that helps the courier find you. Only used when the collection is from your address.', 'bg-couriers'),
                'default' => ''],
            ['type' => 'bgcouriers_pigeon_pickup', 'id' => 'bgcouriers_pigeon_pickup_office_id',
                'title' => __('Pickup office', 'bg-couriers'),
                'desc' => __('The Pigeon office you drop parcels at. Search your city, then pick the office.', 'bg-couriers')],
            self::autolabel_row('pigeon'),
            ['type' => 'sectionend', 'id' => 'bgcouriers_pigeon_delivery'],

            ['type' => 'title', 'id' => 'bgcouriers_pigeon_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_pigeon_ship_in_total', 'title' => __('Delivery in the order total', 'bg-couriers'),
                'desc' => __('On: the customer pays delivery together with the order. Off: delivery is not charged at checkout - the estimated price is shown for information and the customer pays the courier on delivery; cash on delivery then collects only the goods total.', 'bg-couriers'),
                'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_pigeon_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Ship Pigeon free above this goods total (excluding shipping). Set here it applies to ALL delivery options (their own thresholds become inactive); leave empty to set thresholds per delivery option. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgcouriers_pigeon_pricing'],

            ['type' => 'title', 'id' => 'bgcouriers_pigeon_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_pigeon_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your Pigeon contract pays COD out via ППП (пощенски паричен превод). Off = COD needs your own cash register.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_pigeon_cod'],
        ];
    }

    /** Sameday - office/address/easyBox + live quote. Needs a pickup point + per-type service IDs from the contract. */
    private function sameday_courier_fields(): array {
        $cur = get_woocommerce_currency();
        return [
            ['type' => 'title', 'id' => 'bgcouriers_sameday', 'title' => ''],
            ['type' => 'bgcouriers_ppp_notice', 'id' => 'bgcouriers_ppp_notice_sameday', 'courier' => 'sameday'],
            ['type' => 'bgcouriers_cred_hint', 'id' => 'bgcouriers_sameday_credhint', 'courier' => 'sameday'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_sameday_enabled', 'title' => __('Enable Sameday', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_sameday_username', 'title' => __('Username', 'bg-couriers'),
                'desc' => __('Sameday API username (X-Auth-Username).', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'password', 'id' => 'bgcouriers_sameday_password', 'title' => __('Password', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgcouriers_actions', 'id' => 'bgcouriers_sameday_actions'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_sameday_live', 'title' => __('Live mode', 'bg-couriers'),
                'desc' => __('On = the live Sameday account. Off = the demo/test API (sameday-api.demo.zitec.com).', 'bg-couriers'),
                'default' => 'yes', 'autoload' => false],
            ['type' => 'sectionend', 'id' => 'bgcouriers_sameday'],

            ['type' => 'title', 'id' => 'bgcouriers_sameday_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'number', 'id' => 'bgcouriers_sameday_pickup_point', 'title' => __('Pickup point ID (optional)', 'bg-couriers'),
                'desc' => __('Leave empty to ship from your Sameday account\'s default pickup point; enter an ID only to use a different one. Delivery services (24H / locker / PUDO) are discovered from your account automatically.', 'bg-couriers'),
                'default' => '', 'custom_attributes' => ['min' => '0', 'step' => '1'], 'autoload' => false],
            ['type' => 'select', 'id' => 'bgcouriers_sameday_label_paper_size', 'title' => __('Label paper size', 'bg-couriers'),
                'options' => ['A6' => __('A6 (label printer)', 'bg-couriers'), 'A4' => __('A4 (office printer)', 'bg-couriers')], 'default' => 'A6'],
            self::autolabel_row('sameday'),
            ['type' => 'sectionend', 'id' => 'bgcouriers_sameday_delivery'],

            ['type' => 'title', 'id' => 'bgcouriers_sameday_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_sameday_ship_in_total', 'title' => __('Delivery in the order total', 'bg-couriers'),
                'desc' => __('On: the customer pays delivery together with the order. Off: delivery is not charged at checkout - the estimated price is shown for information and the customer pays the courier on delivery; cash on delivery then collects only the goods total.', 'bg-couriers'),
                'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_sameday_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . $cur . ')',
                'desc' => __('Ship Sameday free above this goods total (excluding shipping). Set here it applies to ALL delivery options (their own thresholds become inactive); leave empty to set thresholds per delivery option. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgcouriers_sameday_pricing'],

            ['type' => 'title', 'id' => 'bgcouriers_sameday_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_sameday_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your Sameday contract pays COD out via ППП (пощенски паричен превод). Off = COD needs your own cash register.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_sameday_cod'],
        ];
    }

    /**
     * Express One - office/address/EXOBOX locker + live quote, and a courier it can call.
     *
     * The one field that is its own: where the parcel is collected FROM. Express One calls those the
     * account's "objects" and the test account holds eighteen of them; the id goes on every waybill as
     * SEND_OFFICE_ID, so a mistyped one is a courier sent to the wrong warehouse. It is a list read from
     * the account, never a number to type - and it stays empty and disabled until the credentials work,
     * because there is nothing to read it from before that.
     */
    private function expressone_courier_fields(): array {
        $cur = get_woocommerce_currency();
        return [
            ['type' => 'title', 'id' => 'bgcouriers_expressone', 'title' => ''],
            ['type' => 'bgcouriers_ppp_notice', 'id' => 'bgcouriers_ppp_notice_expressone', 'courier' => 'expressone'],
            ['type' => 'bgcouriers_cred_hint', 'id' => 'bgcouriers_expressone_credhint', 'courier' => 'expressone'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_expressone_enabled', 'title' => __('Enable Express One', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_expressone_username', 'title' => __('API username', 'bg-couriers'),
                'desc' => __('The username Express One issued for the API. It is not the one you sign in to my.expressone.bg with.', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'password', 'id' => 'bgcouriers_expressone_password', 'title' => __('API password', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgcouriers_actions', 'id' => 'bgcouriers_expressone_actions'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_expressone'],

            ['type' => 'title', 'id' => 'bgcouriers_expressone_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgcouriers_expressone_sender_object', 'title' => __('Send parcels from', 'bg-couriers'),
                'desc' => __('Which of your Express One addresses the courier collects from. The list comes from your account - validate the credentials above and save, and it fills in.', 'bg-couriers'),
                'options' => self::expressone_sender_options(), 'default' => ''],
            self::autolabel_row('expressone'),
            ['type' => 'sectionend', 'id' => 'bgcouriers_expressone_delivery'],

            ['type' => 'title', 'id' => 'bgcouriers_expressone_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_expressone_ship_in_total', 'title' => __('Delivery in the order total', 'bg-couriers'),
                'desc' => __('On: the customer pays delivery together with the order. Off: delivery is not charged at checkout - the estimated price is shown for information and the customer pays the courier on delivery; cash on delivery then collects only the goods total.', 'bg-couriers'),
                'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_expressone_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . $cur . ')',
                'desc' => __('Ship Express One free above this goods total (excluding shipping). Set here it applies to ALL delivery options (their own thresholds become inactive); leave empty to set thresholds per delivery option. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgcouriers_expressone_pricing'],

            ['type' => 'title', 'id' => 'bgcouriers_expressone_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_expressone_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your Express One contract pays COD out via ППП (пощенски паричен превод). Off = COD needs your own cash register.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_expressone_cod'],
        ];
    }

    /**
     * The account's own addresses, as a picker. Empty until the credentials work - and it says so rather
     * than showing a blank list, because a merchant looking at an empty dropdown cannot tell "not fetched
     * yet" from "you have none".
     *
     * @return array<string,string>
     */
    private static function expressone_sender_options(): array {
        $out = ['' => __('- not chosen -', 'bg-couriers')];
        if (!class_exists('BGCouriers_Couriers')) { return $out; }
        $co = BGCouriers_Couriers::get('expressone');
        if (!$co || !method_exists($co, 'sender_objects')) { return $out; }
        try {
            foreach ($co->sender_objects() as $id => $line) { $out[(string) $id] = $line; }
        } catch (\Exception $e) {
            $out[''] = __('- enter your credentials and save, then this list fills in -', 'bg-couriers');
        }
        return $out;
    }

    /** BOX NOW - locker-only, flat-rate, OAuth2. Only the fields BoxNow actually uses (no dangling params). */
    private function boxnow_courier_fields(): array {
        return [
            ['type' => 'title', 'id' => 'bgcouriers_boxnow', 'title' => ''],
            ['type' => 'bgcouriers_ppp_notice', 'id' => 'bgcouriers_ppp_notice_boxnow', 'courier' => 'boxnow'],
            ['type' => 'bgcouriers_cred_hint', 'id' => 'bgcouriers_boxnow_credhint', 'courier' => 'boxnow'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_boxnow_enabled', 'title' => __('Enable BOX NOW', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgcouriers_boxnow_username', 'title' => __('Client ID', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'password', 'id' => 'bgcouriers_boxnow_password', 'title' => __('Client secret', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgcouriers_actions', 'id' => 'bgcouriers_boxnow_actions'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_boxnow_live', 'title' => __('Live mode', 'bg-couriers'),
                'desc' => __('On = the live BOX NOW production account. Off = the stage/test API (api-stage.boxnow.bg) with test credentials.', 'bg-couriers'),
                'default' => 'yes', 'autoload' => false],
            ['type' => 'text', 'id' => 'bgcouriers_boxnow_partner_id', 'title' => __('Partner ID', 'bg-couriers'), 'autoload' => false],
            ['type' => 'text', 'id' => 'bgcouriers_boxnow_webhook_secret', 'title' => __('Webhook secret', 'bg-couriers'),
                'desc' => __('You receive it after you register this webhook URL in your BOX NOW account:', 'bg-couriers')
                    . '<br><code>' . esc_html(BGCouriers_Boxnow_Webhook::url()) . '</code>', 'autoload' => false],
            ['type' => 'sectionend', 'id' => 'bgcouriers_boxnow'],

            ['type' => 'title', 'id' => 'bgcouriers_boxnow_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgcouriers_boxnow_warehouse_id', 'title' => __('Pickup location ID', 'bg-couriers'),
                'desc' => __('Your BOX NOW origin/pickup ID (where parcels ship FROM, not the customer’s locker). From your BOX NOW partner account.', 'bg-couriers'), 'autoload' => false],
            ['type' => 'text', 'id' => 'bgcouriers_boxnow_sender_phone', 'title' => __('Sender contact phone', 'bg-couriers'),
                'desc' => __('Your contact phone for the pickup/origin, printed on the parcel. Leave empty to omit.', 'bg-couriers'), 'autoload' => false],
            ['type' => 'checkbox', 'id' => 'bgcouriers_boxnow_declare_value', 'title' => __('Declare the value of prepaid parcels', 'bg-couriers'),
                'desc' => __('Send the order total to BOX NOW as the declared value of a parcel that is already paid for. Off by default, which is what BOX NOW\'s own plugin sends: they do not publish what the field costs or covers. A parcel with cash on delivery always carries its amount.', 'bg-couriers'),
                'default' => 'no'],
            ['type' => 'checkbox', 'id' => 'bgcouriers_boxnow_allow_returns', 'title' => __('Allow returns', 'bg-couriers'), 'default' => 'no'],
            self::autolabel_row('boxnow'),
            ['type' => 'sectionend', 'id' => 'bgcouriers_boxnow_delivery'],

            ['type' => 'title', 'id' => 'bgcouriers_boxnow_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgcouriers_boxnow_flat_price', 'title' => __('Delivery price', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Flat BOX NOW locker price (no live rate API). Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'text', 'id' => 'bgcouriers_boxnow_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Ship BOX NOW free above this goods total (excluding shipping). Empty or 0 disables. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgcouriers_boxnow_pricing'],

            ['type' => 'title', 'id' => 'bgcouriers_boxnow_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgcouriers_boxnow_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your BOX NOW contract pays COD out via ППП. BOX NOW has no ППП today, so leave off - COD then needs your own cash register.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'bgcouriers_boxnow_cod'],
        ];
    }

    private function method_fields(string $courier, string $m, string $label): array {
        $p = "bgcouriers_{$courier}_{$m}_";
        // Only offer live pricing where the courier actually has a price endpoint. BOX NOW does not -
        // its rates are contractual - and offering the choice invites a shop to pick a mode that can
        // only ever fall back, then wonder why the fixed price is what shows.
        $c    = BGCouriers_Couriers::get($courier);
        $live = $c && in_array('live_quote', $c->capabilities(), true);
        $modes = $live
            ? [
                'live'     => __('Live price only - no fixed default (show the cached price until the address is chosen)', 'bg-couriers'),
                'fallback' => __('Live price, use the fixed price below only if the API is unavailable', 'bg-couriers'),
                'fixed'    => __('Always the fixed price below - no live API calls at checkout', 'bg-couriers'),
              ]
            /* translators: shown when the courier has no price API at all */
            : ['fixed' => __('Always the fixed price below - this courier publishes no live prices', 'bg-couriers')];
        $fields = [
            ['type' => 'title', 'id' => $p . 'grp', 'title' => ''],
            ['type' => 'checkbox', 'id' => $p . 'enabled', /* translators: %s: courier name */ 'title' => sprintf(__('Enable “%s”', 'bg-couriers'), $label), 'default' => 'yes'],
            ['type' => 'select', 'id' => $p . 'price_mode', 'title' => __('Delivery price', 'bg-couriers'),
                'options' => $modes, 'default' => $live ? 'fallback' : 'fixed'],
            ['type' => 'text', 'id' => $p . 'price', 'title' => __('Fixed / default price', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Used by the “fixed” and “fallback” delivery-price modes above. In the store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'text', 'id' => $p . 'free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Free delivery for THIS option above this goods total (excluding shipping). Applies only while the courier-level threshold is empty; empty or 0 disables.', 'bg-couriers'),
                'default' => '', 'autoload' => false, 'class' => 'bgc-method-free'],
        ];
        // Only Speedy's API exposes a card-payment control on the COD service (cardPaymentForbidden);
        // no dangling toggle for couriers that cannot honor it.
        if ($courier === 'speedy') {
            $fields[] = ['type' => 'checkbox', 'id' => $p . 'card_payment', 'title' => __('Card payment for COD', 'bg-couriers'),
                'desc' => __('Let the customer pay the cash-on-delivery amount by card at handover (your Speedy account default). Off explicitly forbids card payment for this delivery option.', 'bg-couriers'),
                'default' => 'yes'];
        }
        $fields[] = ['type' => 'sectionend', 'id' => $p . 'grp'];
        return $fields;
    }
}
