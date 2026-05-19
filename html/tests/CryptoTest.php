<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\Crypto;

final class CryptoTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $crypto = new Crypto(str_repeat('a', 32));
        $ciphertext = $crypto->encrypt('pgsql:host=db;dbname=malu');
        $this->assertTrue(str_starts_with($ciphertext, 'v1:'));
        $this->assertSame('pgsql:host=db;dbname=malu', $crypto->decrypt($ciphertext));
    }

    public function testEncryptDecryptEmptyString(): void
    {
        $crypto = new Crypto(str_repeat('a', 32));
        $ciphertext = $crypto->encrypt('');
        $this->assertTrue(str_starts_with($ciphertext, 'v1:'));
        $this->assertSame('', $crypto->decrypt($ciphertext));
    }

    public function testWrongKeyFails(): void
    {
        $ciphertext = (new Crypto(str_repeat('a', 32)))->encrypt('secret');
        $failed = false;
        try {
            (new Crypto(str_repeat('b', 32)))->decrypt($ciphertext);
        } catch (\RuntimeException) {
            $failed = true;
        }
        $this->assertTrue($failed);
    }
}
