<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-sameday.php';

/**
 * Cancelling something that is already gone is not a failure - the end state the merchant asked for is
 * the one they have. Econt has always read "Пратка не е открита" that way; Sameday did not, so cancelling
 * an AWB that had been cancelled earlier (or that lives on the demo stack) reported "the courier did not
 * cancel it" and left a dead number stuck on the order. Seen for real on 2026-08-18 while sweeping the
 * old test waybills: four refused, all four already dead.
 *
 * @group sameday
 */
final class CancelIsIdempotentTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('get_option')->alias(static function ($n, $d = false) { return $d; });
        Functions\when('is_wp_error')->justReturn(false);
        // auth_token() reads a cached token before it would ever reach the network.
        Functions\when('get_transient')->justReturn('test-token');
        Functions\when('set_transient')->justReturn(true);
        Functions\when('esc_html')->returnArg(1);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function sameday_answering(int $code): BGCouriers_Sameday {
        Functions\when('wp_remote_request')->justReturn(['response' => ['code' => $code]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        return new BGCouriers_Sameday([]);
    }

    public function test_a_deleted_shipment_reports_success(): void {
        $this->assertTrue($this->sameday_answering(204)->cancel_label('1ABC'));
    }

    /** The one this test exists for: Sameday has no such AWB, so nobody is coming for it. */
    public function test_a_shipment_sameday_no_longer_has_counts_as_cancelled(): void {
        $this->assertTrue($this->sameday_answering(404)->cancel_label('1VTDLN0017633'));
    }

    /** Anything else is still a failure - an active shipment must never be dropped silently. */
    public function test_a_real_refusal_is_still_a_failure(): void {
        $this->assertFalse($this->sameday_answering(500)->cancel_label('1ABC'));
        $this->assertFalse($this->sameday_answering(400)->cancel_label('1ABC'));
    }
}
