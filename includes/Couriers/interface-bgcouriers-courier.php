<?php
defined('ABSPATH') || exit;

interface BGCouriers_Courier_Interface {
    public function id(): string;
    public function label(): string;
    /** @return string[] subset of ['address','office','automat','live_quote'] */
    public function capabilities(): array;
    /** True when the courier accepts the saved credentials; a worded refusal is thrown as BGCouriers_Api_Exception. */
    public function check_credentials(): bool;
    /** @return array<int,array{city_id:int,name:string,name_lat:string,post_code:string,region:string}> */
    public function fetch_cities(): array;
    /** @return array<int,array{office_id:int,city_id:int,type:string,name:string,address:string}> */
    public function fetch_offices(int $city_id): array;
    public function quote(array $shipment): BGCouriers_Quote;
    public function create_label(\WC_Order $order): BGCouriers_Label;
    /** @return string[] paper formats available on demand (e.g. ['A6','A4']); [] = one fixed native format. */
    public function label_formats(): array;
    /** @param string $format 'A6'/'A4'; '' = the courier's native format. */
    public function get_label_pdf(string $waybill, string $format = ''): string;
    /** True when the shipment is cancelled or already gone at the courier; a worded refusal is thrown as BGCouriers_Api_Exception. */
    public function cancel_label(string $waybill): bool;
    public function track(string $waybill): BGCouriers_Tracking;
    public function tracking_url(string $waybill): string;
}
