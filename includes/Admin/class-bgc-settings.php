<?php
defined('ABSPATH') || exit;

class BGC_Settings {
    const OPT = 'bgc_speedy_settings';
    const GLOBAL_OPT = 'bgc_global_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);
        add_action('wp_ajax_bgc_validate_creds', [$this, 'ajax_validate']);
        add_action('wp_ajax_bgc_sync_now', [$this, 'ajax_sync']);
        add_filter('plugin_action_links_' . plugin_basename(BGC_FILE), [$this, 'action_links']);
        add_filter('plugin_row_meta', [$this, 'row_meta'], 10, 2);
    }

    public static function get(string $group, string $key, $default = '') {
        $opt = get_option($group === 'global' ? self::GLOBAL_OPT : self::OPT, []);
        return $opt[$key] ?? $default;
    }

    public static function courier_config(string $courier): ?array {
        if ($courier !== 'speedy') { return null; }
        $o = get_option(self::OPT, []);
        if (($o['enabled'] ?? 'no') !== 'yes') { return null; }
        return [
            'env' => $o['env'] ?? 'demo',
            'username' => $o['username'] ?? '',
            'password' => BGC_Encryption::decrypt($o['password'] ?? ''),
            'client_id' => (int) ($o['client_id'] ?? 0),
        ];
    }

    /** True when an enabled Speedy config has both a username and a stored (encrypted) password. */
    private static function creds_present(): bool {
        $o = get_option(self::OPT, []);
        return ($o['enabled'] ?? '') === 'yes' && !empty($o['username']) && !empty($o['password']);
    }

    public function menu(): void {
        add_submenu_page('woocommerce', 'BG Couriers', 'BG Couriers', 'manage_woocommerce', 'bg-couriers', [$this, 'page']);
    }

    public function register(): void {
        register_setting('bgc', self::OPT, ['sanitize_callback' => [$this, 'sanitize_speedy']]);
        register_setting('bgc', self::GLOBAL_OPT, ['sanitize_callback' => [$this, 'sanitize_global']]);
    }

    public function sanitize_global($input): array {
        return [
            'dual_currency' => (is_array($input) && isset($input['dual_currency']) && $input['dual_currency'] === 'yes') ? 'yes' : 'no',
        ];
    }

    public function sanitize_speedy($input): array {
        $out = is_array($input) ? $input : [];
        if (!empty($out['password'])) { $out['password'] = BGC_Encryption::encrypt($out['password']); }
        else { $existing = get_option(self::OPT, []); $out['password'] = $existing['password'] ?? ''; }
        $out['enabled'] = (isset($out['enabled']) && $out['enabled'] === 'yes') ? 'yes' : 'no';
        return $out;
    }

    public function page(): void {
        $o = get_option(self::OPT, []); $g = get_option(self::GLOBAL_OPT, []);
        echo '<div class="wrap"><h1>BG Couriers</h1><form method="post" action="options.php">';
        settings_fields('bgc');
        echo '<table class="form-table">';
        printf('<tr><th>%s</th><td><input type="checkbox" name="%s[enabled]" value="yes" %s></td></tr>',
            esc_html__('Enable Speedy','bg-couriers'), self::OPT, checked(($o['enabled'] ?? '') , 'yes', false));
        printf('<tr><th>%s</th><td><select name="%s[env]"><option value="demo" %s>demo</option><option value="live" %s>live</option></select></td></tr>',
            esc_html__('Environment','bg-couriers'), self::OPT, selected(($o['env'] ?? 'demo'),'demo',false), selected(($o['env'] ?? ''),'live',false));
        printf('<tr><th>%s</th><td><input type="text" name="%s[username]" value="%s"></td></tr>',
            esc_html__('API username','bg-couriers'), self::OPT, esc_attr($o['username'] ?? ''));
        printf('<tr><th>%s</th><td><input type="password" name="%s[password]" value="" placeholder="%s"></td></tr>',
            esc_html__('API password','bg-couriers'), self::OPT, esc_attr__('leave blank to keep','bg-couriers'));
        printf('<tr><th>%s</th><td><input type="number" name="%s[client_id]" value="%s"></td></tr>',
            esc_html__('Sender client id','bg-couriers'), self::OPT, esc_attr($o['client_id'] ?? ''));
        printf('<tr><th>%s</th><td><input type="text" name="%s[flat_fallback]" value="%s"></td></tr>',
            esc_html__('Flat fallback price','bg-couriers'), self::OPT, esc_attr($o['flat_fallback'] ?? '6.99'));
        printf('<tr><th>%s</th><td><input type="checkbox" name="%s[dual_currency]" value="yes" %s></td></tr>',
            esc_html__('Dual currency display','bg-couriers'), self::GLOBAL_OPT, checked(($g['dual_currency'] ?? 'yes'),'yes',false));
        echo '</table>';
        submit_button();
        echo '</form>';

        if (self::creds_present()) {
            $nonce = esc_js(wp_create_nonce('bgc_admin'));
            $ajax  = esc_js(admin_url('admin-ajax.php'));
            echo '<p>';
            echo '<button type="button" class="button" id="bgc-validate">' . esc_html__('Validate credentials','bg-couriers') . '</button> ';
            echo '<button type="button" class="button" id="bgc-sync">' . esc_html__('Sync now','bg-couriers') . '</button> ';
            echo '<span id="bgc-status" style="margin-left:10px;vertical-align:middle;"></span>';
            echo '</p>';
            $t_validating = esc_js(__('Validating…','bg-couriers'));
            $t_syncing    = esc_js(__('Syncing… this can take a moment','bg-couriers'));
            $t_valid      = esc_js(__('Credentials valid','bg-couriers'));
            $t_invalid    = esc_js(__('Invalid credentials','bg-couriers'));
            $t_cities     = esc_js(__('cities','bg-couriers'));
            $t_offices    = esc_js(__('offices','bg-couriers'));
            $t_rates      = esc_js(__('rates','bg-couriers'));
            $t_fail       = esc_js(__('Request failed','bg-couriers'));
            echo <<<JS
<script>
(function($){
    var ajaxurl='{$ajax}', nonce='{$nonce}';
    function busy(t){ $('#bgc-validate,#bgc-sync').prop('disabled',true);
        $('#bgc-status').html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span>'+t); }
    function done(){ $('#bgc-validate,#bgc-sync').prop('disabled',false); }
    function err(m){ $('#bgc-status').html('<span style="color:#b32d2e;">✗ '+m+'</span>'); }
    function ok(m){ $('#bgc-status').html('<span style="color:#1a7f37;">✓ '+m+'</span>'); }
    $('#bgc-validate').on('click',function(){
        busy('{$t_validating}');
        $.post(ajaxurl,{action:'bgc_validate_creds',nonce:nonce}).done(function(r){
            if(r&&r.success){ r.data&&r.data.ok ? ok('{$t_valid}') : err('{$t_invalid}'); }
            else { err((r&&r.data&&r.data.msg)||'{$t_invalid}'); }
        }).fail(function(){ err('{$t_fail}'); }).always(done);
    });
    $('#bgc-sync').on('click',function(){
        busy('{$t_syncing}');
        $.post(ajaxurl,{action:'bgc_sync_now',nonce:nonce}).done(function(r){
            if(r&&r.success){ var d=r.data||{}; ok((d.cities||0)+' {$t_cities}, '+(d.offices||0)+' {$t_offices}, '+(d.rates||0)+' {$t_rates}'); }
            else { err((r&&r.data&&r.data.msg)||'{$t_fail}'); }
        }).fail(function(){ err('{$t_fail}'); }).always(done);
    });
})(jQuery);
</script>
JS;
        } else {
            echo '<p class="description">' . esc_html__('Enter and save your API username and password, then Validate / Sync will appear here.','bg-couriers') . '</p>';
        }
        echo '</div>';
    }

    public function ajax_validate(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgc_admin', 'nonce');
        $cfg = self::courier_config('speedy');
        if (!$cfg) { wp_send_json_error(['msg' => __('No credentials saved','bg-couriers')]); }
        $ok = (new BGC_Speedy($cfg))->check_credentials();
        wp_send_json_success(['ok' => (bool) $ok]);
    }

    public function ajax_sync(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgc_admin', 'nonce');
        $cfg = self::courier_config('speedy');
        if (!$cfg) { wp_send_json_error(['msg' => __('No credentials saved','bg-couriers')]); }
        @set_time_limit(180);
        $r = BGC_Sync::run(new BGC_Speedy($cfg));
        wp_send_json_success($r);
    }

    public function action_links($links): array {
        $settings = '<a href="' . esc_url(admin_url('admin.php?page=bg-couriers')) . '">' . esc_html__('Settings','bg-couriers') . '</a>';
        array_unshift($links, $settings);
        return $links;
    }

    public function row_meta($links, $file): array {
        if ($file === plugin_basename(BGC_FILE)) {
            $links[] = '<a href="https://github.com/dangoriaynov" target="_blank" rel="noopener">GitHub</a>';
        }
        return $links;
    }
}
