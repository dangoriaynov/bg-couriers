<?php
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
final class LabelAppliedCheckTest extends BGCouriers_Unit_TestCase {

    private function econt(bool $recipient_pays = false): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($recipient_pays) {
            if ($n === 'bgcouriers_econt_ship_in_total') { return $recipient_pays ? 'no' : 'yes'; }
            return $d;
        });
    }

    /** @return array<string,array{bool,array,array,int}> recipient pays?, request, response, problems */
    public static function econt_cases(): array {
        $cod = static fn(float $amount) => ['label' => ['services' => ['cdAmount' => $amount]]];
        return [
            'matching cod' => [false, $cod(14.44), ['label' => ['services' => [
                ['type' => 'C', 'paymentSide' => 'SENDER', 'price' => 4.48],
                ['type' => 'CD', 'description' => 'Такса наложен платеж', 'count' => 14.44, 'price' => 0.21],
            ], 'receiverDueAmount' => 0]], 0],
            'cod row dropped'  => [false, $cod(12.48), ['label' => ['services' => [['type' => 'C', 'paymentSide' => 'SENDER', 'price' => 4.48]], 'receiverDueAmount' => 0]], 1],
            'wrong cod amount' => [false, $cod(20.0), ['label' => ['services' => [['type' => 'CD', 'count' => 12.0]], 'receiverDueAmount' => 0]], 1],
            'no cod asked'     => [false, ['label' => []], ['label' => ['services' => [], 'receiverDueAmount' => 0]], 0],
            'recipient should pay, sender billed' => [true, ['label' => []], ['label' => ['services' => [], 'receiverDueAmount' => 0]], 1],
            'recipient billed as asked' => [true, ['label' => []], ['label' => ['services' => [['type' => 'C', 'paymentSide' => 'RECEIVER']], 'receiverDueAmount' => 4.94]], 0],
        ];
    }

    /** @dataProvider econt_cases */
    public function test_econt_flags_a_waybill_that_differs_from_what_was_asked(bool $recipient_pays, array $body, array $resp, int $problems): void {
        $this->options(['bgcouriers_econt_ship_in_total' => $recipient_pays ? 'no' : 'yes']);
        $this->assertCount($problems, BGCouriers_Econt::check_applied($body, $resp));
    }

    public function test_econt_names_the_amount_it_did_not_collect(): void {
        $this->options(['bgcouriers_econt_ship_in_total' => 'yes']);
        $p = BGCouriers_Econt::check_applied(['label' => ['services' => ['cdAmount' => 12.48]]],
            ['label' => ['services' => [['type' => 'C']], 'receiverDueAmount' => 0]]);
        $this->assertStringContainsString('12.48', $p[0]);
    }

    /** @return array<string,array{array,array,int}> request, response, problems */
    public static function speedy_cases(): array {
        $cod = static fn(float $amount) => ['service' => ['additionalServices' => ['cod' => ['amount' => $amount]]]];
        return [
            'premium charged'        => [$cod(9.96), ['price' => ['details' => ['codPremium' => ['amount' => 0.26, 'percent' => 0.8]]]], 0],
            'cod silently dropped'   => [$cod(9.96), ['price' => ['details' => ['codPremium' => ['amount' => 0]]]], 1],
            'no price breakdown'     => [$cod(5.0), ['id' => '123'], 1],
            'no cod asked'           => [['service' => []], ['price' => ['details' => []]], 0],
        ];
    }

    /** @dataProvider speedy_cases */
    public function test_speedy_reads_an_applied_cod_off_its_premium(array $body, array $resp, int $problems): void {
        $p = BGCouriers_Speedy::check_applied($body, $resp);
        $this->assertCount($problems, $p);
        if ($problems && isset($body['service']['additionalServices']['cod']['amount'])) {
            $this->assertStringContainsString((string) $body['service']['additionalServices']['cod']['amount'], $p[0]);
        }
    }

    /** The label carries the problems to the caller, and defaults to none. */
    public function test_label_defaults_to_no_problems(): void {
        $this->assertSame([], (new BGCouriers_Label('123'))->problems);
        $this->assertSame(['x'], (new BGCouriers_Label('123', '', ['x']))->problems);
    }

    /** Sameday nests the real reason under errors.children.<field>.errors[]. */
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
        $this->assertSame([], BGCouriers_Sameday::field_errors([]));
    }
}
