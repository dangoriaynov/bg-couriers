<?php
defined('ABSPATH') || exit;

/**
 * The admin side of the settings: the WooCommerce tab's registration, the custom field renderers it
 * uses (sortable courier order, Pigeon pickup pickers, credential hints, the readiness and PPP blocks),
 * the sanitizers that keep a stored credential when its field is posted back blank, and the AJAX
 * behind Validate / Sync / Save / Reset. Instantiated from BGCouriers_Plugin under is_admin() only.
 *
 * Everything that READS a setting is BGCouriers_Settings - split off so that the checkout, which asks
 * those readers on every request, does not carry seven hundred lines of admin screen with them.
 */
class BGCouriers_Settings_Admin {

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
    public function __construct() {
        add_filter('woocommerce_get_settings_pages', [$this, 'register_page']);
        add_action('woocommerce_admin_field_bgcouriers_actions', [$this, 'render_actions']);
        add_action('woocommerce_admin_field_bgcouriers_sortable', [$this, 'render_sortable']);
        add_action('woocommerce_admin_field_bgcouriers_pigeon_pickup', [$this, 'render_pickup']);
        add_action('woocommerce_admin_field_bgcouriers_pigeon_pickup_city', [$this, 'render_pickup_city']);
        add_action('woocommerce_admin_field_bgcouriers_cred_hint', [$this, 'render_cred_hint']);
        add_action('woocommerce_admin_field_bgcouriers_about', [$this, 'render_about']);
        add_action('woocommerce_admin_field_bgcouriers_ppp_notice', [$this, 'render_ppp_notice']);
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            add_filter('woocommerce_admin_settings_sanitize_option_bgcouriers_' . $cid . '_password', [$this, 'sanitize_password'], 10, 3);
            // Keys/usernames are rendered blank (never exposed); keep the stored value when the field is blank.
            add_filter('woocommerce_admin_settings_sanitize_option_bgcouriers_' . $cid . '_username', [$this, 'sanitize_keep'], 10, 3);
            // The webhook secret is a credential too - it is what the incoming signature is checked
            // against - so it is drawn blank like the rest and kept when the blank field is posted back.
            add_filter('woocommerce_admin_settings_sanitize_option_bgcouriers_' . $cid . '_webhook_secret', [$this, 'sanitize_keep'], 10, 3);
        }
        add_filter('woocommerce_admin_settings_sanitize_option_bgcouriers_dropdown_limit', [$this, 'sanitize_dropdown_limit'], 10, 3);
        add_action('wp_ajax_bgcouriers_validate_creds', [$this, 'ajax_validate']);
        add_action('wp_ajax_bgcouriers_sync_now', [$this, 'ajax_sync']);
        add_action('wp_ajax_bgcouriers_reset_creds', [$this, 'ajax_reset_creds']);
        add_action('wp_ajax_bgcouriers_save_settings', [$this, 'ajax_save']);
        add_action('wp_ajax_bgcouriers_enable_check', [$this, 'ajax_enable_check']);
        add_action('wp_ajax_bgcouriers_save_order', [$this, 'ajax_save_order']);
        add_action('wp_ajax_bgcouriers_poll_now', [$this, 'ajax_poll_now']);
        add_filter('plugin_action_links_' . plugin_basename(BGCOURIERS_FILE), [$this, 'action_links']);
    }
    public function register_page($pages) {
        $pages[] = new BGCouriers_WC_Settings();
        return $pages;
    }
    /**
     * Keep bgcouriers_dropdown_limit a positive number; an empty or invalid value resets to the default.
     * The default is the reader's constant, not a number of its own: this used to say 5 after the
     * default had moved to 20, so a merchant who cleared the field got a quarter of the list.
     */
    public function sanitize_dropdown_limit($value, $option, $raw_value) {
        return (int) $raw_value > 0 ? (string) (int) $raw_value : (string) BGCouriers_Settings::DROPDOWN_LIMIT;
    }
    /** Custom WC field: drag-sortable order - of the delivery methods (bgcouriers_<courier>_method_order) OR the couriers (bgcouriers_courier_order). */
    public function render_sortable($field): void {
        $id = $field['id'];
        wp_enqueue_script('jquery-ui-sortable');
        if ($id === 'bgcouriers_courier_order') {
            $labels = BGCouriers_Couriers::all(); // id => label
            $items  = BGCouriers_Settings::courier_order();
            $desc   = __('Drag to set the order couriers appear at checkout.', 'bg-couriers');
        } else {
            $labels = [
                'office'  => __('To office', 'bg-couriers'),
                'address' => __('To address', 'bg-couriers'),
                'automat' => __('To APS', 'bg-couriers'),
            ];
            $courier = preg_match('/^bgcouriers_([a-z0-9]+)_method_order$/', $id, $mm) ? $mm[1] : 'speedy';
            $items  = BGCouriers_Settings::method_order($courier);
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
     * Custom WC field: the town a courier collects from, for a shop whose contract has Pigeon come to
     * its premises rather than the other way round.
     *
     * The same city search the pickup-office picker already uses (AJAX bgcouriers_search_cities), minus
     * the office half - there is no office in this arrangement. The chosen id lands in the hidden input
     * named after the option, so WooCommerce saves it like any other field, and leaving the picker alone
     * keeps what is already saved.
     */
    public function render_pickup_city($field): void {
        $id      = (string) ($field['id'] ?? 'bgcouriers_pigeon_pickup_city_id');
        $courier = (string) ($field['courier'] ?? 'pigeon');
        $current = (int) get_option($id, 0);
        wp_enqueue_script('selectWoo');
        wp_enqueue_style('select2');
        $ph      = esc_js(__('Search your town...', 'bg-couriers'));
        $idjs    = esc_js($id);
        $cour_js = esc_js($courier);
        // The shop's OWN country: this picker names where a parcel is collected FROM, which is always
        // home, while the lookup would otherwise inherit whatever destination a browsing session held.
        $home_js = esc_js(BGCouriers_Settings::home_country());

        $cur_txt = '';
        if ($current > 0) {
            $city = BGCouriers_Nomenclature::city_by_id($courier, $current);
            $cur_txt = $city
                ? esc_js(trim((string) ($city['name'] ?? '') . (!empty($city['region']) ? ' (' . $city['region'] . ')' : '')))
                /* translators: %d: town id */
                : esc_js(sprintf(__('Current town (#%d)', 'bg-couriers'), $current));
        }

        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html($field['title'] ?? '') . '</th><td class="forminp">';
        echo '<select id="bgcouriers_pickup_city_only" style="min-width:300px;"></select>';
        echo '<input type="hidden" id="' . esc_attr($id) . '" name="' . esc_attr($id) . '" value="' . esc_attr((string) $current) . '">';
        echo '<p class="description">' . esc_html($field['desc'] ?? '') . '</p>';
        self::inline_js("
jQuery(function($){
  var \$c=$('#bgcouriers_pickup_city_only'), \$h=$('#{$idjs}');
  var cur=\$h.val();
  if(cur && cur!=='0'){ \$c.append(new Option('{$cur_txt}', cur, true, true)); }
  \$c.select2({ width:'300px', placeholder:'{$ph}', minimumInputLength:1, ajax:{
    url: ajaxurl, dataType:'json', delay:250,
    data: function(p){ return { action:'bgcouriers_search_cities', courier:'{$cour_js}', country:'{$home_js}', term:p.term }; },
    processResults: function(d){ return { results: (d.data||d||[]).map(function(c){
      return { id: c.city_id||c.id, text: (c.name||'') + (c.region ? ' (' + c.region + ')' : '') }; }) }; }
  }});
  \$c.on('select2:select', function(e){ \$h.val(e.params.data.id).trigger('change'); });
});");
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
        // The shop's OWN country: this picker names where a parcel is collected FROM, which is always
        // home, while the lookup would otherwise inherit whatever destination a browsing session held.
        $home_js = esc_js(BGCouriers_Settings::home_country());

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
    data:function(p){ return {action:'bgcouriers_search_cities', courier:'{$cour_js}', country:'{$home_js}', term:p.term||''}; },
    processResults:function(d){ return {results:(d||[]).map(function(c){ return {id:c.city_id, text:(c.name||'')+(c.region?(' ('+c.region+')'):'')}; })}; }
  }});
  \$o.select2({ width:'300px', placeholder:'{$ph_off}' });
  // The office select2 searches its OWN options (no ajax), so the list has to be in the DOM before the
  // merchant types. Load it for the saved city on page load too - not only when a city is re-picked -
  // otherwise the dropdown holds just the one saved office and every search says \"No results found\".
  function loadOffices(cid, keep){
    if(!cid || cid==='0'){ return; }
    \$o.prop('disabled',true);
    $.getJSON(ajaxurl, {action:'bgcouriers_offices', courier:'{$cour_js}', country:'{$home_js}', city_id:cid, type:'office', all:1}, function(rows){
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
     * setup (PPP mode + this courier does not do PPP). Amber = usable for prepaid only; red = unusable (no
     * prepaid gateway) so it won't appear at checkout. Renders nothing when there's nothing to warn about.
     */
    public function render_ppp_notice($field): void {
        $notice = BGCouriers_Settings::courier_blocker((string) ($field['courier'] ?? ''));
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
        // translates the plugin header too (the Bulgarian for "for WooCommerce"), which trips WP.org's
        // trademark check (the name
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
        // The repository rather than a mailbox: a bug reported there can be read, answered and fixed by
        // anyone using the plugin, and a fix can come back as a pull request.
        echo '<p style="margin:.6em 0 .2em;color:#646970;">' . esc_html__('Bugs, ideas, pull requests:', 'bg-couriers')
            . ' <a href="' . esc_url('https://github.com/dangoriaynov/bg-couriers/issues') . '" target="_blank" rel="noopener">github.com/dangoriaynov/bg-couriers</a></p>';
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
     * Full-width PPP-notice banner rendered OUTSIDE the form-table (so it spans the whole settings column,
     * like the enable toggle). Echoes nothing when there is no notice to show.
     */
    public static function ppp_notice_block(string $courier): void {
        $notice = BGCouriers_Settings::courier_blocker($courier);
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
    /**
     * What is still between this courier and a customer seeing it - rendered on its own tab.
     *
     * Only for a courier that is switched ON: for one that is off, "it is off" is the whole answer and
     * a list of everything else would be noise. This is the other half of letting a courier be enabled
     * before it is configured. Without it the merchant trades "I cannot switch it on" for "I switched it
     * on, where is it?", which is the worse of the two.
     */
    public static function readiness_block(string $courier): void {
        if (get_option('bgcouriers_' . $courier . '_enabled', 'no') !== 'yes') { return; }
        $co = BGCouriers_Couriers::get($courier);
        if (!$co || !method_exists($co, 'enable_problems')) { return; }
        try { $problems = $co->enable_problems(); } catch (\Throwable $e) { return; }
        if (empty($problems)) { return; }
        echo '<div class="bgc-readiness" style="border:1px solid #e6cf7a;background:#fef8e7;color:#7a5b00;'
            . 'border-radius:8px;padding:10px 14px;margin:0 0 14px;line-height:1.5;">';
        echo '<strong>' . esc_html__('Switched on, but the checkout will not offer it until:', 'bg-couriers') . '</strong>';
        echo '<ul style="margin:.5em 0 0 1.3em;list-style:disc;">';
        foreach ($problems as $p) {
            echo '<li style="margin:.25em 0;">' . esc_html((string) ($p['msg'] ?? ''));
            if (!empty($p['fix'])) {
                echo '<br><span style="opacity:.85;">' . esc_html__('How to fix:', 'bg-couriers') . ' '
                    . esc_html((string) $p['fix']) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul></div>';
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
    /** The courier's own account of how its credentials are obtained - BGCouriers_Abstract_Courier::credential_hint(). */
    public static function cred_hint_data(string $courier): array {
        $co = BGCouriers_Couriers::get($courier);
        return ($co && method_exists($co, 'credential_hint')) ? (array) $co->credential_hint() : [];
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
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => __('You are not allowed to do that.', 'bg-couriers')]); }
        check_ajax_referer('bgcouriers_admin', 'nonce');
        $courier = sanitize_key(wp_unslash($_POST['courier'] ?? 'speedy'));
        // Credentials, not the enable toggle: checking them is exactly what a merchant does BEFORE
        // switching a courier on, and this used to refuse to run until it was already on.
        if (!BGCouriers_Settings::creds_present($courier)) { wp_send_json_error(['msg' => __('No credentials saved', 'bg-couriers')]); }
        $c = BGCouriers_Couriers::get($courier);
        [$ok, $why] = self::credentials_answer($c);
        update_option('bgcouriers_' . $courier . '_validated', $ok ? 'yes' : 'no'); // drives the green/red credentials tint
        wp_send_json_success(['ok' => $ok, 'msg' => $why]);
    }
    /**
     * The courier's answer to its credentials: [accepted, why not]. A courier that refuses with a reason
     * - a wrong password, or one that cannot be reached at all - throws it, and "Invalid credentials"
     * was all the screen said for either; the reason is what the merchant reads now.
     *
     * @return array{0:bool,1:string}
     */
    private static function credentials_answer(?BGCouriers_Courier_Interface $c): array {
        if (!$c) { return [false, '']; }
        try { return [(bool) $c->check_credentials(), '']; }
        catch (BGCouriers_Api_Exception $e) { return [false, esc_html($e->getMessage())]; }
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
        /*
         * Saved credentials that have never been checked: check them HERE.
         *
         * Saving a username sets _validated = no, and a courier may not be enabled until it is yes - so
         * without this the merchant is refused, sent to a button elsewhere on the page, and made to come
         * back and flip the toggle again. The toggle has already saved the form by the time this runs, so
         * what gets checked is exactly what is on screen. Entering the credentials and switching the
         * courier on is now the whole job.
         */
        $checked = null; $why = '';
        if (BGCouriers_Settings::creds_present($courier) && get_option('bgcouriers_' . $courier . '_validated', 'yes') !== 'yes') {
            [$checked, $why] = self::credentials_answer($c);
            update_option('bgcouriers_' . $courier . '_validated', $checked ? 'yes' : 'no');
        }
        $problems = $c->enable_problems();
        if ($checked === false) {
            // We have just asked the courier and it said no. Say that, instead of "not validated yet" -
            // which would send the merchant to a button that fails in exactly the same way.
            foreach ($problems as $k => $pr) {
                if (($pr['code'] ?? '') !== 'creds_unvalidated') { continue; }
                $problems[$k]['msg'] = $why !== ''
                    /* translators: %s: what the courier answered */
                    ? sprintf(__('The courier refused these API credentials: %s', 'bg-couriers'), $why)
                    : __('The courier refused these API credentials.', 'bg-couriers');
                $problems[$k]['fix'] = __('Check the username/key and password/secret with the courier, press ✕ beside each field to enter them again, then save.', 'bg-couriers');
            }
        }
        // Informs; it does not refuse. Switching a courier on is a decision the merchant is allowed to
        // make before anything else is in place - the credentials come from the courier, and two of them
        // (Express One's collection address, Evropat's sender file) can only be CHOSEN from a list the
        // API returns, so demanding them first made the first step impossible. What is still missing is
        // shown instead, here and on the tab itself, and the checkout is what withholds the courier until
        // it is all there - see courier_offerable().
        wp_send_json_success(['ok' => empty($problems), 'problems' => array_values($problems)]);
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
    /**
     * Run the tracking poll right now, from the button beside the schedule.
     *
     * The schedule answers "how often, unattended"; this answers "I am looking at it now". Same code
     * path, so a manual run cannot produce a different outcome from the automatic one.
     */
    public function ajax_poll_now(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => __('You are not allowed to do this.', 'bg-couriers')]); }
        check_ajax_referer('bgcouriers_admin', 'nonce');
        $order_id = absint(wp_unslash($_POST['order_id'] ?? 0));
        if ($order_id > 0) {
            $ok = BGCouriers_Tracking_Poller::refresh_one($order_id);
            if (!$ok) { wp_send_json_error(['msg' => __('This order has no waybill to track.', 'bg-couriers')]); }
            $order = wc_get_order($order_id);
            wp_send_json_success([
                'msg'   => __('Tracking updated.', 'bg-couriers'),
                'stage' => (string) $order->get_meta('_bgcouriers_track_stage'),
                'text'  => (string) $order->get_meta('_bgcouriers_track_text'),
                'label' => BGCouriers_Tracking::stage_label((string) $order->get_meta('_bgcouriers_track_stage')),
            ]);
        }
        BGCouriers_Tracking_Poller::run();
        wp_send_json_success(['msg' => __('Tracking updated.', 'bg-couriers')]);
    }
    /** The red × by the password: marks the credentials as needing re-validation (so the tint goes red). */
    public function ajax_reset_creds(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => __('You are not allowed to do that.', 'bg-couriers')]); }
        check_ajax_referer('bgcouriers_admin', 'nonce');
        $courier = sanitize_key(wp_unslash($_POST['courier'] ?? 'speedy'));
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
            'present'   => $courier !== '' ? BGCouriers_Settings::creds_present($courier) : false,
            'validated' => $courier !== '' && get_option('bgcouriers_' . $courier . '_validated', 'yes') === 'yes',
        ]);
    }
    public function ajax_sync(): void {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['msg' => __('You are not allowed to do that.', 'bg-couriers')]); }
        check_ajax_referer('bgcouriers_admin', 'nonce');
        $courier = sanitize_key(wp_unslash($_POST['courier'] ?? 'speedy'));
        $c = BGCouriers_Couriers::get($courier);
        // Likewise: pulling a courier's towns and offices is a setup step, and a merchant may well want
        // them in place before the courier goes live on the shop.
        if (!$c || !BGCouriers_Settings::creds_present($courier)) { wp_send_json_error(['msg' => __('No credentials saved', 'bg-couriers')]); }
        @set_time_limit(180); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- needed for long nomenclature sync
        $r = BGCouriers_Sync::run($c);
        // A run that fetched nothing is a failure, and is shown as one - with the courier's own words.
        // The screen writes these as HTML, as it does every message here; a PHP error's text is not
        // escaped at birth the way the adapters' own are (esc_html is a no-op on those).
        if (isset($r['error'])) { wp_send_json_error(['msg' => esc_html((string) $r['error'])]); }
        if (isset($r['warning'])) { $r['warning'] = esc_html((string) $r['warning']); }
        wp_send_json_success($r);
    }
    /** Custom WC settings field: Validate / Sync buttons + the green/red credentials state (locked password + red ×). */
    public function render_actions($field): void {
        $courier = (!empty($field['id']) && preg_match('/^bgcouriers_([a-z0-9]+)_actions$/', (string) $field['id'], $m)) ? $m[1] : 'speedy';
        $present   = BGCouriers_Settings::creds_present($courier);
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
            . '            if(r&&r.success){ var d=r.data||{}; good((d.cities||0)+\' ' . $t['cities'] . ', \'+(d.offices||0)+\' ' . $t['offices'] . ', \'+(d.rates||0)+\' ' . $t['rates'] . '\'+(d.warning?\' (\'+d.warning+\')\':\'\')); }' . "\n"
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
