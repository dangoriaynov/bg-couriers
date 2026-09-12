<?php
defined('ABSPATH') || exit;

/**
 * "Somebody else is issuing this order's waybill right now." Not a failure: the shipment is being made,
 * by another request, and will be on the order by the time it lets go of its claim. It is its own class
 * so that a retry loop can tell it from a courier that refused, and not count it as an attempt that
 * failed - see BGCouriers_Labels::attempt_auto_label().
 */
class BGCouriers_Claimed_Exception extends BGCouriers_Api_Exception {}
