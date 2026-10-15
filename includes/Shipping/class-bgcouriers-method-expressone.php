<?php
defined('ABSPATH') || exit;

/** Express One as a WooCommerce shipping method; all behaviour lives in BGCouriers_Abstract_Method. */
class BGCouriers_Method_Expressone extends BGCouriers_Abstract_Method {
    public function __construct($instance_id = 0) {
        parent::__construct($instance_id, 'expressone', __('Express One', 'bg-couriers'));
    }
}
