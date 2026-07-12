<?php
defined('ABSPATH') || exit;

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
                if (!is_array($data)) { throw new BGC_Api_Exception(esc_html('Invalid JSON from ' . $url)); }
                return $data;
            }
            $last = 'HTTP ' . $code . ': ' . substr($raw, 0, 1000); // keep enough of the body for field-level API errors
        }
        throw new BGC_Api_Exception(esc_html('Request failed: ' . $last));
    }

    /** Seam: overridden in tests; real impl calls wp_remote_post. */
    protected function http_post(string $url, array $body) {
        return wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
    }

    /**
     * Crucial-settings check run before this courier may be enabled. Returns a list of problems, each
     * ['msg' => what is wrong, 'fix' => how to resolve it]; an empty list means it is ready to enable.
     * Reads SAVED options - the merchant should Save (and Validate credentials) before enabling.
     * Couriers override this to add their own required fields on top of the base credential check.
     *
     * @return array<int,array{msg:string,fix:string}>
     */
    public function enable_problems(): array {
        $id = $this->id();
        $problems = [];
        if (!BGC_Settings::creds_present($id)) {
            $problems[] = [
                'msg' => __('API credentials are missing.', 'bg-couriers'),
                'fix' => __('Enter the username/key and password/secret, then click “Save changes”.', 'bg-couriers'),
            ];
        } elseif (get_option('bgc_' . $id . '_validated', 'no') !== 'yes') {
            $problems[] = [
                'msg' => __('The API credentials have not been validated.', 'bg-couriers'),
                'fix' => __('Click “Validate credentials” and make sure the check succeeds.', 'bg-couriers'),
            ];
        }
        return $problems;
    }

    /** Helper for overrides: append a problem when a saved option is empty. */
    protected function need_option(array &$problems, string $option, string $msg, string $fix): void {
        if (trim((string) get_option($option, '')) === '') {
            $problems[] = ['msg' => $msg, 'fix' => $fix];
        }
    }
}
