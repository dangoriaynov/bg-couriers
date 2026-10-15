<?php
defined('ABSPATH') || exit;

class BGCouriers_Encryption {
    // A base64 string never starts with ':', so this prefix tells GCM values from legacy CBC ones.
    const GCM_PREFIX = 'g2:';

    private static function key(?string $key): string {
        if ($key !== null) { return substr(hash('sha256', $key, true), 0, 32); }
        $salt = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
        return substr(hash('sha256', $salt, true), 0, 32);
    }

    public static function encrypt(string $plain, ?string $key = null): string {
        if ($plain === '') { return ''; }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', self::key($key), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) { return ''; }
        return self::GCM_PREFIX . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $cipher, ?string $key = null): string {
        if ($cipher === '') { return ''; }
        if (strncmp($cipher, self::GCM_PREFIX, strlen(self::GCM_PREFIX)) === 0) {
            $raw = base64_decode(substr($cipher, strlen(self::GCM_PREFIX)), true);
            if ($raw === false || strlen($raw) < 29) { return ''; } // 12 iv + 16 tag + >=1 byte ct
            $iv  = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ct  = substr($raw, 28);
            $out = openssl_decrypt($ct, 'aes-256-gcm', self::key($key), OPENSSL_RAW_DATA, $iv, $tag);
            return $out === false ? '' : $out;
        }
        // Legacy AES-256-CBC: base64(iv[16] . ciphertext); re-encrypted as GCM on the next save.
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < 17) { return ''; }
        $iv = substr($raw, 0, 16); $ct = substr($raw, 16);
        $out = openssl_decrypt($ct, 'aes-256-cbc', self::key($key), OPENSSL_RAW_DATA, $iv);
        return $out === false ? '' : $out;
    }
}
