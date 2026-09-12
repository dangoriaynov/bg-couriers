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
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-econt.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-pigeon.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-sameday.php';

/**
 * Two answers the merchant reads were a bare yes or no: cancel_label() and check_credentials(). Both
 * had the courier's reason in hand and threw it away, so a cancel the courier refused read "The courier
 * did not cancel the waybill" whatever the courier had said, and a courier that was down for a minute
 * read "Invalid credentials". Both carry the reason out as an exception now; false stays for a
 * courier that refuses without a word.
 *
 * @group core
 */
final class RefusalsSayWhyTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('get_option')->alias(static function ($n, $d = false) { return $d; });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_headers')->justReturn([]);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function answering(string $json): void {
        Functions\when('wp_remote_post')->justReturn(['body' => $json]);
        Functions\when('wp_remote_get')->justReturn(['body' => $json]);
        Functions\when('wp_remote_request')->justReturn(['body' => $json]);
        Functions\when('wp_remote_retrieve_body')->justReturn($json);
    }

    private function refusal(callable $call, string $expected): void {
        try { $call(); $this->fail('a refusal with a reason must throw'); }
        catch (BGCouriers_Api_Exception $e) { $this->assertStringContainsString($expected, $e->getMessage()); }
    }

    // ── cancel_label ─────────────────────────────────────────────────────────

    public function test_speedy_says_why_a_cancel_was_refused(): void {
        $this->answering('{"error":{"id":"x","message":"Shipment already picked up"}}');
        $this->refusal(fn() => (new BGCouriers_Speedy([]))->cancel_label('63740000001'), 'Speedy: Shipment already picked up');
    }

    public function test_econt_says_why_a_cancel_was_refused(): void {
        $this->answering('{"results":[{"shipmentNum":"1234567890123","error":{"message":"Пратката е предадена за доставка"}}]}');
        $this->refusal(fn() => (new BGCouriers_Econt([]))->cancel_label('1234567890123'), 'Пратката е предадена за доставка');
        // And a shipment Econt no longer has is still simply done.
        $this->answering('{"results":[{"shipmentNum":"1234567890123","error":{"message":"Shipment not found"}}]}');
        $this->assertTrue((new BGCouriers_Econt([]))->cancel_label('1234567890123'));
    }

    public function test_pigeon_says_why_a_cancel_was_refused(): void {
        $this->answering('{"success":false,"message":"Пратката вече е взета от куриер"}');
        $this->refusal(fn() => (new BGCouriers_Pigeon([]))->cancel_label('PGN1'), 'Пратката вече е взета от куриер');
    }

    // ── check_credentials ────────────────────────────────────────────────────

    public function test_speedy_says_why_the_credentials_were_refused(): void {
        $this->answering('{"error":{"id":"x","message":"Invalid username or password"}}');
        $this->refusal(fn() => (new BGCouriers_Speedy([]))->check_credentials(), 'Speedy: Invalid username or password');
    }

    public function test_a_courier_that_cannot_be_reached_is_not_invalid_credentials(): void {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(503);
        $this->answering('<html>Service Unavailable</html>');
        $this->refusal(fn() => (new BGCouriers_Speedy([]))->check_credentials(), 'HTTP 503');
    }

    public function test_sameday_says_why_the_credentials_were_refused(): void {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(401);
        $this->answering('{"error":{"code":401,"message":"Invalid credentials."}}');
        $this->refusal(fn() => (new BGCouriers_Sameday([]))->check_credentials(), 'Invalid credentials.');
    }
}
