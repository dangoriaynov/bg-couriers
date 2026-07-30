<?php
defined('ABSPATH') || exit;

/**
 * A created waybill, plus anything the courier did NOT apply the way we asked for it.
 *
 * `problems` exists because a courier can accept a shipment and quietly drop part of it - Speedy's
 * cash-on-delivery service carries `ignoreIfNotApplicable`, which does exactly that by design, and the
 * waybill then prints with no money to collect. Nobody re-reads a printed label, so a mismatch between
 * what we sent and what the courier applied has to announce itself on the order.
 */
class BGCouriers_Label {
    public string $waybill;
    public string $pdf;
    /** @var string[] Human-readable mismatches between what we asked for and what the courier applied. */
    public array $problems;

    /** @param string[] $problems */
    public function __construct(string $waybill, string $pdf = '', array $problems = []) {
        $this->waybill  = $waybill;
        $this->pdf      = $pdf;
        $this->problems = $problems;
    }
}
