<?php
defined('ABSPATH') || exit;

class BGC_Encryption {
    // Marker prefix for the authenticated (GCM) format. A base64 string can never start with ':' , so this
    // reliably distinguishes new GCM values from legacy CBC values (which are bare base64, no prefix).
    const GCM_PREFIX = 'g2:';

    private static function key(?string $key): string {
        if ($key !== null) { return substr(hash('sha256', $key, true), 0, 32); }
        $salt = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
        return substr(hash('sha256', $salt, true), 0, 32);
    }

    /** Encrypt with AES-256-GCM (authenticated). Stored as GCM_PREFIX . base64(iv[12] . tag[16] . ciphertext). */
    public static function encrypt(string $plain, ?string $key = null): string {
        if ($plain === '') { return ''; }
        $iv  = random_bytes(12); // 96-bit nonce, the GCM standard
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', self::key($key), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) { return ''; }
        return self::GCM_PREFIX . base64_encode($iv . $tag . $ct);
    }

    /** Decrypt a GCM value (new) or a legacy AES-256-CBC value (already-stored secrets), returning '' on failure. */
    public static function decrypt(string $cipher, ?string $key = null): string {
        if ($cipher === '') { return ''; }
        if (strncmp($cipher, self::GCM_PREFIX, strlen(self::GCM_PREFIX)) === 0) {
            $raw = base64_decode(substr($cipher, strlen(self::GCM_PREFIX)), true);
            if ($raw === false || strlen($raw) < 29) { return ''; } // 12 iv + 16 tag + >=1 byte ct
            $iv  = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ct  = substr($raw, 28);
            $out = openssl_decrypt($ct, 'aes-256-gcm', self::key($key), OPENSSL_RAW_DATA, $iv, $tag);
            return $out === false ? '' : $out; // GCM tag mismatch (tampering / wrong key) -> false -> ''
        }
        // Legacy AES-256-CBC: base64(iv[16] . ciphertext). Kept so secrets encrypted before the GCM upgrade
        // still decrypt; they re-encrypt as GCM the next time the merchant saves the field.
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < 17) { return ''; }
        $iv = substr($raw, 0, 16); $ct = substr($raw, 16);
        $out = openssl_decrypt($ct, 'aes-256-cbc', self::key($key), OPENSSL_RAW_DATA, $iv);
        return $out === false ? '' : $out;
    }
}
