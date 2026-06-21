<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

interface BGC_Courier_Interface {
    public function id(): string;
    public function label(): string;
    /** @return string[] subset of ['address','office','automat','live_quote'] */
    public function capabilities(): array;
    public function check_credentials(): bool;
    /** @return array<int,array{city_id:int,name:string,name_lat:string,post_code:string,region:string}> */
    public function fetch_cities(): array;
    /** @return array<int,array{office_id:int,city_id:int,type:string,name:string,address:string}> */
    public function fetch_offices(int $city_id): array;
    public function quote(array $shipment): BGC_Quote;
    public function create_label(\WC_Order $order): BGC_Label;
    public function get_label_pdf(string $waybill): string;
    public function cancel_label(string $waybill): bool;
    public function track(string $waybill): BGC_Tracking;
    public function tracking_url(string $waybill): string;
}
