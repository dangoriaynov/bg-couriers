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
 * the one they have. Econt has always read "shipment not found" that way; Sameday did not, so cancelling
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

    /**
     * The word the shop's own orders column had been reading as "Cancelled" for hours, while the code
     * that decides whether the dead number may be cleared did not recognise it.
     *
     * Sameday refused to collect from the sender on 2026-08-26 (a parcel booked seconds after checkout,
     * for a dispatch a day later) and worded it "collection from the sender refused". Its own is_cancelled()
     * looked only for the Bulgarian for "cancelled" and for "cancel", so "Re-issue waybill" - whose whole
     * purpose is to replace a
     * spent one - would have refused with "the courier did not cancel the waybill". One vocabulary now
     * answers the question everywhere: BGCouriers_Tracking::reads_cancelled().
     */
    public function test_a_refusal_to_collect_reads_as_cancelled(): void {
        $this->assertTrue(BGCouriers_Tracking::reads_cancelled('Отказ от взимане от подател'));
        $this->assertTrue(BGCouriers_Tracking::reads_cancelled('Анулирана пратка'));
        $this->assertTrue(BGCouriers_Tracking::reads_cancelled('Cancelled by sender'));
    }

    /** ...and a shipment that is merely moving must never read that way. */
    public function test_a_live_shipment_does_not_read_as_cancelled(): void {
        $this->assertFalse(BGCouriers_Tracking::reads_cancelled('Приета от куриер'));
        $this->assertFalse(BGCouriers_Tracking::reads_cancelled('Доставена'));
    }

    /** Sameday states it outright, and the flag beats any reading of the wording. */
    public function test_the_canceled_flag_is_enough_on_its_own(): void {
        $t = BGCouriers_Sameday::parse_tracking(
            ['expeditionSummary' => ['canceled' => true],
             'expeditionStatus'  => ['status' => 'нещо, което правилата не разпознават']],
            '1ABC');
        $this->assertSame('cancelled', $t->stage());
    }

    public function test_a_deleted_shipment_reports_success(): void {
        $this->assertTrue($this->sameday_answering(204)->cancel_label('1ABC'));
    }

    /** The one this test exists for: Sameday has no such AWB, so nobody is coming for it. */
    public function test_a_shipment_sameday_no_longer_has_counts_as_cancelled(): void {
        $this->assertTrue($this->sameday_answering(404)->cancel_label('1VTDLN0017633'));
    }

    /** Anything else is still a failure - an active shipment must never be dropped silently. */
    /**
     * A real refusal is a failure - and one that says why. The adapters answered false and the merchant
     * read "The courier did not cancel the waybill" whatever the courier had said; the reason travels
     * as an exception now, and the false answer is kept for a courier that gives no reason at all.
     */
    public function test_a_real_refusal_is_still_a_failure_and_says_why(): void {
        Functions\when('__')->returnArg(1);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"error":{"code":400,"message":"AWB already picked up"}}');
        foreach ([500, 400] as $code) {
            try { $this->sameday_answering($code)->cancel_label('1ABC'); $this->fail('a refusal must throw'); }
            catch (BGCouriers_Api_Exception $e) { $this->assertStringContainsString('HTTP ' . $code . ': AWB already picked up', $e->getMessage()); }
        }
    }
}
