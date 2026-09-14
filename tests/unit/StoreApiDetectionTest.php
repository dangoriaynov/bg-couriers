<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-blocks.php';

/**
 * BGCouriers_Blocks::is_store_api_request() answers "is this request served by the WooCommerce Store
 * API?" - the block checkout's data-and-order-placement endpoint. The address-field hide and the
 * required phone both hang off it, so it has to answer the same whenever it is asked.
 *
 * It used to read `defined('REST_REQUEST') && REST_REQUEST`. REST_REQUEST is defined at parse_request,
 * AFTER init and wp_loaded - so a country-locale read on an early hook (another plugin's init handler,
 * the same trigger as the block-page double-address bug) saw it undefined and took a Store API request
 * for an ordinary one. WC_Countries caches the locale on its first read, so that one early wrong answer
 * stuck for the whole request: WooCommerce's own street/town/postcode were required on an order the
 * block never showed them on. The URL is the same whenever it is read, so the answer comes from the URL
 * alone now - and these tests run with REST_REQUEST deliberately UNDEFINED, the way the request is
 * before parse_request.
 *
 * @group core
 */
final class StoreApiDetectionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_unslash')->returnArg(1);
        // The two sanitizers with their REAL difference: sanitize_text_field strips every %xx octet
        // (WP's _sanitize_text_fields), esc_url_raw keeps percent-encoding. is_store_api_request()
        // must use the URL-preserving one, or the encoded plain-permalink form loses its slashes
        // before parse_str() can decode them - so these stubs let a wrong sanitizer fail the test.
        Functions\when('sanitize_text_field')->alias(static fn($s) => preg_replace('/%[a-f0-9]{2}/i', '', (string) $s));
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('rest_get_url_prefix')->justReturn('wp-json');
        Functions\when('wp_parse_url')->alias(static function ($url, $component = -1) {
            $parsed = parse_url((string) $url, $component);
            return $parsed === false ? '' : $parsed;
        });
    }
    protected function tearDown(): void {
        unset($_SERVER['REQUEST_URI']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /** THE FIX: a Store API request is recognised even before REST_REQUEST is defined. */
    public function test_a_pretty_permalink_store_api_request_is_recognised_without_rest_request(): void {
        $this->assertFalse(defined('REST_REQUEST'), 'guard: this test means to run with REST_REQUEST undefined');
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';
        $this->assertTrue(BGCouriers_Blocks::is_store_api_request());
    }

    /** The block batches some calls through wc/store/v1/batch; the outer URI still names wc/store. */
    public function test_a_store_api_batch_request_is_recognised(): void {
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/batch';
        $this->assertTrue(BGCouriers_Blocks::is_store_api_request());
    }

    /** A plain-permalink shop routes REST through ?rest_route=; the Store API arrives that way too. */
    public function test_a_plain_permalink_store_api_request_is_recognised(): void {
        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/wc/store/v1/cart';
        $this->assertTrue(BGCouriers_Blocks::is_store_api_request());
    }

    /**
     * The real plain-permalink shape: the block's api-fetch percent-encodes the rest_route value, so
     * the slashes arrive as %2F. This is the one that breaks under sanitize_text_field (it deletes the
     * %2F octets); esc_url_raw keeps them and parse_str decodes them back. The discriminating test.
     */
    public function test_a_plain_permalink_store_api_request_with_encoded_slashes_is_recognised(): void {
        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fwc%2Fstore%2Fv1%2Fcart';
        $this->assertTrue(BGCouriers_Blocks::is_store_api_request());
    }

    /** A REST prefix a shop has renamed away from wp-json is honoured. */
    public function test_a_renamed_rest_prefix_is_honoured(): void {
        Functions\when('rest_get_url_prefix')->justReturn('api');
        $_SERVER['REQUEST_URI'] = '/api/wc/store/v1/checkout';
        $this->assertTrue(BGCouriers_Blocks::is_store_api_request());
    }

    /** NEGATIVE: an ordinary front-end page is not the Store API. */
    public function test_a_front_end_page_is_not_the_store_api(): void {
        $_SERVER['REQUEST_URI'] = '/checkout/';
        $this->assertFalse(BGCouriers_Blocks::is_store_api_request());
    }

    /**
     * NEGATIVE, and the strictness the old substring test lacked: a front-end URL that merely CONTAINS
     * the text /wc/store/ in a query value (a redirect arg, say) is not a Store API request. The old
     * `strpos($uri, '/wc/store/')` would have matched it.
     */
    public function test_a_front_end_url_that_merely_contains_the_text_is_not(): void {
        $_SERVER['REQUEST_URI'] = '/my-account/?redirect_to=/wc/store/v1/checkout';
        $this->assertFalse(BGCouriers_Blocks::is_store_api_request());
    }

    /** NEGATIVE: the core REST namespace the block editor reads its settings from is left alone. */
    public function test_the_core_rest_api_is_not_the_store_api(): void {
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/settings/general';
        $this->assertFalse(BGCouriers_Blocks::is_store_api_request());
    }

    /** No request URI at all (CLI, cron): not the Store API. */
    public function test_no_request_uri_is_not_the_store_api(): void {
        unset($_SERVER['REQUEST_URI']);
        $this->assertFalse(BGCouriers_Blocks::is_store_api_request());
    }
}
