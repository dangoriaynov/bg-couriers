<?php
defined('ABSPATH') || exit;

/** A created waybill, plus what the courier did not apply the way it was asked for. */
class BGCouriers_Label {
    public string $waybill;
    public string $pdf;
    /** @var string[] */
    public array $problems;

    /** @param string[] $problems */
    public function __construct(string $waybill, string $pdf = '', array $problems = []) {
        $this->waybill  = $waybill;
        $this->pdf      = $pdf;
        $this->problems = $problems;
    }
}
