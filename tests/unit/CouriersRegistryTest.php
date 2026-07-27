<?php
// tests/unit/CouriersRegistryTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';

/**
 * @group core
 */
final class CouriersRegistryTest extends TestCase {
    protected function setUp(): void { BGCouriers_Couriers::reset(); }

    /** A throwaway courier that satisfies the interface (BGCouriers_Couriers::get() return type). */
    private static function makeStub(): BGCouriers_Courier_Interface {
        return new class implements BGCouriers_Courier_Interface {
            public function id(): string { return 'demo'; }
            public function label(): string { return 'Demo'; }
            public function capabilities(): array { return ['office']; }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $city_id): array { return []; }
            public function quote(array $shipment): BGCouriers_Quote { throw new RuntimeException('n/a'); }
            public function create_label(\WC_Order $order): BGCouriers_Label { throw new RuntimeException('n/a'); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
            public function cancel_label(string $waybill): bool { return true; }
            public function track(string $waybill): BGCouriers_Tracking { throw new RuntimeException('n/a'); }
            public function tracking_url(string $waybill): string { return ''; }
        };
    }

    public function test_register_get_all(): void {
        $stub = self::makeStub();
        BGCouriers_Couriers::register('demo', 'Demo', static function () use ($stub) { return $stub; });
        $this->assertSame($stub, BGCouriers_Couriers::get('demo'));
        $this->assertSame(['demo' => 'Demo'], BGCouriers_Couriers::all());
        $this->assertNull(BGCouriers_Couriers::get('missing'));
    }

    public function test_factory_is_lazy_and_cached(): void {
        $calls = 0;
        BGCouriers_Couriers::register('demo', 'Demo', static function () use (&$calls) { $calls++; return self::makeStub(); });
        $this->assertSame(0, $calls);            // not built until requested
        $a = BGCouriers_Couriers::get('demo');
        $b = BGCouriers_Couriers::get('demo');
        $this->assertSame(1, $calls);            // built once, cached
        $this->assertSame($a, $b);
    }
}
