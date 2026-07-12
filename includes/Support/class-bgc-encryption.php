<?php
defined('ABSPATH') || exit;

class BGC_Encryption {
    private static function key(?string $key): string {
        if ($key !== null) { return substr(hash('sha256', $key, true), 0, 32); }
        $salt = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
        return substr(hash('sha256', $salt, true), 0, 32);
    }
    public static function encrypt(string $plain, ?string $key = null): string {
        if ($plain === '') { return ''; }
        $iv = random_bytes(16);
        $ct = openssl_encrypt($plain, 'aes-256-cbc', self::key($key), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ct);
    }
    public static function decrypt(string $cipher, ?string $key = null): string {
        if ($cipher === '') { return ''; }
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < 17) { return ''; }
        $iv = substr($raw, 0, 16); $ct = substr($raw, 16);
        $out = openssl_decrypt($ct, 'aes-256-cbc', self::key($key), OPENSSL_RAW_DATA, $iv);
        return $out === false ? '' : $out;
    }
}
