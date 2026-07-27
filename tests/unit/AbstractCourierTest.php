<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';

/**
 * @group core
 */
final class AbstractCourierTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('esc_html')->returnArg(1); // exception messages are esc_html()'d (Plugin Check)
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_post_json_parses_200(): void {
        Functions\when('wp_remote_post')->justReturn(['ok']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"a":1}');
        Functions\when('wp_json_encode')->alias('json_encode');
        $c = new BGCouriers_Test_Courier();
        $this->assertSame(['a' => 1], $c->call('https://x', ['k' => 'v']));
    }

    public function test_post_json_throws_on_500(): void {
        Functions\when('wp_remote_post')->justReturn(['x']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('err');
        Functions\when('wp_json_encode')->alias('json_encode');
        $this->expectException(BGCouriers_Api_Exception::class);
        (new BGCouriers_Test_Courier())->call('https://x', []);
    }

    public function test_post_json_retries_twice_on_500(): void {
        Functions\expect('wp_remote_post')->twice()->andReturn(['x']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('err');
        Functions\when('wp_json_encode')->alias('json_encode');
        $this->expectException(BGCouriers_Api_Exception::class);
        (new BGCouriers_Test_Courier())->call('https://x', []);
    }

    public function test_post_json_retries_twice_on_transport_error(): void {
        Functions\expect('wp_remote_post')->twice()->andReturn(['x']);
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_json_encode')->alias('json_encode');
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
