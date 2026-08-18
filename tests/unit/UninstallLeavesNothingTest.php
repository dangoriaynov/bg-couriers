<?php
use PHPUnit\Framework\TestCase;

/**
 * Deleting the plugin used to leave 183 options, three tables and the shipping-zone rows behind on a
 * real shop - so "install it again from scratch" was not something a merchant could do without SQL.
 *
 * uninstall.php is the only file WordPress runs in that moment, and it runs ALONE: none of the plugin's
 * classes are loaded, so anything it names has to be written out. That is exactly what rots - a table
 * added to the schema, or a cron hook added to a class, and this file silently stops covering it. These
 * checks read both sides and compare.
 *
 * @group core
 */
final class UninstallLeavesNothingTest extends TestCase {
    private function uninstall(): string {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
    }

    public function test_it_refuses_to_run_outside_an_uninstall(): void {
        $this->assertStringContainsString("defined('WP_UNINSTALL_PLUGIN') || exit;", $this->uninstall());
    }

    /** Every table the schema creates must be dropped - the sweep is by prefix, so it covers new ones too. */
    public function test_every_table_the_schema_creates_is_dropped(): void {
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-schema.php');
        preg_match_all('/\{\$p\}(bgcouriers_[a-z_]+)/', $schema, $m);
        $this->assertNotEmpty($m[1], 'the schema should create at least one table');
        $u = $this->uninstall();
        $this->assertStringContainsString("SHOW TABLES LIKE '{\$wpdb->prefix}bgcouriers\\_%'", $u,
            'tables are dropped by prefix sweep, which covers every one the schema makes');
        $this->assertStringContainsString('DROP TABLE IF EXISTS', $u);
    }

    /** Every scheduled hook the plugin books must be cleared, or a deleted plugin keeps waking WP-Cron. */
    public function test_every_cron_hook_is_cleared(): void {
        $hooks = [];
        $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/includes'));
        foreach ($dir as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
            if (preg_match_all("/const\s+[A-Z_]*HOOK\s*=\s*'([a-z_]+)'/", (string) file_get_contents($f->getPathname()), $m)) {
                foreach ($m[1] as $h) { $hooks[$h] = true; }
            }
        }
        $this->assertNotEmpty($hooks, 'the plugin should declare at least one cron hook');
        $u = $this->uninstall();
        foreach (array_keys($hooks) as $hook) {
            $this->assertStringContainsString("'" . $hook . "'", $u, $hook . ' is scheduled but never cleared on uninstall');
        }
    }

    public function test_it_removes_the_settings_and_the_zone_rows(): void {
        $u = $this->uninstall();
        $this->assertStringContainsString("option_name LIKE 'bgcouriers\\_%'", $u);
        $this->assertStringContainsString("woocommerce\\_bgcouriers\\_%\\_settings", $u, 'the per-instance method settings too');
        $this->assertStringContainsString('woocommerce_shipping_zone_methods', $u);
    }

    /** The label PDFs carry a name, an address and a phone number - they go with the plugin. */
    public function test_it_removes_the_label_pdfs(): void {
        $this->assertStringContainsString('bgc-labels', $this->uninstall());
    }

    /** Orders are the shop's own record of what shipped. An uninstall must not rewrite that history. */
    public function test_it_leaves_the_orders_alone(): void {
        $u = $this->uninstall();
        $this->assertStringNotContainsString('_bgcouriers_waybill', $u);
        $this->assertStringNotContainsString('postmeta', $u);
        $this->assertStringNotContainsString('wc_orders', $u);
    }
}
