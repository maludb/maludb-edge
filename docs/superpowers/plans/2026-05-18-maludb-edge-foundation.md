# maludb-edge Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the tested PHP foundation for `maludb-edge`: routing, JSON HTTP, config, SQLite metadata, encrypted tenant storage, API-key auth, bootstrap CLI, and initial health/version/docs endpoints.

**Architecture:** Keep the v1 foundation plain PHP and framework-free. The web entry point delegates to focused classes under `html/src/Edge`, while CLI commands reuse the same config, database, migration, and auth/key services. This plan creates the base that later endpoint plans use for MaluDB driver calls, SVPOR, SQL, prompts, files, and MCP.

**Tech Stack:** PHP 8.3, Composer PSR-4 autoloading, PDO SQLite, PDO PostgreSQL through `maludb/client`, built-in PHP test runner scripts, Apache/PHP document root under `html/`.

---

## Scope Check

The approved design covers multiple independent subsystems. This plan implements the foundation slice only. Separate plans should cover:

- MaluDB core REST wrappers for source packages, claims, facts, memories, episodes, retrieval, pools, skills, nodes, PageIndex, and ChatIndex.
- SVPOR subject/verb/predicate browsing and relationship endpoints.
- Durable file archive upload/download/tagging and async jobs.
- Prompt templates, LLM runtime, sessions, and model requests.
- SQL execution and hash-only SQL audit.
- MCP tools, resources, prompts, and Streamable HTTP transport.

This foundation produces working, testable software on its own: an API server with local auth, tenant metadata, migrations, admin bootstrap, docs shell, and health/version endpoints.

## File Structure

- Modify: `html/composer.json` - add PSR-4 autoloading and test scripts.
- Create: `html/.htaccess` - route non-file requests to `index.php` under Apache.
- Create: `html/index.php` - web front controller.
- Create: `html/bin/edge` - CLI entry point for migrations and first-admin bootstrap.
- Create: `html/config/openapi.php` - minimal OpenAPI document generator.
- Create: `html/database/migrations/001_foundation.sql` - SQLite schema.
- Create: `html/src/Edge/Config.php` - environment/config loading.
- Create: `html/src/Edge/Crypto.php` - secret-box encryption for tenant credentials.
- Create: `html/src/Edge/ApiKey.php` - key generation, hashing, verification.
- Create: `html/src/Edge/Db.php` - SQLite connection factory.
- Create: `html/src/Edge/Migrator.php` - migration runner.
- Create: `html/src/Edge/AuthContext.php` - authenticated request identity.
- Create: `html/src/Edge/AuthService.php` - API-key lookup and scope checks.
- Create: `html/src/Edge/Request.php` - normalized HTTP request.
- Create: `html/src/Edge/Response.php` - JSON/HTML response emitter.
- Create: `html/src/Edge/Router.php` - method/path route matching.
- Create: `html/src/Edge/App.php` - route registration and request handling.
- Create: `html/src/Edge/Cli.php` - CLI command implementation.
- Create: `html/tests/TestCase.php` - minimal assertions.
- Create: `html/tests/run.php` - test runner.
- Create: `html/tests/HarnessTest.php` - verifies the custom runner executes tests.
- Create: `html/tests/ApiKeyTest.php` - key hashing and verification tests.
- Create: `html/tests/CryptoTest.php` - tenant secret encryption tests.
- Create: `html/tests/MigratorTest.php` - schema migration tests.
- Create: `html/tests/AuthServiceTest.php` - auth and scope tests.
- Create: `html/tests/RouterTest.php` - route matching tests.
- Create: `html/tests/AppTest.php` - health, docs, version, and auth failure tests.
- Create: `html/tests/CliTest.php` - bootstrap CLI tests.
- Create: `html/tests/AuthenticatedEndpointTest.php` - authenticated route tests.

## Task 1: Composer Autoloading and Test Harness

**Files:**
- Modify: `html/composer.json`
- Create: `html/tests/TestCase.php`
- Create: `html/tests/run.php`
- Create: `html/tests/HarnessTest.php`

- [ ] **Step 1: Write the failing test harness smoke test**

