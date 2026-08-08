<?php
use PHPUnit\Framework\TestCase;

/**
 * The plugin does not print anybody's documents.
 *
 * Invoices and packing lists are printed by whatever the shop chose for that - Print Invoices/Packing
 * Lists, one of the PDF-invoice plugins, or nothing at all - and what goes on that sheet, where, and at
 * what size is a decision belonging to whoever prints it. A shop that wants the courier on its document
 * reads it from the public entry points below; that code lives with the printing plugin, not here.
 *
 * This test exists because the integration WAS here once and grew from "say which courier" into type
 * sizes, column widths and heading wording for a document the plugin does not own.
 */
final class PipDocumentTest extends TestCase {

    /** @return string[] every PHP file that ships in the package */
    private function shipped_sources(): array {
        $root  = dirname(__DIR__, 2) . '/includes';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $out   = [];
        foreach ($files as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && strpos($f->getPathname(), '/lib/') === false) {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    /**
     * Not one hook belonging to a document plugin. Adding one means taking responsibility for a page
     * every other shop prints differently - which is exactly what had to be undone.
     */
    public function test_the_plugin_hooks_nothing_that_belongs_to_a_document_plugin(): void {
        $foreign = ['wc_pip_', 'wpo_wcpdf_', 'wf_woocommerce_packing_list', 'yith_ywpi_'];
        foreach ($this->shipped_sources() as $file) {
            $source = (string) file_get_contents($file);
            foreach ($foreign as $prefix) {
                $this->assertStringNotContainsString(
                    $prefix,
                    $source,
                    basename($file) . " hooks $prefix* - printing is the document plugin's job, not ours"
                );
            }
        }
    }

    /**
     * What a document plugin needs from us instead: the courier of an order, its logo, the name of the
     * delivery method and the waybill. These are the entry points such code calls, so they may not
     * quietly become private or change shape.
     */
    public function test_the_courier_facts_a_printing_plugin_needs_stay_public(): void {
        require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-labels.php';
        require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
        require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-icons.php';

        foreach ([['BGCouriers_Labels', 'order_courier'], ['BGCouriers_Couriers', 'logo_url'],
                  ['BGCouriers_Icons', 'method_label']] as [$class, $method]) {
            $m = new ReflectionMethod($class, $method);
            $this->assertTrue($m->isPublic() && $m->isStatic(), "$class::$method() must stay public static");
        }
    }
}
