<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

abstract class BGC_Abstract_Courier implements BGC_Courier_Interface {
    /** POST JSON, parse JSON, one retry, throw on failure. */
    protected function post_json(string $url, array $body): array {
        $last = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $res = $this->http_post($url, $body);
            if (is_wp_error($res)) { $last = 'transport error'; continue; }
            $code = (int) wp_remote_retrieve_response_code($res);
            $raw  = (string) wp_remote_retrieve_body($res);
            if ($code === 200) {
                $data = json_decode($raw, true);
                if (!is_array($data)) { throw new BGC_Api_Exception('Invalid JSON from ' . $url); }
                return $data;
            }
            $last = 'HTTP ' . $code . ': ' . substr($raw, 0, 200);
        }
        throw new BGC_Api_Exception('Request failed: ' . $last);
    }

    /** Seam: overridden in tests; real impl calls wp_remote_post. */
    protected function http_post(string $url, array $body) {
        return wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
    }
}
