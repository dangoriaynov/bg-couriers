<?php
/**
 * A waybill another request saved while this one held the order in its object cache is found, in both
 * order storages, instead of booking a second shipment. The other request's write is a plain insert into
 * the meta table: no WooCommerce cache hears about it, as none does across PHP processes.
 *
 * @group core
 */
final class IssuedElsewhereIsSeenTest extends WP_UnitTestCase {
    // The test installer resets WordPress's tables, not WooCommerce's: a row left in wc_orders_meta meets a reused order id.
    public function set_up() { parent::set_up(); $this->forget_rows(); }
    public function tear_down() { $this->forget_rows(); parent::tear_down(); }
    private function forget_rows(): void {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wc_orders_meta', ['meta_value' => 'WB-ELSEWHERE']);
        $wpdb->delete($wpdb->postmeta, ['meta_value' => 'WB-ELSEWHERE']);
    }

    /** @return array<string,array{string}> */
    public static function storages(): array {
        return ['HPOS' => ['yes'], 'posts' => ['no']];
    }

    /** @dataProvider storages */
    public function test_a_waybill_saved_by_another_request_is_found(string $hpos): void {
        // Through the option's filter: updating it is refused while any test order is out of sync.
        add_filter('pre_option_woocommerce_custom_orders_table_enabled', static function () use ($hpos) { return $hpos; });
        $uses_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $this->assertSame($hpos === 'yes', $uses_hpos, 'the storage under test is the one in use');

        $o = new WC_Order();
        // No courier is registered under this id: reaching issue() fails the test with "Unknown courier".
        $o->update_meta_data('_bgcouriers_courier', 'nobody');
        $o->save();
        $id = $o->get_id();
        $this->assertSame('', (string) wc_get_order($id)->get_meta('_bgcouriers_waybill'), 'cached without a waybill');

        global $wpdb;
        $uses_hpos
            ? $wpdb->insert($wpdb->prefix . 'wc_orders_meta', ['order_id' => $id, 'meta_key' => '_bgcouriers_waybill', 'meta_value' => 'WB-ELSEWHERE'])
            : $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_bgcouriers_waybill', 'meta_value' => 'WB-ELSEWHERE']);

        $this->assertSame('WB-ELSEWHERE', BGCouriers_Labels::generate($id)->waybill);
    }
}
