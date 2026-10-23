<?php
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';

/**
 * @group core
 */
final class AbstractCourierTest extends BGCouriers_Unit_TestCase {
    protected function setUp(): void {
        parent::setUp();
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    private function answer(int $code, string $body): void {
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
    }

    public function test_post_json_parses_200(): void {
        $this->answer(200, '{"a":1}');
        Functions\when('wp_remote_post')->justReturn(['x']);
        $this->assertSame(['a' => 1], (new BGCouriers_Test_Courier())->call('https://x', ['k' => 'v']));
    }

    /** @return array<string,array{int,string,string}> HTTP code, body, message the merchant reads */
    public static function refusals(): array {
        return [
            'json message'          => [517, '{"type":"ExInvalidParam","message":"Невалидно потребителско име и\\/или парола.","fields":[]}', 'HTTP 517: Невалидно потребителско име и/или парола.'],
            'nested error message'  => [400, '{"error":{"code":12,"message":"Bad request"}}', 'HTTP 400: Bad request'],
            'econt inner errors'    => [517, '{"type":"ExInvalidParam","message":"","fields":[],"innerErrors":[{"type":"ExInvalidAddress","message":"Информацията, която попълнихте за адрес, е недостатъчна.","fields":[],"innerErrors":[]}]}', 'HTTP 517: Информацията, която попълнихте за адрес, е недостатъчна.'],
            'inner errors joined and followed down' => [517, '{"message":"","innerErrors":[{"message":"Първо."},{"message":"","innerErrors":[{"message":"Второ."}]}]}', 'HTTP 517: Първо.; Второ.'],
            'empty shell as it was' => [517, '{"type":"ExInvalidParam","message":"","innerErrors":[]}', 'HTTP 517: {"type":"ExInvalidParam","message":"","innerErrors":[]}'],
            'non-json as it was'    => [502, '<html>Bad Gateway</html>', 'HTTP 502: <html>Bad Gateway</html>'],
        ];
    }

    /** @dataProvider refusals */
    public function test_post_json_shows_the_couriers_own_message_for_a_refusal(int $code, string $body, string $message): void {
        $this->answer($code, $body);
        Functions\when('wp_remote_post')->justReturn(['x']);
        try {
            (new BGCouriers_Test_Courier())->call('https://x', []);
            $this->fail('expected a refusal');
        } catch (BGCouriers_Api_Exception $e) {
            $this->assertSame('The request failed: ' . $message, $e->getMessage());
        }
    }

    public function test_post_json_retries_once_on_500_then_throws(): void {
        $this->answer(500, 'err');
        Functions\expect('wp_remote_post')->twice()->andReturn(['x']);
        $this->expectException(BGCouriers_Api_Exception::class);
        (new BGCouriers_Test_Courier())->call('https://x', []);
    }

    public function test_post_json_retries_once_on_transport_error_then_throws(): void {
        Functions\expect('wp_remote_post')->twice()->andReturn(['x']);
        Functions\when('is_wp_error')->justReturn(true);
        $this->expectException(BGCouriers_Api_Exception::class);
        (new BGCouriers_Test_Courier())->call('https://x', []);
    }
}

class BGCouriers_Test_Courier extends BGCouriers_Abstract_Courier {
    public function id(): string { return 'test'; }
    public function label(): string { return 'Test'; }
    public function capabilities(): array { return []; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $city_id): array { return []; }
    public function quote(array $shipment): BGCouriers_Quote { return new BGCouriers_Quote(0,0,'BGN','live'); }
    public function create_label(\WC_Order $order): BGCouriers_Label { return new BGCouriers_Label(''); }
    public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
    public function cancel_label(string $waybill): bool { return true; }
    public function track(string $waybill): BGCouriers_Tracking { return new BGCouriers_Tracking('','',[]); }
    public function tracking_url(string $waybill): string { return ''; }
    public function call(string $url, array $body): array { return $this->post_json($url, $body); }
}
