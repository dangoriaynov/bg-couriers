<?php
/**
 * "Which delivery options does this town have?" is answered from the SAME list as "which offices does
 * this town have?" - the office dropdown's - and not by a second call to the courier.
 *
 * Two endpoints read the same office list: one to fill the dropdown, one to grey out a delivery option
 * the town does not have. They were two separate code paths with two separate caches of the same data,
 * and only the first of them had been looked after:
 *
 *  - the dropdown's path caches only an answer that HAS something in it, so a courier that failed to
 *    answer is asked again next time. The availability path cached whatever it had - so an API that was
 *    down for one second told every customer for six hours that the town has neither an office nor a
 *    locker, when the town was fine.
 *  - the dropdown's path catches \Throwable, after an adapter's TypeError once turned the whole request
 *    into a 500. The availability path still caught \Exception, so the same adapter fault took the
 *    tabs out along with it.
 *  - and it asked the courier itself, live, on the checkout's own request - so a town that was picked
 *    cost two calls to the courier for one list, and the second could not use what the first had just
 *    cached because it kept its own cache under its own key.
 *
 * @group core
 */
final class CityAvailabilityTest extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
        BGCouriers_Couriers::register('availprobe', 'Probe', static function () { return new BGCouriers_Avail_Stub(); });
        BGCouriers_Avail_Stub::$asked = 0;
        BGCouriers_Avail_Stub::$mode  = 'answer';
    }
    /** Taken back out rather than the registry reset - see OfficeLookupCountryTest for why. */
    public function tear_down() {
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['availprobe']); $r->setValue(null, $v);
        }
        parent::tear_down();
    }

    /** A picked town costs the courier ONE question, whichever of the two things is asked first. */
    public function test_availability_and_the_office_list_share_one_call(): void {
        $a = BGCouriers_Ajax::city_avail_data('availprobe', 900, 'BG');
        $this->assertSame(['office' => true, 'automat' => true], $a);
        BGCouriers_Ajax::city_offices('availprobe', 900, 'office', '', 5, 'BG');

        $this->assertSame(1, BGCouriers_Avail_Stub::$asked, 'the second reader uses what the first fetched');
    }

    /** And the other way round, since the browser asks in either order depending on the tab. */
    public function test_the_office_list_first_then_availability_is_still_one_call(): void {
        BGCouriers_Ajax::city_offices('availprobe', 901, 'office', '', 5, 'BG');
        $a = BGCouriers_Ajax::city_avail_data('availprobe', 901, 'BG');

        $this->assertSame(['office' => true, 'automat' => true], $a);
        $this->assertSame(1, BGCouriers_Avail_Stub::$asked);
    }

    /**
     * The harm: the courier is down for one request, and the town must not be remembered as having
     * nothing for the next six hours. Asked again when the courier is back, it answers.
     */
    public function test_a_failed_lookup_is_not_remembered_as_a_town_with_nothing(): void {
        BGCouriers_Avail_Stub::$mode = 'throw';
        $down = BGCouriers_Ajax::city_avail_data('availprobe', 902, 'BG');
        $this->assertSame(['office' => false, 'automat' => false], $down, 'nothing is known while the courier is down');

        BGCouriers_Avail_Stub::$mode = 'answer';
        $up = BGCouriers_Ajax::city_avail_data('availprobe', 902, 'BG');
        $this->assertSame(['office' => true, 'automat' => true], $up,
            'the next customer is told the truth, not what was cached while the courier was down');
    }

    /** A broken adapter - an Error, not an Exception - must not take the request down with it. */
    public function test_an_adapter_error_is_survived(): void {
        BGCouriers_Avail_Stub::$mode = 'error';
        $a = BGCouriers_Ajax::city_avail_data('availprobe', 903, 'BG');
        $this->assertSame(['office' => false, 'automat' => false], $a);
    }
}

/** Answers with one office and one locker, or refuses the way an API or a broken adapter does. */
final class BGCouriers_Avail_Stub extends BGCouriers_Abstract_Courier {
    public static int $asked = 0;
    /** @var string answer | throw | error */
    public static string $mode = 'answer';
    public function id(): string { return 'availprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'automat', 'address']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $city_id, string $country = ''): array {
        self::$asked++;
        if (self::$mode === 'throw') { throw new BGCouriers_Api_Exception('down'); }
        if (self::$mode === 'error') { throw new \TypeError('a broken adapter'); }
        return [
            ['office_id' => 1, 'city_id' => $city_id, 'type' => 'office',  'name' => 'A', 'address' => '', 'lat' => 0, 'lng' => 0],
            ['office_id' => 2, 'city_id' => $city_id, 'type' => 'automat', 'name' => 'B', 'address' => '', 'lat' => 0, 'lng' => 0],
        ];
    }
    public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(1.0, 0.0, 'EUR', 'fixed'); }
    public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string { return ''; }
    public function cancel_label(string $w): bool { return false; }
    public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
    public function tracking_url(string $w): string { return ''; }
}
