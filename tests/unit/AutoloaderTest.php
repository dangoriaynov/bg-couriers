<?php
use PHPUnit\Framework\TestCase;

/**
 * @group core
 */
final class AutoloaderTest extends TestCase {
    public function test_autoloader_constant_and_class(): void {
        if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
        require_once dirname(__DIR__, 2) . '/includes/class-bgcouriers-autoloader.php';
        $this->assertTrue(class_exists('BGCouriers_Autoloader'));
    }
}
