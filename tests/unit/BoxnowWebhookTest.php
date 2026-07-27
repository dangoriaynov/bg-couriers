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

    public function test_state_labels_cover_the_enum(): void {
        $labels = BGCouriers_Boxnow_Webhook::state_labels();
        foreach (['new', 'in-transit', 'in-final-destination', 'delivered', 'returned', 'expired-return', 'canceled', 'lost', 'missing'] as $s) {
            $this->assertArrayHasKey($s, $labels);
        }
    }
}
