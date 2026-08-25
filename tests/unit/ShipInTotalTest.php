<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';

/**
 * "Delivery in the order total" toggle: drives the checkout rate cost (0 when off) AND the waybill
 * payer / COD amount via service_payer(), with back-compat for the removed "Who pays delivery" select.
 *
 * @group core
 */
final class ShipInTotalTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function options(array $map): void {
        Functions\when('get_option')->alias(function ($name, $default = false) use ($map) {
            return $map[$name] ?? $default;
        });
    }

    /**
     * service_payer() is protected, so reach it through a subclass rather than reflection:
     * ReflectionMethod::setAccessible() is deprecated as of PHP 8.5 and raises there, which nulled
     * every assertion in this file.
     */
    private static function payer(string $courier): string {
        return BGCouriers_Payer_Probe::payer_of($courier);
    }

    /**
     * A shop that has never opened the setting does NOT charge delivery with the order.
     *
     * It used to, and the owner changed it: a shop that collects a delivery fee and pays it straight
     * out again carries the turnover and keeps none of it, and the customer paying the courier at the
     * door is what the shop actually wants. An install that already existed keeps whatever it had -
     * BGCouriers_Plugin::pin_who_pays_delivery() writes that down for it - so this default only ever
     * meets a shop being set up now.
     */
    public function test_a_new_shop_does_not_charge_delivery_with_the_order(): void {
        $this->options([]);
        $this->assertFalse(BGCouriers_Settings::ship_in_total('speedy'));
        $this->assertSame('recipient', self::payer('speedy'));
        foreach (['econt', 'pigeon', 'sameday', 'expressone'] as $c) {
            $this->assertFalse(BGCouriers_Settings::ship_in_total($c), $c . ' must not charge it either');
        }
    }

    /** Ticking it still does what it says. */
    public function test_a_shop_that_asks_for_it_charges_delivery_with_the_order(): void {
        $this->options(['bgcouriers_speedy_ship_in_total' => 'yes']);
        $this->assertTrue(BGCouriers_Settings::ship_in_total('speedy'));
        $this->assertSame('sender', self::payer('speedy'));
    }

    /** And the tabs offer it unticked, or the screen would disagree with the code behind it. */
    public function test_every_tab_offers_it_off(): void {
        $settings = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-wc-settings.php');
        preg_match_all("/'id' => 'bgcouriers_([a-z]+)_ship_in_total'.*?'default' => '(yes|no)'/s", $settings, $m);
        $this->assertNotEmpty($m[1], 'the toggle must be on the tabs at all');
        foreach ($m[1] as $i => $courier) {
            $this->assertSame('no', $m[2][$i], $courier . ' offers the toggle ticked');
        }
    }

    public function test_toggle_off_means_recipient_pays(): void {
        $this->options(['bgcouriers_speedy_ship_in_total' => 'no']);
        $this->assertFalse(BGCouriers_Settings::ship_in_total('speedy'));
        $this->assertSame('recipient', self::payer('speedy'));
    }

    public function test_toggle_on_wins_over_legacy_select(): void {
        $this->options(['bgcouriers_pigeon_ship_in_total' => 'yes', 'bgcouriers_pigeon_service_payer' => 'recipient']);
        $this->assertTrue(BGCouriers_Settings::ship_in_total('pigeon'));
    }

    public function test_legacy_recipient_select_honored_when_toggle_unset(): void {
        $this->options(['bgcouriers_sameday_service_payer' => 'recipient']);
        $this->assertFalse(BGCouriers_Settings::ship_in_total('sameday'));
        $this->assertSame('recipient', self::payer('sameday'));
    }

    /** The other half of that back-compat: a shop that had chosen "the sender pays" still does. */
    public function test_legacy_sender_select_honored_when_toggle_unset(): void {
        $this->options(['bgcouriers_sameday_service_payer' => 'sender']);
        $this->assertTrue(BGCouriers_Settings::ship_in_total('sameday'));
        $this->assertSame('sender', self::payer('sameday'));
    }

    /**
     * BOX NOW has no field for it at all - its delivery-request payload cannot shift the courier fee to
     * the recipient, so the toggle must never apply there however it is set.
     */
    public function test_boxnow_is_always_charged_with_the_order(): void {
        $this->options(['bgcouriers_boxnow_ship_in_total' => 'no']);
        $this->assertTrue(BGCouriers_Settings::ship_in_total('boxnow'));
        $this->assertSame('sender', self::payer('boxnow'));
    }

    /** Econt does support it (paymentReceiverMethod), verified live - so the toggle is honoured. */
    public function test_econt_honours_the_toggle(): void {
        $this->options(['bgcouriers_econt_ship_in_total' => 'no']);
        $this->assertFalse(BGCouriers_Settings::ship_in_total('econt'));
        $this->assertSame('recipient', self::payer('econt'));

        $this->options(['bgcouriers_econt_ship_in_total' => 'yes']);
        $this->assertTrue(BGCouriers_Settings::ship_in_total('econt'));
        $this->assertSame('sender', self::payer('econt'));
    }
}

/** Minimal concrete courier whose only job is to expose the protected payer rule to the test. */
final class BGCouriers_Payer_Probe extends BGCouriers_Abstract_Courier {
    public static function payer_of(string $courier): string { return self::service_payer($courier); }
    public function id(): string { return 'probe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return []; }
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
