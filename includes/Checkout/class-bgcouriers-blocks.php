<?php
defined('ABSPATH') || exit;

/**
 * The block checkout.
 *
 * WooCommerce has two checkouts. The classic one renders PHP templates and fires the hooks this plugin
 * was built on; the block one renders in React and talks to the Store API, which fires NONE of them.
 * Measured against WooCommerce 10.4.4 - `woocommerce_after_shipping_rate`,
 * `woocommerce_review_order_before_shipping`, `woocommerce_after_checkout_validation` and
 * `woocommerce_checkout_create_order` do not appear anywhere in `src/StoreApi/`.
 *
 * The consequence was not "the fields look wrong". It was that a customer could pick Speedy, have
 * nowhere to say WHICH office, place the order anyway - and the order reached the merchant carrying a
 * courier and no destination at all. Nothing refused it, because the refusal itself lives on a hook that
 * never fires. And the block checkout is what WooCommerce gives a new shop by default.
 *
 * This class re-attaches the two server-side halves to their Store API counterparts. It deliberately
 * calls the SAME methods the classic checkout calls, rather than reimplementing them: the selection has
 * always lived in the WooCommerce session (written by this plugin's own AJAX, not by checkout form
 * fields), and the session is the same object on both checkouts. So there is one set of rules about what
 * a valid destination is, and one place that copies it onto the order.
 */
class BGCouriers_Blocks {
    /** Shipping-method id prefix, the same one BGCouriers_Checkout uses. */
    private const RATE_PREFIX = 'bgcouriers_';

    /** @var BGCouriers_Checkout the classic-checkout controller, whose rules this reuses verbatim */
    private $checkout;

