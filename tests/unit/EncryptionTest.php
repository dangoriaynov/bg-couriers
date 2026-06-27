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
}
