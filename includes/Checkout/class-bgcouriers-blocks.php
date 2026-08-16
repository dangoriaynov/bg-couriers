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
        // ...and writes the chosen courier, delivery type, town and office onto the order once it is
        // allowed through. Without this the order carries a shipping rate and nothing else.
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'persist'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'assets'], 20);
        add_action('wp_ajax_bgcouriers_blocks_fields', [$this, 'ajax_fields']);
        add_action('wp_ajax_nopriv_bgcouriers_blocks_fields', [$this, 'ajax_fields']);
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
        return $id && has_block('woocommerce/checkout', $id);
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
        wp_enqueue_script(
            'bgc-blocks',
            BGCOURIERS_URL . 'assets/js/bgc-blocks.js',
            ['jquery', 'wp-element', 'wp-plugins', 'wc-blocks-checkout', 'bgc-checkout'],
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
        // The classic validator reads one thing from the posted form - the billing phone - and the rest
        // from the session. On the Store API there is no posted form, so hand it the customer's phone
        // from the object the Store API has already updated from the request.
        $phone = (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_billing_phone() : '';
        $this->checkout->validate(['billing_phone' => $phone], $errors);
        return $errors;
    }

    /** @param \WC_Order $order the order the Store API has just built from the request */
    public function persist($order, $request = null): void {
        if ($order instanceof \WC_Order) { $this->checkout->persist($order); }
    }
}