Create `html/tests/TestCase.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

abstract class TestCase
{
    protected function assertTrue(bool $condition, string $message = 'Expected condition to be true'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Expected condition to be false'): void
    {
        if ($condition) {
            throw new \RuntimeException($message);
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $prefix = $message !== '' ? $message . ': ' : '';
            throw new \RuntimeException($prefix . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertArrayHasKey(string $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            $prefix = $message !== '' ? $message . ': ' : '';
            throw new \RuntimeException($prefix . "missing key {$key}");
        }
    }
}
```

Create `html/tests/run.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$testFiles = glob(__DIR__ . '/*Test.php') ?: [];
sort($testFiles);

$passed = 0;
$failed = 0;

foreach ($testFiles as $file) {
    require_once $file;
}

foreach (get_declared_classes() as $class) {
    if (!str_starts_with($class, 'MaluDbEdge\\Tests\\') || $class === 'MaluDbEdge\\Tests\\TestCase') {
        continue;
    }
    $object = new $class();
    foreach (get_class_methods($object) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        try {
            $object->$method();
            fwrite(STDOUT, "PASS {$class}::{$method}\n");
            $passed++;
        } catch (Throwable $e) {
            fwrite(STDERR, "FAIL {$class}::{$method}: {$e->getMessage()}\n");
            $failed++;
        }
    }
}

fwrite(STDOUT, "Passed: {$passed}; Failed: {$failed}\n");
exit($failed === 0 ? 0 : 1);
```

