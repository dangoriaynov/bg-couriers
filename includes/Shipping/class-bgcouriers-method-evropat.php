<?php
defined('ABSPATH') || exit;

/**
 * Европът as a WooCommerce shipping method. Everything it does lives in
 * BGCouriers_Abstract_Method - the six of these were the same file, line for line.
 *
 * The class stays because WooCommerce stores its NAME (woocommerce_shipping_methods) and its method id
 * lives in every shipping zone a merchant has set up.
 */
class BGCouriers_Method_Evropat extends BGCouriers_Abstract_Method {
    public function __construct($instance_id = 0) {
        parent::__construct($instance_id, 'evropat', __('Европът', 'bg-couriers'));
    }
}
