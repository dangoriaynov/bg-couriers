<?php
defined('ABSPATH') || exit;

if (!class_exists('WC_Settings_Page')) { return; }

/**
 * WooCommerce → Settings → "BG Couriers" tab.
 * Section per courier (General + Speedy); within a courier: courier-level params
 * + a config block per delivery method (office / address / automat).
 * See feedback-settings-architecture — every future courier follows this shape.
 */
class BGC_WC_Settings extends WC_Settings_Page {

    public function __construct() {
        $this->id    = 'bg_couriers';
        $this->label = __('BG Couriers', 'bg-couriers');
        parent::__construct();
    }

    /** WC 5.5+ */
    protected function get_own_sections() {
        return $this->sections();
    }

    /** Back-compat */
    public function get_sections() {
        return apply_filters('woocommerce_get_sections_' . $this->id, $this->sections());
    }

    private function sections(): array {
        return [
            ''       => __('General', 'bg-couriers'),
            'speedy' => __('Speedy', 'bg-couriers'),
        ];
    }

    /** Used by save() (save_settings_for_current_section). */
    public function get_settings($section = '') {
        return $this->fields_for($section);
    }

    /** Used by output() in WC 5.5+. */
    protected function get_settings_for_section_core($section_id) {
        return $this->fields_for($section_id);
    }

    private function fields_for(string $section): array {
        return $section === 'speedy' ? $this->speedy_fields() : $this->general_fields();
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

    private function speedy_fields(): array {
        $fields = [
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
        $labels = [
            'office'  => __('To office', 'bg-couriers'),
            'address' => __('To address', 'bg-couriers'),
            'automat' => __('To automat (APS)', 'bg-couriers'),
        ];
        foreach ($labels as $m => $label) {
            $fields = array_merge($fields, $this->method_fields('speedy', $m, $label));
        }
        return $fields;
    }

    private function method_fields(string $courier, string $m, string $label): array {
        $p = "bgc_{$courier}_{$m}_";
        return [
            ['type' => 'title', 'id' => $p . 'grp', 'title' => sprintf(__('Speedy — %s', 'bg-couriers'), $label)],
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
