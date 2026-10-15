<?php
defined('ABSPATH') || exit;

/** Sameday as a WooCommerce shipping method; all behaviour lives in BGCouriers_Abstract_Method. */
class BGCouriers_Method_Sameday extends BGCouriers_Abstract_Method {
    public function __construct($instance_id = 0) {
        parent::__construct($instance_id, 'sameday', __('Sameday', 'bg-couriers'));
    }
}
