<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-tracking-poller.php';

/** A courier that answers track() with one prepared reply, or refuses to answer at all. */
if (!class_exists('PollFakeCourier')) {
    final class PollFakeCourier implements BGCouriers_Courier_Interface {
        public function __construct(private ?BGCouriers_Tracking $t) {}
        public function track(string $waybill): BGCouriers_Tracking {
            if ($this->t === null) { throw new BGCouriers_Api_Exception('the courier is down'); }
            return $this->t;
        }
        public function id(): string { return 'fake'; }
        public function label(): string { return 'Fake'; }
        public function capabilities(): array { return ['office']; }
        public function check_credentials(): bool { return true; }
        public function fetch_cities(): array { return []; }
        public function fetch_offices(int $city_id): array { return []; }
        public function quote(array $shipment): BGCouriers_Quote { throw new BGCouriers_Api_Exception('not used'); }
        public function create_label(\WC_Order $order): BGCouriers_Label { throw new BGCouriers_Api_Exception('not used'); }
        public function label_formats(): array { return []; }
        public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
        public function cancel_label(string $waybill): bool { return false; }
        public function tracking_url(string $waybill): string { return ''; }
    }
}

/**
 * One poll of one order is ONE write.
 *
 * The poller used to save the order as it went: once for the handover flag, once for a return waybill,
 * once to mark the shipment finished, once for the display text - and then again through
 * update_status(). A single order could be written five times for one answer from the courier.
 *
 * That is not only four wasted database writes per order, forty orders a run, several times a day. Every
 * save fires woocommerce_update_order, and on this shop other plugins are listening to it - the document
 * generator among them. A poll that writes once is the difference between one hook firing and five.
 *
 * The meta written is unchanged; only the number of times it is flushed.
 *
 * @group core
 */
final class PollWritesOnceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('get_option')->justReturn('');   // every auto-status feature off
        BGCouriers_Couriers::reset();
    }
    protected function tearDown(): void { BGCouriers_Couriers::reset(); Monkey\tearDown(); parent::tearDown(); }

    /** An order already carrying a waybill, as the poller finds one, answered by this tracking. */
    private function poll(?BGCouriers_Tracking $t, array $meta = []): WC_Order {
        $o = new WC_Order();
        $o->meta = array_merge(['_bgcouriers_courier' => 'fake', '_bgcouriers_waybill' => 'W1'], $meta);
        Functions\when('wc_get_order')->justReturn($o);
        BGCouriers_Couriers::register('fake', 'Fake', static function () use ($t) { return new PollFakeCourier($t); });
        // refresh_one() clears the finished flag and saves before it polls - that write is the merchant
        // asking, not the poll, so it is not counted against the answer.
        BGCouriers_Tracking_Poller::refresh_one(1);
        return $o;
    }

    /**
     * The worst case the old code had: a first sighting that sets the handover flag, records a return
     * waybill, writes the display text AND marks the shipment finished. Four reasons to save, one order.
     */
    public function test_a_poll_that_changes_four_things_writes_the_order_once(): void {
        $o = new WC_Order();
        $o->meta = ['_bgcouriers_courier' => 'fake', '_bgcouriers_waybill' => 'W1'];
        Functions\when('wc_get_order')->justReturn($o);
        // handover true + a DIFFERENT waybill on the answer + a terminal stage + new display text.
        $t = new BGCouriers_Tracking('W2', 'Доставена', [['code' => '-14', 'name' => 'Доставена', 'date' => '']], '', true, true);
        BGCouriers_Couriers::register('fake', 'Fake', static function () use ($t) { return new PollFakeCourier($t); });
        $o->saves = 0;   // count only what the POLL writes, not refresh_one's own clearing write

        BGCouriers_Tracking_Poller::refresh_one(1);

        $this->assertSame('yes', $o->meta['_bgcouriers_handover'], 'the handover flag is still recorded');
        $this->assertSame('W2', $o->meta['_bgcouriers_return_waybill'], 'and the return waybill');
        $this->assertSame('yes', $o->meta['_bgcouriers_track_done'], 'and the shipment is marked finished');
        $this->assertSame('delivered', $o->meta['_bgcouriers_track_stage'], 'and the stage the admin shows');
        $this->assertNotEmpty($o->notes, 'and the merchant still gets their order note');
        $this->assertSame(2, $o->saves, 'refresh_one clears the finished flag (1), the whole answer is the other (1)');
    }

    /** The ordinary case: a status that moved along, nothing terminal. */
    public function test_a_poll_that_moves_the_status_along_writes_once(): void {
        $o = $this->poll(
            new BGCouriers_Tracking('W1', 'Приета от куриер',
                [['code' => '1', 'name' => 'a', 'date' => ''], ['code' => '2', 'name' => 'b', 'date' => '']],
                '', null, true),
            ['_bgcouriers_track_status' => 'old']
        );
        $this->assertSame('Приета от куриер', $o->meta['_bgcouriers_track_text']);
        $this->assertSame(2, $o->saves, 'refresh_one clears the finished flag, then one write for the answer');
    }

    /**
     * And the commonest case of all: the courier says exactly what it said last time. Nothing changed,
     * so the poll writes nothing - forty unchanged orders must not be forty writes.
     */
    public function test_a_poll_that_changes_nothing_does_not_write_at_all(): void {
        $o = $this->poll(
            new BGCouriers_Tracking('W1', 'Приета от куриер',
                [['code' => '1', 'name' => 'a', 'date' => ''], ['code' => '2', 'name' => 'b', 'date' => '']],
                '', true, true),
            [
                '_bgcouriers_track_status' => 'Приета от куриер',
                '_bgcouriers_track_text'   => 'Приета от куриер',
                '_bgcouriers_track_stage'  => 'transit',
                '_bgcouriers_handover'     => 'yes',
            ]
        );
        $this->assertSame(1, $o->saves, 'only refresh_one own write; the poll itself found nothing to say');
    }

    /** A courier that cannot be reached leaves the order exactly as it was. */
    public function test_a_courier_that_throws_writes_nothing(): void {
        $o = $this->poll(null, ['_bgcouriers_track_status' => 'old']);
        $this->assertSame(1, $o->saves, 'only refresh_one own write');
        $this->assertSame('old', $o->meta['_bgcouriers_track_status'], 'and nothing was changed in memory either');
    }
}
