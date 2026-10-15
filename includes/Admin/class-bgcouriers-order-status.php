<?php
defined('ABSPATH') || exit;

/** The custom "Shipped" order status; the setting only gates the automatic transition. */
class BGCouriers_Order_Status {
    /** Must fit 20 chars: HPOS stores it in a varchar(20) and MySQL truncates silently. */
    const STATUS = 'wc-bgc-shipped';
    const SLUG = 'bgc-shipped';
    const LEGACY_STATUS = 'wc-bgcouriers-shippe';

    public function __construct() {
        add_action('init', [$this, 'register']);
        add_filter('wc_order_statuses', [$this, 'add_to_list']);
        add_filter('woocommerce_reports_order_statuses', [$this, 'add_to_reports']);
        foreach (['woocommerce_page_wc-orders', 'edit-shop_order'] as $screen) {
            add_filter("bulk_actions-{$screen}", [$this, 'add_bulk_action'], 20);
        }
    }

    public function register(): void {
        register_post_status(self::STATUS, [
            'label'                     => _x('Shipped', 'Order status', 'bg-couriers'),
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count'               => _n_noop('Shipped <span class="count">(%s)</span>',
                                                   'Shipped <span class="count">(%s)</span>', 'bg-couriers'),
        ]);
    }

    public function add_to_list(array $statuses): array {
        $out = [];
        foreach ($statuses as $key => $label) {
            $out[$key] = $label;
            if ($key === 'wc-processing') { $out[self::STATUS] = _x('Shipped', 'Order status', 'bg-couriers'); }
        }
        if (!isset($out[self::STATUS])) { $out[self::STATUS] = _x('Shipped', 'Order status', 'bg-couriers'); }
        return $out;
    }

    /**
     * Legacy reports pass `false` for refund sub-queries; it must come back untouched.
     * @param array|false $statuses
     * @return array|false
     */
    public function add_to_reports($statuses) {
        if (!is_array($statuses)) { return $statuses; }
        $statuses[] = self::SLUG;
        return $statuses;
    }

    /** `mark_<slug>` is the name WC's own bulk handler understands. */
    public function add_bulk_action(array $actions): array {
        $actions['mark_' . self::SLUG] = _x('Change status to shipped', 'Order bulk action', 'bg-couriers');
        return $actions;
    }
}
