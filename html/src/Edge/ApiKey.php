<?php
declare(strict_types=1);

namespace MaluDbEdge;

final class ApiKey
{
    public static function generate(): string
    {
        return 'malu_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $key): string
    {
        return password_hash($key, PASSWORD_ARGON2ID);
    }

    public static function verify(string $key, string $hash): bool
    {
        return password_verify($key, $hash);
    }

    public static function fingerprint(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }
}
