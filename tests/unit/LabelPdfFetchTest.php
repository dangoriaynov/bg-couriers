<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * The shared label fetch: a transport error, an error page and a real PDF each answer the way every
 * courier now depends on. Before this helper existed four couriers had four versions of it, one of
 * them (Econt) with no check at all - an error page was saved and printed as a label.
 *
 * @group core
 */
final class LabelPdfFetchTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('esc_html')->returnArg(1);   // exception messages are esc_html()'d (Plugin Check)
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function courier($response) {
        return new class($response) extends BGCouriers_Abstract_Courier {
            private $res;
            public function __construct($res) { $this->res = $res; }
            protected function http_get(string $url, array $headers = [], int $timeout = 40) { return $this->res; }
            public function pdf(string $url) { return $this->fetch_pdf($url, [], 'Куриер'); }
            public function check(string $raw) { return self::assert_pdf($raw, 'Куриер'); }
            // The interface. None of it is exercised here - only the two helpers above are.
            public function id(): string { return 'fake'; }
            public function label(): string { return 'Fake'; }
            public function capabilities(): array { return []; }
            public function check_credentials(): bool { return false; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $city_id): array { return []; }
            public function quote(array $shipment): BGCouriers_Quote { throw new BGCouriers_Api_Exception('not used'); }
            public function create_label(\WC_Order $order): BGCouriers_Label { throw new BGCouriers_Api_Exception('not used'); }
            public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
            public function cancel_label(string $waybill): bool { return false; }
            public function track(string $waybill): BGCouriers_Tracking { throw new BGCouriers_Api_Exception('not used'); }
            public function tracking_url(string $waybill): string { return ''; }
        };
    }

    public function test_a_real_pdf_comes_back_unchanged(): void {
        Functions\when('wp_remote_retrieve_body')->justReturn('%PDF-1.4 hello');
        Functions\when('is_wp_error')->justReturn(false);
        $this->assertSame('%PDF-1.4 hello', $this->courier(['body' => '%PDF-1.4 hello'])->pdf('https://x/label?key=SECRET'));
    }

    public function test_an_error_page_is_refused_without_quoting_the_url(): void {
        Functions\when('wp_remote_retrieve_body')->justReturn('<html>Forbidden</html>');
        Functions\when('is_wp_error')->justReturn(false);
        try {
            $this->courier(['body' => '<html>Forbidden</html>'])->pdf('https://x/label?key=SECRET');
            $this->fail('an error page must not pass as a label');
        } catch (BGCouriers_Api_Exception $e) {
            $this->assertStringContainsString('Куриер', $e->getMessage());
            // The label link carries the account's API key; it must never reach an order note or a log.
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
            $this->assertStringNotContainsString('Forbidden', $e->getMessage());
        }
    }

    public function test_a_transport_error_says_so_rather_than_blaming_the_pdf(): void {
        Functions\when('is_wp_error')->justReturn(true);
        $err = new class { public function get_error_message() { return 'cURL timed out'; } };
        try {
            $this->courier($err)->pdf('https://x/label?key=SECRET');
            $this->fail('a transport error must be raised');
        } catch (BGCouriers_Api_Exception $e) {
            $this->assertStringContainsString('cURL timed out', $e->getMessage());
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }

    public function test_assert_pdf_checks_bytes_that_arrived_another_way(): void {
        $c = $this->courier(null);
        $this->assertSame('%PDF-x', $c->check('%PDF-x'));
        $this->expectException(BGCouriers_Api_Exception::class);
        $c->check(base64_decode('bm90IGEgcGRm'));
    }
}
