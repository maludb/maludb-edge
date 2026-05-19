<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\ApiKey;

final class ApiKeyTest extends TestCase
{
    public function testGenerateCreatesPrefixedSecret(): void
    {
        $key = ApiKey::generate();
        $this->assertTrue(str_starts_with($key, 'malu_'));
        $this->assertTrue(strlen($key) >= 40);
    }

    public function testHashAndVerify(): void
    {
        $key = 'malu_test_key_123';
        $hash = ApiKey::hash($key);
        $this->assertTrue(ApiKey::verify($key, $hash));
        $this->assertFalse(ApiKey::verify('malu_wrong', $hash));
    }

    public function testFingerprintIsStableAndNonSecret(): void
    {
        $a = ApiKey::fingerprint('malu_test_key_123');
        $b = ApiKey::fingerprint('malu_test_key_123');
        $this->assertSame($a, $b);
        $this->assertSame(16, strlen($a));
    }
}
