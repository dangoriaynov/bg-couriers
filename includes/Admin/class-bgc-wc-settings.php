<?php
defined('ABSPATH') || exit;

if (!class_exists('WC_Settings_Page')) { return; }

/**
 * WooCommerce → Settings → "BG Couriers" tab.
 * Level 1 (WP nav-tabs): courier sections (General + Speedy).
 * Level 2 (WP nav-tabs, JS-switched, no reload): per delivery method (office/address/automat).
 * See feedback-settings-architecture - every future courier follows this shape.
 */
class BGC_WC_Settings extends WC_Settings_Page {

    private static $method_labels = [];

    public function __construct() {
        $this->id    = 'bg_couriers';
        $this->label = __('BG Couriers', 'bg-couriers');
        self::$method_labels = [
            'office'  => __('To office', 'bg-couriers'),
            'address' => __('To address', 'bg-couriers'),
            'automat' => __('To APS', 'bg-couriers'),
        ];
        parent::__construct();
    }

    protected function get_own_sections() { return $this->sections(); }
    public function get_sections() { return apply_filters('woocommerce_get_sections_' . $this->id, $this->sections()); }

    private function sections(): array {
        return [
            ''       => __('General', 'bg-couriers'),
            'speedy' => __('Speedy', 'bg-couriers'),
            'econt'  => __('Econt', 'bg-couriers'),
            'pigeon' => __('Pigeon Express', 'bg-couriers'),
            'boxnow' => __('BOX NOW', 'bg-couriers'),
            'sameday' => __('Sameday', 'bg-couriers'),
            'about'  => __('About', 'bg-couriers'),
        ];
    }

