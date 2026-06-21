<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';

final class AbstractCourierTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_post_json_parses_200(): void {
        Functions\when('wp_remote_post')->justReturn(['ok']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"a":1}');
        Functions\when('wp_json_encode')->alias('json_encode');
        $c = new BGC_Test_Courier();
        $this->assertSame(['a' => 1], $c->call('https://x', ['k' => 'v']));
    }

    public function test_post_json_throws_on_500(): void {
        Functions\when('wp_remote_post')->justReturn(['x']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('err');
        Functions\when('wp_json_encode')->alias('json_encode');
        $this->expectException(BGC_Api_Exception::class);
        (new BGC_Test_Courier())->call('https://x', []);
    }
}

class BGC_Test_Courier extends BGC_Abstract_Courier {
    public function id(): string { return 'test'; }
    public function label(): string { return 'Test'; }
    public function capabilities(): array { return []; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $city_id): array { return []; }
    public function quote(array $shipment): BGC_Quote { return new BGC_Quote(0,0,'BGN','live'); }
    public function create_label(\WC_Order $order): BGC_Label { return new BGC_Label(''); }
    public function get_label_pdf(string $waybill): string { return ''; }
    public function cancel_label(string $waybill): bool { return true; }
    public function track(string $waybill): BGC_Tracking { return new BGC_Tracking('','',[]); }
    public function tracking_url(string $waybill): string { return ''; }
    public function call(string $url, array $body): array { return $this->post_json($url, $body); }
}
