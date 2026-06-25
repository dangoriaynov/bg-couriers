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
        add_filter('woocommerce_admin_settings_sanitize_option_bgc_speedy_password', [$this, 'sanitize_password'], 10, 3);
        add_action('wp_ajax_bgc_validate_creds', [$this, 'ajax_validate']);
        add_action('wp_ajax_bgc_sync_now', [$this, 'ajax_sync']);
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
        if ($courier !== 'speedy') { return null; }
        if (get_option('bgc_speedy_enabled', 'no') !== 'yes') { return null; }
        return [
            'username'  => get_option('bgc_speedy_username', ''),
            'password'  => BGC_Encryption::decrypt(get_option('bgc_speedy_password', '')),
            'sender'    => self::sender(),
        ];
    }

    /** Whether to compute live prices from the courier API (vs. configured defaults). */
    public static function dynamic_pricing(string $courier): bool {
        return get_option('bgc_' . $courier . '_dynamic_pricing', 'yes') === 'yes';
    }

    /** Per delivery-method config (default price in store currency, free-shipping threshold). */
    public static function method_config(string $courier, string $method): array {
        $p = 'bgc_' . $courier . '_' . $method . '_';
        return [
            'enabled'        => get_option($p . 'enabled', 'yes') === 'yes',
            'price'          => (float) get_option($p . 'price', 0),
            'free_enabled'   => get_option($p . 'free_enabled', 'no') === 'yes',
            'free_threshold' => (float) get_option($p . 'free_threshold', 0),
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

    /** Label paper size setting (A6 or A4). */
    public static function label_paper_size(): string {
        $v = (string) get_option('bgc_speedy_label_paper_size', 'A6');
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
            'automat' => __('To automat (APS)', 'bg-couriers'),
        ];
        wp_enqueue_script('jquery-ui-sortable');
        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html($field['title'] ?? '') . '</th><td class="forminp">';
        echo '<ul id="bgc-sort-' . esc_attr($id) . '" class="bgc-sortable" style="margin:0;max-width:320px;">';
        foreach (self::method_order('speedy') as $m) {
            if (!isset($labels[$m])) { continue; }
            echo '<li data-m="' . esc_attr($m) . '" style="padding:8px 12px;margin:4px 0;border:1px solid #c3c4c7;border-radius:4px;background:#fff;cursor:move;">⠿ ' . esc_html($labels[$m]) . '</li>';
        }
        echo '</ul>';
        echo '<input type="hidden" name="' . esc_attr($id) . '" id="' . esc_attr($id) . '" value="' . esc_attr(implode(',', self::method_order('speedy'))) . '">';
        echo '<p class="description">' . esc_html__('Drag to set the order delivery options appear at checkout.', 'bg-couriers') . '</p>';
        $sid = esc_js($id);
        echo "<script>jQuery(function($){ $('#bgc-sort-{$sid}').sortable({update:function(){ $('#{$sid}').val($(this).children().map(function(){return $(this).data('m');}).get().join(',')); }}); });</script>";
        echo '</td></tr>';
    }

    public static function creds_present(): bool {
        return get_option('bgc_speedy_enabled', 'no') === 'yes'
            && get_option('bgc_speedy_username', '') !== ''
            && get_option('bgc_speedy_password', '') !== '';
    }

    public function sanitize_password($value, $option, $raw_value) {
        if ($raw_value === '' || $raw_value === null) {
            return get_option('bgc_speedy_password', '');
        }
        // The WC password field can re-render the stored (already-encrypted) value;
        // if it comes back unchanged, keep it — re-encrypting would double-encrypt it.
        if ($raw_value === get_option('bgc_speedy_password', '')) {
            return $raw_value;
        }
        return BGC_Encryption::encrypt($raw_value);
    }

    // ---- AJAX: validate credentials + sync nomenclature ----

    public function ajax_validate(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgc_admin', 'nonce');
        $cfg = self::courier_config('speedy');
        if (!$cfg) { wp_send_json_error(['msg' => __('No credentials saved', 'bg-couriers')]); }
        $ok = (new BGC_Speedy($cfg))->check_credentials();
        wp_send_json_success(['ok' => (bool) $ok]);
    }

    public function ajax_sync(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgc_admin', 'nonce');
        $cfg = self::courier_config('speedy');
        if (!$cfg) { wp_send_json_error(['msg' => __('No credentials saved', 'bg-couriers')]); }
        @set_time_limit(180);
        $r = BGC_Sync::run(new BGC_Speedy($cfg));
        wp_send_json_success($r);
    }

    /** Custom WC settings field: Validate / Sync buttons (only when creds present). */
    public function render_actions($field): void {
        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__('API check', 'bg-couriers') . '</th><td class="forminp">';
        if (!self::creds_present()) {
            echo '<p class="description">' . esc_html__('Enter and save your API username and password, then Validate / Sync appear here.', 'bg-couriers') . '</p></td></tr>';
            return;
        }
        $nonce = esc_js(wp_create_nonce('bgc_admin'));
        $ajax  = esc_js(admin_url('admin-ajax.php'));
        echo '<button type="button" class="button" id="bgc-validate">' . esc_html__('Validate credentials', 'bg-couriers') . '</button> ';
        echo '<button type="button" class="button" id="bgc-sync">' . esc_html__('Sync now', 'bg-couriers') . '</button> ';
        echo '<span id="bgc-status" style="margin-left:10px;vertical-align:middle;"></span>';
        $t = [
            'validating' => esc_js(__('Validating…', 'bg-couriers')),
            'syncing'    => esc_js(__('Syncing… this can take a moment', 'bg-couriers')),
            'valid'      => esc_js(__('Credentials valid', 'bg-couriers')),
            'invalid'    => esc_js(__('Invalid credentials', 'bg-couriers')),
            'cities'     => esc_js(__('cities', 'bg-couriers')),
            'offices'    => esc_js(__('offices', 'bg-couriers')),
            'rates'      => esc_js(__('rates', 'bg-couriers')),
            'fail'       => esc_js(__('Request failed', 'bg-couriers')),
        ];
        echo <<<JS
<script>
(function($){
    var ajaxurl='{$ajax}', nonce='{$nonce}';
    function busy(t){ $('#bgc-validate,#bgc-sync').prop('disabled',true);
        $('#bgc-status').html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span>'+t); }
    function done(){ $('#bgc-validate,#bgc-sync').prop('disabled',false); }
    function err(m){ $('#bgc-status').html('<span style="color:#b32d2e;">✗ '+m+'</span>'); }
    function ok(m){ $('#bgc-status').html('<span style="color:#1a7f37;">✓ '+m+'</span>'); }
    $('#bgc-validate').on('click',function(){ busy('{$t['validating']}');
        $.post(ajaxurl,{action:'bgc_validate_creds',nonce:nonce}).done(function(r){
            if(r&&r.success){ r.data&&r.data.ok ? ok('{$t['valid']}') : err('{$t['invalid']}'); }
            else { err((r&&r.data&&r.data.msg)||'{$t['invalid']}'); }
        }).fail(function(){ err('{$t['fail']}'); }).always(done); });
    $('#bgc-sync').on('click',function(){ busy('{$t['syncing']}');
        $.post(ajaxurl,{action:'bgc_sync_now',nonce:nonce}).done(function(r){
            if(r&&r.success){ var d=r.data||{}; ok((d.cities||0)+' {$t['cities']}, '+(d.offices||0)+' {$t['offices']}, '+(d.rates||0)+' {$t['rates']}'); }
            else { err((r&&r.data&&r.data.msg)||'{$t['fail']}'); }
        }).fail(function(){ err('{$t['fail']}'); }).always(done); });
})(jQuery);
</script>
JS;
        echo '</td></tr>';
    }

    public function action_links($links): array {
        $url = admin_url('admin.php?page=wc-settings&tab=bg_couriers&section=speedy');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'bg-couriers') . '</a>');
        return $links;
    }
}
