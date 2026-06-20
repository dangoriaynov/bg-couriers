<?php
defined('ABSPATH') || exit;

class BGC_Plugin {
    private static $instance;
    public static function instance(): self {
        return self::$instance ??= new self();
    }
    private function __construct() {}
}
