<?php
defined('ABSPATH') || exit;

/**
 * Data layer + admin glue for bg-couriers settings.
 * Flat WC options (see feedback-settings-architecture). Prices are always in the
 * store's currency (no per-method currency; no dual-currency display in this plugin).
 * UI rendered by BGC_WC_Settings (a WooCommerce Settings tab).
 */
class BGC_Settings {
    const METHODS = ['office', 'address', 'automat'];

    public function __construct() {
        add_filter('woocommerce_get_settings_pages', [$this, 'register_page']);
        add_action('woocommerce_admin_field_bgc_actions', [$this, 'render_actions']);
        add_action('woocommerce_admin_field_bgc_sortable', [$this, 'render_sortable']);
        foreach (array_keys(BGC_Couriers::all()) as $cid) {
            add_filter('woocommerce_admin_settings_sanitize_option_bgc_' . $cid . '_password', [$this, 'sanitize_password'], 10, 3);
        }
        add_action('wp_ajax_bgc_validate_creds', [$this, 'ajax_validate']);
        add_action('wp_ajax_bgc_sync_now', [$this, 'ajax_sync']);
        add_action('wp_ajax_bgc_reset_creds', [$this, 'ajax_reset_creds']);
        add_action('wp_ajax_bgc_save_settings', [$this, 'ajax_save']);
        add_filter('plugin_action_links_' . plugin_basename(BGC_FILE), [$this, 'action_links']);
    }

    public function register_page($pages) {
        $pages[] = new BGC_WC_Settings();
        return $pages;
    }

    // ---- data accessors ----

    public static function get(string $group, string $key, $default = '') {
        $name = $group === 'global' ? 'bgc_' . $key : 'bgc_' . $group . '_' . $key;
        return get_option($name, $default);
    }

    public static function courier_config(string $courier): ?array {
        if (!array_key_exists($courier, BGC_Couriers::all())) { return null; }
        if (get_option('bgc_' . $courier . '_enabled', 'no') !== 'yes') { return null; }
        return [
            'username' => get_option('bgc_' . $courier . '_username', ''),
            'password' => BGC_Encryption::decrypt(get_option('bgc_' . $courier . '_password', '')),
            'sender'   => self::sender(),
        ];
    }

    /** @return array<string,string> id => label of registered couriers. */
    public static function couriers(): array { return BGC_Couriers::all(); }

    /** Whether to compute live prices from the courier API (vs. configured defaults). */
    public static function dynamic_pricing(string $courier): bool {
        return get_option('bgc_' . $courier . '_dynamic_pricing', 'yes') === 'yes';
    }

    /** Per delivery-method config (default price in store currency, free-shipping threshold). */
    public static function method_config(string $courier, string $method): array {
        $p = 'bgc_' . $courier . '_' . $method . '_';
        return [
            'enabled' => get_option($p . 'enabled', 'yes') === 'yes',
            'price'   => (float) get_option($p . 'price', 0),
        ];
    }

    /** How many results to show in checkout city/office dropdowns (shared across couriers). */
    public static function dropdown_limit(): int {
        $raw = get_option('bgc_dropdown_limit', 5);
        if ($raw === '' || (int) $raw <= 0) { return 1000; } // empty / 0 = show all
        return (int) $raw;
    }

    /**
     * Method-level free shipping (the merchant absorbs it) over a goods-total threshold.
     * Auto-enabled by a positive threshold — there is no separate on/off flag.
     */
    public static function free_shipping(string $courier): array {
        $threshold = (float) get_option('bgc_' . $courier . '_free_threshold', 0);
        return [
            'enabled'   => $threshold > 0,
            'threshold' => $threshold,
        ];
    }

    /** @return string[] delivery methods enabled for the courier (drives checkout options). */
    public static function enabled_methods(string $courier): array {
        $out = [];
        foreach (self::METHODS as $m) {
            if (get_option('bgc_' . $courier . '_' . $m . '_enabled', 'yes') === 'yes') { $out[] = $m; }
        }
        return $out;
    }

