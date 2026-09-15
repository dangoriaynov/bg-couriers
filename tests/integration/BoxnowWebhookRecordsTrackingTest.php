<?php
/**
 * A BOX NOW parcel update lands in the same place every other courier's does.
 *
 * BOX NOW does not get polled: it pushes a webhook. The webhook verified the signature, found the
 * order, and then wrote the parcel state to a meta key of its own that nothing ever read. The orders
 * list, the order screen and the delivered-so-advance-the-order rule all read the shipment's STAGE -
 * _bgcouriers_track_stage, _bgcouriers_track_text, _bgcouriers_track_done - which the tracking poll
 * writes for the other six couriers and the webhook never wrote for this one. So a BOX NOW order showed
 * a blank where its tracking belonged, never counted as finished, and was never advanced on delivery.
 *
 * Never seen in production only because a BOX NOW order could not be placed at all until 0.4.6.
 *
 * And the webhook wrote on every message, including a repeat: senders retry and duplicate, and each
 * duplicate was another order note and another save.
 *
 * @group core
 */
final class BoxnowWebhookRecordsTrackingTest extends WP_UnitTestCase {
    private const SECRET = 'whsec-test';

    public function set_up() {
        parent::set_up();
        update_option('bgcouriers_boxnow_webhook_secret', self::SECRET);
        update_option('bgcouriers_autostatus_on_delivered', '');
    }

    private function order(string $parcel): WC_Order {
        $o = new WC_Order();
        $o->set_status('processing');
        $o->update_meta_data('_bgcouriers_courier', 'boxnow');
        $o->update_meta_data('_bgcouriers_waybill', $parcel);
        $o->save();
        return $o;
    }

    /** A signed message, exactly as BOX NOW sends one. */
    private function deliver(array $data): WP_REST_Response {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $raw  = '{"specversion":"1.0","type":"gr.boxnow.parcel_event_change","subject":"p","data":' . $json
              . ',"datasignature":"' . hash_hmac('sha256', $json, self::SECRET) . '"}';
        $req  = new WP_REST_Request('POST', '/bgcouriers/v1/boxnow');
        $req->set_body($raw);
        return (new BGCouriers_Boxnow_Webhook())->handle($req);
    }

    private function notes(int $id): array {
        return array_map(static fn($n) => $n->content, wc_get_order_notes(['order_id' => $id]));
    }

    /** The parcel is in the locker: the order shows it, exactly as a Speedy parcel at an office would. */
    public function test_a_parcel_state_becomes_the_shipment_stage_every_screen_reads(): void {
        $o = $this->order('P-1');
        $r = $this->deliver(['parcelId' => 'P-1', 'parcelState' => 'in-final-destination', 'orderNumber' => (string) $o->get_id()]);
        $this->assertSame(200, $r->get_status());

        $fresh = wc_get_order($o->get_id());
        $this->assertSame('ready', (string) $fresh->get_meta('_bgcouriers_track_stage'), 'in the locker is the "ready" stage');
        $this->assertNotSame('', (string) $fresh->get_meta('_bgcouriers_track_text'), 'with a sentence for the list to show');
        $this->assertSame('', (string) $fresh->get_meta('_bgcouriers_track_done'), 'and not finished yet');
    }

    /** Delivered is terminal, and it advances the order when the merchant asked for that. */
    public function test_delivered_finishes_the_shipment_and_advances_the_order(): void {
        update_option('bgcouriers_autostatus_on_delivered', 'wc-completed');
        $o = $this->order('P-2');
        $this->deliver(['parcelId' => 'P-2', 'parcelState' => 'delivered', 'orderNumber' => (string) $o->get_id()]);

        $fresh = wc_get_order($o->get_id());
        $this->assertSame('delivered', (string) $fresh->get_meta('_bgcouriers_track_stage'));
        $this->assertSame('yes', (string) $fresh->get_meta('_bgcouriers_track_done'));
        $this->assertSame('completed', $fresh->get_status(), 'the same rule the polled couriers follow');
    }

    /** The same message twice is one note, not two - senders retry, and a retry is not news. */
    public function test_a_repeated_message_writes_nothing_new(): void {
        $o = $this->order('P-3');
        $msg = ['parcelId' => 'P-3', 'parcelState' => 'in-transit', 'orderNumber' => (string) $o->get_id()];
        $this->deliver($msg);
        $before = count($this->notes($o->get_id()));
        $this->deliver($msg);
        $this->deliver($msg);

        $this->assertSame($before, count($this->notes($o->get_id())), 'two repeats, no new notes');
        $this->assertSame(1, count(array_filter($this->notes($o->get_id()), static fn($n) => stripos($n, 'BOX NOW') !== false)),
            'exactly one BOX NOW note for one piece of news');
    }

    /**
     * The proof BOX NOW actually offers (Webhook Guide v5): the secret in a request header, no
     * datasignature anywhere. The first live shop to register the webhook got 401 on every message
     * because the signature was the only proof accepted.
     */
    public function test_the_secret_in_the_header_is_proof_enough(): void {
        $o   = $this->order('P-4');
        $req = new WP_REST_Request('POST', '/bgc/v1/boxnow-webhook');
        $req->set_body('{"specversion":"1.0","type":"gr.boxnow.parcel_event_change","data":{"parcelId":"P-4","parcelState":"delivered","orderNumber":"' . $o->get_id() . '"}}');
        $req->set_header(BGCouriers_Boxnow_Webhook::HEADER, self::SECRET);
        $r = (new BGCouriers_Boxnow_Webhook())->handle($req);

        $this->assertSame(200, $r->get_status());
        $this->assertSame('delivered', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_track_stage'));
    }

    /** A refusal says what was missing - BOX NOW support pastes the body into their e-mail verbatim. */
    public function test_a_refusal_names_its_reason(): void {
        $req = new WP_REST_Request('POST', '/bgc/v1/boxnow-webhook');
        $req->set_body('{"specversion":"1.0","data":{"parcelId":"P-5","parcelState":"new"}}');
        $r = (new BGCouriers_Boxnow_Webhook())->handle($req);

        $this->assertSame(401, $r->get_status());
        $this->assertSame(['ok' => false, 'reason' => 'no_credential'], $r->get_data());
    }

    /** Every state BOX NOW documents maps to a stage the plugin knows. */
    public function test_every_documented_state_has_a_stage(): void {
        $expect = ['new' => 'registered', 'in-transit' => 'transit', 'in-final-destination' => 'ready',
                   'delivered' => 'delivered', 'returned' => 'returned', 'expired-return' => 'returned', 'canceled' => 'cancelled'];
        foreach ($expect as $state => $stage) {
            $t = BGCouriers_Boxnow::parse_tracking(['state' => $state], 'P');
            $this->assertSame($stage, $t->stage(), "BOX NOW '{$state}'");
        }
    }
}
