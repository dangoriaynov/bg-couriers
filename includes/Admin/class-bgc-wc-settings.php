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
            'automat' => __('To automat (APS)', 'bg-couriers'),
        ];
        parent::__construct();
    }

    protected function get_own_sections() { return $this->sections(); }
    public function get_sections() { return apply_filters('woocommerce_get_sections_' . $this->id, $this->sections()); }

    private function sections(): array {
        return ['' => __('General', 'bg-couriers'), 'speedy' => __('Speedy', 'bg-couriers')];
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
        return $this->general_fields();
    }

    protected function get_settings_for_section_core($section_id) { return $this->get_settings($section_id); }

    /** Custom output: WP nav-tab section nav + (for Speedy) per-method sub-tabs. */
    public function output() {
        global $current_section;
        $this->section_nav((string) $current_section);

        if ($current_section === 'speedy') {
            $this->output_speedy();
        } else {
            WC_Admin_Settings::output_fields($this->general_fields());
        }
    }

    private function section_nav(string $current): void {
        echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper" style="margin:0 0 1em;">';
        foreach ($this->sections() as $id => $label) {
            $url = admin_url('admin.php?page=wc-settings&tab=bg_couriers' . ($id ? '&section=' . $id : ''));
            $active = $current === $id ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . $active . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private function output_speedy(): void {
        WC_Admin_Settings::output_fields($this->speedy_courier_fields());

        // Level-2 nav-tabs for delivery methods (JS-switched panels; all inputs stay in the form so all save).
        echo '<h2 class="nav-tab-wrapper bgc-method-nav" style="margin-top:1.5em;">';
        $first = true;
        foreach (self::$method_labels as $m => $label) {
            echo '<a href="#" class="nav-tab' . ($first ? ' nav-tab-active' : '') . '" data-bgc-tab="' . esc_attr($m) . '">' . esc_html($label) . '</a>';
            $first = false;
        }
        echo '</h2>';

        $first = true;
        foreach (self::$method_labels as $m => $label) {
            echo '<div class="bgc-method-panel" data-bgc-panel="' . esc_attr($m) . '"' . ($first ? '' : ' style="display:none;"') . '>';
            WC_Admin_Settings::output_fields($this->method_fields('speedy', $m, $label));
            echo '</div>';
            $first = false;
        }
        ?>
<style>
.bgc-method-nav{padding-bottom:0;}
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
})(jQuery);
</script>
        <?php
    }

    private function general_fields(): array {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : ['wc-processing' => 'Processing'];
        return [
            ['type' => 'title', 'id' => 'bgc_general', 'title' => __('General', 'bg-couriers'),
                'desc' => __('Settings that apply to all couriers. Prices are always shown in the store currency.', 'bg-couriers')],
            ['type' => 'text', 'id' => 'bgc_free_shipping_label', 'title' => __('Free shipping label', 'bg-couriers'),
                'desc' => __('Text shown for the shipping price when a method is free (e.g. “Free shipping”).', 'bg-couriers'), 'default' => ''],
            ['type' => 'textarea', 'id' => 'bgc_hidden_fields', 'title' => __('Hidden checkout fields (CSS selectors)', 'bg-couriers'),
                'desc' => __('Comma-separated CSS selectors to hide on checkout (e.g. #billing_company_field, .cart-subtotal).', 'bg-couriers'),
                'css' => 'min-width:400px;height:90px;', 'default' => ''],
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
            ['type' => 'title', 'id' => 'bgc_speedy', 'title' => __('Speedy — courier settings', 'bg-couriers')],
            ['type' => 'checkbox', 'id' => 'bgc_speedy_enabled', 'title' => __('Enable Speedy', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'select', 'id' => 'bgc_speedy_environment', 'title' => __('Environment', 'bg-couriers'),
                'options' => ['demo' => 'demo', 'live' => 'live'], 'default' => 'demo'],
            ['type' => 'text', 'id' => 'bgc_speedy_username', 'title' => __('API username', 'bg-couriers'), 'autoload' => false],
            ['type' => 'password', 'id' => 'bgc_speedy_password', 'title' => __('API password', 'bg-couriers'),
                'value' => '', 'custom_attributes' => ['placeholder' => __('leave blank to keep', 'bg-couriers')], 'autoload' => false],
            ['type' => 'number', 'id' => 'bgc_speedy_client_id', 'title' => __('Sender client id', 'bg-couriers')],
            ['type' => 'select', 'id' => 'bgc_speedy_label_paper_size', 'title' => __('Label paper size', 'bg-couriers'),
                'options' => ['A6' => __('A6 (label printer)', 'bg-couriers'), 'A4' => __('A4 (office printer)', 'bg-couriers')],
                'default' => 'A6'],
            ['type' => 'checkbox', 'id' => 'bgc_speedy_dynamic_pricing',
                'title' => __('Use dynamic pricing', 'bg-couriers'),
                'desc' => __('Calculate shipping cost live via the Speedy API. When off, the per-method default prices below are used.', 'bg-couriers'),
                'default' => 'yes'],
            ['type' => 'bgc_sortable', 'id' => 'bgc_speedy_method_order', 'title' => __('Delivery option order', 'bg-couriers')],
            ['type' => 'bgc_actions', 'id' => 'bgc_speedy_actions'],
            ['type' => 'sectionend', 'id' => 'bgc_speedy'],
        ];
    }

    private function method_fields(string $courier, string $m, string $label): array {
        $p = "bgc_{$courier}_{$m}_";
        return [
            ['type' => 'title', 'id' => $p . 'grp', 'title' => ''],
            ['type' => 'checkbox', 'id' => $p . 'enabled', 'title' => sprintf(__('Enable “%s”', 'bg-couriers'), $label), 'default' => 'yes'],
            ['type' => 'text', 'id' => $p . 'price', 'title' => __('Default price (API fallback)', 'bg-couriers'),
                'desc' => __('In the store currency. Used when the courier API is unavailable or dynamic pricing is off.', 'bg-couriers'), 'default' => ''],
            ['type' => 'checkbox', 'id' => $p . 'free_enabled', 'title' => __('Free shipping over a threshold', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => $p . 'free_threshold', 'title' => __('Free-shipping order amount', 'bg-couriers'),
                'desc' => __('Order subtotal at/above which this method is free.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => $p . 'grp'],
        ];
    }
}
