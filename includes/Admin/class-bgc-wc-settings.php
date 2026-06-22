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
        return [
            ['type' => 'title', 'id' => 'bgc_general', 'title' => __('General', 'bg-couriers')],
            [
                'type'    => 'checkbox',
                'id'      => 'bgc_dual_currency',
                'title'   => __('Dual currency display (BGN / EUR)', 'bg-couriers'),
                'desc'    => __('Show prices in both currencies during the euro transition. Display-only; does not change stored order totals.', 'bg-couriers'),
                'default' => 'yes',
            ],
            ['type' => 'sectionend', 'id' => 'bgc_general'],
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
                'desc' => __('Used when the courier API is unavailable.', 'bg-couriers'), 'default' => ''],
            ['type' => 'select', 'id' => $p . 'currency', 'title' => __('Default price currency', 'bg-couriers'),
                'options' => ['BGN' => 'BGN (лв.)', 'EUR' => 'EUR (€)'], 'default' => 'BGN'],
            ['type' => 'checkbox', 'id' => $p . 'free_enabled', 'title' => __('Free shipping over a threshold', 'bg-couriers'), 'default' => 'no'],
            ['type' => 'text', 'id' => $p . 'free_threshold', 'title' => __('Free-shipping order amount', 'bg-couriers'),
                'desc' => __('Order subtotal at/above which this method is free.', 'bg-couriers'), 'default' => ''],
            ['type' => 'sectionend', 'id' => $p . 'grp'],
        ];
    }
}
