<?php
/**
 * Shared WC_Order stand-in for the unit suite (no WordPress, no WooCommerce). Only the methods our code
 * actually calls. Kept in one place so tests cannot each define a different partial version - whichever
 * loaded first would then decide what the others can test.
 */
if (!class_exists('WC_Order')) {
    class WC_Order {
        public array $meta = [];
        public string $status = 'processing';
        public array $items = [];
        public float $total = 0.0;
        public float $shipping_total = 0.0;
        public string $payment_method = 'cod';
        public string $name = 'Иван Иванов';
        public string $phone = '0888123456';
        /** @var array{0:string,1:string}|null */
        public $transition = null;
        public array $notes = [];

        public function get_meta($k) { return $this->meta[$k] ?? ''; }
        public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
        public function delete_meta_data($k) { unset($this->meta[$k]); }
        public function get_status() { return $this->status; }
        public function update_status($s, $note = '') { $this->status = $s; $this->transition = [$s, $note]; }
        public function add_order_note($n) { $this->notes[] = $n; }
        public function save() {}
        public function get_items() { return $this->items; }
        public function get_total() { return $this->total; }
        public function get_shipping_total() { return $this->shipping_total; }
        public function get_payment_method() { return $this->payment_method; }
        public function get_formatted_billing_full_name() { return $this->name; }
        public function get_billing_phone() { return $this->phone; }
    }
}
if (!class_exists('WC_Order_Item_Stub')) {
    class WC_Order_Item_Stub {
        public function __construct(private int $pid, private int $qty, private float $tot) {}
        public function get_product_id() { return $this->pid; }
        public function get_quantity() { return $this->qty; }
        public function get_total() { return $this->tot; }
        public function get_product() { return null; }
    }
}
