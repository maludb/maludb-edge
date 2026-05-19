<?php
declare(strict_types=1);

namespace MaluDbEdge;

final readonly class Crypto
{
    private string $key;

    public function __construct(string $appKey)
    {
        if (strlen($appKey) < 32) {
            throw new \RuntimeException('Encryption key must be at least 32 bytes');
        }
        $this->key = hash('sha256', $appKey, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, 'v1:')) {
            throw new \RuntimeException('Unsupported ciphertext version');
        }
        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Invalid ciphertext');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $plaintext;
    }
}
