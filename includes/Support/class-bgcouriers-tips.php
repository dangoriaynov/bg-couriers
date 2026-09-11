<?php
defined('ABSPATH') || exit;

/**
 * The hover hints (data-tip): on the admin order screen, in the orders list, and beside the courier
 * rates on the cart and the checkout.
 *
 * One bubble on <body>, positioned in JS - see assets/js/bgc-tip.js for why it is not CSS any more.
 * Every screen enqueues through here so they never drift apart again.
 */
class BGCouriers_Tips {
    public static function enqueue(): void {
        $css = BGCOURIERS_PATH . 'assets/css/bgc-tip.css';
        $js  = BGCOURIERS_PATH . 'assets/js/bgc-tip.js';
        wp_enqueue_style('bgc-tip', BGCOURIERS_URL . 'assets/css/bgc-tip.css', [], is_file($css) ? (string) filemtime($css) : BGCOURIERS_VERSION);
        wp_enqueue_script('bgc-tip', BGCOURIERS_URL . 'assets/js/bgc-tip.js', [], is_file($js) ? (string) filemtime($js) : BGCOURIERS_VERSION, true);
    }
}
