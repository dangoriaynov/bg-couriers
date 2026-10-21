<?php
/**
 * The WooCommerce mobile app edits orders through the REST API. An edit there that changes what the
 * courier needs (contents, COD amount) re-issues the waybill exactly as the same edit in wp-admin does.
 *
 * @group core
 */
final class RestEditReissuesWaybillTest extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
        BGCouriers_Rest_Probe::$created = [];
        BGCouriers_Rest_Probe::$cancelled = [];
        BGCouriers_Couriers::register('restprobe', 'Probe', static function () { return new BGCouriers_Rest_Probe(); });
        update_option('bgcouriers_restprobe_enabled', 'yes');
        update_option('bgcouriers_autoregen_on_change', 'yes');
        add_filter('woocommerce_rest_check_permissions', '__return_true');
        rest_get_server();
    }
    public function tear_down() {
        remove_filter('woocommerce_rest_check_permissions', '__return_true');
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['restprobe']); $r->setValue(null, $v);
        }
        parent::tear_down();
    }

    private function product(string $price): WC_Product_Simple {
        $p = new WC_Product_Simple();
        $p->set_name('P' . $price);
        $p->set_regular_price($price);
        $p->save();
        return $p;
    }

    /** An order with one item and an issued waybill, as it is when the app opens it. */
    private function issued_order(): WC_Order {
        $o = new WC_Order();
        $o->add_product($this->product('5'), 1);
        $o->set_payment_method('cod');
        $o->update_meta_data('_bgcouriers_courier', 'restprobe');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->calculate_totals();
        $o->set_status('processing');
        $o->save();
        BGCouriers_Labels::generate($o->get_id());
        $this->assertSame('WB-1', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_waybill'));
        return $o;
    }

    private function rest_update(int $id, array $body): WP_REST_Response {
        $req = new WP_REST_Request('PUT', '/wc/v3/orders/' . $id);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));
        return $res;
    }

    private function notes(int $id): string {
        return implode("\n", wp_list_pluck(wc_get_order_notes(['order_id' => $id]), 'content'));
    }

    public function test_an_item_added_through_rest_reissues_the_waybill(): void {
        $o = $this->issued_order();
        $this->rest_update($o->get_id(), ['line_items' => [['product_id' => $this->product('7')->get_id(), 'quantity' => 1]]]);

        $fresh = wc_get_order($o->get_id());
        $this->assertSame('12.00', wc_format_decimal($fresh->get_total(), 2), 'the edit reached the order');
        $this->assertSame(['WB-1'], BGCouriers_Rest_Probe::$cancelled, 'the old waybill is voided at the courier');
        $this->assertSame('WB-2', (string) $fresh->get_meta('_bgcouriers_waybill'), 'and a new one is on the order');
        $this->assertSame(BGCouriers_Labels::label_fingerprint($fresh), (string) $fresh->get_meta('_bgcouriers_label_fp'));
        $this->assertSame(BGCouriers_Labels::LABEL_STATE_UNPRINTED, BGCouriers_Labels::label_state($fresh));
        $this->assertStringContainsString('WB-1', $this->notes($o->get_id()));
    }

    public function test_a_status_change_through_rest_keeps_the_waybill(): void {
        $o = $this->issued_order();
        $this->rest_update($o->get_id(), ['status' => 'on-hold']);
        $this->rest_update($o->get_id(), ['status' => 'processing']);

        $this->assertSame([], BGCouriers_Rest_Probe::$cancelled);
        $this->assertSame(['WB-1'], BGCouriers_Rest_Probe::$created);
        $this->assertSame('WB-1', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_waybill'));
    }

    public function test_a_collected_parcel_is_not_reissued_but_the_change_is_noted(): void {
        $o = $this->issued_order();
        $held = wc_get_order($o->get_id());
        $held->update_meta_data('_bgcouriers_handover', 'yes');
        $held->save();

        $this->rest_update($o->get_id(), ['line_items' => [['product_id' => $this->product('7')->get_id(), 'quantity' => 1]]]);

        $fresh = wc_get_order($o->get_id());
        $this->assertSame([], BGCouriers_Rest_Probe::$cancelled);
        $this->assertSame('WB-1', (string) $fresh->get_meta('_bgcouriers_waybill'));
        $this->assertSame(BGCouriers_Labels::LABEL_STATE_STALE, BGCouriers_Labels::label_state($fresh));
        $this->assertStringContainsString('NOT re-issued', $this->notes($o->get_id()));
    }

    public function test_an_order_created_through_rest_is_left_alone(): void {
        $o = $this->issued_order();
        $stale = wc_get_order($o->get_id());
        $stale->update_meta_data('_bgcouriers_label_fp', 'something-else');
        $stale->save();

        do_action('woocommerce_rest_insert_shop_order_object', $stale, new WP_REST_Request('POST', '/wc/v3/orders'), true);

        $this->assertSame([], BGCouriers_Rest_Probe::$cancelled);
        $this->assertSame('WB-1', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_waybill'));
    }
}

final class BGCouriers_Rest_Probe extends BGCouriers_Abstract_Courier {
    /** @var string[] */
    public static array $created = [];
    /** @var string[] */
    public static array $cancelled = [];
    public function id(): string { return 'restprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $c, string $country = ''): array { return []; }
    public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(1.0, 0.0, 'EUR', 'fixed'); }
    public function create_label(\WC_Order $o): BGCouriers_Label {
        $wb = 'WB-' . (count(self::$created) + 1);
        self::$created[] = $wb;
        return new BGCouriers_Label($wb, '');
    }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string { return '%PDF-1.4 fake'; }
    public function cancel_label(string $w): bool { self::$cancelled[] = $w; return true; }
    public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
    public function tracking_url(string $w): string { return ''; }
}
