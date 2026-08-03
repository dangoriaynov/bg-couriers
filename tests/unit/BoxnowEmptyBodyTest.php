<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * BOX NOW answers :cancel with HTTP 200 and an EMPTY body. Requiring JSON of every response turned that
 * success into "BoxNow invalid JSON", cancel_label() swallowed it and returned false, and so every BOX
 * NOW cancellation reported failure while the parcel had in fact been cancelled. The waybill then stayed
 * on the order - which also silently broke re-issue, since generate() hands back an existing waybill
 * rather than creating a new one.
 *
 * Verified against the stage API on 2026-08-04: POST /api/v1/parcels/{id}:cancel -> 200, body "".
 *
 * @group core
 */
final class BoxnowEmptyBodyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('get_transient')->justReturn('tok');
        Functions\when('set_transient')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function courier(int $status, string $body): BGCouriers_Boxnow {
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => $status], 'body' => $body]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
        return new BGCouriers_Boxnow(['username' => 'id', 'password' => 'secret', 'api_url' => 'https://x']);
    }

    public function test_an_empty_200_is_a_successful_cancellation(): void {
        $this->assertTrue($this->courier(200, '')->cancel_label('6935113835'));
    }

    public function test_whitespace_only_body_counts_as_empty(): void {
        $this->assertTrue($this->courier(200, "\n")->cancel_label('6935113835'));
    }

    /** A real refusal must still be reported as one. */
    public function test_an_error_status_is_still_a_failure(): void {
        $this->assertFalse($this->courier(400, '{"code":"P404","status":400}')->cancel_label('6935113835'));
    }

    /** And a 200 carrying something that is not JSON is still a broken response, not a success. */
    public function test_a_non_json_body_is_still_an_error(): void {
        $this->assertFalse($this->courier(200, '<html>gateway</html>')->cancel_label('6935113835'));
    }
}
