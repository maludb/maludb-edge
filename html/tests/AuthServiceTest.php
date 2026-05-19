<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\ApiKey;
use MaluDbEdge\AuthService;
use MaluDbEdge\Db;
use MaluDbEdge\Migrator;

final class AuthServiceTest extends TestCase
{
    public function testAuthenticatesBearerKeyAndChecksScopes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-auth-');
        unlink($path);
        $pdo = null;

        try {
            $pdo = Db::sqlite($path);
            (new Migrator($pdo, __DIR__ . '/../database/migrations'))->migrate();

            $pdo->exec("INSERT INTO users(email, display_name, role) VALUES('admin@example.test', 'Admin', 'admin')");
            $pdo->exec("INSERT INTO tenants(name, dsn_encrypted) VALUES('default', 'v1:test')");
            $key = 'malu_auth_test';
            $stmt = $pdo->prepare('INSERT INTO api_keys(user_id, tenant_id, name, key_hash, key_fingerprint, role, scopes_json) VALUES(1, 1, :name, :hash, :fingerprint, :role, :scopes)');
            $stmt->execute([
                ':name' => 'test',
                ':hash' => ApiKey::hash($key),
                ':fingerprint' => ApiKey::fingerprint($key),
                ':role' => 'writer',
                ':scopes' => json_encode(['sql:execute', 'files:read'], JSON_THROW_ON_ERROR),
            ]);

            $context = (new AuthService($pdo))->authenticate($key);
            $this->assertSame(1, $context->userId);
            $this->assertSame(1, $context->tenantId);
            $this->assertTrue($context->hasScope('sql:execute'));
            $this->assertFalse($context->hasScope('keys:manage'));
        } finally {
            $pdo = null;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testRejectsRevokedKey(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-auth-');
        unlink($path);
        $pdo = null;

        try {
            $pdo = Db::sqlite($path);
            (new Migrator($pdo, __DIR__ . '/../database/migrations'))->migrate();
            $pdo->exec("INSERT INTO users(email, role) VALUES('user@example.test', 'reader')");
            $pdo->exec("INSERT INTO tenants(name, dsn_encrypted) VALUES('default', 'v1:test')");
            $key = 'malu_revoked_test';
            $stmt = $pdo->prepare('INSERT INTO api_keys(user_id, tenant_id, name, key_hash, key_fingerprint, role, scopes_json, revoked_at) VALUES(1, 1, :name, :hash, :fingerprint, :role, :scopes, CURRENT_TIMESTAMP)');
            $stmt->execute([
                ':name' => 'revoked',
                ':hash' => ApiKey::hash($key),
                ':fingerprint' => ApiKey::fingerprint($key),
                ':role' => 'reader',
                ':scopes' => '[]',
            ]);

            $this->assertSame(null, (new AuthService($pdo))->authenticate($key));
        } finally {
            $pdo = null;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
