<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-labels.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-pip.php';

/**
 * The Print Invoices/Packing Lists document belongs to the shop, not to us: we add courier facts to it
 * and leave everything else exactly as PIP built it.
 */
final class PipDocumentTest extends TestCase {

    private function pip(): BGCouriers_PIP {
        return (new ReflectionClass('BGCouriers_PIP'))->newInstanceWithoutConstructor();
    }

    /**
     * An order shipped by anything other than our couriers must come out of the filter untouched. The
     * plugin is installed on shops that also ship by post, by their own van, or by a courier we do not
     * support - blanking their shipping line, or replacing it with an empty courier block, would damage
     * a document we were only asked to annotate.
     */
    public function test_order_without_one_of_our_couriers_keeps_pips_own_shipping_line(): void {
        $order = new WC_Order();
        $this->assertSame('Свободна доставка', $this->pip()->shipping_method('Свободна доставка', 'invoice', $order));
        $this->assertSame('', BGCouriers_PIP::courier_block($order));
    }

    /** Same for the totals row: no courier of ours, no change to the rows PIP assembled. */
    public function test_footer_rows_are_returned_as_built_when_the_courier_is_not_ours(): void {
        Monkey\setUp();
        Functions\when('wc_get_order')->justReturn(new WC_Order());
        $rows = ['shipping' => ['label' => 'Доставка:', 'value' => '5,00 лв.'], 'order_total' => ['label' => 'Общо:', 'value' => '25,00 лв.']];
        $out = $this->pip()->table_footer($rows, 'invoice', 42);
        Monkey\tearDown();
        $this->assertSame($rows, $out);
    }

    /** Nothing to annotate without an order, and nothing to break either. */
    public function test_non_order_input_passes_through(): void {
        $this->assertSame('Speedy', $this->pip()->shipping_method('Speedy', 'invoice', null));
        $this->assertSame([], $this->pip()->table_footer([], 'invoice', 0));
    }

    /**
     * The document's own layout - type sizes, column order, what the heading says - is the shop's
     * business. This plugin styles the elements it prints and nothing else, so that a courier plugin
     * cannot silently restyle the paperwork of every shop that installs it.
     */
    public function test_the_only_styling_shipped_is_for_our_own_elements(): void {
        ob_start();
        $this->pip()->print_styles();
        $css = ob_get_clean();

        foreach (['.document-heading', '.customer-address', '.company-title', '.invoice-header',
                  '.packing-list-header', '.document-colophon', '.customer-details'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $css, "$foreign belongs to PIP's document, not to us");
        }
        $this->assertStringContainsString('.bgc-pip', $css);
    }

    /** Column order and widths are the shop's call: no filter of ours may reorder someone's table. */
    public function test_no_hooks_on_pips_table_layout(): void {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-pip.php');
        foreach (['wc_pip_document_table_headers', 'wc_pip_document_column_widths',
                  'wc_pip_document_table_row_cells', 'wc_pip_document_heading'] as $hook) {
            $this->assertStringNotContainsString($hook, $source, "$hook lays out a document that is not ours");
        }
    }
}
