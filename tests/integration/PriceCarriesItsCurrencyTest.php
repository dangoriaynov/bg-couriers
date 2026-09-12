<?php
/**
 * A cached price is a number AND the unit it is in. Reading it back without the unit is reading a
 * different number.
 *
 * Every price this plugin caches is quoted in the shop's currency, and the currency is threaded all the
 * way down: each courier's quote() is handed $shipment['currency'] and answers in it. Then the answer is
 * put away somewhere that does not ask:
 *
 *  - the checkout quote (transient bgcouriers_q_*) is keyed on courier, method, city, weight, COD and
 *    country - every single thing that changes the price except the unit it is measured in. It stores
 *    the currency beside the number and never compares it.
 *  - the reference price by weight (bgcouriers_ref_*) does not even record one.
 *  - the daily reference table has a `currency` COLUMN, BGCouriers_Rates::set writes it, and
 *    BGCouriers_Rates::get has never once read it.
 *
 * So a shop that changes its currency serves prices in the old one until each cache expires - three
 * hours for the two transients, and until the next sync for the table. Bulgaria's shops are changing
 * from lev to euro, a rate of 1.95583, which turns a cached 7.50 into a checkout that asks for 7.50
 * euro for a 3.83 euro delivery. The same holds for a shop that offers two currencies at once: whoever
 * asks first decides what everyone else is charged until the cache expires.
 *
 * This does not test that the keys differ - they do, trivially, once a currency is in them. It changes
 * the shop's currency and asks what the customer is charged.
 *
 * @group core
 */
final class PriceCarriesItsCurrencyTest extends WP_UnitTestCase {
    /** @var string */
    private $currency = 'BGN';

    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
        // The shop's currency, as a currency switcher or a changeover would leave it: one value now,
        // another value later, from WooCommerce's own accessor.
        add_filter('woocommerce_currency', function () { return $this->currency; }, 99);
        foreach (['bgcouriers_speedy_office_price_mode' => 'live', 'bgcouriers_speedy_office_price' => '0'] as $k => $v) {
            update_option($k, $v);
        }
    }
    public function tear_down() {
        remove_all_filters('woocommerce_currency');
        parent::tear_down();
    }

    /** A courier that answers with whatever the shop asked to be quoted in, like the real ones do. */
    private function courier(float $price) {
        return new class($price) extends BGCouriers_Abstract_Courier {
            public function __construct(public float $price) {}
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Stub'; }
            public function capabilities(): array { return ['office', 'address', 'live_quote']; }
            public function available_methods(): array { return $this->capabilities(); }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $city_id): array { return []; }
            public function quote(array $s): BGCouriers_Quote {
                return new BGCouriers_Quote($this->price, 0.0, (string) ($s['currency'] ?? ''), 'live');
            }
            public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $w, string $f = ''): string { return ''; }
            public function cancel_label(string $w): bool { return false; }
            public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
            public function tracking_url(string $w): string { return ''; }
        };
    }

    /**
     * The harm, in the order it happens: a delivery priced at 7.50 lev, the shop switches to euro, and
     * the next customer is asked for 7.50 of them.
     */
    public function test_the_checkout_quote_is_not_served_in_last_weeks_currency(): void {
        $this->currency = 'BGN';
        $lev = BGCouriers_Pricing::checkout_quote($this->courier(7.50), 'office', 41, 100, ['weight_kg' => 1.0], 'BGN');
        $this->assertEqualsWithDelta(7.50, $lev->price, 0.01, 'quoted in lev, to begin with');
        $this->assertSame('BGN', $lev->currency);

        // The shop is in euro now. 7.50 lev is 3.83 euro; the courier says so when it is asked.
        $this->currency = 'EUR';
        $euro = BGCouriers_Pricing::checkout_quote($this->courier(3.83), 'office', 41, 100, ['weight_kg' => 1.0], 'EUR');

        $this->assertSame('EUR', $euro->currency, 'the price handed to the checkout says what it is in');
        $this->assertEqualsWithDelta(3.83, $euro->price, 0.01,
            'the customer is charged 3.83 euro, not the 7.50 that was a lev price');
    }

    /** And back the other way, so it cannot pass because one direction happens to suit it. */
    public function test_and_the_same_when_the_currency_changes_back(): void {
        $this->currency = 'EUR';
        BGCouriers_Pricing::checkout_quote($this->courier(3.83), 'office', 55, 100, ['weight_kg' => 1.0], 'EUR');

        $this->currency = 'BGN';
        $lev = BGCouriers_Pricing::checkout_quote($this->courier(7.50), 'office', 55, 100, ['weight_kg' => 1.0], 'BGN');
        $this->assertEqualsWithDelta(7.50, $lev->price, 0.01);
    }

    /**
     * And the read itself asks, not only the key.
     *
     * The key is what keeps two currencies apart in practice, but a key is a convention and a cache
     * entry is data: this one records which currency it is in, so the reader can simply check. Put an
     * entry in by hand under the key a euro quote would use, with a lev number inside it, and the
     * checkout must not serve it. That is the same reasoning that put the office guard in cacheSet
     * rather than at its call sites - the invariant belongs where the value is read, not in the shape
     * of whatever built the key.
     */
    public function test_an_entry_that_disagrees_with_its_own_key_is_not_used(): void {
        $this->currency = 'EUR';
        // Warm the cache properly, so the key this test needs is the one the code really uses.
        BGCouriers_Pricing::checkout_quote($this->courier(3.83), 'office', 77, 100, ['weight_kg' => 1.0], 'EUR');

        // Now corrupt it the way a caller building the key some other way would.
        $key = 'bgcouriers_q_speedy_office_77_1_eur';
        $this->assertIsArray(get_transient($key), 'the key this test relies on is the one in use');
        set_transient($key, ['p' => 7.50, 't' => 0.0, 'c' => 'BGN'], 3 * HOUR_IN_SECONDS);

        $q = BGCouriers_Pricing::checkout_quote($this->courier(3.83), 'office', 77, 100, ['weight_kg' => 1.0], 'EUR');
        $this->assertEqualsWithDelta(3.83, $q->price, 0.01, 'a lev entry is not a euro price, whatever it is filed under');
    }

    /**
     * The daily reference table holds ONE row per courier and method - the schema says so, a unique key
     * on (courier, method) - so it cannot carry both currencies at once. A row in a currency the shop no
     * longer uses is therefore not a price: it is no reference at all, and the merchant's own configured
     * price is what should be shown instead of a number in the wrong unit.
     */
    public function test_a_reference_row_in_another_currency_is_not_a_price(): void {
        BGCouriers_Rates::set('speedy', 'office', 7.50, 'BGN');
        $this->currency = 'EUR';

        $this->assertNull(BGCouriers_Rates::get('speedy', 'office', 'EUR'),
            'a lev row is not a euro price');
        $this->assertEqualsWithDelta(7.50, (float) BGCouriers_Rates::get('speedy', 'office', 'BGN'), 0.01,
            'and it is still exactly the lev price it always was');
    }

    /** What the cart and the settings screen show comes from that table, so it must not show it either. */
    public function test_the_estimate_shown_before_a_town_is_chosen_is_in_todays_currency(): void {
        BGCouriers_Rates::set('speedy', 'office', 7.50, 'BGN');
        update_option('bgcouriers_speedy_office_price', '4.20');   // what the merchant configured
        $this->currency = 'EUR';

        $this->assertEqualsWithDelta(4.20, (float) BGCouriers_Pricing::estimate('speedy', 'office'), 0.01,
            'the configured price, not a lev figure wearing a euro sign');
    }
}
