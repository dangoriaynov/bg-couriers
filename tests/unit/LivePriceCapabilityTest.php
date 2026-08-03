<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';

/**
 * "Live price" is only a real choice where the courier has a price endpoint. BOX NOW has none - its
 * rates are contractual - so offering the mode invites a shop to pick one that can only ever fall back,
 * and then wonder why the fixed price is what shows at checkout.
 *
 * @group core
 */
final class LivePriceCapabilityTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('quoting', 'Quoting', static fn() => new BGCouriers_Quoting_Stub());
        BGCouriers_Couriers::register('flat', 'Flat', static fn() => new BGCouriers_Flat_Stub());
    }
    protected function tearDown(): void { BGCouriers_Couriers::reset(); Monkey\tearDown(); parent::tearDown(); }

    private function stored(string $mode): void {
        Functions\when('get_option')->alias(static fn($n, $d = false) => strpos((string) $n, '_price_mode') !== false ? $mode : $d);
    }

    /** A courier that can quote keeps whatever the shop chose. */
    public function test_a_quoting_courier_keeps_its_mode(): void {
        foreach (['live', 'fallback', 'fixed'] as $mode) {
            $this->stored($mode);
            $this->assertSame($mode, BGCouriers_Settings::price_mode('quoting', 'office'), $mode);
        }
    }

    /**
     * A courier with no price endpoint is fixed whatever is stored. A mode saved before the plugin knew
     * the courier's capabilities would otherwise send checkout hunting for a live price on every single
     * request and falling back on every one of them.
     */
    public function test_a_courier_without_prices_is_always_fixed(): void {
        foreach (['live', 'fallback', 'fixed'] as $mode) {
            $this->stored($mode);
            $this->assertSame('fixed', BGCouriers_Settings::price_mode('flat', 'automat'), $mode);
        }
    }

    /** Junk in the option never becomes a live-API mode by accident. */
    public function test_an_unknown_mode_falls_back(): void {
        $this->stored('something else');
        $this->assertSame('fallback', BGCouriers_Settings::price_mode('quoting', 'office'));
        $this->assertSame('fixed', BGCouriers_Settings::price_mode('flat', 'automat'));
    }
}

abstract class BGCouriers_Price_Stub extends BGCouriers_Abstract_Courier {
    public function label(): string { return 'Stub'; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $city_id): array { return []; }
    public function quote(array $shipment): BGCouriers_Quote { throw new \RuntimeException('n/a'); }
    public function create_label(\WC_Order $order): BGCouriers_Label { throw new \RuntimeException('n/a'); }
    public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
    public function track(string $waybill): BGCouriers_Tracking { throw new \RuntimeException('n/a'); }
    public function tracking_url(string $waybill): string { return ''; }
    public function cancel_label(string $waybill): bool { return false; }
    public function check_credentials(): bool { return true; }
}
final class BGCouriers_Quoting_Stub extends BGCouriers_Price_Stub {
    public function id(): string { return 'quoting'; }
    public function capabilities(): array { return ['address', 'office', 'automat', 'live_quote']; }
}
final class BGCouriers_Flat_Stub extends BGCouriers_Price_Stub {
    public function id(): string { return 'flat'; }
    public function capabilities(): array { return ['automat']; }
}
