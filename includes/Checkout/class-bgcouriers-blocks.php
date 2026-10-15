<?php
defined('ABSPATH') || exit;

/** Block checkout: re-attaches the classic checkout's validate/persist to their Store API hooks, since the block fires none of the classic ones. */
class BGCouriers_Blocks {
    private const RATE_PREFIX = 'bgcouriers_';

    private $checkout;

    public function __construct(BGCouriers_Checkout $checkout) {
        $this->checkout = $checkout;
        add_filter('woocommerce_store_api_cart_errors', [$this, 'validate'], 10, 2);
        add_filter('rest_request_before_callbacks', [$this, 'note_route'], 10, 3);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'persist'], 10, 2);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'ensure_address']);
        add_action('wp_enqueue_scripts', [$this, 'assets'], 20);
        add_action('wp_ajax_bgcouriers_blocks_fields', [$this, 'ajax_fields']);
        add_action('wp_ajax_nopriv_bgcouriers_blocks_fields', [$this, 'ajax_fields']);
        add_action('woocommerce_blocks_loaded', [$this, 'register_refresh']);
        add_filter('option_woocommerce_checkout_phone_field', [$this, 'phone_required']);
        add_filter('woocommerce_get_country_locale', [$this, 'hide_address_fields'], 20);
    }

    /** Hides WooCommerce's own address fields on the block and the Store API: both honour `hidden` from the country locale, the classic form never reads it. */
    public function hide_address_fields($locale) {
        if (!is_array($locale) || is_admin() || !class_exists('BGCouriers_Settings') || !BGCouriers_Settings::own_address_fields()) { return $locale; }
        if (!self::serving_courier_checkout()) { return $locale; }
        $hide = ['address_1', 'address_2', 'city', 'state', 'postcode'];
        if (!isset($locale['default'])) { $locale['default'] = []; }
        foreach (array_keys($locale) as $country) {
            foreach ($hide as $field) {
                $locale[$country][$field] = array_merge((array) ($locale[$country][$field] ?? []), ['hidden' => true, 'required' => false]);
            }
        }
        return $locale;
    }

    /** The block reads the phone field's standing from this option, not from the checkout-fields filter the classic form uses. */
    public function phone_required($value) {
        if (is_admin() || !class_exists('BGCouriers_Settings') || !BGCouriers_Settings::own_address_fields()) { return $value; }
        // Store API only; the settings REST API the block editor reads is a different namespace.
        if (!self::serving_courier_checkout()) { return $value; }
        return 'required';
    }

    public const REFRESH_NAMESPACE = 'bg-couriers';

    /** Registers the cart/extensions callback the browser posts to for a recalculation: the block has no update_checkout. */
    public function register_refresh(): void {
        if (!function_exists('woocommerce_store_api_register_update_callback')) { return; }
        woocommerce_store_api_register_update_callback([
            'namespace' => self::REFRESH_NAMESPACE,
            'callback'  => static function ($data) {
                // The block keeps the payment method to itself until the order is placed; COD is a fee in the courier's price, so write it where the rates read it.
                $pm = isset($data['payment_method']) ? sanitize_key((string) $data['payment_method']) : '';
                if ($pm !== '' && function_exists('WC') && WC()->session && WC()->payment_gateways()
                    && array_key_exists($pm, (array) WC()->payment_gateways()->get_available_payment_gateways())) {
                    WC()->session->set('chosen_payment_method', $pm);
                }
            },
        ]);
    }

    public static function is_block_checkout(): bool {
        if (!function_exists('has_block')) { return false; }
        $id = get_queried_object_id();
        // Before the main query get_queried_object_id() is 0, and WC_Countries caches the locale on its first read, so resolve the URL ourselves.
        if (!$id) { $id = self::requested_page_id(); }
        return $id && has_block('woocommerce/checkout', $id);
    }

    private static function serving_courier_checkout(): bool {
        return self::is_store_api_request() || self::is_block_checkout();
    }

    /** Decided from the URL, not REST_REQUEST: that constant is undefined on init/wp_loaded, and a locale read there is cached for the whole request. */
    public static function is_store_api_request(): bool {
        if (empty($_SERVER['REQUEST_URI'])) { return false; }
        // esc_url_raw, not sanitize_text_field: the latter deletes %xx octets and would mangle ?rest_route=%2Fwc%2Fstore.
        $uri    = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
        $prefix = function_exists('rest_get_url_prefix') ? trim((string) rest_get_url_prefix(), '/') : 'wp-json';
        $path   = (string) wp_parse_url($uri, PHP_URL_PATH);
        if ($prefix !== '' && strpos($path, '/' . $prefix . '/wc/store/') !== false) { return true; }
        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);
        if ($query !== '') {
            $args = [];
            parse_str($query, $args);
            if (isset($args['rest_route']) && strpos((string) $args['rest_route'], '/wc/store/') === 0) { return true; }
        }
        return false;
    }

    /** The requested page id without the main query, so the answer is the same on any hook; memoised. */
    private static function requested_page_id(): int {
        static $cache = [];
        if (!function_exists('url_to_postid') || empty($_SERVER['REQUEST_URI'])) { return 0; }
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        if (array_key_exists($uri, $cache)) { return $cache[$uri]; }
        // Whole URL: url_to_postid() also reads ?page_id= / ?p=, so plain permalinks resolve too.
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $url  = $host !== '' ? ((is_ssl() ? 'https://' : 'http://') . $host . $uri) : $uri;
        $id = (int) url_to_postid($url);
        if ($id) { return $cache[$uri] = $id; }
        // Bare home path: the front page may hold the block; page_on_front can be stale after a switch back to a blog home.
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        if (($path === '' || $path === '/') && get_option('show_on_front') === 'page') {
            return $cache[$uri] = (int) get_option('page_on_front');
        }
        return $cache[$uri] = 0;
    }

    public function assets(): void {
        if (!self::is_block_checkout()) { return; }
        $js = BGCOURIERS_PATH . 'assets/js/bgc-blocks.js';
        // wc-blocks-checkout-events (WooCommerce 9.x+) lets the order wait for the fields' flush.
        $deps = ['jquery', 'wp-element', 'wp-plugins', 'wc-blocks-checkout', 'bgc-checkout'];
        if (wp_script_is('wc-blocks-checkout-events', 'registered')) { $deps[] = 'wc-blocks-checkout-events'; }
        wp_enqueue_script(
            'bgc-blocks',
            BGCOURIERS_URL . 'assets/js/bgc-blocks.js',
            $deps,
            is_file($js) ? (string) filemtime($js) : BGCOURIERS_VERSION,
            true
        );
    }

    /** The same markup the classic checkout prints under each rate row; the hidden shipping_method input is the seam bgc-checkout.js already reads. */
    public function ajax_fields(): void {
        check_ajax_referer('bgcouriers_checkout', 'nonce');
        if (!function_exists('WC') || !WC()->cart) { wp_send_json_error(['html' => '']); }
        $chosen = isset($_POST['rate']) ? sanitize_text_field(wp_unslash($_POST['rate'])) : '';
        if (strpos($chosen, self::RATE_PREFIX) !== 0) { wp_send_json_success(['html' => '', 'courier' => '']); }
        $courier = substr(explode(':', $chosen)[0], strlen(self::RATE_PREFIX));

        // On admin-ajax nothing has calculated shipping yet, so get_packages() would be empty and the markup blank.
        WC()->cart->calculate_shipping();

        $html = '';
        foreach (WC()->shipping()->get_packages() as $package) {
            foreach ((array) ($package['rates'] ?? []) as $rate) {
                if (!is_object($rate) || !method_exists($rate, 'get_method_id')) { continue; }
                if (substr($rate->get_method_id(), strlen(self::RATE_PREFIX)) !== $courier) { continue; }
                ob_start();
                $this->checkout->render_fields($rate, 0);
                $html .= (string) ob_get_clean();
            }
        }
        if ($html === '') { wp_send_json_success(['html' => '', 'courier' => $courier]); }
        $html = '<input type="hidden" name="shipping_method[0]" value="' . esc_attr($chosen) . '">'
            . BGCouriers_Checkout::allmap_button_for_blocks() . $html;
        wp_send_json_success(['html' => $html, 'courier' => $courier]);
    }

    public function validate($errors, $cart = null) {
        if (!is_wp_error($errors)) { return $errors; }
        // Only when placing the order: the Store API asks this on every cart read too and shows the answer as a banner.
        if (!self::placing_order()) { return $errors; }
        $phone = (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_billing_phone() : '';
        $this->checkout->validate(['billing_phone' => $phone], $errors);
        return $errors;
    }

    private static $route = '';
    private static $method = '';

    /** Inside a wc/store/v1/batch the request URI says "batch"; each inner request passes here with its own route. */
    public function note_route($response, $handler, $request) {
        if ($request instanceof \WP_REST_Request) {
            self::$route  = (string) $request->get_route();
            self::$method = strtoupper((string) $request->get_method());
        }
        return $response;
    }

    private static function placing_order(): bool {
        return self::$method === 'POST' && preg_match('#/wc/store/v1/checkout$#', self::$route) === 1;
    }

    public function persist($order, $request = null): void {
        if ($order instanceof \WC_Order) { $this->checkout->persist($order); }
    }

    /** Re-asserts the address at the last hook before payment: a Store API customer-to-order sync can run after persist() and blank it. */
    public function ensure_address($order): void {
        if (!$order instanceof \WC_Order || (string) $order->get_meta('_bgcouriers_courier') === '') { return; }
        if (trim((string) $order->get_shipping_address_1()) !== '' && trim((string) $order->get_shipping_city()) !== '') { return; }
        $this->checkout->persist($order);
        $order->save();
    }
}
