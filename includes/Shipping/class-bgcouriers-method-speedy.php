<?php
defined('ABSPATH') || exit;

/** Speedy as a WooCommerce shipping method; all behaviour lives in BGCouriers_Abstract_Method. */
class BGCouriers_Method_Speedy extends BGCouriers_Abstract_Method {
    public function __construct($instance_id = 0) {
        parent::__construct($instance_id, 'speedy', __('Speedy', 'bg-couriers'));
    }
}
