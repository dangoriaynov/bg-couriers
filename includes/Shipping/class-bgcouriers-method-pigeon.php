<?php
defined('ABSPATH') || exit;

/** Pigeon Express as a WooCommerce shipping method; all behaviour lives in BGCouriers_Abstract_Method. */
class BGCouriers_Method_Pigeon extends BGCouriers_Abstract_Method {
    public function __construct($instance_id = 0) {
        parent::__construct($instance_id, 'pigeon', __('Pigeon Express', 'bg-couriers'));
    }
}
