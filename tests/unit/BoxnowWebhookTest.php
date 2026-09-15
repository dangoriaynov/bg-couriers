<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow-webhook.php';

/**
 * The BOX NOW webhook must only be trusted when the HMAC-SHA256 signature over `data` matches the shared
 * secret - a tampered payload or wrong/empty secret is rejected.
 *
 * @group boxnow
 */
final class BoxnowWebhookTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when('__')->returnArg(1); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** Build a WebhookMessage whose `data` substring is exactly what we sign. */
    private function body(array $data, string $secret, ?string $sig = null): string {
        $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sig = $sig ?? hash_hmac('sha256', $dataJson, $secret);
        return '{"specversion":"1.0","type":"gr.boxnow.parcel_event_change","subject":"p1","data":' . $dataJson . ',"datasignature":"' . $sig . '"}';
    }

    public function test_valid_signature_passes(): void {
        $secret = 'sec-123';
        $body = $this->body(['parcelId' => '2945843660', 'parcelState' => 'delivered', 'orderNumber' => '42'], $secret);
        $this->assertTrue(BGCouriers_Boxnow_Webhook::verify($body, $secret));
    }

    public function test_tampered_data_fails(): void {
        $secret = 'sec-123';
        $body = $this->body(['parcelId' => '2945843660', 'parcelState' => 'delivered', 'orderNumber' => '42'], $secret);
        $tampered = str_replace('delivered', 'returned', $body); // signature no longer matches the data
        $this->assertFalse(BGCouriers_Boxnow_Webhook::verify($tampered, $secret));
    }

    public function test_wrong_secret_fails(): void {
        $body = $this->body(['parcelId' => 'x', 'parcelState' => 'new', 'orderNumber' => '1'], 'right-secret');
        $this->assertFalse(BGCouriers_Boxnow_Webhook::verify($body, 'wrong-secret'));
    }

    public function test_empty_secret_fails(): void {
        $body = $this->body(['parcelId' => 'x'], 'whatever');
        $this->assertFalse(BGCouriers_Boxnow_Webhook::verify($body, ''));
    }

    public function test_missing_signature_fails(): void {
        $this->assertFalse(BGCouriers_Boxnow_Webhook::verify('{"data":{"parcelId":"x"}}', 'sec'));
    }

    /**
     * BOX NOW's Webhook Guide (v5, 2025-12): a signing key is handed out only "if needed", and
     * "additional authentication ... can be managed through request headers" - a name/value pair the
     * partner configures in the BOX NOW profile. So a message carrying the secret in our header is
     * trusted without any datasignature at all.
     */
    public function test_the_secret_in_the_header_is_trusted_without_a_signature(): void {
        $raw = '{"specversion":"1.0","data":{"parcelId":"x","parcelState":"new","orderNumber":"1"}}';
        $this->assertSame('', BGCouriers_Boxnow_Webhook::refusal($raw, 'sec-123', 'sec-123'));
    }

    public function test_a_wrong_header_is_refused_by_name(): void {
        $raw = '{"data":{"parcelId":"x"}}';
        $this->assertSame('bad_header', BGCouriers_Boxnow_Webhook::refusal($raw, 'sec-123', 'nope'));
    }

    public function test_a_wrong_header_does_not_veto_a_good_signature(): void {
        $body = $this->body(['parcelId' => 'x', 'parcelState' => 'new'], 'sec-123');
        $this->assertSame('', BGCouriers_Boxnow_Webhook::refusal($body, 'sec-123', 'stale'));
    }

    /** The reasons a merchant (or BOX NOW support) reads back in the 401 body. */
    public function test_each_refusal_names_its_reason(): void {
        $signed = $this->body(['parcelId' => 'x'], 'right');
        $this->assertSame('no_secret',     BGCouriers_Boxnow_Webhook::refusal($signed, '', ''));
        $this->assertSame('no_credential', BGCouriers_Boxnow_Webhook::refusal('{"data":{"parcelId":"x"}}', 'right', ''));
        $this->assertSame('bad_signature', BGCouriers_Boxnow_Webhook::refusal($signed, 'wrong', ''));
    }

    /** The guide says "HMAC SHA256 digest" and nothing about hex: a Base64 digest is the same proof. */
    public function test_a_base64_signature_passes(): void {
        $secret = 'sec-123';
        $data   = ['parcelId' => '2945843660', 'parcelState' => 'delivered', 'orderNumber' => '42'];
        $mac    = hash_hmac('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secret, true);
        $this->assertTrue(BGCouriers_Boxnow_Webhook::verify($this->body($data, $secret, base64_encode($mac)), $secret), 'standard Base64');
        $urlsafe = rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
        $this->assertTrue(BGCouriers_Boxnow_Webhook::verify($this->body($data, $secret, $urlsafe), $secret), 'URL-safe, unpadded');
        $this->assertFalse(BGCouriers_Boxnow_Webhook::verify($this->body($data, $secret, base64_encode('not the mac, 32 bytes long!!!!')), $secret));
    }

    public function test_state_labels_cover_the_enum(): void {
        $labels = BGCouriers_Boxnow_Webhook::state_labels();
        foreach (['new', 'in-transit', 'in-final-destination', 'delivered', 'returned', 'expired-return', 'canceled', 'lost', 'missing'] as $s) {
            $this->assertArrayHasKey($s, $labels);
        }
    }
}
