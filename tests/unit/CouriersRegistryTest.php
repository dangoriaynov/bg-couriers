<?php
// tests/unit/CouriersRegistryTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-couriers.php';

/**
 * @group core
 */
final class CouriersRegistryTest extends TestCase {
    protected function setUp(): void { BGC_Couriers::reset(); }

    /** A throwaway courier that satisfies the interface (BGC_Couriers::get() return type). */
    private static function makeStub(): BGC_Courier_Interface {
        return new class implements BGC_Courier_Interface {
            public function id(): string { return 'demo'; }
            public function label(): string { return 'Demo'; }
            public function capabilities(): array { return ['office']; }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $city_id): array { return []; }
            public function quote(array $shipment): BGC_Quote { throw new RuntimeException('n/a'); }
            public function create_label(\WC_Order $order): BGC_Label { throw new RuntimeException('n/a'); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
            public function cancel_label(string $waybill): bool { return true; }
            public function track(string $waybill): BGC_Tracking { throw new RuntimeException('n/a'); }
            public function tracking_url(string $waybill): string { return ''; }
        };
    }

    public function test_register_get_all(): void {
        $stub = self::makeStub();
        BGC_Couriers::register('demo', 'Demo', static function () use ($stub) { return $stub; });
        $this->assertSame($stub, BGC_Couriers::get('demo'));
        $this->assertSame(['demo' => 'Demo'], BGC_Couriers::all());
        $this->assertNull(BGC_Couriers::get('missing'));
    }

    public function test_factory_is_lazy_and_cached(): void {
        $calls = 0;
        BGC_Couriers::register('demo', 'Demo', static function () use (&$calls) { $calls++; return self::makeStub(); });
        $this->assertSame(0, $calls);            // not built until requested
        $a = BGC_Couriers::get('demo');
        $b = BGC_Couriers::get('demo');
        $this->assertSame(1, $calls);            // built once, cached
        $this->assertSame($a, $b);
    }
}
