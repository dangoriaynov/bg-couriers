<?php
defined('ABSPATH') || exit;

class BGC_Settings {
    const OPT = 'bgc_speedy_settings';
    const GLOBAL_OPT = 'bgc_global_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);
        add_action('admin_post_bgc_sync_now', [$this, 'sync_now']);
        add_action('admin_post_bgc_validate_creds', [$this, 'validate_creds']);
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
    public function menu(): void {
        add_submenu_page('woocommerce', 'BG Couriers', 'BG Couriers', 'manage_woocommerce', 'bg-couriers', [$this, 'page']);
    }
    public function register(): void {
        register_setting('bgc', self::OPT, ['sanitize_callback' => [$this, 'sanitize_speedy']]);
        register_setting('bgc', self::GLOBAL_OPT);
    }
    public function sanitize_speedy($input): array {
        $out = is_array($input) ? $input : [];
        if (!empty($out['password'])) { $out['password'] = BGC_Encryption::encrypt($out['password']); }
        else { $existing = get_option(self::OPT, []); $out['password'] = $existing['password'] ?? ''; }
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
        $sync = wp_nonce_url(admin_url('admin-post.php?action=bgc_sync_now'), 'bgc_sync_now');
        $val  = wp_nonce_url(admin_url('admin-post.php?action=bgc_validate_creds'), 'bgc_validate_creds');
        echo '<a class="button" href="' . esc_url($val) . '">' . esc_html__('Validate credentials','bg-couriers') . '</a> ';
        echo '<a class="button" href="' . esc_url($sync) . '">' . esc_html__('Sync now','bg-couriers') . '</a>';
        echo '</div>';
    }
    public function sync_now(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        check_admin_referer('bgc_sync_now');
        $cfg = self::courier_config('speedy');
        if ($cfg) { BGC_Sync::run(new BGC_Speedy($cfg)); }
        wp_safe_redirect(admin_url('admin.php?page=bg-couriers')); exit;
    }
    public function validate_creds(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('forbidden'); }
        check_admin_referer('bgc_validate_creds');
        $cfg = self::courier_config('speedy');
        $ok = $cfg ? (new BGC_Speedy($cfg))->check_credentials() : false;
        set_transient('bgc_creds_ok', $ok ? '1' : '0', 60);
        wp_safe_redirect(admin_url('admin.php?page=bg-couriers')); exit;
    }
}