Create `html/tests/HarnessTest.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

final class HarnessTest extends TestCase
{
    public function testHarnessRunsTests(): void
    {
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run the test harness before autoload config**

Run: `cd html && php tests/run.php`

Expected: FAIL because `MaluDbEdge\Tests\TestCase` cannot be autoloaded before Composer knows the test namespace.

- [ ] **Step 3: Update Composer config**

Replace `html/composer.json` with:

```json
{
    "require": {
        "maludb/client": "^0.1"
    },
    "autoload": {
        "psr-4": {
            "MaluDbEdge\\": "src/",
            "MaluDbEdge\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "php tests/run.php"
    }
}
```

- [ ] **Step 4: Regenerate autoload files**

Run: `cd html && composer dump-autoload`

Expected: Composer prints `Generated autoload files`.

- [ ] **Step 5: Run the empty harness**

Run: `cd html && composer test`

Expected: PASS with `Passed: 1; Failed: 0`.

- [ ] **Step 6: Commit**

```bash
git add html/composer.json html/tests/TestCase.php html/tests/run.php html/tests/HarnessTest.php
git commit -m "test: add PHP test harness"
```

## Task 2: Config, API Keys, and Crypto

**Files:**
- Create: `html/src/Edge/Config.php`
- Create: `html/src/Edge/ApiKey.php`
- Create: `html/src/Edge/Crypto.php`
- Create: `html/tests/ApiKeyTest.php`
- Create: `html/tests/CryptoTest.php`

- [ ] **Step 1: Write failing key tests**

Create `html/tests/ApiKeyTest.php`:

```php
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
```

- [ ] **Step 2: Write failing crypto tests**

Create `html/tests/CryptoTest.php`:

```php
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
```

- [ ] **Step 3: Run tests to verify failure**

Run: `cd html && composer test`

Expected: FAIL with classes `MaluDbEdge\ApiKey` and `MaluDbEdge\Crypto` not found.

- [ ] **Step 4: Implement `Config`**

Create `html/src/Edge/Config.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge;

final readonly class Config
{
    public function __construct(
        public string $sqlitePath,
        public string $appKey,
        public string $archivePath,
        public string $baseUrl,
    ) {}

    public static function fromEnv(): self
    {
        $root = dirname(__DIR__, 2);
        return new self(
            sqlitePath: getenv('MALUDB_EDGE_SQLITE') ?: $root . '/var/edge.sqlite',
            appKey: getenv('MALUDB_EDGE_APP_KEY') ?: '',
            archivePath: getenv('MALUDB_EDGE_ARCHIVE') ?: $root . '/var/archive',
            baseUrl: rtrim(getenv('MALUDB_EDGE_BASE_URL') ?: 'http://localhost', '/'),
        );
    }

    public function requireAppKey(): string
    {
        if (strlen($this->appKey) < 32) {
            throw new \RuntimeException('MALUDB_EDGE_APP_KEY must be at least 32 bytes');
        }
        return $this->appKey;
    }
}
```

- [ ] **Step 5: Implement `ApiKey`**

Create `html/src/Edge/ApiKey.php`:

```php
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
```

- [ ] **Step 6: Implement `Crypto`**

Create `html/src/Edge/Crypto.php`:

```php
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
        if ($raw === false || strlen($raw) < 29) {
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
```

- [ ] **Step 7: Run tests**

Run: `cd html && composer dump-autoload && composer test`

Expected: PASS for `ApiKeyTest` and `CryptoTest`.

- [ ] **Step 8: Commit**

```bash
git add html/composer.json html/src/Edge/Config.php html/src/Edge/ApiKey.php html/src/Edge/Crypto.php html/tests/ApiKeyTest.php html/tests/CryptoTest.php
git commit -m "feat: add config and key security primitives"
```

## Task 3: SQLite Database and Migrations

**Files:**
- Create: `html/database/migrations/001_foundation.sql`
- Create: `html/src/Edge/Db.php`
- Create: `html/src/Edge/Migrator.php`
- Create: `html/tests/MigratorTest.php`

- [ ] **Step 1: Write failing migration test**

Create `html/tests/MigratorTest.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\Db;
use MaluDbEdge\Migrator;

final class MigratorTest extends TestCase
{
    public function testMigratesFoundationTables(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-db-');
        unlink($path);
        $pdo = Db::sqlite($path);
        (new Migrator($pdo, __DIR__ . '/../database/migrations'))->migrate();

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertTrue(in_array('users', $tables, true));
        $this->assertTrue(in_array('tenants', $tables, true));
        $this->assertTrue(in_array('api_keys', $tables, true));
        $this->assertTrue(in_array('audit_events', $tables, true));
        $this->assertTrue(in_array('schema_migrations', $tables, true));

        $count = (int)$pdo->query('SELECT count(*) FROM schema_migrations')->fetchColumn();
        $this->assertSame(1, $count);
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd html && composer test`

Expected: FAIL with classes `Db` and `Migrator` not found.

- [ ] **Step 3: Create the foundation migration**

Create `html/database/migrations/001_foundation.sql`:

```sql
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    display_name TEXT,
    role TEXT NOT NULL DEFAULT 'reader',
    disabled_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tenants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    dsn_encrypted TEXT NOT NULL,
    username_encrypted TEXT,
    password_encrypted TEXT,
    disabled_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    key_hash TEXT NOT NULL,
    key_fingerprint TEXT NOT NULL UNIQUE,
    role TEXT NOT NULL DEFAULT 'reader',
    scopes_json TEXT NOT NULL DEFAULT '[]',
    last_used_at TEXT,
    revoked_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    original_name TEXT NOT NULL,
    storage_path TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    mime_type TEXT,
    byte_size INTEGER NOT NULL,
    source_package_id INTEGER,
    document_id INTEGER,
    status TEXT NOT NULL DEFAULT 'archived',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, sha256)
);

CREATE TABLE IF NOT EXISTS file_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    tag_kind TEXT NOT NULL,
    tag_value TEXT NOT NULL,
    tag_object_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(file_id, tag_kind, tag_value)
);

CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    job_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    payload_json TEXT NOT NULL DEFAULT '{}',
    result_json TEXT,
    error_message TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    api_key_id INTEGER,
    tenant_id INTEGER,
    channel TEXT NOT NULL,
    action TEXT NOT NULL,
    status_code INTEGER,
    duration_ms INTEGER,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sql_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    api_key_id INTEGER NOT NULL,
    tenant_id INTEGER NOT NULL,
    statement_hash TEXT NOT NULL,
    status TEXT NOT NULL,
    duration_ms INTEGER NOT NULL,
    row_count INTEGER,
    error_code TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

- [ ] **Step 4: Implement `Db`**

Create `html/src/Edge/Db.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge;

use PDO;

final class Db
{
    public static function sqlite(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }
}
```

- [ ] **Step 5: Implement `Migrator`**

Create `html/src/Edge/Migrator.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge;

use PDO;

final readonly class Migrator
{
    public function __construct(private PDO $pdo, private string $migrationDir) {}

    public function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $files = glob($this->migrationDir . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $version = basename($file);
            $stmt = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
            $stmt->execute([':version' => $version]);
            if ($stmt->fetchColumn()) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Could not read migration {$version}");
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $insert = $this->pdo->prepare('INSERT INTO schema_migrations(version) VALUES(:version)');
                $insert->execute([':version' => $version]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }
    }
}
```

- [ ] **Step 6: Run tests**

Run: `cd html && composer dump-autoload && composer test`

Expected: PASS including `MigratorTest`.

- [ ] **Step 7: Commit**

```bash
git add html/database/migrations/001_foundation.sql html/src/Edge/Db.php html/src/Edge/Migrator.php html/tests/MigratorTest.php
git commit -m "feat: add SQLite foundation migrations"
```

## Task 4: Auth Context and Auth Service

**Files:**
- Create: `html/src/Edge/AuthContext.php`
- Create: `html/src/Edge/AuthService.php`
- Create: `html/tests/AuthServiceTest.php`

- [ ] **Step 1: Write failing auth service tests**

Create `html/tests/AuthServiceTest.php`:

```php
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
    }

    public function testRejectsRevokedKey(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-auth-');
        unlink($path);
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
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd html && composer test`

Expected: FAIL with `AuthService` not found.

- [ ] **Step 3: Implement `AuthContext`**

Create `html/src/Edge/AuthContext.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge;

final readonly class AuthContext
{
    public function __construct(
        public int $userId,
        public int $apiKeyId,
        public int $tenantId,
        public string $role,
        public array $scopes,
    ) {}

    public function hasScope(string $scope): bool
    {
        return $this->role === 'admin' || in_array($scope, $this->scopes, true);
    }
}
```

- [ ] **Step 4: Implement `AuthService`**

Create `html/src/Edge/AuthService.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge;

use PDO;

final readonly class AuthService
{
    public function __construct(private PDO $pdo) {}

    public function authenticate(?string $apiKey): ?AuthContext
    {
        if ($apiKey === null || $apiKey === '') {
            return null;
        }
        $fingerprint = ApiKey::fingerprint($apiKey);
        $stmt = $this->pdo->prepare(
            'SELECT k.id AS api_key_id, k.user_id, k.tenant_id, k.key_hash, k.role, k.scopes_json
               FROM api_keys k
               JOIN users u ON u.id = k.user_id
               JOIN tenants t ON t.id = k.tenant_id
              WHERE k.key_fingerprint = :fingerprint
                AND k.revoked_at IS NULL
                AND u.disabled_at IS NULL
                AND t.disabled_at IS NULL'
        );
        $stmt->execute([':fingerprint' => $fingerprint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !ApiKey::verify($apiKey, (string)$row['key_hash'])) {
            return null;
        }
        $this->pdo->prepare('UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':id' => (int)$row['api_key_id']]);
        $scopes = json_decode((string)$row['scopes_json'], true);
        return new AuthContext(
            userId: (int)$row['user_id'],
            apiKeyId: (int)$row['api_key_id'],
            tenantId: (int)$row['tenant_id'],
            role: (string)$row['role'],
            scopes: is_array($scopes) ? array_values($scopes) : [],
        );
    }

    public function requireScope(AuthContext $context, string $scope): void
    {
        if (!$context->hasScope($scope)) {
            throw new \RuntimeException("Missing scope: {$scope}", 403);
        }
    }
}
```

- [ ] **Step 5: Run tests**

Run: `cd html && composer dump-autoload && composer test`

Expected: PASS including `AuthServiceTest`.

- [ ] **Step 6: Commit**

```bash
git add html/src/Edge/AuthContext.php html/src/Edge/AuthService.php html/tests/AuthServiceTest.php
git commit -m "feat: add API key authentication"
```

## Task 5: HTTP Request, Response, and Router

**Files:**
- Create: `html/src/Edge/Request.php`
- Create: `html/src/Edge/Response.php`
- Create: `html/src/Edge/Router.php`
- Create: `html/tests/RouterTest.php`

- [ ] **Step 1: Write failing router tests**

Create `html/tests/RouterTest.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\Request;
use MaluDbEdge\Response;
use MaluDbEdge\Router;

final class RouterTest extends TestCase
{
    public function testMatchesStaticRoute(): void
    {
        $router = new Router();
        $router->get('/v1/health', fn(Request $request) => Response::json(['status' => 'ok']));
        $response = $router->dispatch(new Request('GET', '/v1/health', [], null));
        $this->assertSame(200, $response->status);
        $this->assertSame('{"status":"ok"}', $response->body);
    }

    public function testMatchesRouteParam(): void
    {
        $router = new Router();
        $router->get('/v1/users/{id}', fn(Request $request) => Response::json(['id' => $request->param('id')]));
        $response = $router->dispatch(new Request('GET', '/v1/users/42', [], null));
        $this->assertSame('{"id":"42"}', $response->body);
    }

    public function testReturns404(): void
    {
        $router = new Router();
        $response = $router->dispatch(new Request('GET', '/missing', [], null));
        $this->assertSame(404, $response->status);
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd html && composer test`

Expected: FAIL with request/router classes not found.

- [ ] **Step 3: Implement `Request`**

Create `html/src/Edge/Request.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge;

final class Request
{
    private array $params = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly mixed $body,
    ) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $raw = file_get_contents('php://input') ?: '';
        $body = $raw !== '' ? json_decode($raw, true) : null;
        return new self($method, $path, array_change_key_case($headers, CASE_LOWER), $body);
    }

    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }

    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }
        return $this->headers['x-maludb-key'] ?? null;
    }
}
```

- [ ] **Step 4: Implement `Response`**

Create `html/src/Edge/Response.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge;

final readonly class Response
{
    public function __construct(public int $status, public array $headers, public string $body) {}

    public static function json(mixed $payload, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public static function error(string $code, string $message, int $status, ?string $detail = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($detail !== null) {
            $error['detail'] = $detail;
        }
        return self::json(['error' => $error], $status);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
```

- [ ] **Step 5: Implement `Router`**

Create `html/src/Edge/Router.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[] = [$method, '#^' . $pattern . '$#', $handler];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as [$method, $pattern, $handler]) {
            if ($method !== $request->method) {
                continue;
            }
            if (preg_match($pattern, $request->path, $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }
                return $handler($request->withParams($params));
            }
        }
        return Response::error('not_found', 'Route not found', 404);
    }
}
```

- [ ] **Step 6: Run tests**

Run: `cd html && composer dump-autoload && composer test`

Expected: PASS including `RouterTest`.

- [ ] **Step 7: Commit**

```bash
git add html/src/Edge/Request.php html/src/Edge/Response.php html/src/Edge/Router.php html/tests/RouterTest.php
git commit -m "feat: add HTTP router primitives"
```

## Task 6: App Routes, Front Controller, and Docs Shell

**Files:**
- Create: `html/src/Edge/App.php`
- Create: `html/config/openapi.php`
- Create: `html/index.php`
- Create: `html/.htaccess`
- Create: `html/tests/AppTest.php`

- [ ] **Step 1: Write failing app tests**

Create `html/tests/AppTest.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\App;
use MaluDbEdge\Config;
use MaluDbEdge\Db;
use MaluDbEdge\Migrator;
use MaluDbEdge\Request;

final class AppTest extends TestCase
{
    public function testHealthReturnsOk(): void
    {
        $app = $this->app();
        $response = $app->handle(new Request('GET', '/v1/health', [], null));
        $this->assertSame(200, $response->status);
        $this->assertSame('{"status":"ok"}', $response->body);
    }

    public function testVersionReturnsEdgeVersion(): void
    {
        $app = $this->app();
        $response = $app->handle(new Request('GET', '/v1/version', [], null));
        $this->assertSame(200, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertSame('maludb-edge', $payload['name']);
    }

    public function testDocsReturnsHtml(): void
    {
        $app = $this->app();
        $response = $app->handle(new Request('GET', '/v1/docs', [], null));
        $this->assertSame(200, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
    }

    private function app(): App
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-app-');
        unlink($path);
        $pdo = Db::sqlite($path);
        (new Migrator($pdo, __DIR__ . '/../database/migrations'))->migrate();
        $config = new Config($path, str_repeat('a', 32), sys_get_temp_dir() . '/edge-archive', 'http://localhost');
        return new App($config, $pdo);
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd html && composer test`

Expected: FAIL with `App` not found.

- [ ] **Step 3: Create OpenAPI config**

Create `html/config/openapi.php`:

```php
<?php
declare(strict_types=1);

return [
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'maludb-edge API',
        'version' => '0.1.0',
    ],
    'paths' => [
        '/v1/health' => [
            'get' => [
                'summary' => 'Health check',
                'responses' => [
                    '200' => ['description' => 'API is healthy'],
                ],
            ],
        ],
        '/v1/version' => [
            'get' => [
                'summary' => 'Version metadata',
                'responses' => [
                    '200' => ['description' => 'Version metadata'],
                ],
            ],
        ],
    ],
];
```

- [ ] **Step 4: Implement `App`**

Create `html/src/Edge/App.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge;

use PDO;

final readonly class App
{
    private Router $router;

    public function __construct(private Config $config, private PDO $pdo)
    {
        $router = new Router();
        $router->get('/v1/health', fn(Request $request) => Response::json(['status' => 'ok']));
        $router->get('/v1/version', fn(Request $request) => Response::json(['name' => 'maludb-edge', 'version' => '0.1.0']));
        $router->get('/v1/openapi.json', fn(Request $request) => Response::json(require dirname(__DIR__, 2) . '/config/openapi.php'));
        $router->get('/v1/docs', fn(Request $request) => Response::html('<!doctype html><html><head><title>maludb-edge API</title></head><body><h1>maludb-edge API</h1><p>OpenAPI: <a href="/v1/openapi.json">/v1/openapi.json</a></p></body></html>'));
        $this->router = $router;
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (\RuntimeException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500;
            return Response::error('runtime_error', $e->getMessage(), $status);
        } catch (\Throwable $e) {
            return Response::error('internal_error', 'Internal server error', 500);
        }
    }
}
```

- [ ] **Step 5: Create front controller**

Create `html/index.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MaluDbEdge\App;
use MaluDbEdge\Config;
use MaluDbEdge\Db;
use MaluDbEdge\Request;

$config = Config::fromEnv();
$pdo = Db::sqlite($config->sqlitePath);
$response = (new App($config, $pdo))->handle(Request::fromGlobals());
$response->emit();
```

- [ ] **Step 6: Create Apache rewrite config**

Create `html/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

- [ ] **Step 7: Run tests**

Run: `cd html && composer dump-autoload && composer test`

Expected: PASS including `AppTest`.

- [ ] **Step 8: Commit**

```bash
git add html/src/Edge/App.php html/config/openapi.php html/index.php html/.htaccess html/tests/AppTest.php
git commit -m "feat: add foundation API routes"
```

## Task 7: CLI Migration and Admin Bootstrap

**Files:**
- Create: `html/src/Edge/Cli.php`
- Create: `html/bin/edge`
- Create: `html/tests/CliTest.php`

- [ ] **Step 1: Write failing CLI test**

Create `html/tests/CliTest.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\Cli;
use MaluDbEdge\Config;
use MaluDbEdge\Db;
use MaluDbEdge\Migrator;

final class CliTest extends TestCase
{
    public function testAdminCreateCreatesUserTenantAndKey(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-cli-');
        unlink($path);
        $config = new Config($path, str_repeat('a', 32), sys_get_temp_dir() . '/edge-archive', 'http://localhost');
        $pdo = Db::sqlite($path);
        (new Migrator($pdo, __DIR__ . '/../database/migrations'))->migrate();

        $output = (new Cli($config, $pdo))->run([
            'edge',
            'admin:create',
            '--email=admin@example.test',
            '--tenant=default',
            '--dsn=pgsql:host=127.0.0.1;dbname=maludb',
            '--username=malu',
            '--password=secret',
        ]);

        $this->assertTrue(str_contains($output, 'malu_'));
        $this->assertSame(1, (int)$pdo->query('SELECT count(*) FROM users')->fetchColumn());
        $this->assertSame(1, (int)$pdo->query('SELECT count(*) FROM tenants')->fetchColumn());
        $this->assertSame(1, (int)$pdo->query('SELECT count(*) FROM api_keys')->fetchColumn());
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd html && composer test`

Expected: FAIL with `Cli` not found.

- [ ] **Step 3: Implement `Cli`**

Create `html/src/Edge/Cli.php`:

```php
<?php
declare(strict_types=1);

namespace MaluDbEdge;

use PDO;

final readonly class Cli
{
    public function __construct(private Config $config, private PDO $pdo) {}

    public function run(array $argv): string
    {
        $command = $argv[1] ?? 'help';
        return match ($command) {
            'migrate' => $this->migrate(),
            'admin:create' => $this->adminCreate($argv),
            default => "Commands:\n  migrate\n  admin:create --email= --tenant= --dsn= --username= --password=\n",
        };
    }

    private function migrate(): string
    {
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        return "Migrations applied\n";
    }

    private function adminCreate(array $argv): string
    {
        $args = $this->parseArgs($argv);
        foreach (['email', 'tenant', 'dsn'] as $required) {
            if (($args[$required] ?? '') === '') {
                throw new \RuntimeException("Missing --{$required}");
            }
        }
        $crypto = new Crypto($this->config->requireAppKey());
        $this->pdo->beginTransaction();
        try {
            $user = $this->pdo->prepare("INSERT INTO users(email, display_name, role) VALUES(:email, :name, 'admin') ON CONFLICT(email) DO UPDATE SET role = 'admin' RETURNING id");
            $user->execute([':email' => $args['email'], ':name' => $args['email']]);
            $userId = (int)$user->fetchColumn();

            $tenant = $this->pdo->prepare('INSERT INTO tenants(name, dsn_encrypted, username_encrypted, password_encrypted) VALUES(:name, :dsn, :username, :password) ON CONFLICT(name) DO UPDATE SET dsn_encrypted = excluded.dsn_encrypted, username_encrypted = excluded.username_encrypted, password_encrypted = excluded.password_encrypted RETURNING id');
            $tenant->execute([
                ':name' => $args['tenant'],
                ':dsn' => $crypto->encrypt($args['dsn']),
                ':username' => isset($args['username']) ? $crypto->encrypt($args['username']) : null,
                ':password' => isset($args['password']) ? $crypto->encrypt($args['password']) : null,
            ]);
            $tenantId = (int)$tenant->fetchColumn();

            $plainKey = ApiKey::generate();
            $insertKey = $this->pdo->prepare("INSERT INTO api_keys(user_id, tenant_id, name, key_hash, key_fingerprint, role, scopes_json) VALUES(:user_id, :tenant_id, 'bootstrap-admin', :hash, :fingerprint, 'admin', :scopes)");
            $insertKey->execute([
                ':user_id' => $userId,
                ':tenant_id' => $tenantId,
                ':hash' => ApiKey::hash($plainKey),
                ':fingerprint' => ApiKey::fingerprint($plainKey),
                ':scopes' => json_encode(['*'], JSON_THROW_ON_ERROR),
            ]);
            $this->pdo->commit();
            return "Admin API key: {$plainKey}\n";
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function parseArgs(array $argv): array
    {
        $args = [];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            }
        }
        return $args;
    }
}
```

- [ ] **Step 4: Create CLI entry point**

Create `html/bin/edge`:

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MaluDbEdge\Cli;
use MaluDbEdge\Config;
use MaluDbEdge\Db;

$config = Config::fromEnv();
$pdo = Db::sqlite($config->sqlitePath);

try {
    echo (new Cli($config, $pdo))->run($argv);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
```

- [ ] **Step 5: Make CLI executable**

Run: `chmod +x html/bin/edge`

Expected: command exits successfully.

- [ ] **Step 6: Run tests**

Run: `cd html && composer dump-autoload && composer test`

Expected: PASS including `CliTest`.

- [ ] **Step 7: Run migrate command manually**

Run: `cd html && MALUDB_EDGE_APP_KEY=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa php bin/edge migrate`

Expected: `Migrations applied`.

- [ ] **Step 8: Commit**

```bash
git add html/src/Edge/Cli.php html/bin/edge html/tests/CliTest.php
git commit -m "feat: add edge bootstrap CLI"
```

## Task 8: Authenticated Endpoint Pattern and Audit Hook

**Files:**
- Modify: `html/src/Edge/App.php`
- Create: `html/tests/AuthenticatedEndpointTest.php`

- [ ] **Step 1: Write failing authenticated endpoint test**

Create `html/tests/AuthenticatedEndpointTest.php`:

```php
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
    public function testMeRequiresApiKey(): void
    {
        [$app] = $this->appWithKey();
        $response = $app->handle(new Request('GET', '/v1/me', [], null));
        $this->assertSame(401, $response->status);
    }

    public function testMeReturnsAuthenticatedContext(): void
    {
        [$app, $key] = $this->appWithKey();
        $response = $app->handle(new Request('GET', '/v1/me', ['authorization' => 'Bearer ' . $key], null));
        $this->assertSame(200, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertSame(1, $payload['user_id']);
        $this->assertSame(1, $payload['tenant_id']);
    }

    private function appWithKey(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-me-');
        unlink($path);
        $pdo = Db::sqlite($path);
        (new Migrator($pdo, __DIR__ . '/../database/migrations'))->migrate();
        $pdo->exec("INSERT INTO users(email, role) VALUES('user@example.test', 'reader')");
        $pdo->exec("INSERT INTO tenants(name, dsn_encrypted) VALUES('default', 'v1:test')");
        $key = 'malu_me_test';
        $stmt = $pdo->prepare("INSERT INTO api_keys(user_id, tenant_id, name, key_hash, key_fingerprint, role, scopes_json) VALUES(1, 1, 'me', :hash, :fingerprint, 'reader', '[]')");
        $stmt->execute([':hash' => ApiKey::hash($key), ':fingerprint' => ApiKey::fingerprint($key)]);
        $config = new Config($path, str_repeat('a', 32), sys_get_temp_dir() . '/edge-archive', 'http://localhost');
        return [new App($config, $pdo), $key];
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd html && composer test`

Expected: FAIL because `/v1/me` returns 404.

- [ ] **Step 3: Add authenticated helper and `/v1/me` route**

Modify `html/src/Edge/App.php` so it contains this route in the constructor after `/v1/docs`:

```php
$router->get('/v1/me', function (Request $request): Response {
    $context = $this->authenticate($request);
    return Response::json([
        'user_id' => $context->userId,
        'api_key_id' => $context->apiKeyId,
        'tenant_id' => $context->tenantId,
        'role' => $context->role,
        'scopes' => $context->scopes,
    ]);
});
```

Add this private method to `html/src/Edge/App.php`:

```php
private function authenticate(Request $request): AuthContext
{
    $context = (new AuthService($this->pdo))->authenticate($request->bearerToken());
    if ($context === null) {
        throw new \RuntimeException('Missing or invalid API key', 401);
    }
    return $context;
}
```

- [ ] **Step 4: Run tests**

Run: `cd html && composer dump-autoload && composer test`

Expected: PASS including `AuthenticatedEndpointTest`.

- [ ] **Step 5: Commit**

```bash
git add html/src/Edge/App.php html/tests/AuthenticatedEndpointTest.php
git commit -m "feat: add authenticated endpoint pattern"
```

## Task 9: Final Foundation Verification

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Update README with foundation commands**

Replace `README.md` with:

```markdown
# maludb-edge

Plain PHP REST and MCP edge gateway for MaluDB.

## Foundation Commands

```bash
cd html
composer install
composer dump-autoload
MALUDB_EDGE_APP_KEY=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa php bin/edge migrate
MALUDB_EDGE_APP_KEY=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa php bin/edge admin:create --email=admin@example.test --tenant=default --dsn='pgsql:host=127.0.0.1;port=5432;dbname=maludb' --username=maludb --password=secret
composer test
php -S 127.0.0.1:8080 -t .
```

Health endpoint:

```bash
curl http://127.0.0.1:8080/v1/health
```
```

- [ ] **Step 2: Run full test suite**

Run: `cd html && composer test`

Expected: PASS with every `*Test` class passing and final line `Failed: 0`.

- [ ] **Step 3: Run CLI bootstrap smoke**

Run:

```bash
cd html
rm -f var/edge.sqlite
MALUDB_EDGE_APP_KEY=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa php bin/edge migrate
MALUDB_EDGE_APP_KEY=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa php bin/edge admin:create --email=admin@example.test --tenant=default --dsn='pgsql:host=127.0.0.1;port=5432;dbname=maludb' --username=maludb --password=secret
```

Expected: first command prints `Migrations applied`; second command prints a line starting with `Admin API key: malu_`.

- [ ] **Step 4: Run local server smoke**

Run:

```bash
cd html
MALUDB_EDGE_APP_KEY=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa php -S 127.0.0.1:8080 -t .
```

In another shell run:

```bash
curl -s http://127.0.0.1:8080/v1/health
curl -s http://127.0.0.1:8080/v1/version
curl -s http://127.0.0.1:8080/v1/openapi.json
```

Expected responses include `{"status":"ok"}`, `{"name":"maludb-edge","version":"0.1.0"}`, and an OpenAPI JSON object.

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs: document foundation commands"
```

## Handoff Notes

After this plan is implemented, the next plan should add MaluDB connection resolution from the encrypted tenant record and implement the first driver-backed endpoints: `/v1/version`, `/v1/source-packages`, `/v1/claims`, `/v1/facts`, `/v1/memories`, `/v1/episodes`, `/v1/search/text`, and `/v1/retrievals`.
