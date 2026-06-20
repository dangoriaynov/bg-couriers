<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;
class BGC_Tracking { public string $waybill; public string $status; public array $events;
    public function __construct(string $waybill, string $status, array $events = []) {
        $this->waybill = $waybill; $this->status = $status; $this->events = $events; } }