    /** Global sender address (for shipping labels). */
    public static function sender(): array {
        return [
            'name'     => get_option('bgc_sender_name', ''),
            'phone'    => get_option('bgc_sender_phone', ''),
            'email'    => get_option('bgc_sender_email', ''),
            'city'     => get_option('bgc_sender_city', ''),
            'region'   => get_option('bgc_sender_region', ''),
            'street'   => get_option('bgc_sender_street', ''),
            'postcode' => get_option('bgc_sender_postcode', ''),
        ];
    }

    /** Auto-generate labels when an order reaches a status. */
    public static function autolabel(): array {
        return [
            'enabled' => get_option('bgc_autolabel_enabled', 'no') === 'yes',
            'status'  => get_option('bgc_autolabel_status', 'wc-processing'),
        ];
    }

    /** Label paper size setting (A6 or A4), per courier. */
    public static function label_paper_size(string $courier = 'speedy'): string {
        $v = (string) get_option('bgc_' . $courier . '_label_paper_size', 'A6');
        return in_array($v, ['A6', 'A4'], true) ? $v : 'A6';
    }

    public static function free_shipping_label(): string {
        return (string) get_option('bgc_free_shipping_label', '');
    }

    public static function hidden_fields(): string {
        return (string) get_option('bgc_hidden_fields', '');
    }

    /** Emergency help shown after repeated checkout failures. */
    public static function emergency(): array {
        return [
            'phone'   => (string) get_option('bgc_emergency_phone', ''),
            'message' => (string) get_option('bgc_emergency_message', ''),
        ];
    }

    /** Configured order of delivery methods at checkout (all methods, default order). */
    public static function method_order(string $courier): array {
        $raw = (string) get_option('bgc_' . $courier . '_method_order', '');
        $order = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
        foreach (self::METHODS as $m) { if (!in_array($m, $order, true)) { $order[] = $m; } }
        return array_values(array_intersect($order, self::METHODS));
    }

    /** Custom WC field: drag-sortable order of the delivery methods. */
    public function render_sortable($field): void {
        $id = $field['id'];
        $labels = [
            'office'  => __('To office', 'bg-couriers'),
            'address' => __('To address', 'bg-couriers'),
            'automat' => __('To APS', 'bg-couriers'),
        ];
        wp_enqueue_script('jquery-ui-sortable');
        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html($field['title'] ?? '') . '</th><td class="forminp">';
        $courier = preg_match('/^bgc_([a-z0-9]+)_method_order$/', $id, $mm) ? $mm[1] : 'speedy';
        // Horizontal row — delivery options sit side by side at checkout, so the order control mirrors that.
        echo '<ul id="bgc-sort-' . esc_attr($id) . '" class="bgc-sortable" style="display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:0;list-style:none;">';
        foreach (self::method_order($courier) as $m) {
            if (!isset($labels[$m])) { continue; }
            echo '<li data-m="' . esc_attr($m) . '" style="padding:8px 12px;margin:0;border:1px solid #c3c4c7;border-radius:4px;background:#fff;cursor:move;white-space:nowrap;">⠿ ' . esc_html($labels[$m]) . '</li>';
        }
        echo '</ul>';
        echo '<input type="hidden" name="' . esc_attr($id) . '" id="' . esc_attr($id) . '" value="' . esc_attr(implode(',', self::method_order($courier))) . '">';
        echo '<p class="description">' . esc_html__('Drag to set the order delivery options appear at checkout.', 'bg-couriers') . '</p>';
        $sid = esc_js($id);
        echo "<script>jQuery(function($){ $('#bgc-sort-{$sid}').sortable({update:function(){ $('#{$sid}').val($(this).children().map(function(){return $(this).data('m');}).get().join(',')); }}); });</script>";
        echo '</td></tr>';
    }

    public static function creds_present(string $courier = 'speedy'): bool {
        return get_option('bgc_' . $courier . '_enabled', 'no') === 'yes'
            && get_option('bgc_' . $courier . '_username', '') !== ''
            && get_option('bgc_' . $courier . '_password', '') !== '';
    }

    public function sanitize_password($value, $option, $raw_value) {
        $key = is_array($option) ? (string) ($option['id'] ?? '') : (string) $option;
        if ($raw_value === '' || $raw_value === null) {
            return get_option($key, '');
        }
        // The WC password field can re-render the stored (already-encrypted) value;
        // if it comes back unchanged, keep it — re-encrypting would double-encrypt it.
        if ($raw_value === get_option($key, '')) {
            return $raw_value;
        }
        // A genuinely new password -> the credentials are no longer validated until re-checked.
        if (preg_match('/^bgc_([a-z0-9]+)_password$/', $key, $mm)) { update_option('bgc_' . $mm[1] . '_validated', 'no'); }
        return BGC_Encryption::encrypt($raw_value);
    }

