<?php
use Brain\Monkey\Functions;

/**
 * A print or generate that finds another request issuing the same waybill waits for it and uses its
 * waybill, never books a second one, and when nothing comes back says why for each order.
 *
 * @group core
 */
final class PrintWaitsForTheIssuerTest extends BGCouriers_Unit_TestCase {
    private $saved_wpdb;
    /** @var WC_Order The order as the database has it. */
    private WC_Order $db;
    /** @var WC_Order The copy this request's object cache handed out before the other request saved. */
    private WC_Order $cached;
    private bool $cache_dropped = false;

    protected function setUp(): void {
        parent::setUp();
        $this->saved_wpdb = $GLOBALS['wpdb'];
        foreach (['esc_html__', 'wp_kses_post', 'esc_attr'] as $f) { Functions\when($f)->returnArg(1); }
        Functions\when('clean_post_cache')->alias(function () { $this->cache_dropped = true; });

        $this->db = new WC_Order();
        $this->db->id = 7; $this->db->number = '1007';
        $this->db->meta['_bgcouriers_courier'] = 'probe';
        $this->cached = clone $this->db;
        Functions\when('wc_get_order')->alias(function ($id) {
            if ((int) $id !== 7) { return false; }
            return $this->cache_dropped ? $this->db : $this->cached;
        });
        BGCouriers_Couriers::register('probe', 'Probe', static function () { return new BGCouriers_Print_Probe(); });
        BGCouriers_Print_Probe::$created = [];
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->saved_wpdb;
        BGCouriers_Couriers::reset();
        parent::tearDown();
    }

    /** The other request holds the claim; while this one waits, it saves $waybill (or nothing) and lets go. */
    private function other_request_issues(string $waybill, bool $lets_go = true): void {
        $GLOBALS['wpdb'] = new class(function () use ($waybill) { if ($waybill !== '') { $this->db->meta['_bgcouriers_waybill'] = $waybill; } }, $lets_go) {
            public array $waits = [];
            private $finish; private bool $lets_go;
            public function __construct(callable $finish, bool $lets_go) { $this->finish = $finish; $this->lets_go = $lets_go; }
            public function prepare($sql, ...$a) { return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $sql), $a); }
            public function get_var($sql) {
                if (!preg_match('/GET_LOCK\(\'[^\']+\', (\d+)\)/', $sql, $m)) { return null; }
                if ((int) $m[1] === 0) { return '0'; }
                $this->waits[] = (int) $m[1];
                ($this->finish)();
                return $this->lets_go ? '1' : '0';
            }
            public function query($sql) { return 1; }
        };
    }

    public function test_a_waybill_being_issued_elsewhere_is_waited_for_and_used(): void {
        $this->other_request_issues('1CJ-OTHER');

        $label = BGCouriers_Labels::generate_or_wait(7);

        $this->assertSame('1CJ-OTHER', $label->waybill);
        $this->assertSame([BGCouriers_Labels::CLAIM_WAIT], $GLOBALS['wpdb']->waits, 'it waited on the claim');
        $this->assertSame([], BGCouriers_Print_Probe::$created, 'and booked nothing of its own');
    }

    public function test_bulk_print_includes_an_order_whose_waybill_another_request_was_issuing(): void {
        $this->other_request_issues('1CJ-OTHER');

        [$ok, $errors] = BGCouriers_Bulk_Labels::printable([7]);

        $this->assertSame([7], $ok);
        $this->assertSame([], $errors);
    }

    /** @return array<string,array{string,bool,string}> */
    public static function nothing_came_back(): array {
        return [
            'the other request failed' => ['', true, 'ended without one'],
            'the other request outlasted the wait' => ['', false, 'being issued right now'],
        ];
    }

    /** @dataProvider nothing_came_back */
    public function test_when_no_waybill_comes_back_the_reason_reaches_the_merchant(string $wb, bool $lets_go, string $says): void {
        $this->other_request_issues($wb, $lets_go);

        [$ok, $errors] = BGCouriers_Bulk_Labels::printable([7]);
        $page = BGCouriers_Bulk_Labels::nothing_printed($errors);

        $this->assertSame([], $ok);
        $this->assertSame([], BGCouriers_Print_Probe::$created, 'no second shipment either way');
        $this->assertStringContainsString('#1007: ', $page);
        $this->assertStringContainsString($says, $page);
        $this->assertStringContainsString($says, implode(" ", array_merge($this->cached->notes, $this->db->notes)));
    }

    public function test_an_order_without_a_bg_couriers_courier_is_named_on_the_page(): void {
        $this->cached->meta['_bgcouriers_courier'] = '';

        [$ok, $errors] = BGCouriers_Bulk_Labels::printable([7]);

        $this->assertSame([], $ok);
        $this->assertStringContainsString('#1007: This order is not shipped with a BG Couriers courier.',
            BGCouriers_Bulk_Labels::nothing_printed($errors));
    }

    public function test_a_label_the_courier_would_not_hand_over_says_why(): void {
        $this->cached->meta['_bgcouriers_waybill'] = '1CJ-1';
        $this->cached->meta['_bgcouriers_label_paper_size'] = 'A6';
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/nonexistent', 'baseurl' => 'https://shop.example/u']);

        $this->assertSame('', BGCouriers_Labels::batch_pdf([7], 'A6'));
        $this->assertSame([7 => 'Probe: the label did not come back as a PDF.'], BGCouriers_Labels::print_errors());
    }
}

/** Books PROBE-n and refuses every label PDF. */
final class BGCouriers_Print_Probe extends BGCouriers_Abstract_Courier {
    /** @var string[] */
    public static array $created = [];
    public function id(): string { return 'probe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $c): array { return []; }
    public function quote(array $s): BGCouriers_Quote { throw new BGCouriers_Api_Exception('not used'); }
    public function create_label(\WC_Order $o): BGCouriers_Label {
        self::$created[] = 'PROBE-' . (count(self::$created) + 1);
        return new BGCouriers_Label(end(self::$created));
    }
    public function label_formats(): array { return ['A6', 'A4']; }
    public function get_label_pdf(string $w, string $f = ''): string { throw new BGCouriers_Api_Exception('Probe: the label did not come back as a PDF.'); }
    public function cancel_label(string $w): bool { return true; }
    public function track(string $w): BGCouriers_Tracking { throw new BGCouriers_Api_Exception('not used'); }
    public function tracking_url(string $w): string { return ''; }
}
