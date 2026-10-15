<?php
defined('ABSPATH') || exit;

/** Another request is issuing this order's waybill right now; not a failure, so retry loops must not count it. */
class BGCouriers_Claimed_Exception extends BGCouriers_Api_Exception {}
