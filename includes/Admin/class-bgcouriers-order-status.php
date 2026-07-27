<?php
defined('ABSPATH') || exit;

/**
 * The "Shipped" order status.
 *
 * WooCommerce ships processing / on-hold / completed / cancelled / refunded / failed - there is no state
 * for "the courier has the parcel and it is on its way", which is exactly the window a merchant wants to
 * see. Registering it here makes it a first-class status: selectable on the order screen, listed in the
 * status filter row above the orders table, and settable in bulk.
 *
 * The status is always registered (harmless, and an order already in it must keep rendering); what the
 * separate setting gates is the AUTOMATIC transition - see BGCouriers_Tracking_Poller.
 */
class BGCouriers_Order_Status {
    /** Full status key, as WooCommerce stores it. */
    const STATUS = 'wc-bgc-shipped';
    /** The same key without WooCommerce's `wc-` prefix, which is what WC_Order::get_status() returns. */
    const SLUG = 'bgc-shipped';

    public function __construct() {
        add_action('init', [$this, 'register']);
        add_filter('wc_order_statuses', [$this, 'add_to_list']);
        // Orders in this status are still "live" work, so keep the row actions and reports treating them
        // like processing rather than like a finished order.
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

    /** Insert right after "processing" - that is where it belongs in the order's life. */
    public function add_to_list(array $statuses): array {
        $out = [];
        foreach ($statuses as $key => $label) {
            $out[$key] = $label;
            if ($key === 'wc-processing') { $out[self::STATUS] = _x('Shipped', 'Order status', 'bg-couriers'); }
        }
        // Defensive: if this WooCommerce ever drops wc-processing, still offer the status.
        if (!isset($out[self::STATUS])) { $out[self::STATUS] = _x('Shipped', 'Order status', 'bg-couriers'); }
        return $out;
    }

    public function add_to_reports(array $statuses): array {
        $statuses[] = self::SLUG;
        return $statuses;
    }

    /**
     * WooCommerce hardcodes its own four "Change status to ..." bulk actions, so a custom status has none.
     * The `mark_<slug>` name is the convention WC's own handler understands, so adding the entry is enough.
     */
    public function add_bulk_action(array $actions): array {
        $actions['mark_' . self::SLUG] = _x('Change status to shipped', 'Order bulk action', 'bg-couriers');
        return $actions;
    }
}