    // ---- AJAX: validate credentials + sync nomenclature ----

    public function ajax_validate(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgc_admin', 'nonce');
        $courier = sanitize_key($_POST['courier'] ?? 'speedy');
        if (!self::courier_config($courier)) { wp_send_json_error(['msg' => __('No credentials saved', 'bg-couriers')]); }
        $c = BGC_Couriers::get($courier);
        $ok = (bool) ($c && $c->check_credentials());
        update_option('bgc_' . $courier . '_validated', $ok ? 'yes' : 'no'); // drives the green/red credentials tint
        wp_send_json_success(['ok' => $ok]);
    }

    /** The red × by the password: marks the credentials as needing re-validation (so the tint goes red). */
    public function ajax_reset_creds(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgc_admin', 'nonce');
        $courier = sanitize_key($_POST['courier'] ?? 'speedy');
        update_option('bgc_' . $courier . '_validated', 'no');
        wp_send_json_success(['ok' => true]);
    }

    /** AJAX save of a BG Couriers settings section (no page reload). Mirrors WC's own field save. */
    public function ajax_save(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => __('You are not allowed to do this.', 'bg-couriers')]); }
        check_ajax_referer('bgc_save', 'bgc_nonce');
        if (!class_exists('WC_Admin_Settings')) { wp_send_json_error(['msg' => __('WooCommerce not available.', 'bg-couriers')]); }
        $section = isset($_POST['bgc_section']) ? sanitize_key(wp_unslash($_POST['bgc_section'])) : '';
        $page = new BGC_WC_Settings();
        WC_Admin_Settings::save_fields($page->get_settings($section), $_POST); // runs the same sanitize filters as a normal save
        $courier = array_key_exists($section, BGC_Couriers::all()) ? $section : '';
        wp_send_json_success([
            'msg'       => __('Saved', 'bg-couriers'),
            'courier'   => $courier,
            'present'   => $courier !== '' ? self::creds_present($courier) : false,
            'validated' => $courier !== '' && get_option('bgc_' . $courier . '_validated', 'no') === 'yes',
        ]);
    }

    public function ajax_sync(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgc_admin', 'nonce');
        $courier = sanitize_key($_POST['courier'] ?? 'speedy');
        $c = BGC_Couriers::get($courier);
        if (!$c || !self::courier_config($courier)) { wp_send_json_error(['msg' => __('No credentials saved', 'bg-couriers')]); }
        @set_time_limit(180);
        wp_send_json_success(BGC_Sync::run($c));
    }

    /** Custom WC settings field: Validate / Sync buttons + the green/red credentials state (locked password + red ×). */
    public function render_actions($field): void {
        $courier = (!empty($field['id']) && preg_match('/^bgc_([a-z0-9]+)_actions$/', (string) $field['id'], $m)) ? $m[1] : 'speedy';
        $present   = self::creds_present($courier);
        $validated = $present && get_option('bgc_' . $courier . '_validated', 'no') === 'yes';
        $nonce = esc_js(wp_create_nonce('bgc_admin'));
        $ajax  = esc_js(admin_url('admin-ajax.php'));

        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__('API check', 'bg-couriers') . '</th><td class="forminp">';
        if ($present) {
            echo '<button type="button" class="button" id="bgc-validate">' . esc_html__('Validate credentials', 'bg-couriers') . '</button> ';
            echo '<button type="button" class="button" id="bgc-sync">' . esc_html__('Sync now', 'bg-couriers') . '</button> ';
            echo '<span id="bgc-status" style="margin-left:10px;vertical-align:middle;"></span>';
        } else {
            echo '<p class="description">' . esc_html__('Enter and save your API username and password, then Validate / Sync appear here.', 'bg-couriers') . '</p>';
        }
        echo '</td></tr>';

        $t = [
            'validating' => esc_js(__('Validating…', 'bg-couriers')),
            'syncing'    => esc_js(__('Syncing… this can take a moment', 'bg-couriers')),
            'valid'      => esc_js(__('Credentials valid', 'bg-couriers')),
            'invalid'    => esc_js(__('Invalid credentials', 'bg-couriers')),
            'cities'     => esc_js(__('cities', 'bg-couriers')),
            'offices'    => esc_js(__('offices', 'bg-couriers')),
            'rates'      => esc_js(__('rates', 'bg-couriers')),
            'fail'       => esc_js(__('Request failed', 'bg-couriers')),
            'change'     => esc_js(__('Change credentials', 'bg-couriers')),
            'savefirst'  => esc_js(__('Save your changes first, then validate.', 'bg-couriers')),
        ];
        $present_js   = $present ? 'true' : 'false';
        $validated_js = $validated ? 'true' : 'false';

        echo <<<JS
<script>
(function($){
    var ajaxurl='{$ajax}', nonce='{$nonce}', courier='{$courier}', present={$present_js}, validated={$validated_js};
    var u=$('#bgc_'+courier+'_username'), p=$('#bgc_'+courier+'_password');
    if(!p.length){ return; }
    var vbtn=$('#bgc-validate'), sbtn=$('#bgc-sync'), st=$('#bgc-status');
    var rows=u.closest('tr').add(p.closest('tr')).add(vbtn.closest('tr'));
    var xbtn=$('<button type="button" class="button bgc-cred-x" title="{$t['change']}">✕</button>');
    p.after(xbtn);
    function syncV(){ vbtn.prop('disabled', present ? (!p.prop('disabled')) : true).attr('title', p.prop('disabled')?'':'{$t['savefirst']}'); }
    function tint(ok){ rows.toggleClass('bgc-creds-ok',ok).toggleClass('bgc-creds-edit',!ok); }
    function lock(green){ p.prop('disabled',true).addClass('bgc-cred-locked').val('').attr('placeholder','••••••••'); xbtn.show(); tint(green); syncV(); }
    function unlock(){ p.prop('disabled',false).removeClass('bgc-cred-locked').val('').attr('placeholder',''); xbtn.hide(); tint(false); syncV(); p.focus(); }
    if(present){ lock(validated); } else { xbtn.hide(); }
    xbtn.on('click',function(){ unlock(); $.post(ajaxurl,{action:'bgc_reset_creds',nonce:nonce,courier:courier}); });
    $(document).on('bgc:saved',function(e,d){ if(d&&d.courier===courier){ present=!!d.present; if(present){ lock(!!d.validated); } else { unlock(); xbtn.hide(); } } });

    function busy(t){ vbtn.add(sbtn).prop('disabled',true); st.html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span>'+t); }
    function err(m){ st.html('<span style="color:#b32d2e;">✗ '+m+'</span>'); }
    function good(m){ st.html('<span style="color:#1a7f37;">✓ '+m+'</span>'); }
    vbtn.on('click',function(){ if(!p.prop('disabled')){ err('{$t['savefirst']}'); return; } busy('{$t['validating']}');
        $.post(ajaxurl,{action:'bgc_validate_creds',nonce:nonce,courier:courier}).done(function(r){
            if(r&&r.success&&r.data&&r.data.ok){ good('{$t['valid']}'); lock(true); }
            else { err((r&&r.data&&r.data.msg)||'{$t['invalid']}'); tint(false); }
        }).fail(function(){ err('{$t['fail']}'); }).always(function(){ sbtn.prop('disabled',false); syncV(); }); });
    sbtn.on('click',function(){ busy('{$t['syncing']}');
        $.post(ajaxurl,{action:'bgc_sync_now',nonce:nonce,courier:courier}).done(function(r){
            if(r&&r.success){ var d=r.data||{}; good((d.cities||0)+' {$t['cities']}, '+(d.offices||0)+' {$t['offices']}, '+(d.rates||0)+' {$t['rates']}'); }
            else { err((r&&r.data&&r.data.msg)||'{$t['fail']}'); }
        }).fail(function(){ err('{$t['fail']}'); }).always(function(){ sbtn.prop('disabled',false); syncV(); }); });
})(jQuery);
</script>
JS;
    }

    public function action_links($links): array {
        $url = admin_url('admin.php?page=wc-settings&tab=bg_couriers&section=speedy');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'bg-couriers') . '</a>');
        return $links;
    }
}
