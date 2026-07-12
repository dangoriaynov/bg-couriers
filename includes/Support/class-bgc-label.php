<?php
defined('ABSPATH') || exit;
class BGC_Label { public string $waybill; public string $pdf;
    public function __construct(string $waybill, string $pdf = '') { $this->waybill = $waybill; $this->pdf = $pdf; } }
