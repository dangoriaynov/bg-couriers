<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-blocks.php';

/**
 * BGCouriers_Blocks::is_block_checkout() answers "is this request the checkout block?" - the hide of
 * WooCommerce's own address fields, the required phone and the pickers all hang off it.
 *
 * It used to read get_queried_object_id() alone. That is 0 until WordPress has run the main query, so
 * anything that reads the country locale or the address fields on an early hook (init, wp_loaded - a
 * common thing for another plugin to do) was told "not the block checkout". WC_Countries caches the
 * locale on its first read, so that one early wrong answer stuck for the whole request and WooCommerce's
 * street, town and postcode fields came back on the block checkout - the double-address the hide exists
 * to stop (measured 2026-09-14 on dev). The fix resolves the requested URL to a page id when the query
 * is not ready yet, so the answer is the same whenever it is asked, and stays page-scoped: it must be
 * true ONLY for the page that actually carries the block, never for My Account or the cart.
 *
 * @group core
 */
final class BlockCheckoutDetectionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('wp_parse_url')->alias(static function ($url, $component = -1) {
            $parsed = parse_url((string) $url, $component);
            return $parsed === false ? '' : $parsed;
        });
        // The block lives on some pages, not others - has_block is true only for those page ids.
        Functions\when('has_block')->alias(static function ($block, $id) {
            return $block === 'woocommerce/checkout' && in_array((int) $id, [55, 77, 99], true);
        });
        $_SERVER['HTTP_HOST'] = 'shop.example';
    }

    protected function tearDown(): void {
        unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /** The ordinary case: the query has run, the queried page carries the block. */
    public function test_a_queried_block_page_is_the_block_checkout(): void {
        Functions\when('get_queried_object_id')->justReturn(55);
        $_SERVER['REQUEST_URI'] = '/whatever-a/';
        $this->assertTrue(BGCouriers_Blocks::is_block_checkout());
    }

    /** The query has run and the queried page does not carry the block. */
    public function test_a_queried_ordinary_page_is_not(): void {
        Functions\when('get_queried_object_id')->justReturn(60);
        $_SERVER['REQUEST_URI'] = '/whatever-b/';
        $this->assertFalse(BGCouriers_Blocks::is_block_checkout());
    }

    /**
     * THE FIX. The query has NOT run (early hook: get_queried_object_id() is 0), but the requested URL
     * resolves to a page that carries the block - so it is still recognised as the block checkout.
     */
    public function test_before_the_query_the_url_still_finds_the_block(): void {
        Functions\when('get_queried_object_id')->justReturn(0);
        Functions\when('url_to_postid')->alias(static function ($url) {
            return strpos((string) $url, '/checkout-blk-c/') !== false ? 77 : 0;
        });
        $_SERVER['REQUEST_URI'] = '/checkout-blk-c/?ver=1';
        $this->assertTrue(BGCouriers_Blocks::is_block_checkout());
    }

    /**
     * NEGATIVE CONTROL. Query not ready, and the requested URL is a page WITHOUT the block (My Account,
     * cart). It must stay false - the hide is page-scoped, not "every front-end request", so those
     * pages keep their own address fields.
     */
    public function test_before_the_query_a_non_block_url_is_not(): void {
        Functions\when('get_queried_object_id')->justReturn(0);
        Functions\when('url_to_postid')->alias(static function ($url) {
            return strpos((string) $url, '/my-account-d/') !== false ? 88 : 0;
        });
        $_SERVER['REQUEST_URI'] = '/my-account-d/edit-address/shipping/';
        $this->assertFalse(BGCouriers_Blocks::is_block_checkout());
    }

    /**
     * The front-page edge. url_to_postid() returns 0 for the bare home URL, so a shop that puts the
     * checkout block on a static front page is covered through page_on_front.
     */
    public function test_before_the_query_the_front_page_is_resolved(): void {
        Functions\when('get_queried_object_id')->justReturn(0);
        Functions\when('url_to_postid')->justReturn(0);
        Functions\when('get_option')->alias(static function ($name) {
            return $name === 'page_on_front' ? 99 : false;
        });
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertTrue(BGCouriers_Blocks::is_block_checkout());
    }
}
