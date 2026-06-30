<?php
defined('ABSPATH') || exit;

if (!class_exists('WC_Settings_Page')) { return; }

/**
 * WooCommerce → Settings → "BG Couriers" tab.
 * Level 1 (WP nav-tabs): courier sections (General + Speedy).
 * Level 2 (WP nav-tabs, JS-switched, no reload): per delivery method (office/address/automat).
 * See feedback-settings-architecture — every future courier follows this shape.
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
        ];
    }

    /** Full field set for the section — used by save() (save_settings_for_current_section). */
    public function get_settings($section = '') {
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
        return $this->general_fields();
    }

    protected function get_settings_for_section_core($section_id) { return $this->get_settings($section_id); }

    /** Custom output: WP nav-tab section nav + (for Speedy) per-method sub-tabs. */
    // Suppress WooCommerce's default section nav (the "subsubsub" link row) — we render our own
    // nicer nav-tabs in output(); otherwise the General/Speedy row shows twice.
    public function output_sections() {}

    public function output() {
        global $current_section;
        echo '<style>
        #wpbody .bgc-settings table.form-table th { padding: 9px 12px 9px 0; width: 210px; }
        #wpbody .bgc-settings table.form-table td { padding: 7px 0; }
        #wpbody .bgc-settings table.form-table { margin: 0; }
        #wpbody .bgc-settings .bgc-group { border: 1px solid #e2e6ea; border-radius: 10px; padding: 6px 16px 12px; margin: 0 0 16px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        #wpbody .bgc-settings .bgc-group > h2 { font-size: 1.02em; margin: 12px 0 4px; }
        #wpbody .bgc-settings .bgc-group > p.description { margin-top: 0; }
        /* Nice wide rounded shadowed tabs — applies to both the courier nav and the per-method nav. */
        #wpbody .bgc-settings .nav-tab-wrapper { border-bottom:none; margin:0 0 16px; display:flex; flex-wrap:wrap; gap:10px; padding:0; }
        #wpbody .bgc-settings .nav-tab { border:1px solid #dcdcde; border-radius:11px; padding:11px 26px; margin:0; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.10); font-weight:500; line-height:1.2; color:#1d2327; transition:box-shadow .15s ease, transform .15s ease, background .15s ease; }
        #wpbody .bgc-settings .nav-tab:hover { box-shadow:0 3px 8px rgba(0,0,0,.16); }
        #wpbody .bgc-settings .nav-tab.nav-tab-active { border-color:#8c8f94; box-shadow:0 5px 13px rgba(0,0,0,.20); transform:translateY(-1px); }
        #wpbody .bgc-settings .nav-tab.bgc-tab-on { background:#eafaf0; border-color:#c4e7cf; }
        #wpbody .bgc-settings .nav-tab.bgc-tab-off { background:#fdeeee; border-color:#eecfcf; }
        #wpbody .bgc-settings .nav-tab.bgc-tab-on.nav-tab-active { background:#d8f3e1; }
        #wpbody .bgc-settings .nav-tab.bgc-tab-off.nav-tab-active { background:#fbdcdc; }
        #wpbody .bgc-settings .bgc-enable-toggle { display:flex; align-items:center; gap:12px; padding:11px 14px; margin:2px 0 14px; border-radius:10px; border:1px solid #e2e6ea; }
        #wpbody .bgc-settings .bgc-enable-toggle.bgc-enable-on { background:#f1faf3; border-color:#c4e7cf; }
        #wpbody .bgc-settings .bgc-enable-toggle.bgc-enable-off { background:#fdf5f5; border-color:#eecfcf; }
        #wpbody .bgc-settings .bgc-switch { position:relative; display:inline-block; width:46px; height:26px; flex:0 0 auto; }
        #wpbody .bgc-settings .bgc-switch input { opacity:0; width:0; height:0; margin:0; }
        #wpbody .bgc-settings .bgc-slider { position:absolute; cursor:pointer; inset:0; background:#c9ced3; border-radius:26px; transition:.2s; }
        #wpbody .bgc-settings .bgc-slider:before { content:""; position:absolute; height:20px; width:20px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; }
        #wpbody .bgc-settings .bgc-switch input:checked + .bgc-slider { background:#46b450; }
        #wpbody .bgc-settings .bgc-switch input:checked + .bgc-slider:before { transform:translateX(20px); }
        #wpbody .bgc-settings .bgc-enable-text { font-size:13px; color:#1d2327; }
        /* Credentials state: green when validated, red while editing/unverified; locked masked password + red change-× */
        #wpbody .bgc-settings tr.bgc-creds-ok > th, #wpbody .bgc-settings tr.bgc-creds-ok > td { background:#f1faf3; }
        #wpbody .bgc-settings tr.bgc-creds-edit > th, #wpbody .bgc-settings tr.bgc-creds-edit > td { background:#fdf5f5; }
        #wpbody .bgc-settings .bgc-cred-x { color:#b32d2e; border-color:#dca7a7 !important; margin-left:8px; font-weight:700; line-height:1.6; }
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
        } else {
            WC_Admin_Settings::output_fields($this->general_fields());
        }
        echo '</div></div>';

        // AJAX "Save changes" — save without a page reload, with a top-right toast (green ok / red error).
        $save_nonce = esc_js(wp_create_nonce('bgc_save'));
        $ajaxurl    = esc_js(admin_url('admin-ajax.php'));
        $sect       = esc_js((string) $current_section);
        $i_saved    = esc_js(__('Saved', 'bg-couriers'));
        $i_failed   = esc_js(__('Could not save — please try again.', 'bg-couriers'));
        echo <<<JS
