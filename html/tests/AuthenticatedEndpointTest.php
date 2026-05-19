<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\ApiKey;
use MaluDbEdge\App;
use MaluDbEdge\Config;
use MaluDbEdge\Db;
use MaluDbEdge\Migrator;
use MaluDbEdge\Request;

final class AuthenticatedEndpointTest extends TestCase
{
    /** @var list<string> */
    private array $sqlitePaths = [];

    public function __destruct()
    {
        foreach ($this->sqlitePaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testMeRequiresApiKey(): void
    {
        $app = $this->app();

        $response = $app->handle(new Request('GET', '/v1/me', [], null));

        $this->assertSame(401, $response->status);
        $this->assertSame('{"error":{"code":"unauthorized","message":"Invalid API key"}}', $response->body);
    }

    public function testMeRejectsInvalidApiKey(): void
    {
        $app = $this->app();

        $response = $app->handle(new Request('GET', '/v1/me', ['Authorization' => 'Bearer not-a-real-key'], null));

        $this->assertSame(401, $response->status);
        $this->assertSame('{"error":{"code":"unauthorized","message":"Invalid API key"}}', $response->body);
    }

    public function testMeReturnsAuthenticatedContext(): void
    {
        [$app, $apiKeyId, $apiKey] = $this->appWithApiKey();

        $response = $app->handle(new Request('GET', '/v1/me', ['Authorization' => 'Bearer ' . $apiKey], null));

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json', $response->headers['Content-Type']);

        $payload = json_decode($response->body, true);
        $this->assertSame(1, $payload['user_id']);
        $this->assertSame($apiKeyId, $payload['api_key_id']);
        $this->assertSame(1, $payload['tenant_id']);
        $this->assertSame('writer', $payload['role']);
        $this->assertSame(['sql:execute', 'files:read'], $payload['scopes']);
    }

    private function app(): App
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-me-');
        unlink($path);
        $this->sqlitePaths[] = $path;
        $pdo = Db::sqlite($path);
        (new Migrator($pdo, __DIR__ . '/../database/migrations'))->migrate();
        $config = new Config($path, str_repeat('a', 32), sys_get_temp_dir() . '/edge-archive', 'http://localhost');
        return new App($config, $pdo);
    }

    /** @return array{0: App, 1: int, 2: string} */
    private function appWithApiKey(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-me-');
        unlink($path);
        $this->sqlitePaths[] = $path;
        $pdo = Db::sqlite($path);
        (new Migrator($pdo, __DIR__ . '/../database/migrations'))->migrate();

        $pdo->exec("INSERT INTO users(email, display_name, role) VALUES('writer@example.test', 'Writer', 'writer')");
        $pdo->exec("INSERT INTO tenants(name, dsn_encrypted) VALUES('default', 'v1:test')");

        $apiKey = 'malu_me_test_key';
        $stmt = $pdo->prepare('INSERT INTO api_keys(user_id, tenant_id, name, key_hash, key_fingerprint, role, scopes_json) VALUES(1, 1, :name, :hash, :fingerprint, :role, :scopes)');
        $stmt->execute([
            ':name' => 'me-test',
            ':hash' => ApiKey::hash($apiKey),
            ':fingerprint' => ApiKey::fingerprint($apiKey),
            ':role' => 'writer',
            ':scopes' => json_encode(['sql:execute', 'files:read'], JSON_THROW_ON_ERROR),
        ]);

        $config = new Config($path, str_repeat('a', 32), sys_get_temp_dir() . '/edge-archive', 'http://localhost');
        return [new App($config, $pdo), (int)$pdo->lastInsertId(), $apiKey];
    }
}