    /** Full field set for the section - used by save() (save_settings_for_current_section). */
    public function get_settings($section = '') {
        if ($section === 'about') {
            return [
                ['type' => 'bgc_about', 'id' => 'bgc_about'],
                ['type' => 'sectionend', 'id' => 'bgc_about'],
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
        return $this->general_fields();
    }

    protected function get_settings_for_section_core($section_id) { return $this->get_settings($section_id); }

    /** Custom output: WP nav-tab section nav + (for Speedy) per-method sub-tabs. */
    // Suppress WooCommerce's default section nav (the "subsubsub" link row) - we render our own
    // nicer nav-tabs in output(); otherwise the General/Speedy row shows twice.
    public function output_sections() {}

    public function output() {
        global $current_section;
        echo '<style>
        #wpbody .bgc-settings table.form-table th { padding: 9px 18px 9px 0; width: 340px; vertical-align: middle; }
        #wpbody .bgc-settings table.form-table td { padding: 7px 0; }
        /* Auto width (not 100%) so the table hugs its label + value columns instead of leaving a big empty
           gap to the right of every field. */
        #wpbody .bgc-settings table.form-table { margin: 0; width: auto !important; }
        /* Full-width banners (ППП notice, API-credentials hint) span the whole settings column, not just the
           auto-sized cell. */
        #wpbody .bgc-settings .bgc-ppp-notice,
        #wpbody .bgc-settings .bgc-cred-hint { display: block; width: 100%; max-width: none; box-sizing: border-box; }
        #wpbody .bgc-settings table.form-table td[colspan] { width: auto; }
        /* Consistent field width + left alignment for EVERY field (courier-level fields fill a full-width
           table, per-method fields sit in a narrower card - without this their inputs align differently). */
        #wpbody .bgc-settings table.form-table td > select,
        #wpbody .bgc-settings table.form-table td > textarea,
        #wpbody .bgc-settings table.form-table td > input:not([type=checkbox]):not([type=radio]) { width: 400px !important; min-width: 0 !important; max-width: 100% !important; box-sizing: border-box; float: none; margin: 0; display: inline-block; vertical-align: top; }
        /* Each field description collapses into a small (i) that sits inline right after the field label; the
           text appears in a self-contained CSS hover/focus tooltip. Fully independent of the WooCommerce
           help-tip so it can never float over or overlap the label. */
        #wpbody .bgc-settings .bgc-help { display:inline-block; box-sizing:border-box; width:16px; height:16px; margin:0 0 0 6px; border-radius:50%; background:#c3c8ce; color:#fff; font:italic 700 11px/16px Georgia, serif; text-align:center; vertical-align:middle; cursor:help; position:relative; }
        #wpbody .bgc-settings .bgc-help::before { content:"i"; }
        #wpbody .bgc-settings .bgc-help:hover, #wpbody .bgc-settings .bgc-help:focus { background:#2271b1; outline:none; }
        #wpbody .bgc-settings .bgc-help:hover::after, #wpbody .bgc-settings .bgc-help:focus::after { content:attr(data-tip); position:absolute; left:0; top:calc(100% + 7px); width:300px; max-width:300px; white-space:normal; background:#1d2327; color:#fff; font:400 12px/1.5 -apple-system,"Segoe UI",Roboto,sans-serif; font-style:normal; text-align:left; padding:9px 12px; border-radius:8px; box-shadow:0 5px 18px rgba(0,0,0,.28); z-index:1000; pointer-events:none; }
        /* One standardised red (disabled / error) + green (enabled / ok) palette, reused everywhere. */
        #wpbody .bgc-settings { --bgc-red-bg:#fcf0f1; --bgc-red-bg2:#f7dde0; --bgc-red-bd:#e6a2a5; --bgc-red-tx:#b32d2e;
            --bgc-green-bg:#eef9f1; --bgc-green-bg2:#dcf1e3; --bgc-green-bd:#c4e7cf; --bgc-green-tx:#1a7f37; }
        #wpbody .bgc-settings .bgc-group { border: 1px solid #e2e6ea; border-radius: 10px; padding: 6px 16px 12px; margin: 0 0 16px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        #wpbody .bgc-settings .bgc-group > h2 { font-size: 1.02em; margin: 12px 0 4px; }
        #wpbody .bgc-settings .bgc-group > p.description { margin-top: 0; }
        /* Nice wide rounded shadowed tabs - applies to both the courier nav and the per-method nav. */
        #wpbody .bgc-settings .nav-tab-wrapper { border-bottom:none; margin:0 0 16px; display:flex; flex-wrap:wrap; gap:10px; padding:0; }
        #wpbody .bgc-settings .nav-tab { border:1px solid #dcdcde; border-radius:11px; padding:11px 26px; margin:0; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.10); font-weight:500; line-height:1.2; color:#1d2327; transition:box-shadow .15s ease, transform .15s ease, background .15s ease; }
        #wpbody .bgc-settings .nav-tab:hover { box-shadow:0 3px 8px rgba(0,0,0,.16); }
        #wpbody .bgc-settings .nav-tab.nav-tab-active { border-color:#8c8f94; box-shadow:0 5px 13px rgba(0,0,0,.20); transform:translateY(-1px); }
        #wpbody .bgc-settings .nav-tab.bgc-tab-on { background:var(--bgc-green-bg); border-color:var(--bgc-green-bd); }
        #wpbody .bgc-settings .nav-tab.bgc-tab-off { background:var(--bgc-red-bg); border-color:var(--bgc-red-bd); }
        #wpbody .bgc-settings .nav-tab.bgc-tab-on.nav-tab-active { background:var(--bgc-green-bg2); }
        #wpbody .bgc-settings .nav-tab.bgc-tab-off.nav-tab-active { background:var(--bgc-red-bg2); }
        #wpbody .bgc-settings .bgc-courier-tabs { display:inline-flex; flex-wrap:wrap; gap:10px; }
        #wpbody .bgc-settings .nav-tab { display:inline-flex; align-items:center; gap:8px; }
        #wpbody .bgc-settings .bgc-courier-tab { padding-left:16px; padding-right:20px; cursor:move; }
        #wpbody .bgc-settings .bgc-tab-ico { flex:0 0 auto; display:block; width:16px; height:16px; object-fit:contain; }
        #wpbody .bgc-settings .ui-sortable-helper { box-shadow:0 8px 22px rgba(0,0,0,.28); }
        #wpbody .bgc-settings .ui-sortable-placeholder { visibility:visible !important; background:#f0f0f1; border:1px dashed #b0b3b8; box-shadow:none; }
        #wpbody .bgc-settings .bgc-enable-toggle { display:flex; align-items:center; gap:12px; padding:11px 14px; margin:2px 0 14px; border-radius:10px; border:1px solid #e2e6ea; }
        #wpbody .bgc-settings .bgc-enable-toggle.bgc-enable-on { background:var(--bgc-green-bg); border-color:var(--bgc-green-bd); }
        #wpbody .bgc-settings .bgc-enable-toggle.bgc-enable-off { background:var(--bgc-red-bg); border-color:var(--bgc-red-bd); }
        .bgc-enable-modal { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:100001; display:flex; align-items:center; justify-content:center; padding:16px; }
        .bgc-enable-box { background:#fff; border-radius:10px; max-width:540px; width:100%; padding:18px 22px; box-shadow:0 12px 40px rgba(0,0,0,.3); }
        .bgc-enable-box h3 { margin:0 0 6px; color:#b32d2e; }
        .bgc-enable-box ul { margin:12px 0 16px; padding-left:18px; }
        .bgc-enable-box li { margin-bottom:10px; }
        .bgc-enable-box .bgc-fix { color:#50575e; }
        #wpbody .bgc-settings .bgc-switch { position:relative; display:inline-block; width:46px; height:26px; flex:0 0 auto; }
        #wpbody .bgc-settings .bgc-switch input { opacity:0; width:0; height:0; margin:0; }
        #wpbody .bgc-settings .bgc-slider { position:absolute; cursor:pointer; inset:0; background:#c9ced3; border-radius:26px; transition:.2s; }
        #wpbody .bgc-settings .bgc-slider:before { content:""; position:absolute; height:20px; width:20px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; }
        #wpbody .bgc-settings .bgc-switch input:checked + .bgc-slider { background:var(--bgc-green-tx); }
        #wpbody .bgc-settings .bgc-switch input:checked + .bgc-slider:before { transform:translateX(20px); }
        #wpbody .bgc-settings .bgc-enable-text { font-size:13px; color:#1d2327; }
        /* Every setting checkbox renders as a red/green toggle switch (green = on, red = off), reusing the palette. */
        #wpbody .bgc-settings .form-table td input[type=checkbox] { -webkit-appearance:none!important; appearance:none!important; position:relative; box-sizing:border-box; width:44px!important; height:24px!important; min-width:44px; margin:0 8px 0 0; padding:0; border:none!important; border-radius:24px; background:var(--bgc-red-tx)!important; box-shadow:none!important; cursor:pointer; transition:background .2s; vertical-align:middle; }
        #wpbody .bgc-settings .form-table td input[type=checkbox]::before { content:""!important; position:absolute; top:3px; left:3px; width:18px; height:18px; margin:0; background:#fff; border-radius:50%; transition:transform .2s; box-shadow:0 1px 2px rgba(0,0,0,.25); }
        #wpbody .bgc-settings .form-table td input[type=checkbox]:checked { background:var(--bgc-green-tx)!important; }
        #wpbody .bgc-settings .form-table td input[type=checkbox]:checked::before { transform:translateX(20px); }
        #wpbody .bgc-settings .form-table td input[type=checkbox]:focus { outline:none; box-shadow:0 0 0 2px rgba(34,113,177,.35)!important; }
        /* Credentials state: green when validated, red while editing/unverified; locked masked password + red change-×.
           The rows render as one clean full-bleed tinted band - a 16px horizontal box-shadow bridges the group
           side padding so the colour reaches the panel edges instead of leaving an uneven inset frame. */
        #wpbody .bgc-settings tr.bgc-creds-ok > th, #wpbody .bgc-settings tr.bgc-creds-ok > td,
        #wpbody .bgc-settings tr.bgc-creds-edit > th, #wpbody .bgc-settings tr.bgc-creds-edit > td { vertical-align:middle; }
        #wpbody .bgc-settings tr.bgc-creds-ok > th, #wpbody .bgc-settings tr.bgc-creds-ok > td { background:var(--bgc-green-bg); }
        #wpbody .bgc-settings tr.bgc-creds-edit > th, #wpbody .bgc-settings tr.bgc-creds-edit > td { background:var(--bgc-red-bg); }
        #wpbody .bgc-settings .bgc-cred-x { color:var(--bgc-red-tx); border-color:var(--bgc-red-bd) !important; margin-left:8px; font-weight:700; line-height:1.6; }
        #wpbody .bgc-settings input.bgc-cred-locked { background:#f0f0f1; color:#787c82; letter-spacing:2px; }
        #bgc-toasts { position:fixed; top:46px; right:22px; z-index:100001; display:flex; flex-direction:column; gap:9px; }
        #bgc-toasts .bgc-toast { padding:12px 18px; border-radius:9px; color:#fff; font-weight:600; font-size:13px; box-shadow:0 6px 18px rgba(0,0,0,.20); opacity:0; transform:translateY(-10px); transition:opacity .25s ease, transform .25s ease; max-width:360px; }
        #bgc-toasts .bgc-toast.show { opacity:1; transform:none; }
        #bgc-toasts .bgc-toast-ok { background:#1a7f37; }
        #bgc-toasts .bgc-toast-err { background:#b32d2e; }
        </style>';
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
        } else {
            WC_Admin_Settings::output_fields($this->general_fields());
        }
        echo '</div></div>';

        // Turn every field description into a small (i) that sits inline right after the field label. Text /
        // select / number fields print their description as a <span class="description"> in the value cell; a
        // checkbox prints it as a raw text node inside its <label>. Pull that text out into a (i) on the label
        // and drop the inline copy. Descriptions with a link or <code> (e.g. the webhook URL) are left inline.
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline JS, no dynamic values.
        echo '<script>' . "\n"
            . "(function(\$){\n"
            . "    \$(function(){\n"
            . "        \$('#wpbody .bgc-settings table.form-table > tbody > tr').each(function(){\n"
            . "            var tr=\$(this), th=tr.children('th').first(), td=tr.children('td').first();\n"
            . "            if(!th.length || !td.length || \$.trim(th.text())===''){ return; }\n"
            . "            var text='', label=null;\n"
            . "            if(td.hasClass('forminp-checkbox')){\n"
            . "                label=td.find('label').first(); if(!label.length){ return; }\n"
            . "                text=\$.trim(label.text());\n"
            . "            } else {\n"
            . "                var d=td.find('.description').first();\n"
            . "                if(!d.length || d.find('code,a').length){ return; }\n"
            . "                text=\$.trim(d.text());\n"
            . "            }\n"
            . "            if(!text){ return; }\n"
            . "            var tip=\$('<span class=\"bgc-help\" tabindex=\"0\" role=\"img\"></span>').attr('data-tip',text).attr('aria-label',text);\n"
            . "            var thl=th.find('label').first();\n"
            . "            (thl.length ? thl : th).append(tip);\n"
            . "            if(label){ label.contents().filter(function(){ return this.nodeType===3; }).remove(); }\n"
            . "            else { td.find('.description').first().remove(); }\n"
            . "        });\n"
            . "    });\n"
            . "})(jQuery);\n"
            . '</script>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        // AJAX "Save changes" - save without a page reload, with a top-right toast (green ok / red error).
        $save_nonce = esc_js(wp_create_nonce('bgc_save'));
        $ajaxurl    = esc_js(admin_url('admin-ajax.php'));
        $sect       = esc_js((string) $current_section);
        $i_saved    = esc_js(__('Saved', 'bg-couriers'));
        $i_failed   = esc_js(__('Could not save - please try again.', 'bg-couriers'));
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- inline admin JS; every interpolated value is esc_js()'d.
        echo '<script>' . "\n"
            . "(function(\$){\n"
            . "    var ajaxurl='" . $ajaxurl . "', nonce='" . $save_nonce . "', section='" . $sect . "';\n"
            . "    function toast(msg,type,ms){ var c=\$('#bgc-toasts'); if(!c.length){ c=\$('<div id=\"bgc-toasts\"></div>').appendTo('body'); }\n"
            . "        var t=\$('<div class=\"bgc-toast bgc-toast-'+type+'\">'+'</div>').text(msg).appendTo(c);\n"
            . "        requestAnimationFrame(function(){ t.addClass('show'); });\n"
            . "        setTimeout(function(){ t.removeClass('show'); setTimeout(function(){ t.remove(); }, 320); }, ms||3000); }\n"
            . "    var form=\$('#mainform'); if(!form.length){ return; }\n"
            . "    function busy(save,on){ save.prop('disabled',on).toggleClass('is-busy',on); }\n"
            . "    form.on('click', 'button[name=\"save\"], input[name=\"save\"]', function(e){\n"
            . "        e.preventDefault(); e.stopImmediatePropagation();\n"
            . "        var save=\$(this); busy(save,true);\n"
            . "        var data=form.serialize()+'&action=bgc_save_settings&bgc_nonce='+nonce+'&bgc_section='+encodeURIComponent(section)+'&save=1';\n"
            . "        \$.post(ajaxurl,data).done(function(r){\n"
            . "            if(r&&r.success){ toast((r.data&&r.data.msg)||'" . $i_saved . "','ok',2500); \$(document).trigger('bgc:saved',[r.data||{}]); }\n"
            . "            else { toast((r&&r.data&&r.data.msg)||'" . $i_failed . "','err',7000); }\n"
            . "        }).fail(function(){ toast('" . $i_failed . "','err',7000); }).always(function(){ busy(save,false); });\n"
            . "    });\n"
            . "})(jQuery);\n"
            . '</script>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /** Brand colour per courier - original, trademark-safe (not the couriers' logos). */
    private static function courier_color(string $id): string {
        $map = ['speedy' => '#E30613', 'econt' => '#0072BC', 'pigeon' => '#F58220', 'boxnow' => '#00B4A0', 'sameday' => '#A50034'];
        return $map[$id] ?? '#6b7280';
    }

    /**
     * Courier brand logo shown before the name on its tab. Uses the bundled logo when present,
     * and falls back to an original brand-coloured parcel badge otherwise.
     */
    private static function courier_icon(string $id): string {
        $url = BGC_Couriers::logo_url($id);
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
            $on       = get_option('bgc_' . $id . '_enabled', 'no') === 'yes';
            $notice   = BGC_Settings::ppp_courier_notice($id);
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
        if (array_key_exists('', $sections)) { echo $this->nav_pill('', $sections[''], $current, false); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nav_pill() escapes all fields internally
        echo '<span class="bgc-courier-tabs">'; // draggable couriers, in the saved order
        foreach (BGC_Settings::courier_order() as $cid) {
            if (isset($sections[$cid])) { echo $this->nav_pill($cid, $sections[$cid], $current, true); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nav_pill() escapes all fields internally
        }
        echo '</span></nav>';
        wp_enqueue_script('jquery-ui-sortable');
        $ajax  = esc_js(admin_url('admin-ajax.php'));
        $nonce = esc_js(wp_create_nonce('bgc_admin'));
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- inline admin JS; every interpolated value is esc_js()'d.
        echo '<script>' . "\n"
            . "jQuery(function(\$){\n"
            . "    var c = \$('.bgc-courier-tabs'); if (!c.length || !\$.fn.sortable) { return; }\n"
            . "    var dragged = false;\n"
            . "    c.sortable({ items: '> .bgc-courier-tab', distance: 6, cursor: 'move', tolerance: 'pointer', opacity: .85,\n"
            . "        start: function(){ dragged = true; },\n"
            . "        stop: function(){ setTimeout(function(){ dragged = false; }, 0); },\n"
            . "        update: function(){\n"
            . "            var order = c.children('.bgc-courier-tab').map(function(){ return \$(this).data('courier'); }).get().join(',');\n"
            . "            \$.post('" . $ajax . "', { action: 'bgc_save_order', nonce: '" . $nonce . "', order: order });\n"
            . "        }\n"
            . "    });\n"
            . "    c.on('click', '.bgc-courier-tab', function(e){ if (dragged) { e.preventDefault(); } });\n"
            . "});\n"
            . '</script>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render the courier settings section (courier fields + per-method sub-tabs).
     * Works for any courier id (speedy, econt, …).
     */
    private function output_courier(string $courier_id): void {
        $fields    = $this->{$courier_id . '_courier_fields'}();
        $enable_id = 'bgc_' . $courier_id . '_enabled';
        // The enable toggle, the ППП notice and the API-credentials hint all render as prominent FULL-WIDTH
        // blocks at the top of the tab (outside the form-table, which otherwise auto-sizes them narrow), so
        // pull them out of the field list here and echo them directly below.
        $fields = array_values(array_filter($fields, static function ($f) use ($enable_id) {
            if (isset($f['id']) && $f['id'] === $enable_id) { return false; }
            $type = $f['type'] ?? '';
            return $type !== 'bgc_ppp_notice' && $type !== 'bgc_cred_hint';
        }));
        $on    = get_option($enable_id, 'no') === 'yes';
        $label = $this->sections()[$courier_id] ?? ucfirst($courier_id);
        $on_t  = esc_html__('enabled', 'bg-couriers');
        $off_t = esc_html__('disabled', 'bg-couriers');
        echo '<div class="bgc-enable-toggle ' . ($on ? 'bgc-enable-on' : 'bgc-enable-off') . '" data-on="' . esc_attr($on_t) . '" data-off="' . esc_attr($off_t) . '">'
            . '<label class="bgc-switch"><input type="checkbox" name="' . esc_attr($enable_id) . '" value="1"' . checked($on, true, false) . '><span class="bgc-slider"></span></label>'
            . '<span class="bgc-enable-text"><strong>' . esc_html($label) . '</strong> - <span class="bgc-enable-state">' . esc_html($on ? $on_t : $off_t) . '</span></span>'
            . '</div>';
        BGC_Settings::ppp_notice_block($courier_id); // full-width, escaped internally
        BGC_Settings::cred_hint_block($courier_id);  // full-width, escaped internally
        WC_Admin_Settings::output_fields($fields);
        $c_id    = esc_js($courier_id);
        $c_ajax  = esc_js(admin_url('admin-ajax.php'));
        $c_save  = esc_js(wp_create_nonce('bgc_save'));
        $c_admin = esc_js(wp_create_nonce('bgc_admin'));
        /* translators: %s: courier name */
        $i_title = esc_js(sprintf(__('“%s” can’t be enabled yet', 'bg-couriers'), $label));
        $i_intro = esc_js(__('Please fix the following, then enable it again:', 'bg-couriers'));
        $i_fix   = esc_js(__('How to fix:', 'bg-couriers'));
        $i_close = esc_js(__('Close', 'bg-couriers'));
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- inline admin JS; every interpolated value is esc_js()'d.
        echo '<script>' . "\n"
            . "(function(\$){\n"
            . "    var courier='" . $c_id . "', ajaxurl='" . $c_ajax . "', saveNonce='" . $c_save . "', adminNonce='" . $c_admin . "', section='" . $c_id . "';\n"
            . "    function esc(s){ return \$('<i>').text(s==null?'':s).html(); }\n"
            . "    function saveForm(cb){ var f=\$('#mainform'); if(!f.length){ if(cb){cb();} return; }\n"
            . "        \$.post(ajaxurl, f.serialize()+'&action=bgc_save_settings&bgc_nonce='+saveNonce+'&bgc_section='+encodeURIComponent(section)).always(function(){ if(cb){cb();} }); }\n"
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
            . "            \$.post(ajaxurl,{action:'bgc_enable_check',nonce:adminNonce,courier:courier}).done(function(r){\n"
            . "                if(!(r&&r.success)){ cb.prop('checked',false); setVis(box,false); saveForm(); showProblems(r&&r.data&&r.data.problems); }\n"
            . "            }).always(function(){ cb.prop('disabled',false); });\n"
            . "        });\n"
            . "    });\n"
            . "})(jQuery);\n"
            . '</script>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        // Delivery-method sub-tabs - only the methods this courier actually offers (available_methods() =
        // capabilities pruned by real synced point counts, so e.g. Pigeon shows no "to APS" tab: no lockers).
        // Skip entirely for single-method / flat-rate couriers (e.g. BoxNow = locker only, one flat price).
        $c       = BGC_Couriers::get($courier_id);
        $caps    = $c ? array_values(array_diff($c->available_methods(), ['live_quote'])) : array_keys(self::$method_labels);
        $methods = array_filter(self::$method_labels, static function ($m) use ($caps) { return in_array($m, $caps, true); }, ARRAY_FILTER_USE_KEY);
        // Show the tabs (and panels) in the merchant's saved drag order.
        $ordered = [];
        foreach (BGC_Settings::method_order($courier_id) as $m) { if (isset($methods[$m])) { $ordered[$m] = $methods[$m]; } }
        if ($ordered) { $methods = $ordered; }
        if (count($methods) > 1) {
            // Level-2 nav-tabs for delivery methods - drag to reorder (saves bgc_<courier>_method_order); JS-switched panels.
            echo '<h2 class="nav-tab-wrapper bgc-method-nav" data-courier="' . esc_attr($courier_id)
                . '" data-nonce="' . esc_attr(wp_create_nonce('bgc_admin')) . '" style="margin-top:1.5em;">';
            $first = true;
            foreach ($methods as $m => $label) {
                $mid = 'bgc_' . $courier_id . '_' . $m . '_enabled';
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
                $en = 'bgc_' . $courier_id . '_' . $m . '_enabled';
                $mf = array_values(array_filter($this->method_fields($courier_id, $m, $label), static function ($f) use ($en) {
                    return !(isset($f['id']) && $f['id'] === $en);
                }));
                echo '<div class="bgc-method-panel" data-bgc-panel="' . esc_attr($m) . '"' . ($first ? '' : ' style="display:none;"') . '>';
                WC_Admin_Settings::output_fields($mf);
                echo '</div>';
                $first = false;
            }
        }
        ?>
<style>
.bgc-method-nav{padding-bottom:0;}
.bgc-method-nav .bgc-method-tab{display:inline-flex;align-items:center;gap:8px;cursor:move;}
.bgc-method-nav .ui-sortable-helper{box-shadow:0 6px 16px rgba(0,0,0,.22);}
.bgc-method-panel{border:1px solid #e2e6ea;border-radius:12px;padding:8px 18px 14px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.07);margin:0 0 10px;}
#wpbody .bgc-settings .bgc-switch-sm{width:32px;height:18px;}
#wpbody .bgc-settings .bgc-switch-sm .bgc-slider:before{height:12px;width:12px;left:3px;bottom:3px;}
#wpbody .bgc-settings .bgc-switch-sm input:checked + .bgc-slider:before{transform:translateX(14px);}
.bgc-method-panel table.form-table{margin-top:.5em;}
.bgc-method-panel h2{display:none;} /* method name lives in the tab, hide the empty group title */
</style>
<script>
(function($){
    var mn=$('.bgc-method-nav');
    function switchTo(t){ mn.find('.nav-tab').removeClass('nav-tab-active'); mn.find('[data-bgc-tab="'+t+'"]').addClass('nav-tab-active');
        $('.bgc-method-panel').hide().filter('[data-bgc-panel="'+t+'"]').show(); }
    var dragged=false;
    if (mn.length && $.fn.sortable) {
        mn.sortable({ items:'> .bgc-method-tab', distance:6, tolerance:'pointer', cursor:'move', opacity:.85,
            start:function(){ dragged=true; }, stop:function(){ setTimeout(function(){ dragged=false; },0); },
            update:function(){
                var order=mn.children('.bgc-method-tab').map(function(){ return $(this).data('bgc-tab'); }).get().join(',');
                $.post(ajaxurl,{ action:'bgc_save_order', nonce: mn.data('nonce'), courier: mn.data('courier'), order: order });
            }
        });
    }
    mn.on('click','.nav-tab',function(e){ e.preventDefault(); if(dragged){return;} switchTo($(this).data('bgc-tab')); }); // a drag isn't a tab switch
    $(document).on('change','.bgc-method-tab input[type=checkbox]',function(){
        var on=this.checked;
        $(this).closest('.bgc-method-tab').toggleClass('bgc-tab-on',on).toggleClass('bgc-tab-off',!on);
    });
})(jQuery);
</script>
        <?php
    }

    private function general_fields(): array {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : ['wc-processing' => 'Processing'];
        $courier_opts = ['' => __('Automatic (first / cheapest)', 'bg-couriers')];
        foreach ($this->sections() as $sid => $slabel) { if ($sid !== '') { $courier_opts[$sid] = $slabel; } }
        return [
            // --- Checkout experience ---
            ['type' => 'title', 'id' => 'bgc_checkout', 'title' => __('Checkout', 'bg-couriers'),
                'desc' => __('How the checkout delivery picker behaves. Reorder couriers by dragging their tabs above; prices show in the store currency.', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgc_default_courier', 'title' => __('Default courier', 'bg-couriers'),
                'desc' => __('Which courier is pre-selected at checkout (its first delivery option is the default).', 'bg-couriers'),
                'options' => $courier_opts, 'default' => ''],
            ['type' => 'number', 'id' => 'bgc_dropdown_limit', 'title' => __('Checkout dropdown results', 'bg-couriers'),
                'desc' => __('Max city / office results in checkout dropdowns. Default 5.', 'bg-couriers'),
                'default' => 5, 'custom_attributes' => ['min' => '1', 'step' => '1']],
            ['type' => 'checkbox', 'id' => 'bgc_preload_cities', 'title' => __('Preload city lists', 'bg-couriers'),
                'desc' => __('Embed each courier’s office/APS city list so those dropdowns open instantly. Address search stays live. Recommended.', 'bg-couriers'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => 'bgc_address_map', 'title' => __('Address map picker', 'bg-couriers'),
                'desc' => __('Adds a “Choose on map” pin picker to address delivery that fills the address automatically. Uses OpenStreetMap unless a Google key is set below.', 'bg-couriers'), 'default' => 'yes'],
            ['type' => 'text', 'id' => 'bgc_google_maps_key', 'title' => __('Google Maps API key (optional)', 'bg-couriers'),
                'desc' => __('Optional Google Maps API key for better maps and address lookup (empty = free OpenStreetMap). Needs “Maps JavaScript API” + “Geocoding API”.', 'bg-couriers'),
                'default' => '', 'autoload' => false],
            ['type' => 'checkbox', 'id' => 'bgc_hide_country', 'title' => __('Hide "Country" field', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'textarea', 'id' => 'bgc_hidden_fields', 'title' => __('Hidden checkout fields (CSS selectors)', 'bg-couriers'),
                'desc' => __('Comma-separated CSS selectors to hide on checkout (e.g. #billing_company_field, .cart-subtotal).', 'bg-couriers'),
                'css' => 'min-width:400px;height:90px;', 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgc_checkout'],

            // --- Prices & display ---
            ['type' => 'title', 'id' => 'bgc_pricing', 'title' => __('Prices & display', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgc_dual_currency', 'title' => __('Enable 2 currencies', 'bg-couriers'),
                'desc' => __('Also show delivery prices in BGN (лв.) next to the store currency. Fixed rate 1 EUR = 1.95583 BGN.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'checkbox', 'id' => 'bgc_cart_estimate_enabled', 'title' => __('Shipping estimate on the cart', 'bg-couriers'),
                'desc' => __('On the cart page, show a rough cached shipping price per courier/option (the exact price is calculated at checkout). Off by default.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgc_free_shipping_label', 'title' => __('Free shipping label', 'bg-couriers'),
                'desc' => __('Text shown for the shipping price when a method is free (e.g. “Free shipping”).', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgc_pricing'],

            ['type' => 'title', 'id' => 'bgc_cod', 'title' => __('Cash on delivery (наложен платеж)', 'bg-couriers'),
                'desc' => __('How you fiscalise collected COD. With ППП (no cash register), a courier that lacks ППП needs prepayment at checkout (or is hidden). Enable ППП per courier on its tab.', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgc_cod_fiscalization', 'title' => __('COD fiscalisation', 'bg-couriers'),
                'options' => [
                    'cash_register' => __('I issue the receipt myself (I have a cash register)', 'bg-couriers'),
                    'ppp'           => __('I rely on the courier\'s postal money transfer / ППП (no cash register)', 'bg-couriers'),
                ],
                'default' => 'cash_register'],
            ['type' => 'sectionend', 'id' => 'bgc_cod'],

            ['type' => 'title', 'id' => 'bgc_labels', 'title' => __('Label generation', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgc_autolabel_enabled',
                'title' => __('Auto-generate labels', 'bg-couriers'),
                'desc' => __('Automatically generate a shipping label when an order reaches the status below.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'select', 'id' => 'bgc_autolabel_status', 'title' => __('Trigger status', 'bg-couriers'),
                'options' => $statuses, 'default' => 'wc-processing'],
            ['type' => 'checkbox', 'id' => 'bgc_send_email',
                'title' => __('Share customer email with courier', 'bg-couriers'),
                'desc' => __('Put the customer’s e-mail on the shipment for courier notifications, when provided.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'bgc_labels'],

            ['type' => 'title', 'id' => 'bgc_tracking', 'title' => __('Shipment tracking', 'bg-couriers'),
                'desc' => __('Poll couriers for tracking updates and note them on the order (BOX NOW uses its webhook). Only active shipments from the last 45 days.', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgc_tracking_poll', 'title' => __('Auto-update tracking', 'bg-couriers'),
                'options' => [
                    'off'        => __('Off', 'bg-couriers'),
                    'bgc_30min'  => __('Every 30 minutes', 'bg-couriers'),
                    'hourly'     => __('Hourly', 'bg-couriers'),
                    'twicedaily' => __('Twice a day', 'bg-couriers'),
                    'daily'      => __('Once a day', 'bg-couriers'),
                ],
                'default' => 'twicedaily'],
            ['type' => 'select', 'id' => 'bgc_autostatus_on_delivered', 'title' => __('On delivery, set order to', 'bg-couriers'),
                'desc' => __('Optionally move the order to this status when tracking reports delivery. “Do not change” only adds a note.', 'bg-couriers'),
                'options' => array_merge(['' => __('Do not change (note only)', 'bg-couriers')], $statuses),
                'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgc_tracking'],

            ['type' => 'title', 'id' => 'bgc_emergency', 'title' => __('Emergency contact', 'bg-couriers'),
                'desc' => __('After repeated failed checkout attempts, show a one-time help box with a phone link. Empty phone = disabled.', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_emergency_phone', 'title' => __('Help phone number', 'bg-couriers'),
                'custom_attributes' => ['placeholder' => '+359888123456']],
            ['type' => 'textarea', 'id' => 'bgc_emergency_message', 'title' => __('Help message', 'bg-couriers'),
                'desc' => __('Shown above the phone link. Leave empty for a default message.', 'bg-couriers'),
                'css' => 'min-width:400px;height:70px;'],
            ['type' => 'sectionend', 'id' => 'bgc_emergency'],
        ];
    }

    private function speedy_courier_fields(): array {
        return [
            ['type' => 'title', 'id' => 'bgc_speedy', 'title' => ''],
            ['type' => 'bgc_ppp_notice', 'id' => 'bgc_ppp_notice_speedy', 'courier' => 'speedy'],
            ['type' => 'bgc_cred_hint', 'id' => 'bgc_speedy_credhint', 'courier' => 'speedy'],
            ['type' => 'checkbox', 'id' => 'bgc_speedy_enabled', 'title' => __('Enable Speedy', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgc_speedy_username', 'title' => __('API username', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'password', 'id' => 'bgc_speedy_password', 'title' => __('API password', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgc_actions', 'id' => 'bgc_speedy_actions'],
            ['type' => 'sectionend', 'id' => 'bgc_speedy'],

            ['type' => 'title', 'id' => 'bgc_speedy_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgc_speedy_label_paper_size', 'title' => __('Label paper size', 'bg-couriers'),
                'options' => ['A6' => __('A6 (label printer)', 'bg-couriers'), 'A4' => __('A4 (office printer)', 'bg-couriers')],
                'default' => 'A6'],
            ['type' => 'text', 'id' => 'bgc_speedy_contents', 'title' => __('Parcel contents (description)', 'bg-couriers'),
                'custom_attributes' => ['placeholder' => 'Goods'], 'default' => ''],
            ['type' => 'select', 'id' => 'bgc_speedy_package', 'title' => __('Package type', 'bg-couriers'),
                'options' => [
                    'BOX'      => __('Box', 'bg-couriers'),
                    'ENVELOPE' => __('Envelope', 'bg-couriers'),
                    'PALLET'   => __('Pallet', 'bg-couriers'),
                ],
                'default' => 'BOX'],
            ['type' => 'sectionend', 'id' => 'bgc_speedy_delivery'],

            ['type' => 'title', 'id' => 'bgc_speedy_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_speedy_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Ship Speedy free above this goods total (excluding shipping). Empty or 0 disables. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgc_speedy_pricing'],

            ['type' => 'title', 'id' => 'bgc_speedy_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgc_speedy_service_payer', 'title' => __('Who pays delivery', 'bg-couriers'),
                'options' => [
                    'sender'    => __('Sender (you - already charged at checkout)', 'bg-couriers'),
                    'recipient' => __('Recipient (pays the courier at delivery)', 'bg-couriers'),
                ],
                'desc' => __('Sender: you pay the courier; COD collects the full total (goods + shipping). Recipient: customer pays delivery at the door; COD collects only the goods total.', 'bg-couriers'),
                'default' => 'sender'],
            ['type' => 'select', 'id' => 'bgc_speedy_open_before_pay', 'title' => __('Open before payment', 'bg-couriers'),
                'options' => [
                    'no'   => __('No', 'bg-couriers'),
                    'open' => __('Allow open before payment', 'bg-couriers'),
                    'test' => __('Allow test before payment', 'bg-couriers'),
                ],
                'default' => 'no'],
            ['type' => 'checkbox', 'id' => 'bgc_speedy_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your Speedy contract pays COD out via ППП (пощенски паричен превод) - lets you accept COD with no cash register.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'sectionend', 'id' => 'bgc_speedy_cod'],
        ];
    }

    private function econt_courier_fields(): array {
        $cd_opts = ['' => __('- none (COD off) -', 'bg-couriers')];
        $sender_opts = ['' => __('- automatic (first profile address) -', 'bg-couriers')];
        if (BGC_Settings::creds_present('econt')) {
            $econt = BGC_Couriers::get('econt');
            if ($econt && method_exists($econt, 'cd_pay_options')) {
                foreach ($econt->cd_pay_options() as $num => $lbl) { $cd_opts[$num] = $lbl; }
            }
            if ($econt && method_exists($econt, 'sender_addresses')) {
                foreach ($econt->sender_addresses() as $id => $lbl) { $sender_opts[$id] = $lbl; }
            }
        }
        return [
            ['type' => 'title', 'id' => 'bgc_econt', 'title' => ''],
            ['type' => 'bgc_ppp_notice', 'id' => 'bgc_ppp_notice_econt', 'courier' => 'econt'],
            ['type' => 'bgc_cred_hint', 'id' => 'bgc_econt_credhint', 'courier' => 'econt'],
            ['type' => 'checkbox', 'id' => 'bgc_econt_enabled', 'title' => __('Enable Econt', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgc_econt_username', 'title' => __('API username', 'bg-couriers'), 'autoload' => false],
            ['type' => 'password', 'id' => 'bgc_econt_password', 'title' => __('API password', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgc_actions', 'id' => 'bgc_econt_actions'],
            ['type' => 'sectionend', 'id' => 'bgc_econt'],

            ['type' => 'title', 'id' => 'bgc_econt_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgc_econt_sender_address', 'title' => __('Ship-from address', 'bg-couriers'),
                'desc' => __('The ship-from address on the waybill (from your Econt profile). Automatic = the first profile address.', 'bg-couriers'),
                'options' => $sender_opts, 'default' => ''],
            ['type' => 'select', 'id' => 'bgc_econt_label_paper_size', 'title' => __('Label format', 'bg-couriers'),
                'desc' => __('Econt labels are A4-landscape only (fixed by its API). The bulk “Print A4” packs several per sheet without scaling.', 'bg-couriers'),
                'options' => ['A4' => __('A4-landscape (fixed by Econt)', 'bg-couriers')],
                'default' => 'A4'],
            ['type' => 'text', 'id' => 'bgc_econt_shipment_description', 'title' => __('Parcel contents description', 'bg-couriers'),
                'desc' => __('Short contents text on the Econt waybill (e.g. “Хранителни добавки”). Empty = a generic value.', 'bg-couriers'),
                'default' => '', 'autoload' => false],
            ['type' => 'sectionend', 'id' => 'bgc_econt_delivery'],

            ['type' => 'title', 'id' => 'bgc_econt_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_econt_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Ship Econt free above this goods total (excluding shipping). Empty or 0 disables. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgc_econt_pricing'],

            ['type' => 'title', 'id' => 'bgc_econt_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgc_econt_cod_enabled', 'title' => __('Cash on delivery (наложен платеж)', 'bg-couriers'),
                'desc' => __('Attach наложен платеж (full total + packing list) to every COD Econt order, paid out via the agreement below. Prepaid orders are never charged again.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'select', 'id' => 'bgc_econt_cd_num', 'title' => __('CD pay-out agreement', 'bg-couriers'),
                'desc' => __('The наложен платеж pay-out agreement (from your Econt profile).', 'bg-couriers'),
                'options' => $cd_opts, 'default' => ''],
            ['type' => 'checkbox', 'id' => 'bgc_econt_pay_after_accept', 'title' => __('Allow inspection before payment', 'bg-couriers'),
                'desc' => __('Let the recipient inspect the shipment before paying (преглед).', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'checkbox', 'id' => 'bgc_econt_sms_notification', 'title' => __('SMS notification', 'bg-couriers'),
                'desc' => __('Send the recipient an SMS notification.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgc_econt_delivery_email', 'title' => __('E-mail on delivery', 'bg-couriers'),
                'desc' => __('Notify this e-mail when the shipment is delivered (leave empty to disable).', 'bg-couriers'),
                'default' => ''],
            ['type' => 'checkbox', 'id' => 'bgc_econt_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your Econt pay-out agreement above is ППП (пощенски паричен превод) - lets you accept COD with no cash register.', 'bg-couriers'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => 'bgc_econt_cod'],
        ];
    }

    private function pigeon_courier_fields(): array {
        return [
            ['type' => 'title', 'id' => 'bgc_pigeon', 'title' => ''],
            ['type' => 'bgc_ppp_notice', 'id' => 'bgc_ppp_notice_pigeon', 'courier' => 'pigeon'],
            ['type' => 'bgc_cred_hint', 'id' => 'bgc_pigeon_credhint', 'courier' => 'pigeon'],
            ['type' => 'checkbox', 'id' => 'bgc_pigeon_enabled', 'title' => __('Enable Pigeon Express', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgc_pigeon_username', 'title' => __('API Key', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'password', 'id' => 'bgc_pigeon_password', 'title' => __('API Secret', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgc_actions', 'id' => 'bgc_pigeon_actions'],
            ['type' => 'checkbox', 'id' => 'bgc_pigeon_live', 'title' => __('Live mode', 'bg-couriers'),
                'desc' => __('On = the live Pigeon production account. Off = the demo/test API (api-demo.pigeonexpress.com) with test credentials.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'sectionend', 'id' => 'bgc_pigeon'],

            ['type' => 'title', 'id' => 'bgc_pigeon_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'bgc_pigeon_pickup', 'id' => 'bgc_pigeon_pickup_office_id',
                'title' => __('Pickup office', 'bg-couriers'),
                'desc' => __('The Pigeon office you drop parcels at. Search your city, then pick the office.', 'bg-couriers')],
            ['type' => 'number', 'id' => 'bgc_pigeon_box_length', 'title' => __('Default parcel length (cm)', 'bg-couriers'),
                'desc_tip' => __('Default parcel size (cm) Pigeon needs on every quote/label, used when an order has none of its own.', 'bg-couriers'),
                'default' => '40', 'custom_attributes' => ['min' => '1', 'step' => '1']],
            ['type' => 'number', 'id' => 'bgc_pigeon_box_width', 'title' => __('Default parcel width (cm)', 'bg-couriers'),
                'default' => '40', 'custom_attributes' => ['min' => '1', 'step' => '1']],
            ['type' => 'number', 'id' => 'bgc_pigeon_box_height', 'title' => __('Default parcel height (cm)', 'bg-couriers'),
                'default' => '40', 'custom_attributes' => ['min' => '1', 'step' => '1']],
            ['type' => 'sectionend', 'id' => 'bgc_pigeon_delivery'],

            ['type' => 'title', 'id' => 'bgc_pigeon_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_pigeon_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Ship Pigeon free above this goods total (excluding shipping). Empty or 0 disables. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgc_pigeon_pricing'],

            ['type' => 'title', 'id' => 'bgc_pigeon_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgc_pigeon_service_payer', 'title' => __('Who pays delivery', 'bg-couriers'),
                'options' => [
                    'sender'    => __('Sender (you - already charged at checkout)', 'bg-couriers'),
                    'recipient' => __('Recipient (pays the courier at delivery)', 'bg-couriers'),
                ],
                'desc' => __('Sender: you pay the courier; COD collects the full total (goods + shipping). Recipient: customer pays delivery at the door; COD collects only the goods total.', 'bg-couriers'),
                'default' => 'sender'],
            ['type' => 'checkbox', 'id' => 'bgc_pigeon_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your Pigeon contract pays COD out via ППП (пощенски паричен превод). Off = COD needs your own cash register.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'bgc_pigeon_cod'],
        ];
    }

    /** Sameday - office/address/easyBox + live quote. Needs a pickup point + per-type service IDs from the contract. */
    private function sameday_courier_fields(): array {
        $cur = get_woocommerce_currency();
        return [
            ['type' => 'title', 'id' => 'bgc_sameday', 'title' => ''],
            ['type' => 'bgc_ppp_notice', 'id' => 'bgc_ppp_notice_sameday', 'courier' => 'sameday'],
            ['type' => 'bgc_cred_hint', 'id' => 'bgc_sameday_credhint', 'courier' => 'sameday'],
            ['type' => 'checkbox', 'id' => 'bgc_sameday_enabled', 'title' => __('Enable Sameday', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgc_sameday_username', 'title' => __('Username', 'bg-couriers'),
                'desc' => __('Sameday API username (X-Auth-Username).', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'password', 'id' => 'bgc_sameday_password', 'title' => __('Password', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgc_actions', 'id' => 'bgc_sameday_actions'],
            ['type' => 'checkbox', 'id' => 'bgc_sameday_live', 'title' => __('Live mode', 'bg-couriers'),
                'desc' => __('On = the live Sameday account. Off = the demo/test API (sameday-api.demo.zitec.com).', 'bg-couriers'),
                'default' => 'yes', 'autoload' => false],
            ['type' => 'sectionend', 'id' => 'bgc_sameday'],

            ['type' => 'title', 'id' => 'bgc_sameday_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'number', 'id' => 'bgc_sameday_pickup_point', 'title' => __('Pickup point ID', 'bg-couriers'),
                'desc' => __('The Sameday pickup-point ID the merchant ships from. Required for quotes and labels.', 'bg-couriers'),
                'default' => '', 'custom_attributes' => ['min' => '0', 'step' => '1'], 'autoload' => false],
            ['type' => 'text', 'id' => 'bgc_sameday_service_office', 'title' => __('Service ID - to office', 'bg-couriers'),
                'desc' => __('Map each delivery type to a Sameday service ID from your contract (used for pricing and labels).', 'bg-couriers'), 'default' => '', 'autoload' => false],
            ['type' => 'text', 'id' => 'bgc_sameday_service_address', 'title' => __('Service ID - to address', 'bg-couriers'), 'default' => '', 'autoload' => false],
            ['type' => 'text', 'id' => 'bgc_sameday_service_automat', 'title' => __('Service ID - to locker (easyBox)', 'bg-couriers'), 'default' => '', 'autoload' => false],
            ['type' => 'select', 'id' => 'bgc_sameday_label_paper_size', 'title' => __('Label paper size', 'bg-couriers'),
                'options' => ['A6' => __('A6 (label printer)', 'bg-couriers'), 'A4' => __('A4 (office printer)', 'bg-couriers')], 'default' => 'A6'],
            ['type' => 'sectionend', 'id' => 'bgc_sameday_delivery'],

            ['type' => 'title', 'id' => 'bgc_sameday_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_sameday_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . $cur . ')',
                'desc' => __('Ship Sameday free above this goods total (excluding shipping). Empty or 0 disables. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgc_sameday_pricing'],

            ['type' => 'title', 'id' => 'bgc_sameday_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgc_sameday_service_payer', 'title' => __('Who pays delivery', 'bg-couriers'),
                'options' => [
                    'sender'    => __('Sender (you - already charged at checkout)', 'bg-couriers'),
                    'recipient' => __('Recipient (pays the courier at delivery)', 'bg-couriers'),
                ],
                'desc' => __('Sender: you pay the courier; COD collects the full total (goods + shipping). Recipient: customer pays delivery at the door; COD collects only the goods total.', 'bg-couriers'),
                'default' => 'sender'],
            ['type' => 'checkbox', 'id' => 'bgc_sameday_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your Sameday contract pays COD out via ППП (пощенски паричен превод). Off = COD needs your own cash register.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'bgc_sameday_cod'],
        ];
    }

    /** BOX NOW - locker-only, flat-rate, OAuth2. Only the fields BoxNow actually uses (no dangling params). */
    private function boxnow_courier_fields(): array {
        return [
            ['type' => 'title', 'id' => 'bgc_boxnow', 'title' => ''],
            ['type' => 'bgc_ppp_notice', 'id' => 'bgc_ppp_notice_boxnow', 'courier' => 'boxnow'],
            ['type' => 'bgc_cred_hint', 'id' => 'bgc_boxnow_credhint', 'courier' => 'boxnow'],
            ['type' => 'checkbox', 'id' => 'bgc_boxnow_enabled', 'title' => __('Enable BOX NOW', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgc_boxnow_username', 'title' => __('Client ID', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'password', 'id' => 'bgc_boxnow_password', 'title' => __('Client secret', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgc_actions', 'id' => 'bgc_boxnow_actions'],
            ['type' => 'checkbox', 'id' => 'bgc_boxnow_live', 'title' => __('Live mode', 'bg-couriers'),
                'desc' => __('On = the live BOX NOW production account. Off = the stage/test API (api-stage.boxnow.bg) with test credentials.', 'bg-couriers'),
                'default' => 'yes', 'autoload' => false],
            ['type' => 'text', 'id' => 'bgc_boxnow_partner_id', 'title' => __('Partner ID', 'bg-couriers'), 'autoload' => false],
            ['type' => 'text', 'id' => 'bgc_boxnow_webhook_secret', 'title' => __('Webhook secret', 'bg-couriers'),
                'desc' => __('You receive it after you register this webhook URL in your BOX NOW account:', 'bg-couriers')
                    . '<br><code>' . esc_html(BGC_Boxnow_Webhook::url()) . '</code>', 'autoload' => false],
            ['type' => 'sectionend', 'id' => 'bgc_boxnow'],

            ['type' => 'title', 'id' => 'bgc_boxnow_delivery', 'title' => __('Delivery & label', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_boxnow_warehouse_id', 'title' => __('Pickup location ID', 'bg-couriers'),
                'desc' => __('Your BOX NOW origin/pickup ID (where parcels ship FROM, not the customer’s locker). From your BOX NOW partner account.', 'bg-couriers'), 'autoload' => false],
            ['type' => 'text', 'id' => 'bgc_boxnow_sender_phone', 'title' => __('Sender contact phone', 'bg-couriers'),
                'desc' => __('Your contact phone for the pickup/origin, printed on the parcel. Leave empty to omit.', 'bg-couriers'), 'autoload' => false],
            ['type' => 'checkbox', 'id' => 'bgc_boxnow_allow_returns', 'title' => __('Allow returns', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'bgc_boxnow_delivery'],

            ['type' => 'title', 'id' => 'bgc_boxnow_pricing', 'title' => __('Pricing', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_boxnow_flat_price', 'title' => __('Delivery price', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Flat BOX NOW locker price (no live rate API). Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'text', 'id' => 'bgc_boxnow_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Ship BOX NOW free above this goods total (excluding shipping). Empty or 0 disables. Store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => 'bgc_boxnow_pricing'],

            ['type' => 'title', 'id' => 'bgc_boxnow_cod', 'title' => __('Cash on delivery', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgc_boxnow_ppp_payout', 'title' => __('COD payout via ППП', 'bg-couriers'),
                'desc' => __('Enable if your BOX NOW contract pays COD out via ППП. BOX NOW has no ППП today, so leave off - COD then needs your own cash register.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'bgc_boxnow_cod'],
        ];
    }

    private function method_fields(string $courier, string $m, string $label): array {
        $p = "bgc_{$courier}_{$m}_";
        return [
            ['type' => 'title', 'id' => $p . 'grp', 'title' => ''],
            ['type' => 'checkbox', 'id' => $p . 'enabled', /* translators: %s: courier name */ 'title' => sprintf(__('Enable “%s”', 'bg-couriers'), $label), 'default' => 'yes'],
            ['type' => 'select', 'id' => $p . 'price_mode', 'title' => __('Delivery price', 'bg-couriers'),
                'options' => [
                    'live'     => __('Live price only - no fixed default (show the cached price until the address is chosen)', 'bg-couriers'),
                    'fallback' => __('Live price, use the fixed price below only if the API is unavailable', 'bg-couriers'),
                    'fixed'    => __('Always the fixed price below - no live API calls at checkout', 'bg-couriers'),
                ], 'default' => 'fallback'],
            ['type' => 'text', 'id' => $p . 'price', 'title' => __('Fixed / default price', 'bg-couriers') . ' (' . get_woocommerce_currency() . ')',
                'desc' => __('Used by the “fixed” and “fallback” delivery-price modes above. In the store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => $p . 'grp'],
        ];
    }
}