<script>
(function($){
    var ajaxurl='{$ajaxurl}', nonce='{$save_nonce}', section='{$sect}';
    function toast(msg,type,ms){ var c=$('#bgc-toasts'); if(!c.length){ c=$('<div id="bgc-toasts"></div>').appendTo('body'); }
        var t=$('<div class="bgc-toast bgc-toast-'+type+'"></div>').text(msg).appendTo(c);
        requestAnimationFrame(function(){ t.addClass('show'); });
        setTimeout(function(){ t.removeClass('show'); setTimeout(function(){ t.remove(); }, 320); }, ms||3000); }
    var form=$('#mainform'); if(!form.length){ return; }
    form.on('submit', function(e){
        e.preventDefault();
        var save=form.find('button[name="save"],input[name="save"]'); save.prop('disabled',true);
        var data=form.serialize()+'&action=bgc_save_settings&bgc_nonce='+nonce+'&bgc_section='+encodeURIComponent(section);
        $.post(ajaxurl,data).done(function(r){
            if(r&&r.success){ toast((r.data&&r.data.msg)||'{$i_saved}','ok',2500); $(document).trigger('bgc:saved',[r.data||{}]); }
            else { toast((r&&r.data&&r.data.msg)||'{$i_failed}','err',7000); }
        }).fail(function(){ toast('{$i_failed}','err',7000); }).always(function(){ save.prop('disabled',false); });
    });
})(jQuery);
</script>
JS;
    }

    private function section_nav(string $current): void {
        echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper">';
        foreach ($this->sections() as $id => $label) {
            $url = admin_url('admin.php?page=wc-settings&tab=bg_couriers' . ($id ? '&section=' . $id : ''));
            $active = $current === $id ? ' nav-tab-active' : '';
            // Courier sections (every non-General one) get a light green/red tint by enabled state.
            $tint = $id !== '' ? (get_option('bgc_' . $id . '_enabled', 'no') === 'yes' ? ' bgc-tab-on' : ' bgc-tab-off') : '';
            echo '<a class="nav-tab' . $active . $tint . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    /**
     * Render the courier settings section (courier fields + per-method sub-tabs).
     * Works for any courier id (speedy, econt, …).
     */
    private function output_courier(string $courier_id): void {
        $fields    = $this->{$courier_id . '_courier_fields'}();
        $enable_id = 'bgc_' . $courier_id . '_enabled';
        // The enable control is a prominent toggle at the top of the tab (it still saves via get_settings()),
        // so pull it out of the form-table — it must not render as an ordinary checkbox row.
        $fields = array_values(array_filter($fields, static function ($f) use ($enable_id) {
            return !(isset($f['id']) && $f['id'] === $enable_id);
        }));
        $on    = get_option($enable_id, 'no') === 'yes';
        $label = $this->sections()[$courier_id] ?? ucfirst($courier_id);
        $on_t  = esc_html__('enabled', 'bg-couriers');
        $off_t = esc_html__('disabled', 'bg-couriers');
        echo '<div class="bgc-enable-toggle ' . ($on ? 'bgc-enable-on' : 'bgc-enable-off') . '" data-on="' . esc_attr($on_t) . '" data-off="' . esc_attr($off_t) . '">'
            . '<label class="bgc-switch"><input type="checkbox" name="' . esc_attr($enable_id) . '" value="1"' . checked($on, true, false) . '><span class="bgc-slider"></span></label>'
            . '<span class="bgc-enable-text"><strong>' . esc_html($label) . '</strong> — <span class="bgc-enable-state">' . ($on ? $on_t : $off_t) . '</span></span>'
            . '</div>';
        WC_Admin_Settings::output_fields($fields);
        ?>
<script>
(function($){
    $(document).on('change','.bgc-enable-toggle input[type=checkbox]',function(){
        var on=this.checked, box=$(this).closest('.bgc-enable-toggle');
        box.toggleClass('bgc-enable-on',on).toggleClass('bgc-enable-off',!on);
        box.find('.bgc-enable-state').text(on?box.data('on'):box.data('off'));
        $('.bgc-settings .nav-tab-active').toggleClass('bgc-tab-on',on).toggleClass('bgc-tab-off',!on);
    });
})(jQuery);
</script>
        <?php

        // Level-2 nav-tabs for delivery methods (JS-switched panels; all inputs stay in the form so all save).
        echo '<h2 class="nav-tab-wrapper bgc-method-nav" style="margin-top:1.5em;">';
        $first = true;
        foreach (self::$method_labels as $m => $label) {
            $mid = 'bgc_' . $courier_id . '_' . $m . '_enabled';
            $mon = get_option($mid, 'yes') === 'yes';
            echo '<a href="#" class="nav-tab bgc-method-tab' . ($first ? ' nav-tab-active' : '') . ($mon ? ' bgc-tab-on' : ' bgc-tab-off') . '" data-bgc-tab="' . esc_attr($m) . '">'
                . '<label class="bgc-switch bgc-switch-sm" onclick="event.stopPropagation();"><input type="checkbox" name="' . esc_attr($mid) . '" value="1"' . checked($mon, true, false) . '><span class="bgc-slider"></span></label>'
                . '<span>' . esc_html($label) . '</span></a>';
            $first = false;
        }
        echo '</h2>';

        $first = true;
        foreach (self::$method_labels as $m => $label) {
            // Only the price field renders in the panel — the enable control is the tab toggle (still saved via get_settings()).
            $en = 'bgc_' . $courier_id . '_' . $m . '_enabled';
            $mf = array_values(array_filter($this->method_fields($courier_id, $m, $label), static function ($f) use ($en) {
                return !(isset($f['id']) && $f['id'] === $en);
            }));
            echo '<div class="bgc-method-panel" data-bgc-panel="' . esc_attr($m) . '"' . ($first ? '' : ' style="display:none;"') . '>';
            WC_Admin_Settings::output_fields($mf);
            echo '</div>';
            $first = false;
        }
        ?>
<style>
.bgc-method-nav{padding-bottom:0;}
.bgc-method-nav .bgc-method-tab{display:inline-flex;align-items:center;gap:8px;}
.bgc-method-panel{border:1px solid #e2e6ea;border-radius:12px;padding:8px 18px 14px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.07);margin:0 0 10px;}
#wpbody .bgc-settings .bgc-switch-sm{width:32px;height:18px;}
#wpbody .bgc-settings .bgc-switch-sm .bgc-slider:before{height:12px;width:12px;left:3px;bottom:3px;}
#wpbody .bgc-settings .bgc-switch-sm input:checked + .bgc-slider:before{transform:translateX(14px);}
.bgc-method-panel table.form-table{margin-top:.5em;}
.bgc-method-panel h2{display:none;} /* method name lives in the tab, hide the empty group title */
</style>
<script>
(function($){
    $('.bgc-method-nav').on('click','.nav-tab',function(e){
        e.preventDefault();
        var t=$(this).data('bgc-tab');
        $('.bgc-method-nav .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.bgc-method-panel').hide().filter('[data-bgc-panel="'+t+'"]').show();
    });
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
            ['type' => 'title', 'id' => 'bgc_general', 'title' => __('General', 'bg-couriers'),
                'desc' => __('Settings that apply to all couriers. Prices are always shown in the store currency.', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_free_shipping_label', 'title' => __('Free shipping label', 'bg-couriers'),
                'desc' => __('Text shown for the shipping price when a method is free (e.g. “Free shipping”).', 'bg-couriers'), 'default' => ''],
            ['type' => 'textarea', 'id' => 'bgc_hidden_fields', 'title' => __('Hidden checkout fields (CSS selectors)', 'bg-couriers'),
                'desc' => __('Comma-separated CSS selectors to hide on checkout (e.g. #billing_company_field, .cart-subtotal).', 'bg-couriers'),
                'css' => 'min-width:400px;height:90px;', 'default' => ''],
            ['type' => 'checkbox', 'id' => 'bgc_hide_country', 'title' => __('Hide the country field', 'bg-couriers'),
                'desc' => __('Hide the Country / Region selector at checkout. Deliveries are Bulgaria-only, so it is fixed to Bulgaria.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'number', 'id' => 'bgc_dropdown_limit', 'title' => __('Checkout dropdown results', 'bg-couriers'),
                'desc' => __('How many city / office results to show in checkout dropdowns (search shows the same max). Default 5.', 'bg-couriers'),
                'default' => 5, 'custom_attributes' => ['min' => '1', 'step' => '1']],
            ['type' => 'select', 'id' => 'bgc_default_courier', 'title' => __('Default courier', 'bg-couriers'),
                'desc' => __('Which courier is pre-selected at checkout. The default delivery option is the first one in each courier’s “Delivery option order” below.', 'bg-couriers'),
                'options' => $courier_opts, 'default' => ''],
            ['type' => 'bgc_sortable', 'id' => 'bgc_courier_order', 'title' => __('Courier order', 'bg-couriers')],
            ['type' => 'sectionend', 'id' => 'bgc_general'],

            ['type' => 'title', 'id' => 'bgc_sender', 'title' => __('Sender address (for labels)', 'bg-couriers'),
                'desc' => __('Used as the sender on generated shipping labels.', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_sender_name', 'title' => __('Company / sender name', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_sender_phone', 'title' => __('Phone', 'bg-couriers')],
            ['type' => 'email', 'id' => 'bgc_sender_email', 'title' => __('Email', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_sender_city', 'title' => __('City', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_sender_region', 'title' => __('Region', 'bg-couriers')],
            ['type' => 'textarea', 'id' => 'bgc_sender_street', 'title' => __('Street address', 'bg-couriers'), 'css' => 'min-width:400px;height:70px;'],
            ['type' => 'text', 'id' => 'bgc_sender_postcode', 'title' => __('Post code', 'bg-couriers')],
            ['type' => 'sectionend', 'id' => 'bgc_sender'],

            ['type' => 'title', 'id' => 'bgc_labels', 'title' => __('Label generation', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgc_autolabel_enabled',
                'title' => __('Auto-generate labels', 'bg-couriers'),
                'desc' => __('Automatically generate a shipping label when an order reaches the status below.', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'select', 'id' => 'bgc_autolabel_status', 'title' => __('Trigger status', 'bg-couriers'),
                'options' => $statuses, 'default' => 'wc-processing'],
            ['type' => 'sectionend', 'id' => 'bgc_labels'],

            ['type' => 'title', 'id' => 'bgc_emergency', 'title' => __('Emergency contact', 'bg-couriers'),
                'desc' => __('If a customer fails to place an order several times in a row at checkout, a one-time help box appears with a clickable phone link. Leave the phone empty to disable.', 'bg-couriers')],
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
            ['type' => 'checkbox', 'id' => 'bgc_speedy_enabled', 'title' => __('Enable Speedy', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgc_speedy_username', 'title' => __('API username', 'bg-couriers'), 'autoload' => false],
            ['type' => 'password', 'id' => 'bgc_speedy_password', 'title' => __('API password', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgc_actions', 'id' => 'bgc_speedy_actions'],
            ['type' => 'select', 'id' => 'bgc_speedy_label_paper_size', 'title' => __('Label paper size', 'bg-couriers'),
                'options' => ['A6' => __('A6 (label printer)', 'bg-couriers'), 'A4' => __('A4 (office printer)', 'bg-couriers')],
                'default' => 'A6'],
            ['type' => 'checkbox', 'id' => 'bgc_speedy_dynamic_pricing',
                'title' => __('Use dynamic pricing', 'bg-couriers'),
                'desc' => __('Calculate shipping cost live via the Speedy API. When off, the per-method default prices below are used.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'text', 'id' => 'bgc_speedy_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers'),
                'desc' => __('Ship Speedy free (you absorb the cost) when the order goods total (without shipping) reaches this amount — for all delivery types. Enter a positive amount to enable; leave empty or 0 to disable. In the store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'bgc_sortable', 'id' => 'bgc_speedy_method_order', 'title' => __('Delivery option order', 'bg-couriers')],
            ['type' => 'sectionend', 'id' => 'bgc_speedy'],
        ];
    }

    private function econt_courier_fields(): array {
        return [
            ['type' => 'title', 'id' => 'bgc_econt', 'title' => ''],
            ['type' => 'checkbox', 'id' => 'bgc_econt_enabled', 'title' => __('Enable Econt', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgc_econt_username', 'title' => __('API username', 'bg-couriers'), 'autoload' => false],
            ['type' => 'password', 'id' => 'bgc_econt_password', 'title' => __('API password', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgc_actions', 'id' => 'bgc_econt_actions'],
            ['type' => 'select', 'id' => 'bgc_econt_label_paper_size', 'title' => __('Label paper size', 'bg-couriers'),
                'options' => ['A6' => __('A6 (label printer)', 'bg-couriers'), 'A4' => __('A4 (office printer)', 'bg-couriers')],
                'default' => 'A6'],
            ['type' => 'checkbox', 'id' => 'bgc_econt_dynamic_pricing',
                'title' => __('Use dynamic pricing', 'bg-couriers'),
                'desc' => __('Calculate shipping cost live via the Econt API. When off, the per-method default prices below are used.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'text', 'id' => 'bgc_econt_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers'),
                'desc' => __('Ship Econt free (you absorb the cost) when the order goods total (without shipping) reaches this amount — for all delivery types. Enter a positive amount to enable; leave empty or 0 to disable. In the store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'bgc_sortable', 'id' => 'bgc_econt_method_order', 'title' => __('Delivery option order', 'bg-couriers')],
            ['type' => 'sectionend', 'id' => 'bgc_econt'],
        ];
    }

    private function pigeon_courier_fields(): array {
        return [
            ['type' => 'title', 'id' => 'bgc_pigeon', 'title' => ''],
            ['type' => 'checkbox', 'id' => 'bgc_pigeon_enabled', 'title' => __('Enable Pigeon Express', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => 'bgc_pigeon_username', 'title' => __('API Key', 'bg-couriers'), 'autoload' => false],
            ['type' => 'password', 'id' => 'bgc_pigeon_password', 'title' => __('API Secret', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'bgc_actions', 'id' => 'bgc_pigeon_actions'],
            ['type' => 'text', 'id' => 'bgc_pigeon_base_url', 'title' => __('API base URL', 'bg-couriers'),
                'desc' => __('Per-account Pigeon API base URL (e.g. https://api.pigeonexpress.com). Leave empty for the production default.', 'bg-couriers'),
                'default' => '', 'autoload' => false],
            ['type' => 'number', 'id' => 'bgc_pigeon_pickup_office_id', 'title' => __('Pickup office ID', 'bg-couriers'),
                'desc' => __('The Pigeon office ID the merchant ships from. Used for quotes and label creation.', 'bg-couriers'),
                'default' => '', 'custom_attributes' => ['min' => '0', 'step' => '1'], 'autoload' => false],
            ['type' => 'select', 'id' => 'bgc_pigeon_label_paper_size', 'title' => __('Label paper size', 'bg-couriers'),
                'options' => ['A6' => __('A6 (label printer)', 'bg-couriers'), 'A4' => __('A4 (office printer)', 'bg-couriers')],
                'default' => 'A6'],
            ['type' => 'checkbox', 'id' => 'bgc_pigeon_dynamic_pricing',
                'title' => __('Use dynamic pricing', 'bg-couriers'),
                'desc' => __('Calculate shipping cost live via the Pigeon Express API. When off, the per-method default prices below are used.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'text', 'id' => 'bgc_pigeon_free_threshold', 'title' => __('Free-shipping threshold', 'bg-couriers'),
                'desc' => __('Ship Pigeon Express free (you absorb the cost) when the order goods total (without shipping) reaches this amount — for all delivery types. Enter a positive amount to enable; leave empty or 0 to disable. In the store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'bgc_sortable', 'id' => 'bgc_pigeon_method_order', 'title' => __('Delivery option order', 'bg-couriers')],
            ['type' => 'sectionend', 'id' => 'bgc_pigeon'],
        ];
    }

    private function method_fields(string $courier, string $m, string $label): array {
        $p = "bgc_{$courier}_{$m}_";
        return [
            ['type' => 'title', 'id' => $p . 'grp', 'title' => ''],
            ['type' => 'checkbox', 'id' => $p . 'enabled', 'title' => sprintf(__('Enable “%s”', 'bg-couriers'), $label), 'default' => 'yes'],
            ['type' => 'text', 'id' => $p . 'price', 'title' => __('Default price (API fallback)', 'bg-couriers'),
                'desc' => __('Leave empty to use only the live API price. If set, this price is used for this courier + delivery option when there is no connection to the API (or dynamic pricing is off). In the store currency.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => $p . 'grp'],
        ];
    }
}
