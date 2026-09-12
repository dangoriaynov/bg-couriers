<?php
defined('ABSPATH') || exit;

class BGCouriers_Logger {
    /**
     * Words that make a key a secret wherever they appear in its name. Matched inside the name, not
     * against the whole of it, because every courier spells the same thing differently and a list of
     * whole names is a list that is always one courier behind: X-AUTH-TOKEN, client_secret,
     * apiPassword and webhook_secret share no whole name at all.
     */
    private const SECRET_WORDS = [
        'password', 'passphrase', 'passwd', 'secret', 'token', 'apikey', 'clientkey', 'clientid',
        'authorization', 'signature', 'credential', 'bearer', 'privatekey',
    ];

    /**
     * ...and names that are a secret only when that IS the whole name. 'key' inside a name means
     * nothing (keyword, monkey, key_id) and 'user' is half of every other field, but a context whose
     * key is exactly one of these is carrying the thing itself.
     */
    private const SECRET_NAMES = ['key', 'pass', 'auth', 'user', 'username', 'login', 'pwd'];

    public static function debug(string $msg, array $ctx = []): void {
        if (!(function_exists('get_option') && get_option('bgcouriers_debug') === 'yes')) { return; }
        error_log('[bg-couriers] ' . $msg . ($ctx ? ' ' . wp_json_encode(self::redact($ctx)) : '')); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated logger, debug only
    }

    /**
     * Take every credential out of a context before it reaches the log, at any depth.
     *
     * This used to be one unset() of four literal key names - userName, password, api_key, api_secret -
     * which covered the fields of two couriers out of seven, only at the top level, and only in the
     * exact spelling those two use. BOX NOW's client_id/client_secret, Sameday's X-AUTH-TOKEN,
     * Evropat's clientKey and the webhook secret are all spelled differently and would have gone
     * straight into the shop's PHP error log. Nothing hands a request body to this logger today -
     * which is exactly why the hole was invisible, and why it is worth closing before something does.
     *
     * Each courier is also asked what IT calls its credentials, so a courier added later is covered
     * without anybody editing the list above.
     *
     * @param mixed $v
     * @return mixed
     */
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

    /** One spelling for a key name: lower case, letters and digits only. */
    private static function flatten(string $k): string {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $k));
    }

    /**
     * What the couriers themselves call their credential fields.
     *
     * Not cached: debug() runs only where a merchant has switched debugging on, so the loop is not
     * worth a cache that would freeze the answer at whatever was registered the first time anything
     * was logged.
     */
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
