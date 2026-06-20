<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Quote {
    public float $price; public float $tax; public string $currency; public string $source;
    public function __construct(float $price, float $tax, string $currency, string $source) {
        $this->price = $price; $this->tax = $tax; $this->currency = $currency; $this->source = $source;
    }
    public function total(): float { return $this->price + $this->tax; }
}
