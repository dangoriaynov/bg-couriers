<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-encryption.php';

/**
 * @group core
 */
final class EncryptionTest extends TestCase {
    public function test_round_trip(): void {
        $key = str_repeat('k', 32);
        $cipher = BGC_Encryption::encrypt('s3cret-pass', $key);
        $this->assertNotSame('s3cret-pass', $cipher);
        $this->assertSame('s3cret-pass', BGC_Encryption::decrypt($cipher, $key));
    }
    public function test_empty_is_empty(): void {
        $this->assertSame('', BGC_Encryption::encrypt('', str_repeat('k', 32)));
    }

    public function test_new_values_use_gcm_format(): void {
        $cipher = BGC_Encryption::encrypt('hello', str_repeat('k', 32));
        $this->assertStringStartsWith('g2:', $cipher);
    }

    /** A secret encrypted with the OLD AES-256-CBC scheme must still decrypt after the GCM upgrade. */
    public function test_legacy_cbc_still_decrypts(): void {
        $key    = str_repeat('k', 32);
        $aesKey = substr(hash('sha256', $key, true), 0, 32); // same derivation BGC_Encryption uses
        $iv     = random_bytes(16);
        $ct     = openssl_encrypt('old-secret', 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $iv);
        $legacy = base64_encode($iv . $ct); // bare base64, no prefix = the old on-disk format
        $this->assertSame('old-secret', BGC_Encryption::decrypt($legacy, $key));
    }

    /** GCM is authenticated: a tampered ciphertext must fail closed (empty), not return garbage. */
    public function test_tampered_gcm_fails_closed(): void {
        $key    = str_repeat('k', 32);
        $cipher = BGC_Encryption::encrypt('s3cret-pass', $key);
        $raw    = base64_decode(substr($cipher, 3), true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === "\x00" ? "\x01" : "\x00"; // flip a ciphertext byte
        $tampered = 'g2:' . base64_encode($raw);
        $this->assertSame('', BGC_Encryption::decrypt($tampered, $key));
    }
}
