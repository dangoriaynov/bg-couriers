<?php
defined('ABSPATH') || exit;

class BGCouriers_Logger {
    /** Matched inside a key name: every courier spells its secrets differently. */
    private const SECRET_WORDS = [
        'password', 'passphrase', 'passwd', 'secret', 'token', 'apikey', 'clientkey', 'clientid',
        'authorization', 'signature', 'credential', 'bearer', 'privatekey',
    ];

    /** Secret only when it is the whole key name. */
    private const SECRET_NAMES = ['key', 'pass', 'auth', 'user', 'username', 'login', 'pwd'];

    public static function debug(string $msg, array $ctx = []): void {
        if (!(function_exists('get_option') && get_option('bgcouriers_debug') === 'yes')) { return; }
        error_log('[bg-couriers] ' . $msg . ($ctx ? ' ' . wp_json_encode(self::redact($ctx)) : '')); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated logger, debug only
    }

    /** Replace every credential in a context with '***', at any depth. */
    private static function redact($v, int $depth = 0) {
        if (!is_array($v) || $depth > 6) { return $v; }
        $out = [];
        foreach ($v as $k => $item) {
            if (is_string($k) && self::is_secret($k)) { $out[$k] = '***'; continue; }
            $out[$k] = self::redact($item, $depth + 1);
        }
        return $out;
    }

    private static function is_secret(string $key): bool {
        $flat = self::flatten($key);
        if ($flat === '') { return false; }
        if (in_array($flat, self::SECRET_NAMES, true)) { return true; }
        foreach (self::SECRET_WORDS as $w) {
            if (strpos($flat, $w) !== false) { return true; }
        }
        return in_array($flat, self::courier_keys(), true);
    }

    private static function flatten(string $k): string {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $k));
    }

    private static function courier_keys(): array {
        $keys = [];
        if (!class_exists('BGCouriers_Couriers')) { return $keys; }
        foreach (array_keys(BGCouriers_Couriers::all()) as $id) {
            $co = BGCouriers_Couriers::get($id);
            if (!$co || !method_exists($co, 'credential_fields')) { continue; }
            foreach ((array) $co->credential_fields() as $f) {
                $flat = self::flatten((string) $f);
                if ($flat !== '') { $keys[] = $flat; }
            }
        }
        return array_values(array_unique($keys));
    }
}
