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
