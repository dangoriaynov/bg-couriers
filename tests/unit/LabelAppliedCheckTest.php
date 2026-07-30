<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';

/**
 * A courier can accept a shipment and silently drop the cash-on-delivery - Speedy's COD service is sent
 * with ignoreIfNotApplicable, which permits exactly that, and Econt simply omits the CD service row. The
 * waybill then prints with nothing to collect and the goods leave for free, which is how a real shipment
 * went out unnoticed. These checks are the only thing standing between that and a silent loss.
 *
 * Every response fixture below is the real shape observed live on the production accounts.
 *
 * @group core
 */
final class LabelAppliedCheckTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function econt(bool $recipient_pays = false): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($recipient_pays) {
            if ($n === 'bgcouriers_econt_ship_in_total') { return $recipient_pays ? 'no' : 'yes'; }
            return $d;
        });
    }

    /** Econt's real create response: services[] with a 'CD' row whose `count` is the amount to collect. */
    public function test_econt_accepts_a_matching_cod(): void {
        $this->econt();
        $body = ['label' => ['services' => ['cdAmount' => 14.44]]];
        $resp = ['label' => ['services' => [
            ['type' => 'C', 'paymentSide' => 'SENDER', 'price' => 4.48],
            ['type' => 'CD', 'description' => 'Такса наложен платеж', 'count' => 14.44, 'price' => 0.21],
        ], 'receiverDueAmount' => 0]];
        $this->assertSame([], BGCouriers_Econt::check_applied($body, $resp));
    }

    /** The exact failure that cost real money: a waybill created with no CD row at all. */
    public function test_econt_flags_a_dropped_cod(): void {
        $this->econt();
        $body = ['label' => ['services' => ['cdAmount' => 12.48]]];
        $resp = ['label' => ['services' => [['type' => 'C', 'paymentSide' => 'SENDER', 'price' => 4.48]],
                             'receiverDueAmount' => 0]];
        $p = BGCouriers_Econt::check_applied($body, $resp);
        $this->assertCount(1, $p);
        $this->assertStringContainsString('12.48', $p[0]);
    }

    /** A COD that was applied for the WRONG amount is just as expensive as none at all. */
    public function test_econt_flags_a_wrong_cod_amount(): void {
        $this->econt();
        $body = ['label' => ['services' => ['cdAmount' => 20.0]]];
        $resp = ['label' => ['services' => [['type' => 'CD', 'count' => 12.0]], 'receiverDueAmount' => 0]];
        $this->assertCount(1, BGCouriers_Econt::check_applied($body, $resp));
    }

    /** Rounding noise is not a mismatch. */
    public function test_econt_tolerates_a_cent_of_rounding(): void {
        $this->econt();
        $body = ['label' => ['services' => ['cdAmount' => 14.44]]];
        $resp = ['label' => ['services' => [['type' => 'CD', 'count' => 14.44]], 'receiverDueAmount' => 0]];
        $this->assertSame([], BGCouriers_Econt::check_applied($body, $resp));
    }

    /** No COD requested (a prepaid order) - nothing to check, and nothing to complain about. */
    public function test_econt_says_nothing_when_no_cod_was_asked_for(): void {
        $this->econt();
        $this->assertSame([], BGCouriers_Econt::check_applied(['label' => []], ['label' => ['services' => [], 'receiverDueAmount' => 0]]));
    }

    /**
     * The payer check is deliberately inert for Econt TODAY: ship_in_total() hardcodes Econt to
     * "charged with the order", so the sender being billed is the expected outcome, not a fault. The
     * check is already in place so that it starts reporting the moment recipient-pays is offered for
     * Econt (the API supports it - paymentReceiverMethod - the plugin does not send it yet).
     */
    public function test_econt_does_not_flag_the_payer_while_the_merchant_is_meant_to_pay(): void {
        $this->econt(true); // even with the option set, ship_in_total() still forces merchant-pays
        $this->assertSame([], BGCouriers_Econt::check_applied(['label' => []],
            ['label' => ['services' => [], 'receiverDueAmount' => 0]]));
    }

    /** Speedy charges a premium for COD, so a non-zero premium proves the service was applied. */
    public function test_speedy_accepts_an_applied_cod(): void {
        $body = ['service' => ['additionalServices' => ['cod' => ['amount' => 9.96]]]];
        $resp = ['price' => ['details' => ['codPremium' => ['amount' => 0.26, 'percent' => 0.8]]]];
        $this->assertSame([], BGCouriers_Speedy::check_applied($body, $resp));
    }

    /** ignoreIfNotApplicable lets Speedy drop the COD and still return a waybill - premium stays at zero. */
    public function test_speedy_flags_a_silently_dropped_cod(): void {
        $body = ['service' => ['additionalServices' => ['cod' => ['amount' => 9.96]]]];
        $resp = ['price' => ['details' => ['codPremium' => ['amount' => 0]]]];
        $p = BGCouriers_Speedy::check_applied($body, $resp);
        $this->assertCount(1, $p);
        $this->assertStringContainsString('9.96', $p[0]);
    }

    /** A response with no price breakdown at all must not be read as "all good". */
    public function test_speedy_flags_a_missing_price_breakdown(): void {
        $body = ['service' => ['additionalServices' => ['cod' => ['amount' => 5.0]]]];
        $this->assertCount(1, BGCouriers_Speedy::check_applied($body, ['id' => '123']));
    }

    public function test_speedy_says_nothing_when_no_cod_was_asked_for(): void {
        $this->assertSame([], BGCouriers_Speedy::check_applied(['service' => []], ['price' => ['details' => []]]));
    }

    /** The label carries the problems to the caller, and defaults to none. */
    public function test_label_defaults_to_no_problems(): void {
        $this->assertSame([], (new BGCouriers_Label('123'))->problems);
        $this->assertSame(['x'], (new BGCouriers_Label('123', '', ['x']))->problems);
    }

    /**
     * Sameday buries the real reason in errors.children.<field>.errors[] and puts only "Validation
     * Failed" at the top level. This is the exact payload the live account returns when the contract
     * does not allow charging the recipient - without flattening it the merchant cannot tell what to fix.
     */
    public function test_sameday_field_errors_are_flattened(): void {
        $errors = ['children' => [
            'awbPayment'   => ['errors' => ['The selected choice is invalid.']],
            'awbRecipient' => ['children' => ['phoneNumber' => ['errors' => ['This value is not valid.']]]],
            'service'      => ['children' => []],
        ]];
        $out = BGCouriers_Sameday::field_errors($errors);
        $this->assertContains('awbPayment: The selected choice is invalid.', $out);
        $this->assertContains('awbRecipient.phoneNumber: This value is not valid.', $out);
        $this->assertCount(2, $out, 'a node with no errors contributes nothing');
    }

    public function test_sameday_field_errors_on_an_empty_body(): void {
        $this->assertSame([], BGCouriers_Sameday::field_errors([]));
    }
}
