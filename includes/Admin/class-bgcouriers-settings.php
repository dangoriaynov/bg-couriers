<?php
defined('ABSPATH') || exit;

/**
 * Data layer + admin glue for bg-couriers settings.
 * Flat WC options (see feedback-settings-architecture). Prices are always in the
 * store's currency (no per-method currency; no dual-currency display in this plugin).
 * UI rendered by BGCouriers_WC_Settings (a WooCommerce Settings tab).
 */
class BGCouriers_Settings {

    /**
     * Queue a piece of admin inline JS the wp_enqueue way: attached to a registered no-src holder
     * handle (footer, jquery dep) via wp_add_inline_script instead of echoing a <script> tag.
     * Callable any time before the admin footer prints; snippets keep their call order.
     */
    public static function inline_js(string $js): void {
        if (!wp_script_is('bgc-admin-inline', 'registered')) {
            wp_register_script('bgc-admin-inline', false, ['jquery'], defined('BGCOURIERS_VERSION') ? BGCOURIERS_VERSION : '1', true);
        }
        wp_enqueue_script('bgc-admin-inline');
        wp_add_inline_script('bgc-admin-inline', $js);
    }

    const METHODS = ['office', 'address', 'automat'];

    public function __construct() {
        add_filter('woocommerce_get_settings_pages', [$this, 'register_page']);
        add_action('woocommerce_admin_field_bgcouriers_actions', [$this, 'render_actions']);
        add_action('woocommerce_admin_field_bgcouriers_sortable', [$this, 'render_sortable']);
        add_action('woocommerce_admin_field_bgcouriers_pigeon_pickup', [$this, 'render_pickup']);
        add_action('woocommerce_admin_field_bgcouriers_cred_hint', [$this, 'render_cred_hint']);
        add_action('woocommerce_admin_field_bgcouriers_about', [$this, 'render_about']);
        add_action('woocommerce_admin_field_bgcouriers_ppp_notice', [$this, 'render_ppp_notice']);
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            add_filter('woocommerce_admin_settings_sanitize_option_bgcouriers_' . $cid . '_password', [$this, 'sanitize_password'], 10, 3);
            // Keys/usernames are rendered blank (never exposed); keep the stored value when the field is blank.
            add_filter('woocommerce_admin_settings_sanitize_option_bgcouriers_' . $cid . '_username', [$this, 'sanitize_keep'], 10, 3);
        }
        add_filter('woocommerce_admin_settings_sanitize_option_bgcouriers_dropdown_limit', [$this, 'sanitize_dropdown_limit'], 10, 3);
        add_action('wp_ajax_bgcouriers_validate_creds', [$this, 'ajax_validate']);
        add_action('wp_ajax_bgcouriers_sync_now', [$this, 'ajax_sync']);
        add_action('wp_ajax_bgcouriers_reset_creds', [$this, 'ajax_reset_creds']);
        add_action('wp_ajax_bgcouriers_save_settings', [$this, 'ajax_save']);
        add_action('wp_ajax_bgcouriers_enable_check', [$this, 'ajax_enable_check']);
        add_action('wp_ajax_bgcouriers_save_order', [$this, 'ajax_save_order']);
        add_filter('plugin_action_links_' . plugin_basename(BGCOURIERS_FILE), [$this, 'action_links']);
    }

    public function register_page($pages) {
        $pages[] = new BGCouriers_WC_Settings();
        return $pages;
    }

    // ---- data accessors ----

    public static function get(string $group, string $key, $default = '') {
        $name = $group === 'global' ? 'bgcouriers_' . $key : 'bgcouriers_' . $group . '_' . $key;
        return get_option($name, $default);
    }

    public static function courier_config(string $courier): ?array {
        if (!array_key_exists($courier, BGCouriers_Couriers::all())) { return null; }
        if (get_option('bgcouriers_' . $courier . '_enabled', 'no') !== 'yes') { return null; }
        return [
            'username' => get_option('bgcouriers_' . $courier . '_username', ''),
            'password' => BGCouriers_Encryption::decrypt(get_option('bgcouriers_' . $courier . '_password', '')),
        ];
    }

    /** @return array<string,string> id => label of registered couriers. */
    public static function couriers(): array { return BGCouriers_Couriers::all(); }

    /**
     * Per courier+method delivery-price mode:
     *  - 'live'     : live API only (cached/reference before an address is chosen); no fixed default.
     *  - 'fallback' : live API, fall back to the fixed price if the API is unavailable.
     *  - 'fixed'    : always the fixed price; no live API calls at checkout.
     */
    public static function price_mode(string $courier, string $method): string {
        $m = (string) get_option('bgcouriers_' . $courier . '_' . $method . '_price_mode', 'fallback');
        return in_array($m, ['live', 'fallback', 'fixed'], true) ? $m : 'fallback';
    }

    /** Per delivery-method config (default price in store currency, free-shipping threshold). */
    public static function method_config(string $courier, string $method): array {
        $p = 'bgcouriers_' . $courier . '_' . $method . '_';
        return [
            'enabled' => get_option($p . 'enabled', 'yes') === 'yes',
            'price'   => (float) get_option($p . 'price', 0),
        ];
    }

    /** Default number of results in the checkout city / street search. */
    const DROPDOWN_LIMIT = 20;

    /**
     * How many results a checkout search returns. This bounds the CITY search (only when the city lists
     * are not preloaded - with preloading the search is local and shows everything) and the STREET search.
     * Office lists are never limited: they are fetched whole for the chosen city.
     *
     * Not unlimited on purpose. A two-letter term matches hundreds of streets in a big city and thousands
     * of villages nationwide; sending and rendering that on every keystroke is slow exactly on the phones
     * where it hurts most. 20 is far past "my town is missing" while staying small.
     */
    public static function dropdown_limit(): int {
        $raw = get_option('bgcouriers_dropdown_limit', self::DROPDOWN_LIMIT);
        if ($raw === '' || (int) $raw <= 0) { return self::DROPDOWN_LIMIT; }
        return (int) $raw;
    }

    /** Keep bgcouriers_dropdown_limit a positive number; an empty/invalid value resets to the default 5. */
    public function sanitize_dropdown_limit($value, $option, $raw_value) {
        return (int) $raw_value > 0 ? (string) (int) $raw_value : '5';
    }

    /**
     * Method-level free shipping (the merchant absorbs it) over a goods-total threshold.
     * Auto-enabled by a positive threshold - there is no separate on/off flag.
     */
    /**
     * Free-shipping config for a courier (+optionally one of its delivery methods). Precedence:
     * a COURIER-level threshold applies to every delivery option (the per-option fields are inactive
     * in the UI while it is set); only when it is empty do the per-option thresholds take over.
     */
    public static function free_shipping(string $courier, string $method = ''): array {
        $threshold = (float) get_option('bgcouriers_' . $courier . '_free_threshold', 0);
        if ($threshold <= 0 && $method !== '') {
            $threshold = (float) get_option('bgcouriers_' . $courier . '_' . $method . '_free_threshold', 0);
        }
        return [
            'enabled'   => $threshold > 0,
            'threshold' => $threshold,
        ];
    }

    /**
     * Default parcel dimensions in cm - one set for ALL couriers whose APIs take a parcel size
     * (a locker parcel must fit its box). Falls back to the old per-Pigeon options on installs
     * that configured those before the fields moved to General.
     */
    public static function box_dims(): array {
        // Default 10x10x10: small enough to pass every courier's locker (APS) compartment validation
        // out of the box (the old 40cm default was rejected by Speedy automats).
        $g = static function (string $k): int {
            $v = (int) get_option('bgcouriers_box_' . $k, 0);
            if ($v <= 0) { $v = (int) get_option('bgcouriers_pigeon_box_' . $k, 10); } // pre-move installs
            return max(1, $v);
        };
        return ['length' => $g('length'), 'width' => $g('width'), 'height' => $g('height')];
    }

    /**
     * Default parcel weight in kg, declared on a waybill when the order's products carry no weight of
     * their own. One value for ALL couriers - a per-courier fallback made the same order weigh different
     * amounts depending on who shipped it. Clamped to the 0.1 kg floor every courier API enforces.
     */
    public static function default_weight_kg(): float {
        return max(0.1, round((float) get_option('bgcouriers_default_weight_kg', 1.0), 3));
    }

    /** One contents description for every courier's waybill (moved to General from per-courier fields). */
    public static function shipment_contents(): string {
        $v = trim((string) get_option('bgcouriers_shipment_contents', ''));
        if ($v === '') { // pre-move installs configured these per courier
            $v = trim((string) get_option('bgcouriers_speedy_contents', ''))
                ?: trim((string) get_option('bgcouriers_econt_shipment_description', ''));
        }
        return $v !== '' ? $v : 'Goods';
    }

    /** @return string[] delivery methods enabled for the courier (drives checkout options). */
    public static function enabled_methods(string $courier): array {
        $out = [];
        foreach (self::METHODS as $m) {
            if (get_option('bgcouriers_' . $courier . '_' . $m . '_enabled', 'yes') === 'yes') { $out[] = $m; }
        }
        // Prune to what the courier can actually do AND has synced points for - so an option the courier
        // does not offer (e.g. Pigeon "to APS", which has no lockers) never reaches checkout, even if the
        // toggle option still reads 'yes'. Falls back to raw toggles if the registry isn't loaded yet.
        if (class_exists('BGCouriers_Couriers')) {
            $co = BGCouriers_Couriers::get($courier);
            if ($co) { $out = array_values(array_intersect($out, $co->available_methods())); }
        }
        return $out;
    }


    /** Auto-generate labels when an order reaches a status. */
    public static function autolabel(): array {
        return [
            'enabled' => get_option('bgcouriers_autolabel_enabled', 'no') === 'yes',
            'status'  => get_option('bgcouriers_autolabel_status', 'wc-processing'),
        ];
    }

    /** Whether the customer's e-mail may be sent to the courier when generating a label. */
    public static function send_email(): bool {
        return get_option('bgcouriers_send_email', 'no') === 'yes';
    }

    /** The e-mail to pass to a courier for this order: the customer's, only if enabled and non-empty. */
    public static function label_email(\WC_Order $order): string {
        return self::send_email() ? (string) $order->get_billing_email() : '';
    }

    /** Label paper size setting (A6 or A4), per courier. */
    public static function label_paper_size(string $courier = 'speedy'): string {
        $v = (string) get_option('bgcouriers_' . $courier . '_label_paper_size', 'A6');
        return in_array($v, ['A6', 'A4'], true) ? $v : 'A6';
    }

    public static function free_shipping_label(): string {
        return (string) get_option('bgcouriers_free_shipping_label', '');
    }

    /**
     * How the merchant fiscalises cash the courier collects on delivery:
     *  - 'cash_register' (default): the merchant issues the receipt themselves - COD works with any courier;
     *  - 'ppp': the merchant relies on the courier paying out via пощенски паричен превод (ППП), which is only
     *    legal with couriers that actually offer ППП.
     */
    public static function cod_fiscalization(): string {
        return get_option('bgcouriers_cod_fiscalization', 'cash_register') === 'ppp' ? 'ppp' : 'cash_register';
    }

    /** Whether THIS courier pays collected COD out to the merchant via ППП (пощенски паричен превод). */
    public static function courier_ppp_payout(string $courier): bool {
        // Per-courier toggle (default on for Speedy/Econt, off otherwise incl. BOX NOW). BOX NOW doesn't offer
        // ППП today, but the merchant can flip it on the day it does - no code change needed.
        $default = in_array($courier, ['speedy', 'econt'], true) ? 'yes' : 'no';
        return get_option('bgcouriers_' . $courier . '_ppp_payout', $default) === 'yes';
    }

    /** Whether COD is legally usable with this courier: the merchant issues receipts, or the courier does ППП. */
    public static function cod_allowed_for(string $courier): bool {
        return self::cod_fiscalization() === 'cash_register' || self::courier_ppp_payout($courier);
    }

    /**
     * Whether this courier's delivery price is charged with the order at checkout (default) or only shown
     * for information while the customer pays the courier's own fee on delivery. Drives BOTH the checkout
     * rate cost (0 when off) and the waybill payer/COD amount (service_payer(): off = recipient pays,
     * COD collects goods only). Econt and BOX NOW have no verified recipient-pays API field, so for them
     * delivery is always charged with the order.
     */
    public static function ship_in_total(string $courier): bool {
        // BOX NOW has no way to charge the recipient for the delivery itself: its delivery-request payload
        // is orderNumber/invoiceValue/paymentMode/amountToBeCollected/allowReturn/origin/destination/items,
        // and paymentMode+amountToBeCollected are the cash-on-delivery of the GOODS - the courier fee is
        // billed to the merchant by contract. So for BOX NOW delivery is always charged with the order.
        // Econt does support it - paymentReceiverMethod + paymentReceiverAmountIsPercent, verified live
        // against ee.econt.com: the whole fee moves from senderDueAmount to receiverDueAmount.
        if (!in_array($courier, ['speedy', 'pigeon', 'sameday', 'econt'], true)) { return true; }
        $v = (string) get_option('bgcouriers_' . $courier . '_ship_in_total', '');
        if ($v === '') { // pre-toggle installs: honor the old "Who pays delivery" select
            return get_option('bgcouriers_' . $courier . '_service_payer', 'sender') !== 'recipient';
        }
        return $v !== 'no';
    }

    public static function is_cod_gateway(string $gid, $gw): bool {
        return $gid === 'cod' || (is_object($gw) && is_a($gw, 'WC_Gateway_COD'));
    }

    /** Whether the shop has at least one enabled NON-COD (prepaid / card / bank transfer) payment gateway. */
    public static function has_prepaid_gateway(): bool {
        if (!function_exists('WC') || !WC()->payment_gateways()) { return true; } // can't tell -> assume yes (don't over-restrict)
        foreach (WC()->payment_gateways()->payment_gateways() as $gid => $gw) {
            if (is_object($gw) && $gw->enabled === 'yes' && !self::is_cod_gateway((string) $gid, $gw)) { return true; }
        }
        return false;
    }

    /**
     * Warning to show on a courier's settings tab when it can't be fully used under the current COD setup:
     * only when fiscalisation = ППП and this courier does NOT do ППП. Returns ['level'=>'error'|'warning',
     * 'msg'=>string] or null when there's nothing to warn about.
     *
     * @return array{level:string,msg:string}|null
     */
    /**
     * Why this courier cannot be used as currently configured, if there is a reason. Drives both the red
     * tab tint and the notice on the courier's own settings page, so a courier that will fail is visible
     * before an order runs into it rather than after.
     *
     * @param string $courier Courier id.
     * @return array{level:string,msg:string}|null
     */
    public static function courier_blocker(string $courier): ?array {
        // Sameday refuses recipient-paid delivery unless the contract covers it, and when it refuses no
        // waybill is created AT ALL - every order with this courier fails. We only know once Sameday has
        // said so (there is no way to ask up front), so this reads the flag that its rejection sets.
        if ($courier === 'sameday'
            && get_option(BGCouriers_Sameday::NO_RECIPIENT_PAY, '') === 'yes'
            && !self::ship_in_total('sameday')) {
            return ['level' => 'error', 'msg' => __('Sameday does not support “the recipient pays the delivery” on this account, so NO waybill can be created while it is set that way. Turn on “Delivery in the order total” below, or ask Sameday to allow recipient payment on your contract.', 'bg-couriers')];
        }
        return self::ppp_courier_notice($courier);
    }

    public static function ppp_courier_notice(string $courier): ?array {
        if ($courier === '' || self::cod_fiscalization() !== 'ppp' || self::courier_ppp_payout($courier)) {
            return null;
        }
        if (self::has_prepaid_gateway()) {
            return ['level' => 'warning', 'msg' => __('This courier does not offer ППП. Because your Cash on delivery setting relies on the courier\'s ППП, it can be used here only for PREPAID orders - cash-on-delivery is turned off for it at checkout.', 'bg-couriers')];
        }
        return ['level' => 'error', 'msg' => __('This courier does not offer ППП and your shop has no prepaid (card / bank transfer) payment method, so it cannot take cash-on-delivery and will NOT appear at checkout. Add a prepaid payment method, or set Cash on delivery to "I have a cash register".', 'bg-couriers')];
    }

    public static function hidden_fields(): string {
        return (string) get_option('bgcouriers_hidden_fields', '');
    }

    /**
     * The merchant's "hide these checkout fields" setting, reduced to a CSS selector list that is safe to
     * print into a stylesheet. This is the escaping gate for that option: it ends up inside a stylesheet,
     * where the danger is a value that closes the selector and opens its own rule.
     *
     * Each entry is validated on its own and DROPPED if it is not a plain selector - deliberately not
     * "stripped clean", because silently rewriting someone's selector into a different one that happens to
     * match other elements is worse than ignoring it. Anything that could terminate the selector or start a
     * declaration ({ } ; @ \ / < > and friends) simply fails the pattern.
     *
     * @return string comma-joined selectors, or '' when nothing usable remains
     */
    public static function hidden_field_selectors(): string {
        $out = [];
        foreach (explode(',', wp_strip_all_tags(self::hidden_fields())) as $sel) {
            $sel = trim($sel);
            if ($sel === '' || strlen($sel) > 200) { continue; }
            // tag / .class / #id / * to start, then selector syntax only: [attr="v"], :pseudo, combinators.
            if (!preg_match('/^[A-Za-z0-9_\-#.*:\[][A-Za-z0-9_\-#.*\[\]="\':()\s>+~]*$/', $sel)) { continue; }
            $out[] = $sel;
        }
        return implode(',', $out);
    }

    /** Emergency help shown after repeated checkout failures. */
    public static function emergency(): array {
        return [
            'phone'   => (string) get_option('bgcouriers_emergency_phone', ''),
            'message' => (string) get_option('bgcouriers_emergency_message', ''),
        ];
    }

    /** Configured order of delivery methods at checkout (all methods, default order). */
    public static function method_order(string $courier): array {
        $raw = (string) get_option('bgcouriers_' . $courier . '_method_order', '');
        $order = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
        foreach (self::METHODS as $m) { if (!in_array($m, $order, true)) { $order[] = $m; } }
        return array_values(array_intersect($order, self::METHODS));
    }

    /** Configured order couriers appear at checkout (registered couriers, default registration order). */
    public static function courier_order(): array {
        $all = array_keys(BGCouriers_Couriers::all());
        $raw = (string) get_option('bgcouriers_courier_order', '');
        $order = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
        foreach ($all as $c) { if (!in_array($c, $order, true)) { $order[] = $c; } }
        return array_values(array_intersect($order, $all));
    }

    /** Custom WC field: drag-sortable order - of the delivery methods (bgcouriers_<courier>_method_order) OR the couriers (bgcouriers_courier_order). */
    public function render_sortable($field): void {
        $id = $field['id'];
        wp_enqueue_script('jquery-ui-sortable');
        if ($id === 'bgcouriers_courier_order') {
            $labels = BGCouriers_Couriers::all(); // id => label
            $items  = self::courier_order();
            $desc   = __('Drag to set the order couriers appear at checkout.', 'bg-couriers');
        } else {
            $labels = [
                'office'  => __('To office', 'bg-couriers'),
                'address' => __('To address', 'bg-couriers'),
                'automat' => __('To APS', 'bg-couriers'),
            ];
            $courier = preg_match('/^bgcouriers_([a-z0-9]+)_method_order$/', $id, $mm) ? $mm[1] : 'speedy';
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
        self::inline_js("jQuery(function($){ $('#bgc-sort-{$sid}').sortable({update:function(){ $('#{$sid}').val($(this).children().map(function(){return $(this).data('m');}).get().join(',')); }}); });");
        echo '</td></tr>';
    }

    /**
     * Custom WC field: Pigeon pickup-office picker. A city search (select2, AJAX bgcouriers_search_cities) drives an
     * office dropdown (AJAX bgcouriers_offices, type=office); the chosen office id is stored in the hidden input whose
     * name is the option id (bgcouriers_pigeon_pickup_office_id), so WC saves it like a normal field. Leaving the
     * picker untouched keeps the current value (the hidden input already holds it).
     */
    public function render_pickup($field): void {
        $id      = (string) ($field['id'] ?? 'bgcouriers_pigeon_pickup_office_id');
        $courier = (string) ($field['courier'] ?? 'pigeon');
        $current = (int) get_option($id, 0);
        wp_enqueue_script('selectWoo');
        wp_enqueue_style('select2');
        $ph_city = esc_js(__('Search your city...', 'bg-couriers'));
        $ph_off  = esc_js(__('Pick the pickup office', 'bg-couriers'));
        $idjs    = esc_js($id);
        $cour_js = esc_js($courier);

        // Resolve the SAVED office to its city + name/address so both dropdowns open pre-filled with a
        // readable label (city name, "office - address"), exactly like the checkout picker - not a bare "#id".
        $cur_office_txt = ''; $cur_city_id = 0; $cur_city_txt = '';
        if ($current > 0) {
            $off = BGCouriers_Nomenclature::office_by_id($courier, $current);
            if ($off) {
                $cur_office_txt = esc_js(trim((string) ($off['name'] ?? '') . (!empty($off['address']) ? ' - ' . $off['address'] : '')));
                $cur_city_id    = (int) ($off['city_id'] ?? 0);
                $city = $cur_city_id > 0 ? BGCouriers_Nomenclature::city_by_id($courier, $cur_city_id) : null;
                if ($city) {
                    $cur_city_txt = esc_js(trim((string) ($city['name'] ?? '') . (!empty($city['region']) ? ' (' . $city['region'] . ')' : '')));
                }
            }
            if ($cur_office_txt === '') {
                /* translators: %d: pickup office id */
                $cur_office_txt = esc_js(sprintf(__('Current pickup office (#%d)', 'bg-couriers'), $current));
            }
        }
        $cur_city_js = (string) $cur_city_id;

        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html($field['title'] ?? '') . '</th><td class="forminp">';
        echo '<select id="bgcouriers_pickup_city" style="min-width:300px;"></select><br>';
        echo '<select id="bgcouriers_pickup_office" style="min-width:300px;margin-top:6px;"></select>';
        echo '<input type="hidden" id="' . esc_attr($id) . '" name="' . esc_attr($id) . '" value="' . esc_attr((string) $current) . '">';
        echo '<p class="description">' . esc_html($field['desc'] ?? '') . '</p>';
        self::inline_js("
jQuery(function($){
  var \$c=$('#bgcouriers_pickup_city'), \$o=$('#bgcouriers_pickup_office'), \$h=$('#{$idjs}');
  var cur=\$h.val();
  if(cur && cur!=='0'){ \$o.append(new Option('{$cur_office_txt}', cur, true, true)); }
  var curCity='{$cur_city_js}';
  if(curCity && curCity!=='0'){ \$c.append(new Option('{$cur_city_txt}', curCity, true, true)); }
  \$c.select2({ width:'300px', placeholder:'{$ph_city}', minimumInputLength:1, ajax:{
    url: ajaxurl, dataType:'json', delay:250,
    data:function(p){ return {action:'bgcouriers_search_cities', courier:'{$cour_js}', term:p.term||''}; },
    processResults:function(d){ return {results:(d||[]).map(function(c){ return {id:c.city_id, text:(c.name||'')+(c.region?(' ('+c.region+')'):'')}; })}; }
  }});
  \$o.select2({ width:'300px', placeholder:'{$ph_off}' });
  // The office select2 searches its OWN options (no ajax), so the list has to be in the DOM before the
  // merchant types. Load it for the saved city on page load too - not only when a city is re-picked -
  // otherwise the dropdown holds just the one saved office and every search says \"No results found\".
  function loadOffices(cid, keep){
    if(!cid || cid==='0'){ return; }
    \$o.prop('disabled',true);
    $.getJSON(ajaxurl, {action:'bgcouriers_offices', courier:'{$cour_js}', city_id:cid, type:'office', all:1}, function(rows){
      rows = rows || [];
      \$o.empty();
      rows.forEach(function(r){ \$o.append(new Option((r.name||'')+(r.address?(' - '+r.address):''), r.office_id)); });
      \$o.prop('disabled',false);
      // Keep the saved office selected when it is in this city; otherwise fall back to the first one.
      var found = keep && rows.some(function(r){ return String(r.office_id)===String(keep); });
      var want  = found ? String(keep) : (rows.length ? String(rows[0].office_id) : '');
      if(want){ \$o.val(want); \$h.val(want); }
      \$o.trigger('change');
    });
  }
  loadOffices(curCity, cur);
  \$c.on('select2:select', function(e){ loadOffices(e.params.data.id, null); });
  \$o.on('change', function(){ if($(this).val()){ \$h.val($(this).val()); } });
});
");
        echo '</td></tr>';
    }

    /**
     * Custom WC field: the "About" tab - the plugin author, links to their other free WordPress.org plugins,
     * an optional Revolut donation link and a contact e-mail. All static + escaped, confined to this tab (no
     * dashboard-wide notices), per the WordPress.org guidelines on donations and cross-promotion.
     */
    /**
     * Custom WC field: a banner at the top of a courier tab when it can't be fully used under the current COD
     * setup (ППП mode + this courier does not do ППП). Amber = usable for prepaid only; red = unusable (no
     * prepaid gateway) so it won't appear at checkout. Renders nothing when there's nothing to warn about.
     */
    public function render_ppp_notice($field): void {
        $notice = self::courier_blocker((string) ($field['courier'] ?? ''));
        if (!$notice) { return; }
        $is_err = $notice['level'] === 'error';
        $bg  = $is_err ? 'var(--bgc-red-bg)' : '#fef8e7'; // red = error, amber = warning (intentionally distinct)
        $bd  = $is_err ? 'var(--bgc-red-bd)' : '#e6cf7a';
        $col = $is_err ? 'var(--bgc-red-tx)' : '#7a5b00';
        echo '<tr valign="top"><td colspan="2" class="forminp" style="padding-top:4px;">';
        echo '<div class="bgc-ppp-notice" style="border:1px solid ' . esc_attr($bd) . ';background:' . esc_attr($bg)
            . ';color:' . esc_attr($col) . ';border-radius:8px;padding:10px 14px;line-height:1.5;">';
        echo '<strong>' . esc_html($is_err ? __('This courier is currently unusable', 'bg-couriers') : __('Cash on delivery is off for this courier', 'bg-couriers')) . '</strong><br>';
        echo esc_html($notice['msg']);
        echo '</div></td></tr>';
    }

    public function render_about($field): void {
        $plugins = [
            ['name' => 'RiskyBuyer', 'url' => 'https://wordpress.org/plugins/riskybuyer/', 'note' => __('COD risk scoring for WooCommerce', 'bg-couriers')],
            ['name' => 'Ordelist - Order List Enhancer for WooCommerce', 'url' => 'https://wordpress.org/plugins/ordelist-order-list-enhancer-for-woocommerce/', 'note' => __('in review', 'bg-couriers')],
        ];
        echo '<tr valign="top"><td colspan="2" class="forminp" style="padding-top:4px;">';
        echo '<div class="bgc-about" style="max-width:760px;line-height:1.6;">';
        // Brand name - NOT translatable: if "BG Couriers for WooCommerce" is a translatable string, WordPress
        // translates the plugin header too ("за WooCommerce"), which trips WP.org's trademark check (the name
        // must keep the English "for woocommerce" pattern).
        echo '<h3 style="margin:.2em 0 .4em;">' . esc_html('BG Couriers for WooCommerce') . '</h3>';
        echo '<p style="margin:.2em 0;">' . esc_html__('Free shipping integration for the Bulgarian couriers, built and maintained by an independent developer.', 'bg-couriers') . '</p>';
        echo '<h4 style="margin:.9em 0 .3em;">' . esc_html__('My other free plugins', 'bg-couriers') . '</h4>';
        echo '<ul style="margin:.2em 0 .2em 1.3em;list-style:disc;">';
        foreach ($plugins as $p) {
            echo '<li style="margin:.2em 0;"><a href="' . esc_url($p['url']) . '" target="_blank" rel="noopener">' . esc_html($p['name']) . '</a>'
                . ' <span style="color:#646970;">- ' . esc_html($p['note']) . '</span></li>';
        }
        echo '</ul>';
        echo '<h4 style="margin:.9em 0 .3em;">' . esc_html__('Support the work', 'bg-couriers') . '</h4>';
        echo '<p style="margin:.2em 0;">' . esc_html__('These plugins are free. If they help your store, a small donation keeps more of them coming - thank you!', 'bg-couriers') . '</p>';
        echo '<p style="margin:.4em 0;"><a class="button button-primary" href="' . esc_url('https://revolut.me/danq6lus') . '" target="_blank" rel="noopener">' . esc_html__('Donate via Revolut', 'bg-couriers') . '</a></p>';
        echo '<p style="margin:.6em 0 .2em;color:#646970;">' . esc_html__('Contact / feedback:', 'bg-couriers') . ' <a href="' . esc_url('mailto:winter2007d@gmail.com') . '">winter2007d@gmail.com</a></p>';
        echo '</div></td></tr>';
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
        echo '<details class="bgc-cred-hint" style="border:1px solid #dcdcde;border-radius:6px;padding:8px 12px;background:#fbfbfc;">';
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

    /**
     * Full-width ППП-notice banner rendered OUTSIDE the form-table (so it spans the whole settings column,
     * like the enable toggle). Echoes nothing when there is no notice to show.
     */
    public static function ppp_notice_block(string $courier): void {
        $notice = self::courier_blocker($courier);
        if (!$notice) { return; }
        $is_err = $notice['level'] === 'error';
        $bg  = $is_err ? 'var(--bgc-red-bg)' : '#fef8e7'; // red = error, amber = warning (intentionally distinct)
        $bd  = $is_err ? 'var(--bgc-red-bd)' : '#e6cf7a';
        $col = $is_err ? 'var(--bgc-red-tx)' : '#7a5b00';
        echo '<div class="bgc-ppp-notice" style="border:1px solid ' . esc_attr($bd) . ';background:' . esc_attr($bg)
            . ';color:' . esc_attr($col) . ';border-radius:8px;padding:10px 14px;margin:0 0 14px;line-height:1.5;">';
        echo '<strong>' . esc_html($is_err ? __('This courier is currently unusable', 'bg-couriers') : __('Cash on delivery is off for this courier', 'bg-couriers')) . '</strong><br>';
        echo esc_html($notice['msg']);
        echo '</div>';
    }

    /** Full-width "How do I get API credentials?" hint rendered OUTSIDE the form-table (spans the column). */
    public static function cred_hint_block(string $courier): void {
        $data = self::cred_hint_data($courier);
        if (empty($data)) { return; }
        echo '<details class="bgc-cred-hint" style="border:1px solid #dcdcde;border-radius:6px;padding:8px 12px;margin:0 0 14px;background:#fbfbfc;">';
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
        echo '</div></details>';
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
        return get_option('bgcouriers_' . $courier . '_enabled', 'no') === 'yes'
            && get_option('bgcouriers_' . $courier . '_username', '') !== ''
            && get_option('bgcouriers_' . $courier . '_password', '') !== '';
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
        if (preg_match('/^bgcouriers_([a-z0-9]+)_username$/', $key, $mm)) { update_option('bgcouriers_' . $mm[1] . '_validated', 'no'); }
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
        if (preg_match('/^bgcouriers_([a-z0-9]+)_password$/', $key, $mm)) { update_option('bgcouriers_' . $mm[1] . '_validated', 'no'); }
        return BGCouriers_Encryption::encrypt($raw_value);
    }

    // ---- AJAX: validate credentials + sync nomenclature ----

    public function ajax_validate(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgcouriers_admin', 'nonce');
        $courier = sanitize_key($_POST['courier'] ?? 'speedy');
        if (!self::courier_config($courier)) { wp_send_json_error(['msg' => __('No credentials saved', 'bg-couriers')]); }
        $c = BGCouriers_Couriers::get($courier);
        $ok = (bool) ($c && $c->check_credentials());
        update_option('bgcouriers_' . $courier . '_validated', $ok ? 'yes' : 'no'); // drives the green/red credentials tint
        wp_send_json_success(['ok' => $ok]);
    }

    /** Pre-enable check: return the courier's crucial-settings problems; a non-empty list blocks enabling. */
    public function ajax_enable_check(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['problems' => [['msg' => __('You are not allowed to do this.', 'bg-couriers'), 'fix' => '']]]);
        }
        check_ajax_referer('bgcouriers_admin', 'nonce');
        $courier = sanitize_key(wp_unslash($_POST['courier'] ?? ''));
        $c = BGCouriers_Couriers::get($courier);
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
        check_ajax_referer('bgcouriers_admin', 'nonce');
        $order   = array_values(array_filter(array_map('sanitize_key', explode(',', sanitize_text_field(wp_unslash($_POST['order'] ?? ''))))));
        $courier = isset($_POST['courier']) ? sanitize_key(wp_unslash($_POST['courier'])) : '';
        // With a courier: the drag order of that courier's delivery-option tabs. Without: the courier order.
        if ($courier !== '' && array_key_exists($courier, BGCouriers_Couriers::all())) {
            update_option('bgcouriers_' . $courier . '_method_order', implode(',', $order));
        } else {
            update_option('bgcouriers_courier_order', implode(',', $order));
        }
        wp_send_json_success();
    }

    /** The red × by the password: marks the credentials as needing re-validation (so the tint goes red). */
    public function ajax_reset_creds(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgcouriers_admin', 'nonce');
        $courier = sanitize_key($_POST['courier'] ?? 'speedy');
        update_option('bgcouriers_' . $courier . '_validated', 'no');
        wp_send_json_success(['ok' => true]);
    }

    /** AJAX save of a BG Couriers settings section (no page reload). Mirrors WC's own field save. */
    public function ajax_save(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => __('You are not allowed to do this.', 'bg-couriers')]); }
        check_ajax_referer('bgcouriers_save', 'bgcouriers_nonce');
        if (!class_exists('WC_Admin_Settings')) { wp_send_json_error(['msg' => __('WooCommerce not available.', 'bg-couriers')]); }
        // BGCouriers_WC_Settings skips defining itself when WC's abstract settings page isn't loaded (e.g. admin-ajax) -
        // load the base, then (re)include the class so we can build + save the section's fields.
        if (!class_exists('BGCouriers_WC_Settings')) {
            if (!class_exists('WC_Settings_Page') && function_exists('WC')) {
                foreach (['/includes/admin/settings/class-wc-settings-page.php', '/includes/admin/abstract-wc-settings-page.php'] as $rel) {
                    $base = WC()->plugin_path() . $rel;
                    if (is_readable($base)) { include_once $base; break; }
                }
            }
            if (class_exists('WC_Settings_Page')) { require BGCOURIERS_PATH . 'includes/Admin/class-bgcouriers-wc-settings.php'; }
        }
        if (!class_exists('BGCouriers_WC_Settings')) { wp_send_json_error(['msg' => __('Settings unavailable.', 'bg-couriers')]); }
        $section = isset($_POST['bgcouriers_section']) ? sanitize_key(wp_unslash($_POST['bgcouriers_section'])) : '';
        $page = new BGCouriers_WC_Settings();
        WC_Admin_Settings::save_fields($page->get_settings($section), $_POST); // runs the same sanitize filters as a normal save
        $courier = array_key_exists($section, BGCouriers_Couriers::all()) ? $section : '';
        wp_send_json_success([
            'msg'       => __('Saved', 'bg-couriers'),
            'courier'   => $courier,
            'present'   => $courier !== '' ? self::creds_present($courier) : false,
            'validated' => $courier !== '' && get_option('bgcouriers_' . $courier . '_validated', 'yes') === 'yes',
        ]);
    }

    public function ajax_sync(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => 'forbidden']); }
        check_ajax_referer('bgcouriers_admin', 'nonce');
        $courier = sanitize_key($_POST['courier'] ?? 'speedy');
        $c = BGCouriers_Couriers::get($courier);
        if (!$c || !self::courier_config($courier)) { wp_send_json_error(['msg' => __('No credentials saved', 'bg-couriers')]); }
        @set_time_limit(180); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- needed for long nomenclature sync
        wp_send_json_success(BGCouriers_Sync::run($c));
    }

    /** Custom WC settings field: Validate / Sync buttons + the green/red credentials state (locked password + red ×). */
    public function render_actions($field): void {
        $courier = (!empty($field['id']) && preg_match('/^bgcouriers_([a-z0-9]+)_actions$/', (string) $field['id'], $m)) ? $m[1] : 'speedy';
        $present   = self::creds_present($courier);
        // Default 'yes' for already-configured couriers: creds saved before this flag existed are assumed
        // valid (green) until something explicitly invalidates them (a password change, the × reset, or a
        // failed Validate set the flag to 'no').
        $validated = $present && get_option('bgcouriers_' . $courier . '_validated', 'yes') === 'yes';
        $nonce = esc_js(wp_create_nonce('bgcouriers_admin'));
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
        self::inline_js("\n"
            . '(function($){' . "\n"
            . '    var ajaxurl=\'' . $ajax . '\', nonce=\'' . $nonce . '\', courier=\'' . $courier_js . '\', present=' . $present_js . ', validated=' . $validated_js . ';' . "\n"
            . '    var u=$(\'#bgcouriers_\'+courier+\'_username\'), p=$(\'#bgcouriers_\'+courier+\'_password\');' . "\n"
            . '    if(!p.length){ return; }' . "\n"
            . '    var vbtn=$(\'#bgc-validate\'), sbtn=$(\'#bgc-sync\'), st=$(\'#bgc-status\');' . "\n"
            . '    var rows=u.closest(\'tr\').add(p.closest(\'tr\')).add(vbtn.closest(\'tr\'));' . "\n"
            . '    rows.closest(\'table\').addClass(\'bgc-cred-table\');' . "\n"
            . '    function tint(ok){ rows.toggleClass(\'bgc-creds-ok\',ok).toggleClass(\'bgc-creds-edit\',!ok); }' . "\n"
            . '    function ctl(fld){' . "\n"
            . '        if(!fld.length){ return {lock:function(){},unlock:function(){},editing:function(){return false;},xb:$()}; }' . "\n"
            . '        var xb=$(\'<button type="button" class="button bgc-cred-x" title="' . $t['change'] . '">✕</button>\');' . "\n"
            . '        fld.after(xb);' . "\n"
            . '        var o={ lock:function(){ fld.prop(\'disabled\',true).addClass(\'bgc-cred-locked\').val(\'\').attr(\'placeholder\',\'••••••••\'); xb.show(); },' . "\n"
            . '                unlock:function(){ fld.prop(\'disabled\',false).removeClass(\'bgc-cred-locked\').val(\'\').attr(\'placeholder\',\'\'); xb.hide(); },' . "\n"
            . '                editing:function(){ return !fld.prop(\'disabled\'); }, xb:xb };' . "\n"
            . '        xb.on(\'click\',function(){ o.unlock(); fld.focus(); tint(false); syncV(); $.post(ajaxurl,{action:\'bgcouriers_reset_creds\',nonce:nonce,courier:courier}); });' . "\n"
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
            . '        $.post(ajaxurl,{action:\'bgcouriers_validate_creds\',nonce:nonce,courier:courier}).done(function(r){' . "\n"
            . '            if(r&&r.success&&r.data&&r.data.ok){ good(\'' . $t['valid'] . '\'); lockAll(true); }' . "\n"
            . '            else { err((r&&r.data&&r.data.msg)||\'' . $t['invalid'] . '\'); tint(false); }' . "\n"
            . '        }).fail(function(){ err(\'' . $t['fail'] . '\'); }).always(function(){ sbtn.prop(\'disabled\',false); syncV(); }); });' . "\n"
            . '    sbtn.on(\'click\',function(){ busy(\'' . $t['syncing'] . '\');' . "\n"
            . '        $.post(ajaxurl,{action:\'bgcouriers_sync_now\',nonce:nonce,courier:courier}).done(function(r){' . "\n"
            . '            if(r&&r.success){ var d=r.data||{}; good((d.cities||0)+\' ' . $t['cities'] . ', \'+(d.offices||0)+\' ' . $t['offices'] . ', \'+(d.rates||0)+\' ' . $t['rates'] . '\'); }' . "\n"
            . '            else { err((r&&r.data&&r.data.msg)||\'' . $t['fail'] . '\'); }' . "\n"
            . '        }).fail(function(){ err(\'' . $t['fail'] . '\'); }).always(function(){ sbtn.prop(\'disabled\',false); syncV(); }); });' . "\n"
            . '})(jQuery);' . "\n"
        );
    }

    public function action_links($links): array {
        $url = admin_url('admin.php?page=wc-settings&tab=bg_couriers&section=speedy');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'bg-couriers') . '</a>');
        return $links;
    }
}
