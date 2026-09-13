<?php
use PHPUnit\Framework\TestCase;

/**
 * The "track this parcel" link the customer is given, per courier - a page that exists, with the number
 * in it. Two of these were dead on 2026-09-13 and nothing had noticed: BOX NOW's pointed at a host that
 * does not resolve, Sameday's at a 404 page. Every one below was opened in a browser with a real
 * waybill of that courier on that day (Speedy, Econt and Pigeon showed the parcel; BOX NOW's and
 * Sameday's pages showed it once repointed). Европът's page takes no number - see its own docblock.
 *
 * @group core
 */
final class TrackingLinksTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); \Brain\Monkey\setUp();
        \Brain\Monkey\Functions\when('__')->returnArg(1);
        // Sameday's constructor reads a setting (which of its hosts to talk to); when another file has
        // already defined get_option for the process this passes anyway, which is not a reason to skip it.
        \Brain\Monkey\Functions\when('get_option')->alias(static function ($k, $d = '') { return $d; });
    }
    protected function tearDown(): void { \Brain\Monkey\tearDown(); parent::tearDown(); }

    public function test_every_link_is_the_page_that_exists_with_the_number_in_it(): void {
        $this->assertSame('https://www.speedy.bg/en/track-shipment?shipmentNumber=63740770880', (new BGCouriers_Speedy([]))->tracking_url('63740770880'));
        $this->assertSame('https://www.econt.com/en/services/track-shipment/1055247918969', (new BGCouriers_Econt([]))->tracking_url('1055247918969'));
        $this->assertSame('https://track.pigeonexpress.com/?tracking_number=458543185311', (new BGCouriers_Pigeon([]))->tracking_url('458543185311'));
        $this->assertSame('https://sameday.bg/status-na-pratkata/?awb=1CJALN20234067', (new BGCouriers_Sameday([]))->tracking_url('1CJALN20234067'));
        $this->assertSame('https://boxnow.bg/track?track=0960382208', (new BGCouriers_Boxnow([]))->tracking_url('0960382208'));
        $this->assertSame('https://expressone.bg/bg/tracking/29803940', (new BGCouriers_Expressone([]))->tracking_url('29803940'));
        $this->assertSame('https://evropat.bg/track/', (new BGCouriers_Evropat([]))->tracking_url('123'));
    }

    /** A number is a URL component, whatever it holds. */
    public function test_the_number_is_encoded(): void {
        $this->assertStringEndsWith('awb=1CJ%2F1', (new BGCouriers_Sameday([]))->tracking_url('1CJ/1'));
        $this->assertStringEndsWith('track=a%20b', (new BGCouriers_Boxnow([]))->tracking_url('a b'));
    }
}
