<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-currency.php';

/**
 * @group core
 */
final class CurrencyTest extends TestCase {
    public function test_bgn_to_eur_uses_peg(): void {
        $this->assertEqualsWithDelta(10.00, BGC_Currency::convert(19.5583, 'BGN', 'EUR'), 0.001);
    }
    public function test_eur_to_bgn_uses_peg(): void {
        $this->assertEqualsWithDelta(19.5583, BGC_Currency::convert(10.00, 'EUR', 'BGN'), 0.001);
    }
    public function test_dual_disabled_returns_single(): void {
        $this->assertSame('19.56 лв.', BGC_Currency::dual(19.5583, 'BGN', false));
    }
    public function test_dual_enabled_appends_other(): void {
        $this->assertSame('19.56 лв. (10.00 €)', BGC_Currency::dual(19.5583, 'BGN', true));
    }
}