    public function __construct(BGCouriers_Checkout $checkout) {
        $this->checkout = $checkout;
        // Blocks the order while the destination is missing or belongs to another courier. Store API
        // surfaces whatever is added here to the customer and refuses to place the order.
        add_filter('woocommerce_store_api_cart_errors', [$this, 'validate'], 10, 2);
        add_filter('rest_request_before_callbacks', [$this, 'note_route'], 10, 3);
        // ...and writes the chosen courier, delivery type, town and office onto the order once it is
        // allowed through. Without this the order carries a shipping rate and nothing else.
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'persist'], 10, 2);
        // ...and once more when the order is fully built, in case WooCommerce's own address sync ran
        // over it in between - see ensure_address().
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'ensure_address']);
        add_action('wp_enqueue_scripts', [$this, 'assets'], 20);
        add_action('wp_ajax_bgcouriers_blocks_fields', [$this, 'ajax_fields']);
        add_action('wp_ajax_nopriv_bgcouriers_blocks_fields', [$this, 'ajax_fields']);
        // The block's way of being told "recalculate": see refresh() and bgc-blocks.js.
        add_action('woocommerce_blocks_loaded', [$this, 'register_refresh']);
        // The phone is required on the block for the same reason it is on the classic form: a courier
        // label needs a number to reach the recipient. See phone_required().
        add_filter('option_woocommerce_checkout_phone_field', [$this, 'phone_required']);
        // ...and WooCommerce's own street, town and postcode fields step aside for the courier's, as
        // they do on the classic form. See hide_address_fields().
        add_filter('woocommerce_get_country_locale', [$this, 'hide_address_fields'], 20);
    }

    /**
     * WooCommerce's address fields step aside for the courier's on the block too.
     *
     * The classic checkout drops WooCommerce's street, town, region and postcode fields when the plugin
     * owns the address (BGCouriers_Checkout::simplify_fields): the customer names the town and the
     * street or office in the courier's own fields, and persist() writes the order's address from
     * that. The block kept WooCommerce's fields, required - so a customer typed the town and the
     * street twice, once for WooCommerce and once for the courier, and whatever they typed the first
     * time was overwritten by the second (measured 2026-09-13 on dev: shipping-address_1, city and
     * postcode all required beside the courier's town and street).
     *
     * The block and the Store API both read a field's standing from the country locale, and both
     * honour `hidden` - the block does not render such a field, the Store API does not require it. So
     * they are hidden here, for every country, on the block page and on Store API requests, where the
     * plugin owns the address fields; the classic form is untouched (it never reads `hidden`, and has
     * already dropped the fields). The country stays: the shipping zone is matched on it.
     */
    public function hide_address_fields($locale) {
        if (!is_array($locale) || is_admin() || !class_exists('BGCouriers_Settings') || !BGCouriers_Settings::own_address_fields()) { return $locale; }
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $store_api = defined('REST_REQUEST') && REST_REQUEST && strpos($uri, '/wc/store/') !== false;
        if (!$store_api && !self::is_block_checkout()) { return $locale; }
        $hide = ['address_1', 'address_2', 'city', 'state', 'postcode'];
        if (!isset($locale['default'])) { $locale['default'] = []; }
        foreach (array_keys($locale) as $country) {
            foreach ($hide as $field) {
                $locale[$country][$field] = array_merge((array) ($locale[$country][$field] ?? []), ['hidden' => true, 'required' => false]);
            }
        }
        return $locale;
    }

    /**
     * The block's phone field is required, not "optional".
     *
     * The classic checkout makes the billing phone required through the checkout-fields filter, because
     * every courier label needs a number for the recipient. The block reads its phone field's standing
     * from the shop's `woocommerce_checkout_phone_field` setting instead - "optional" on a shop set up
     * with the defaults - so it printed "Phone (optional)", let the order go without one, and the
     * customer then read the plugin's refusal ("please enter a phone number so the courier can reach
     * the recipient") at the very end. Measured 2026-09-13 on dev. Required here, the block asks for it
     * where every other required field is asked for, and validates it itself.
     *
     * Front end and Store API only, and only where the plugin owns the address fields, as on the
     * classic form: the settings screen and the block editor keep showing what the merchant chose.
     */
    public function phone_required($value) {
        if (is_admin() || !class_exists('BGCouriers_Settings') || !BGCouriers_Settings::own_address_fields()) { return $value; }
        // The Store API (wc/store) is where the block reads the field's standing and validates the
        // order; the settings REST API the block editor reads is left alone, so the editor keeps
        // showing what the merchant chose.
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $store_api = defined('REST_REQUEST') && REST_REQUEST && strpos($uri, '/wc/store/') !== false;
        if (!$store_api && !self::is_block_checkout()) { return $value; }
        return 'required';
    }

    /**
     * The name under which the browser asks the block for a recalculation - see register_refresh().
     */
    public const REFRESH_NAMESPACE = 'bg-couriers';

    /**
     * A recalculation the browser can ask for.
     *
     * On the classic checkout every change to the delivery - the type, the town, the office - is saved to
     * the session and then `update_checkout` is fired, WooCommerce re-renders the order review with the
     * shipping rates priced for the new selection, and `updated_checkout` brings the pickers back to
     * life. The checkout BLOCK has no handler for `update_checkout` at all: nothing recalculated, and the
     * pickers stayed greyed out and dead (pointer-events none) from the first tab click - measured on
     * 2026-09-13, ten seconds and counting; the classic checkout was back in four. The only way through
     * was the combined map, which switches the rate itself.
     *
     * The Store API's way of letting a plugin ask for a recalculation is the cart/extensions endpoint:
     * the browser posts to it under a registered name, the callback runs, and the cart - shipping rates
     * included - comes back recalculated and the block re-renders from it. The callback has nothing to
     * do: the selection is already in the session, where the rates read it. Being registered is the
     * whole point.
     */
    public function register_refresh(): void {
        if (!function_exists('woocommerce_store_api_register_update_callback')) { return; }
        woocommerce_store_api_register_update_callback([
            'namespace' => self::REFRESH_NAMESPACE,
            'callback'  => static function ($data) {
                // Every rate the block shows is priced from the session's selection - and from the
                // payment method, which the classic checkout writes to the session on every
                // recalculation and the block keeps to itself until the order is placed. Cash on
                // delivery is a collection fee in the courier's price, so the block sends its choice
                // along and it is written where the rates read it, before they are priced.
                $pm = isset($data['payment_method']) ? sanitize_key((string) $data['payment_method']) : '';
                if ($pm !== '' && function_exists('WC') && WC()->session && WC()->payment_gateways()
                    && array_key_exists($pm, (array) WC()->payment_gateways()->get_available_payment_gateways())) {
                    WC()->session->set('chosen_payment_method', $pm);
                }
            },
        ]);
    }

    /**
     * Is the page being viewed built out of the checkout BLOCK rather than the shortcode?
     *
     * Public because BGCouriers_Checkout::assets() asks it too. That guard used to be is_checkout()
     * alone, which is true only for the page WooCommerce has been TOLD is the checkout - so a shop that
     * puts the checkout block on any other page got courier rates, no pickers and no explanation.
     */
    public static function is_block_checkout(): bool {
        if (!function_exists('has_block')) { return false; }
        $id = get_queried_object_id();
        // The main query may not have run yet - another plugin can read the country locale or the
        // address fields on an early hook (init, wp_loaded), and WC_Countries caches the locale on its
        // first read, so whatever answer we give then stands for the whole request. Before the query,
        // get_queried_object_id() is 0; resolve the requested URL to a page id ourselves, so the answer
        // does not depend on WHEN we are asked. See requested_page_id() and hide_address_fields().
        if (!$id) { $id = self::requested_page_id(); }
        return $id && has_block('woocommerce/checkout', $id);
    }

    /**
     * The page id the current request is FOR, worked out without the main query.
     *
     * is_block_checkout() reads get_queried_object_id(), which is 0 until WordPress has run the main
     * query - so a locale or address-field read on an early hook (another plugin's init handler is the
     * common one) would be told "not the block checkout", and because WC_Countries caches the locale on
     * its first read that wrong answer would stick for the whole request: WooCommerce's own street, town
     * and postcode fields would come back on the block checkout, the very double-address the locale hide
     * exists to stop (measured 2026-09-14 on dev - an init read of get_country_locale() unhid them). This
     * resolves the requested URL to a page id the way WordPress itself will, so the answer is the same
     * whenever it is asked. Memoised: is_block_checkout() has several callers, and this is a database
     * read most requests would otherwise repeat for nothing.
     */
    private static function requested_page_id(): int {
        static $cache = [];
        if (!function_exists('url_to_postid') || empty($_SERVER['REQUEST_URI'])) { return 0; }
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        if (array_key_exists($uri, $cache)) { return $cache[$uri]; }
        // The bare home URL resolves to 0; a shop that puts the checkout block on a static front page is
        // exactly that case, so the front-page id stands in for it.
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        if ($path === '' || $path === '/') { return $cache[$uri] = (int) get_option('page_on_front'); }
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $url  = $host !== '' ? ((is_ssl() ? 'https://' : 'http://') . $host . $uri) : $uri;
        return $cache[$uri] = (int) url_to_postid($url);
    }

    /**
     * The picker's own script, on top of everything the classic checkout already loads.
     *
     * `wc-blocks-checkout` is what exposes the slot this fills, and `wp-element` is what renders into it -
     * both are registered by WooCommerce itself, so there is no build step and no bundled framework here.
     */
    public function assets(): void {
        if (!self::is_block_checkout()) { return; }
        $js = BGCOURIERS_PATH . 'assets/js/bgc-blocks.js';
        // The checkout events package is what lets the order wait for the fields' flush; a WooCommerce
        // without it (before 9.x) still gets the pickers, without the wait.
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

    /**
     * The very same markup the classic checkout prints under each courier's rate row.
     *
     * Rendered here rather than rebuilt in React on purpose. The pickers are a few hundred lines of
     * behaviour - per-city availability, the office preload, the street search, the interactive map, the
     * per-courier memory - and a second implementation of them would drift from the first within a
     * release. What the block checkout is missing is a PLACE to put this markup, not the markup.
     *
     * The hidden shipping_method input travels with it: `chosenCourier()` in bgc-checkout.js already
     * falls back to one, which is the seam that lets every one of those behaviours work unchanged on a
     * checkout whose radio buttons it cannot see.
     */
    public function ajax_fields(): void {
        // The same nonce the classic checkout's own save uses, and verified rather than waved past: this
        // is a POST, and a POST that renders anything gets checked. It is also the reason the picker can
        // be trusted to be about THIS visitor's cart and no one else's.
        check_ajax_referer('bgcouriers_checkout', 'nonce');
        if (!function_exists('WC') || !WC()->cart) { wp_send_json_error(['html' => '']); }
        $chosen = isset($_POST['rate']) ? sanitize_text_field(wp_unslash($_POST['rate'])) : '';
        if (strpos($chosen, self::RATE_PREFIX) !== 0) { wp_send_json_success(['html' => '', 'courier' => '']); }
        $courier = substr(explode(':', $chosen)[0], strlen(self::RATE_PREFIX));

        // Shipping has to be worked out first. On an admin-ajax request nothing has asked for it, so
        // get_packages() answers with an empty array and the markup comes back blank - which looks
        // exactly like "this courier has no fields" and is why the picker rendered nothing at all.
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

    /**
     * @param \WP_Error $errors collected by the Store API; anything added here blocks the order
     * @param mixed     $cart   unused - every rule reads the session, exactly as the classic path does
     */
    public function validate($errors, $cart = null) {
        if (!is_wp_error($errors)) { return $errors; }
        // Only when the order is being PLACED. The Store API asks this filter on every cart read as
        // well - the cart block's page, the checkout block's first paint - and the answer is shown as a
        // red banner: a customer opening their cart read "please enter a phone number" and "please
        // choose your Speedy delivery point before placing the order" over a cart they had not begun
        // to check out (measured 2026-09-14 on dev, the cart block). The classic checkout says these
        // things when the button is pressed, and so does the block now.
        if (!self::placing_order()) { return $errors; }
        // The classic validator reads one thing from the posted form - the billing phone - and the rest
        // from the session. On the Store API there is no posted form, so hand it the customer's phone
        // from the object the Store API has already updated from the request.
        $phone = (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_billing_phone() : '';
        $this->checkout->validate(['billing_phone' => $phone], $errors);
        return $errors;
    }

    /** The REST route and method being served right now - the inner one, when the block batches. */
    private static $route = '';
    private static $method = '';

    /**
     * Note which REST request is being served. The block batches some of its requests through
     * wc/store/v1/batch, and inside a batch the server's request URI says "batch" whatever the inner
     * call is; the REST server hands each inner request through this filter with its own route.
     */
    public function note_route($response, $handler, $request) {
        if ($request instanceof \WP_REST_Request) {
            self::$route  = (string) $request->get_route();
            self::$method = strtoupper((string) $request->get_method());
        }
        return $response;
    }

    /** Is this the Store API request that places the order - a POST to wc/store/v1/checkout? */
    private static function placing_order(): bool {
        return self::$method === 'POST' && preg_match('#/wc/store/v1/checkout$#', self::$route) === 1;
    }

    /** @param \WC_Order $order the order the Store API has just built from the request */
    public function persist($order, $request = null): void {
        if ($order instanceof \WC_Order) { $this->checkout->persist($order); }
    }

    /**
     * The order's address is the courier selection, whatever WooCommerce copied over it.
     *
     * persist() writes the delivery meta AND the order's own address from the session. Seen once on
     * 2026-09-14 on dev: an order placed through the block carried the courier meta and an EMPTY
     * shipping address - WooCommerce's address fields are hidden on the block, so its customer object
     * holds blanks, and one of the Store API's own syncs of customer to order ran after ours. The
     * next run carried the address. Rather than depend on the order of two syncs inside WooCommerce,
     * the address is asserted again at the last hook before payment, and only where it is missing.
     */
    public function ensure_address($order): void {
        if (!$order instanceof \WC_Order || (string) $order->get_meta('_bgcouriers_courier') === '') { return; }
        if (trim((string) $order->get_shipping_address_1()) !== '' && trim((string) $order->get_shipping_city()) !== '') { return; }
        $this->checkout->persist($order);
        $order->save();
    }
}
