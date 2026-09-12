<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-logger.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';

/**
 * Nothing that is a credential may reach the shop's PHP error log.
 *
 * The log is only written when a merchant turns debugging on, which is precisely when it gets read by
 * somebody else - a host's support desk, a pasted excerpt in a forum thread. The redaction used to be
 * one unset() of four literal key names, covering two couriers out of seven, at the top level only.
 * These are the spellings the other five actually use.
 *
 * @group core
 */
final class LoggerRedactionTest extends TestCase {
    protected function setUp(): void {
        // Deliberately no get_option()/wp_json_encode() stub: this exercises the redaction alone, and
        // Brain Monkey's when() defines a real function that outlives the test - stubbing get_option
        // here made another file's test fail on an expectation it never set.
        parent::setUp(); Monkey\setUp();
        BGCouriers_Couriers::reset();
    }
    protected function tearDown(): void { BGCouriers_Couriers::reset(); Monkey\tearDown(); parent::tearDown(); }

    /** What the logger would have written, without touching the real error log. */
    private function logged(array $ctx): string {
        // Private on purpose: the redaction is not an API, it is what debug() does on the way out.
        return (string) json_encode((new ReflectionMethod('BGCouriers_Logger', 'redact'))->invoke(null, $ctx, 0));
    }

    public function test_every_courier_spelling_of_a_credential_is_taken_out(): void {
        // Distinctive values, because a two-letter one is a substring of half the key names around it.
        $out = $this->logged([
            'userName' => 'SECRET-username', 'password' => 'SECRET-speedy-pass',
            'client_id' => 'SECRET-boxnow-id', 'client_secret' => 'SECRET-boxnow-secret',
            'clientKey' => 'SECRET-evropat-key', 'X-AUTH-TOKEN' => 'SECRET-sameday-token',
            'api_key' => 'SECRET-apikey', 'api_secret' => 'SECRET-apisecret',
            'webhook_secret' => 'SECRET-webhook', 'Authorization' => 'Bearer SECRET-bearer',
        ]);
        $this->assertStringNotContainsString('SECRET-', $out, 'something reached the log: ' . $out);
        foreach (['SECRET-username', 'SECRET-speedy-pass', 'SECRET-boxnow-id', 'SECRET-boxnow-secret',
                  'SECRET-evropat-key', 'SECRET-sameday-token', 'SECRET-apikey', 'SECRET-apisecret',
                  'SECRET-webhook', 'SECRET-bearer'] as $secret) {
            $this->assertStringNotContainsString($secret, $out, $secret . ' reached the log');
        }
    }

    /** A body is nested, and the old version only ever looked at the top level. */
    public function test_a_credential_nested_in_a_request_body_is_taken_out(): void {
        $out = $this->logged(['req' => ['url' => 'https://api.example.bg/x', 'body' => ['clientKey' => 'SECRET-clientkey', 'barcode' => '123']]]);
        $this->assertStringNotContainsString('SECRET-clientkey', $out);
        $this->assertStringContainsString('123', $out, 'the harmless fields still have to survive');
        $this->assertStringContainsString('api.example.bg', $out);
    }

    /** A courier added later is covered by what it says its own credential fields are. */
    public function test_a_couriers_own_field_name_is_taken_out(): void {
        BGCouriers_Couriers::register('fake', 'Fake', static function () {
            return new class extends BGCouriers_Abstract_Courier {
                public function credential_fields(): array { return ['portal_passphrase']; }
                public function id(): string { return 'fake'; }
                public function label(): string { return 'Fake'; }
                public function capabilities(): array { return []; }
                public function check_credentials(): bool { return true; }
                public function fetch_cities(): array { return []; }
                public function fetch_offices(int $city_id): array { return []; }
                public function quote(array $shipment): BGCouriers_Quote { return new BGCouriers_Quote(0.0, 0.0, 'BGN', 'fallback'); }
                public function create_label(\WC_Order $order): BGCouriers_Label { return new BGCouriers_Label(''); }
                public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
                public function cancel_label(string $waybill): bool { return true; }
                public function track(string $waybill): BGCouriers_Tracking { return new BGCouriers_Tracking('', '', []); }
                public function tracking_url(string $waybill): string { return ''; }
            };
        });
        $this->assertStringNotContainsString('SECRET-passphrase', $this->logged(['portal_passphrase' => 'SECRET-passphrase']));
    }

    public function test_the_message_and_its_ordinary_context_are_left_alone(): void {
        $out = $this->logged(['courier' => 'speedy', 'country' => 'BG', 'err' => 'timeout', 'page' => 3]);
        $this->assertStringContainsString('speedy', $out);
        $this->assertStringContainsString('timeout', $out);
        $this->assertStringContainsString('3', $out);
    }
}
