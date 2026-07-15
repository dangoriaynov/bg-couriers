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
        add_action('woocommerce_admin_field_bgc_pigeon_pickup', [$this, 'render_pickup']);
        add_action('woocommerce_admin_field_bgc_cred_hint', [$this, 'render_cred_hint']);
        foreach (array_keys(BGC_Couriers::all()) as $cid) {
            add_filter('woocommerce_admin_settings_sanitize_option_bgc_' . $cid . '_password', [$this, 'sanitize_password'], 10, 3);
            // Keys/usernames are rendered blank (never exposed); keep the stored value when the field is blank.
            add_filter('woocommerce_admin_settings_sanitize_option_bgc_' . $cid . '_username', [$this, 'sanitize_keep'], 10, 3);
        }
        add_filter('woocommerce_admin_settings_sanitize_option_bgc_dropdown_limit', [$this, 'sanitize_dropdown_limit'], 10, 3);
        add_action('wp_ajax_bgc_validate_creds', [$this, 'ajax_validate']);
        add_action('wp_ajax_bgc_sync_now', [$this, 'ajax_sync']);
        add_action('wp_ajax_bgc_reset_creds', [$this, 'ajax_reset_creds']);
        add_action('wp_ajax_bgc_save_settings', [$this, 'ajax_save']);
        add_action('wp_ajax_bgc_enable_check', [$this, 'ajax_enable_check']);
        add_action('wp_ajax_bgc_save_order', [$this, 'ajax_save_order']);
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
        ];
    }

    /** @return array<string,string> id => label of registered couriers. */
    public static function couriers(): array { return BGC_Couriers::all(); }

    /**
     * Per courier+method delivery-price mode:
     *  - 'live'     : live API only (cached/reference before an address is chosen); no fixed default.
     *  - 'fallback' : live API, fall back to the fixed price if the API is unavailable.
     *  - 'fixed'    : always the fixed price; no live API calls at checkout.
     */
    public static function price_mode(string $courier, string $method): string {
        $m = (string) get_option('bgc_' . $courier . '_' . $method . '_price_mode', 'fallback');
        return in_array($m, ['live', 'fallback', 'fixed'], true) ? $m : 'fallback';
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
        if ($raw === '' || (int) $raw <= 0) { return 5; } // empty / invalid falls back to the default 5
        return (int) $raw;
    }

    /** Keep bgc_dropdown_limit a positive number; an empty/invalid value resets to the default 5. */
    public function sanitize_dropdown_limit($value, $option, $raw_value) {
        return (int) $raw_value > 0 ? (string) (int) $raw_value : '5';
    }

    /**
     * Method-level free shipping (the merchant absorbs it) over a goods-total threshold.
     * Auto-enabled by a positive threshold - there is no separate on/off flag.
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


    /** Auto-generate labels when an order reaches a status. */
    public static function autolabel(): array {
        return [
            'enabled' => get_option('bgc_autolabel_enabled', 'no') === 'yes',
            'status'  => get_option('bgc_autolabel_status', 'wc-processing'),
        ];
    }

    /** Whether the customer's e-mail may be sent to the courier when generating a label. */
    public static function send_email(): bool {
        return get_option('bgc_send_email', 'no') === 'yes';
    }

    /** The e-mail to pass to a courier for this order: the customer's, only if enabled and non-empty. */
    public static function label_email(\WC_Order $order): string {
        return self::send_email() ? (string) $order->get_billing_email() : '';
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

    /** Configured order couriers appear at checkout (registered couriers, default registration order). */
    public static function courier_order(): array {
        $all = array_keys(BGC_Couriers::all());
        $raw = (string) get_option('bgc_courier_order', '');
        $order = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
        foreach ($all as $c) { if (!in_array($c, $order, true)) { $order[] = $c; } }
        return array_values(array_intersect($order, $all));
    }

    /** Custom WC field: drag-sortable order - of the delivery methods (bgc_<courier>_method_order) OR the couriers (bgc_courier_order). */
    public function render_sortable($field): void {
        $id = $field['id'];
        wp_enqueue_script('jquery-ui-sortable');
        if ($id === 'bgc_courier_order') {
            $labels = BGC_Couriers::all(); // id => label
            $items  = self::courier_order();
            $desc   = __('Drag to set the order couriers appear at checkout.', 'bg-couriers');
        } else {
            $labels = [
                'office'  => __('To office', 'bg-couriers'),
                'address' => __('To address', 'bg-couriers'),
                'automat' => __('To APS', 'bg-couriers'),
            ];
            $courier = preg_match('/^bgc_([a-z0-9]+)_method_order$/', $id, $mm) ? $mm[1] : 'speedy';
            $items  = self::method_order($courier);
            $desc   = __('Drag to set the order delivery options appear at checkout.', 'bg-couriers');
        }
        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html($field['title'] ?? '') . '</th><td class="forminp">';
        // Horizontal row - options sit side by side at checkout, so the order control mirrors that.
        echo '<ul id="bgc-sort-' . esc_attr($id) . '" class="bgc-sortable" style="display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:0;list-style:none;">';
        foreach ($items as $key) {
            if (!isset($labels[$key])) { continue; }
            echo '<li data-m="' . esc_attr($key) . '" style="padding:8px 12px;margin:0;border:1px solid #c3c4c7;border-radius:4px;background:#fff;cursor:move;white-space:nowrap;">⠿ ' . esc_html($labels[$key]) . '</li>';
        }
        echo '</ul>';
        echo '<input type="hidden" name="' . esc_attr($id) . '" id="' . esc_attr($id) . '" value="' . esc_attr(implode(',', $items)) . '">';
        echo '<p class="description">' . esc_html($desc) . '</p>';
        $sid = esc_js($id);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $sid is esc_js($id)
        echo "<script>jQuery(function($){ $('#bgc-sort-{$sid}').sortable({update:function(){ $('#{$sid}').val($(this).children().map(function(){return $(this).data('m');}).get().join(',')); }}); });</script>";
        echo '</td></tr>';
    }

    /**
     * Custom WC field: Pigeon pickup-office picker. A city search (select2, AJAX bgc_search_cities) drives an
     * office dropdown (AJAX bgc_offices, type=office); the chosen office id is stored in the hidden input whose
     * name is the option id (bgc_pigeon_pickup_office_id), so WC saves it like a normal field. Leaving the
     * picker untouched keeps the current value (the hidden input already holds it).
     */
    public function render_pickup($field): void {
        $id      = (string) ($field['id'] ?? 'bgc_pigeon_pickup_office_id');
        $current = (int) get_option($id, 0);
        wp_enqueue_script('selectWoo');
        wp_enqueue_style('select2');
        $ph_city = esc_js(__('Search your city...', 'bg-couriers'));
        $ph_off  = esc_js(__('Pick the pickup office', 'bg-couriers'));
        /* translators: %d: Pigeon office id */
        $cur_txt = $current > 0 ? esc_js(sprintf(__('Current pickup office (#%d)', 'bg-couriers'), $current)) : '';
        $idjs    = esc_js($id);

        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html($field['title'] ?? '') . '</th><td class="forminp">';
        echo '<select id="bgc_pickup_city" style="min-width:300px;"></select><br>';
        echo '<select id="bgc_pickup_office" style="min-width:300px;margin-top:6px;"></select>';
        echo '<input type="hidden" id="' . esc_attr($id) . '" name="' . esc_attr($id) . '" value="' . esc_attr((string) $current) . '">';
        echo '<p class="description">' . esc_html($field['desc'] ?? '') . '</p>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- all interpolated vars ($idjs,$cur_txt,$ph_city,$ph_off) are esc_js()'d above
        echo "<script>
jQuery(function($){
  var \$c=$('#bgc_pickup_city'), \$o=$('#bgc_pickup_office'), \$h=$('#{$idjs}');
  var cur=\$h.val();
  if(cur && cur!=='0'){ \$o.append(new Option('{$cur_txt}', cur, true, true)); }
  \$c.select2({ width:'300px', placeholder:'{$ph_city}', minimumInputLength:1, ajax:{
    url: ajaxurl, dataType:'json', delay:250,
    data:function(p){ return {action:'bgc_search_cities', courier:'pigeon', term:p.term||''}; },
    processResults:function(d){ return {results:(d||[]).map(function(c){ return {id:c.city_id, text:(c.name||'')+(c.region?(' ('+c.region+')'):'')}; })}; }
  }});
  \$o.select2({ width:'300px', placeholder:'{$ph_off}' });
  \$c.on('select2:select', function(e){
    var cid=e.params.data.id; \$o.prop('disabled',true);
    $.getJSON(ajaxurl, {action:'bgc_offices', courier:'pigeon', city_id:cid, type:'office', all:1}, function(rows){
      \$o.empty();
      (rows||[]).forEach(function(r){ \$o.append(new Option((r.name||'')+(r.address?(' - '+r.address):''), r.office_id)); });
      \$o.prop('disabled',false).trigger('change');
      if((rows||[]).length){ \$h.val(rows[0].office_id); }
    });
  });
  \$o.on('change', function(){ if($(this).val()){ \$h.val($(this).val()); } });
});
</script>";
        echo '</td></tr>';
    }

    /**
     * Custom WC field: a collapsible "How do I get API credentials?" hint per courier (opened on request
     * via a native <details>). Content is the researched, courier-specific way to obtain access - where to
     * write, what to provide, what you receive. Static text only, escaped at output.
     */
    public function render_cred_hint($field): void {
        $data = self::cred_hint_data((string) ($field['courier'] ?? ''));
        if (empty($data)) { return; }
        echo '<tr valign="top"><td colspan="2" class="forminp" style="padding-top:4px;">';
        echo '<details class="bgc-cred-hint" style="border:1px solid #dcdcde;border-radius:6px;padding:8px 12px;background:#fbfbfc;max-width:760px;">';
        echo '<summary style="cursor:pointer;font-weight:600;color:#2271b1;">'
            . esc_html__('How do I get API credentials for this courier?', 'bg-couriers') . '</summary>';
        echo '<div style="margin-top:8px;line-height:1.5;">';
        echo '<p style="margin:.3em 0;">' . esc_html($data['intro']) . '</p>';
        echo '<ol style="margin:.3em 0 .3em 1.4em;">';
        foreach ($data['steps'] as $step) { echo '<li style="margin:.2em 0;">' . esc_html($step) . '</li>'; }
        echo '</ol>';
        echo '<p style="margin:.3em 0;"><strong>' . esc_html__('You receive:', 'bg-couriers') . '</strong> ' . esc_html($data['receive']) . '</p>';
        if (!empty($data['url'])) {
            echo '<p style="margin:.3em 0;">' . esc_html($data['url_label']) . ' <a href="' . esc_url($data['url'])
                . '" target="_blank" rel="noopener noreferrer">' . esc_html($data['url']) . '</a></p>';
        }
        echo '<p style="margin:.5em 0 0;color:#646970;font-size:.92em;">'
            . esc_html__('A courier can change its process - if a step differs, follow the courier\'s own instructions.', 'bg-couriers') . '</p>';
        echo '</div></details></td></tr>';
    }

    /** Researched per-courier credential-acquisition steps (verified against each courier's own site/docs). */
    public static function cred_hint_data(string $courier): array {
        switch ($courier) {
            case 'speedy':
                return [
                    'intro' => __('Speedy issues API access to registered business clients - there is no instant self-signup.', 'bg-couriers'),
                    'steps' => [
                        __('Have (or open) a Speedy business contract.', 'bg-couriers'),
                        __('Request REST API access (ask for a test account first) from your Speedy account manager, or via the integration contact on the Speedy "System integration" page below.', 'bg-couriers'),
                        __('Speedy issues an API username + password for api.speedy.bg.', 'bg-couriers'),
                        __('Enter the username and password below, click Validate, then Sync.', 'bg-couriers'),
                    ],
                    'receive'   => __('API username + password', 'bg-couriers'),
                    'url_label' => __('Speedy system integration:', 'bg-couriers'),
                    'url'       => 'https://www.speedy.bg/en/system-integration',
                ];
            case 'econt':
                return [
                    'intro' => __('Econt\'s API uses your own e-Econt ("Моят Еконт") business account - there is no separate API key.', 'bg-couriers'),
                    'steps' => [
                        __('Register or open a business account in "Моят Еконт" at ee.econt.com.', 'bg-couriers'),
                        __('Confirm with Econt that API access is enabled for your account.', 'bg-couriers'),
                        __('Your API username is your account e-mail; the password is your account password.', 'bg-couriers'),
                        __('Enter the e-mail and password below, click Validate, then Sync.', 'bg-couriers'),
                    ],
                    'receive'   => __('account e-mail (username) + password', 'bg-couriers'),
                    'url_label' => __('Моят Еконт:', 'bg-couriers'),
                    'url'       => 'https://ee.econt.com',
                ];
            case 'pigeon':
                return [
                    'intro' => __('Pigeon Express issues an API Key + Secret to business clients on request.', 'bg-couriers'),
                    'steps' => [
                        __('Contact Pigeon Express and request API access (e-mail support@pigeonexpress.com, or via pigeonexpress.com).', 'bg-couriers'),
                        __('They issue an API Key + API Secret (production; ask for a sandbox/test key to test).', 'bg-couriers'),
                        __('Ask them for your pickup office ID (the office you drop parcels off at).', 'bg-couriers'),
                        __('Enter the Key, Secret and pickup office below, click Validate, then Sync. Tick "Sandbox" only for a test account.', 'bg-couriers'),
                    ],
                    'receive'   => __('API Key + API Secret (+ your pickup office ID)', 'bg-couriers'),
                    'url_label' => __('Pigeon API docs:', 'bg-couriers'),
                    'url'       => 'https://api-docs.pigeonexpress.com',
                ];
            case 'boxnow':
                return [
                    'intro' => __('BOX NOW issues OAuth2 credentials through its integration team.', 'bg-couriers'),
                    'steps' => [
                        __('E-mail integrationsupport@boxnow.bg with your company name, address, tax ID (ЕИК), contact details, and the phone numbers of the people who will use the Partner Portal (needed for OTP SMS login).', 'bg-couriers'),
                        __('They issue your OAuth2 Client ID + Client Secret and confirm your Warehouse ID and Partner ID.', 'bg-couriers'),
                        __('Enter the Client ID, Client Secret, Partner ID and Warehouse ID below; choose the Production environment (or Stage for testing).', 'bg-couriers'),
                    ],
                    'receive'   => __('OAuth2 Client ID + Client Secret (+ Partner ID, Warehouse ID)', 'bg-couriers'),
                    'url_label' => __('BOX NOW:', 'bg-couriers'),
                    'url'       => 'https://www.boxnow.bg',
                ];
            case 'sameday':
                return [
                    'intro' => __('Sameday issues API credentials (username + password) to clients after a business contract.', 'bg-couriers'),
                    'steps' => [
                        __('Sign a Sameday business contract (via sameday.bg or your Sameday account manager).', 'bg-couriers'),
                        __('Request API / eAWB access; you receive a username + password.', 'bg-couriers'),
                        __('Ask for your pickup-point ID and the service IDs for each delivery type (office / address / easyBox locker) from your contract.', 'bg-couriers'),
                        __('Enter the username, password, pickup point and service IDs below; tick "Sandbox" to use the test environment (sameday-api.demo.zitec.com).', 'bg-couriers'),
                    ],
                    'receive'   => __('username + password (+ pickup point and per-type service IDs)', 'bg-couriers'),
                    'url_label' => __('Sameday Bulgaria:', 'bg-couriers'),
                    'url'       => 'https://sameday.bg',
                ];
        }
        return [];
    }

    public static function creds_present(string $courier = 'speedy'): bool {
        return get_option('bgc_' . $courier . '_enabled', 'no') === 'yes'
            && get_option('bgc_' . $courier . '_username', '') !== ''
            && get_option('bgc_' . $courier . '_password', '') !== '';
    }

    /** Keep a stored key/username (plaintext) when the field is submitted blank; store a new value plainly. */
    public function sanitize_keep($value, $option, $raw_value) {
        $key = is_array($option) ? (string) ($option['id'] ?? '') : (string) $option;
        if ($raw_value === '' || $raw_value === null) {
            return get_option($key, ''); // blank/disabled field -> keep existing (never overwrite with empty)
        }
        if ($raw_value === get_option($key, '')) {
            return $raw_value; // unchanged
        }
        // A genuinely new key/username -> the credentials must be re-validated.
        if (preg_match('/^bgc_([a-z0-9]+)_username$/', $key, $mm)) { update_option('bgc_' . $mm[1] . '_validated', 'no'); }
        return sanitize_text_field($raw_value);
    }

    public function sanitize_password($value, $option, $raw_value) {
        $key = is_array($option) ? (string) ($option['id'] ?? '') : (string) $option;
        if ($raw_value === '' || $raw_value === null) {
            return get_option($key, '');
        }
        // The WC password field can re-render the stored (already-encrypted) value;
        // if it comes back unchanged, keep it - re-encrypting would double-encrypt it.
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

    /** Pre-enable check: return the courier's crucial-settings problems; a non-empty list blocks enabling. */
    public function ajax_enable_check(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['problems' => [['msg' => __('You are not allowed to do this.', 'bg-couriers'), 'fix' => '']]]);
        }
        check_ajax_referer('bgc_admin', 'nonce');
        $courier = sanitize_key(wp_unslash($_POST['courier'] ?? ''));
        $c = BGC_Couriers::get($courier);
        if (!$c || !method_exists($c, 'enable_problems')) {
            wp_send_json_error(['problems' => [['msg' => __('Unknown courier.', 'bg-couriers'), 'fix' => '']]]);
        }
        $problems = $c->enable_problems();
        if (!empty($problems)) { wp_send_json_error(['problems' => array_values($problems)]); }
        wp_send_json_success(['ok' => true]);
    }

    /** Save the courier order dragged on the settings tabs (drives checkout + cart ordering via sort_rates). */
    public function ajax_save_order(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(); }
        check_ajax_referer('bgc_admin', 'nonce');
        $order   = array_values(array_filter(array_map('sanitize_key', explode(',', sanitize_text_field(wp_unslash($_POST['order'] ?? ''))))));
        $courier = isset($_POST['courier']) ? sanitize_key(wp_unslash($_POST['courier'])) : '';
        // With a courier: the drag order of that courier's delivery-option tabs. Without: the courier order.
        if ($courier !== '' && array_key_exists($courier, BGC_Couriers::all())) {
            update_option('bgc_' . $courier . '_method_order', implode(',', $order));
        } else {
            update_option('bgc_courier_order', implode(',', $order));
        }
        wp_send_json_success();
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
        // BGC_WC_Settings skips defining itself when WC's abstract settings page isn't loaded (e.g. admin-ajax) -
        // load the base, then (re)include the class so we can build + save the section's fields.
        if (!class_exists('BGC_WC_Settings')) {
            if (!class_exists('WC_Settings_Page') && function_exists('WC')) {
                foreach (['/includes/admin/settings/class-wc-settings-page.php', '/includes/admin/abstract-wc-settings-page.php'] as $rel) {
                    $base = WC()->plugin_path() . $rel;
                    if (is_readable($base)) { include_once $base; break; }
                }
            }
            if (class_exists('WC_Settings_Page')) { require BGC_PATH . 'includes/Admin/class-bgc-wc-settings.php'; }
        }
        if (!class_exists('BGC_WC_Settings')) { wp_send_json_error(['msg' => __('Settings unavailable.', 'bg-couriers')]); }
        $section = isset($_POST['bgc_section']) ? sanitize_key(wp_unslash($_POST['bgc_section'])) : '';
        $page = new BGC_WC_Settings();
        WC_Admin_Settings::save_fields($page->get_settings($section), $_POST); // runs the same sanitize filters as a normal save
        $courier = array_key_exists($section, BGC_Couriers::all()) ? $section : '';
        wp_send_json_success([
            'msg'       => __('Saved', 'bg-couriers'),
            'courier'   => $courier,
            'present'   => $courier !== '' ? self::creds_present($courier) : false,
            'validated' => $courier !== '' && get_option('bgc_' . $courier . '_validated', 'yes') === 'yes',
        ]);
    }

    public function ajax_sync(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgc_admin', 'nonce');
        $courier = sanitize_key($_POST['courier'] ?? 'speedy');
        $c = BGC_Couriers::get($courier);
        if (!$c || !self::courier_config($courier)) { wp_send_json_error(['msg' => __('No credentials saved', 'bg-couriers')]); }
        @set_time_limit(180); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- needed for long nomenclature sync
        wp_send_json_success(BGC_Sync::run($c));
    }

    /** Custom WC settings field: Validate / Sync buttons + the green/red credentials state (locked password + red ×). */
    public function render_actions($field): void {
        $courier = (!empty($field['id']) && preg_match('/^bgc_([a-z0-9]+)_actions$/', (string) $field['id'], $m)) ? $m[1] : 'speedy';
        $present   = self::creds_present($courier);
        // Default 'yes' for already-configured couriers: creds saved before this flag existed are assumed
        // valid (green) until something explicitly invalidates them (a password change, the × reset, or a
        // failed Validate set the flag to 'no').
        $validated = $present && get_option('bgc_' . $courier . '_validated', 'yes') === 'yes';
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

        $courier_js = esc_js($courier);
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- inline admin JS; every interpolated value ($ajax, $nonce, $courier_js, $t[...]) is esc_js()'d above.
        echo '<script>' . "\n"
            . '(function($){' . "\n"
            . '    var ajaxurl=\'' . $ajax . '\', nonce=\'' . $nonce . '\', courier=\'' . $courier_js . '\', present=' . $present_js . ', validated=' . $validated_js . ';' . "\n"
            . '    var u=$(\'#bgc_\'+courier+\'_username\'), p=$(\'#bgc_\'+courier+\'_password\');' . "\n"
            . '    if(!p.length){ return; }' . "\n"
            . '    var vbtn=$(\'#bgc-validate\'), sbtn=$(\'#bgc-sync\'), st=$(\'#bgc-status\');' . "\n"
            . '    var rows=u.closest(\'tr\').add(p.closest(\'tr\')).add(vbtn.closest(\'tr\'));' . "\n"
            . '    function tint(ok){ rows.toggleClass(\'bgc-creds-ok\',ok).toggleClass(\'bgc-creds-edit\',!ok); }' . "\n"
            . '    function ctl(fld){' . "\n"
            . '        if(!fld.length){ return {lock:function(){},unlock:function(){},editing:function(){return false;},xb:$()}; }' . "\n"
            . '        var xb=$(\'<button type="button" class="button bgc-cred-x" title="' . $t['change'] . '">✕</button>\');' . "\n"
            . '        fld.after(xb);' . "\n"
            . '        var o={ lock:function(){ fld.prop(\'disabled\',true).addClass(\'bgc-cred-locked\').val(\'\').attr(\'placeholder\',\'••••••••\'); xb.show(); },' . "\n"
            . '                unlock:function(){ fld.prop(\'disabled\',false).removeClass(\'bgc-cred-locked\').val(\'\').attr(\'placeholder\',\'\'); xb.hide(); },' . "\n"
            . '                editing:function(){ return !fld.prop(\'disabled\'); }, xb:xb };' . "\n"
            . '        xb.on(\'click\',function(){ o.unlock(); fld.focus(); tint(false); syncV(); $.post(ajaxurl,{action:\'bgc_reset_creds\',nonce:nonce,courier:courier}); });' . "\n"
            . '        return o;' . "\n"
            . '    }' . "\n"
            . '    var cu=ctl(u), cp=ctl(p);' . "\n"
            . "    function syncV(){ var ed=cu.editing()||cp.editing(); vbtn.prop('disabled', present ? ed : true).attr('title', ed?'" . $t['savefirst'] . "':''); }\n"
            . '    function lockAll(green){ cu.lock(); cp.lock(); tint(green); syncV(); }' . "\n"
            . '    function unlockAll(){ cu.unlock(); cp.unlock(); tint(false); syncV(); p.focus(); }' . "\n"
            . '    if(present){ lockAll(validated); } else { cu.xb.hide(); cp.xb.hide(); }' . "\n"
            . '    $(document).on(\'bgc:saved\',function(e,d){ if(d&&d.courier===courier){ present=!!d.present; if(present){ lockAll(!!d.validated); } else { unlockAll(); cu.xb.hide(); cp.xb.hide(); } } });' . "\n"
            . "\n"
            . '    function busy(t){ vbtn.add(sbtn).prop(\'disabled\',true); st.html(\'<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span>\'+t); }' . "\n"
            . '    function err(m){ st.html(\'<span style="color:#b32d2e;">✗ \'+m+\'</span>\'); }' . "\n"
            . '    function good(m){ st.html(\'<span style="color:#1a7f37;">✓ \'+m+\'</span>\'); }' . "\n"
            . '    vbtn.on(\'click\',function(){ if(cu.editing()||cp.editing()){ err(\'' . $t['savefirst'] . '\'); return; } busy(\'' . $t['validating'] . '\');' . "\n"
            . '        $.post(ajaxurl,{action:\'bgc_validate_creds\',nonce:nonce,courier:courier}).done(function(r){' . "\n"
            . '            if(r&&r.success&&r.data&&r.data.ok){ good(\'' . $t['valid'] . '\'); lockAll(true); }' . "\n"
            . '            else { err((r&&r.data&&r.data.msg)||\'' . $t['invalid'] . '\'); tint(false); }' . "\n"
            . '        }).fail(function(){ err(\'' . $t['fail'] . '\'); }).always(function(){ sbtn.prop(\'disabled\',false); syncV(); }); });' . "\n"
            . '    sbtn.on(\'click\',function(){ busy(\'' . $t['syncing'] . '\');' . "\n"
            . '        $.post(ajaxurl,{action:\'bgc_sync_now\',nonce:nonce,courier:courier}).done(function(r){' . "\n"
            . '            if(r&&r.success){ var d=r.data||{}; good((d.cities||0)+\' ' . $t['cities'] . ', \'+(d.offices||0)+\' ' . $t['offices'] . ', \'+(d.rates||0)+\' ' . $t['rates'] . '\'); }' . "\n"
            . '            else { err((r&&r.data&&r.data.msg)||\'' . $t['fail'] . '\'); }' . "\n"
            . '        }).fail(function(){ err(\'' . $t['fail'] . '\'); }).always(function(){ sbtn.prop(\'disabled\',false); syncV(); }); });' . "\n"
            . '})(jQuery);' . "\n"
            . '</script>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function action_links($links): array {
        $url = admin_url('admin.php?page=wc-settings&tab=bg_couriers&section=speedy');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'bg-couriers') . '</a>');
        return $links;
    }
}
