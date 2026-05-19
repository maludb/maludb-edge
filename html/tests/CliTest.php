<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\ApiKey;
use MaluDbEdge\AuthService;
use MaluDbEdge\Cli;
use MaluDbEdge\Config;
use MaluDbEdge\Crypto;
use MaluDbEdge\Db;
use PDO;

final class CliTest extends TestCase
{
    public function testMigrateRunsFoundationMigrations(): void
    {
        [$pdo, $path] = $this->temporaryDatabase();

        try {
            $cli = new Cli($pdo, $this->configFor($path));

            $this->assertSame("Migrations applied\n", $cli->run(['edge', 'migrate']));

            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $this->assertTrue(in_array('users', $tables, true));
            $this->assertTrue(in_array('tenants', $tables, true));
            $this->assertTrue(in_array('api_keys', $tables, true));
        } finally {
            $pdo = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAdminCreateCreatesEncryptedTenantUserAndOneTimeApiKey(): void
    {
        [$pdo, $path] = $this->migratedTemporaryDatabase();
        $config = $this->configFor($path);

        try {
            $output = (new Cli($pdo, $config))->run([
                'edge',
                'admin:create',
                '--email=admin@example.test',
                '--tenant=default',
                '--dsn=sqlite:/secret/example.db',
                '--username=root',
                '--password=top-secret',
            ]);

            $this->assertTrue(str_starts_with($output, 'Admin API key: malu_'));
            $this->assertTrue(str_ends_with($output, "\n"));
            $plainKey = trim(substr($output, strlen('Admin API key: ')));

            $user = $pdo->query("SELECT id, email, role, disabled_at FROM users WHERE email = 'admin@example.test'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('admin@example.test', $user['email']);
            $this->assertSame('admin', $user['role']);
            $this->assertSame(null, $user['disabled_at']);

            $tenant = $pdo->query("SELECT id, name, dsn_encrypted, username_encrypted, password_encrypted, disabled_at FROM tenants WHERE name = 'default'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('default', $tenant['name']);
            $this->assertSame(null, $tenant['disabled_at']);
            $this->assertFalse(str_contains((string)$tenant['dsn_encrypted'], 'sqlite:/secret/example.db'));
            $this->assertFalse(str_contains((string)$tenant['username_encrypted'], 'root'));
            $this->assertFalse(str_contains((string)$tenant['password_encrypted'], 'top-secret'));

            $crypto = new Crypto($config->requireAppKey());
            $this->assertSame('sqlite:/secret/example.db', $crypto->decrypt((string)$tenant['dsn_encrypted']));
            $this->assertSame('root', $crypto->decrypt((string)$tenant['username_encrypted']));
            $this->assertSame('top-secret', $crypto->decrypt((string)$tenant['password_encrypted']));

            $keyRow = $pdo->query('SELECT name, key_hash, key_fingerprint, role, scopes_json FROM api_keys')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('admin bootstrap', $keyRow['name']);
            $this->assertSame(ApiKey::fingerprint($plainKey), $keyRow['key_fingerprint']);
            $this->assertTrue(ApiKey::verify($plainKey, (string)$keyRow['key_hash']));
            $this->assertSame('admin', $keyRow['role']);
            $this->assertSame(['*', 'admin:*'], json_decode((string)$keyRow['scopes_json'], true));
            $this->assertFalse(str_contains((string)$keyRow['key_hash'], $plainKey));

            $context = (new AuthService($pdo))->authenticate($plainKey);
            $this->assertSame('admin', $context->role);
            $this->assertTrue($context->hasScope('anything:anywhere'));
        } finally {
            $pdo = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAdminCreateIsIdempotentForUserAndTenantButCreatesNewApiKey(): void
    {
        [$pdo, $path] = $this->migratedTemporaryDatabase();
        $config = $this->configFor($path);
        $cli = new Cli($pdo, $config);

        try {
            $args = [
                'edge',
                'admin:create',
                '--email=admin@example.test',
                '--tenant=default',
                '--dsn=sqlite:/first.db',
                '--username=first',
                '--password=first-secret',
            ];
            $first = $cli->run($args);
            $second = $cli->run([
                'edge',
                'admin:create',
                '--email=admin@example.test',
                '--tenant=default',
                '--dsn=sqlite:/second.db',
                '--username=second',
                '--password=second-secret',
            ]);

            $this->assertFalse($first === $second, 'Expected a fresh one-time API key on each bootstrap');
            $this->assertSame(1, (int)$pdo->query('SELECT count(*) FROM users')->fetchColumn());
            $this->assertSame(1, (int)$pdo->query('SELECT count(*) FROM tenants')->fetchColumn());
            $this->assertSame(2, (int)$pdo->query('SELECT count(*) FROM api_keys')->fetchColumn());

            $crypto = new Crypto($config->requireAppKey());
            $tenant = $pdo->query("SELECT dsn_encrypted, username_encrypted, password_encrypted FROM tenants WHERE name = 'default'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('sqlite:/second.db', $crypto->decrypt((string)$tenant['dsn_encrypted']));
            $this->assertSame('second', $crypto->decrypt((string)$tenant['username_encrypted']));
            $this->assertSame('second-secret', $crypto->decrypt((string)$tenant['password_encrypted']));
        } finally {
            $pdo = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testUnknownCommandReturnsHelp(): void
    {
        [$pdo, $path] = $this->temporaryDatabase();

        try {
            $output = (new Cli($pdo, $this->configFor($path)))->run(['edge', 'bogus']);

            $this->assertTrue(str_contains($output, "Usage:\n"));
            $this->assertTrue(str_contains($output, 'migrate'));
            $this->assertTrue(str_contains($output, 'admin:create --email= --tenant= --dsn= --username= --password='));
        } finally {
            $pdo = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAdminCreateRejectsEmptyCredentialOptions(): void
    {
        [$pdo, $path] = $this->migratedTemporaryDatabase();
        $cli = new Cli($pdo, $this->configFor($path));

        try {
            foreach (['dsn', 'username', 'password'] as $emptyOption) {
                $args = [
                    'edge',
                    'admin:create',
                    '--email=admin@example.test',
                    '--tenant=default',
                    '--dsn=sqlite:/example.db',
                    '--username=root',
                    '--password=secret',
                ];
                foreach ($args as $index => $arg) {
                    if (str_starts_with($arg, "--{$emptyOption}=")) {
                        $args[$index] = "--{$emptyOption}=";
                    }
                }

                try {
                    $cli->run($args);
                    $this->assertTrue(false, "Expected empty --{$emptyOption}= to throw");
                } catch (\InvalidArgumentException $e) {
                    $this->assertTrue(str_contains($e->getMessage(), "--{$emptyOption}= must not be empty"));
                }
            }
        } finally {
            $pdo = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAdminCreateRejectsUnknownAndMalformedOptions(): void
    {
        [$pdo, $path] = $this->migratedTemporaryDatabase();
        $cli = new Cli($pdo, $this->configFor($path));

        try {
            try {
                $cli->run([
                    'edge',
                    'admin:create',
                    '--email=admin@example.test',
                    '--tenant=default',
                    '--dsn=sqlite:/example.db',
                    '--username=root',
                    '--password=secret',
                    '--role=admin',
                ]);
                $this->assertTrue(false, 'Expected unknown option to throw');
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'Unknown option --role'));
            }

            try {
                $cli->run([
                    'edge',
                    'admin:create',
                    '--email=admin@example.test',
                    '--tenant=default',
                    '--dsn',
                    '--username=root',
                    '--password=secret',
                ]);
                $this->assertTrue(false, 'Expected malformed option to throw');
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'Malformed option --dsn'));
            }
        } finally {
            $pdo = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testFrontCommandExistsAndIsExecutable(): void
    {
        $path = dirname(__DIR__) . '/bin/edge';

        $this->assertTrue(is_file($path));
        $this->assertTrue(is_executable($path));
    }

    public function testFrontCommandGuardsAgainstNonCliSapiBeforeBootstrap(): void
    {
        $contents = file_get_contents(dirname(__DIR__) . '/bin/edge');
        $guardOffset = strpos($contents, "PHP_SAPI !== 'cli'");
        $bootstrapOffset = strpos($contents, 'Config::fromEnv()');

        $this->assertTrue($guardOffset !== false, 'Expected PHP_SAPI cli guard');
        $this->assertTrue($bootstrapOffset !== false, 'Expected CLI bootstrap');
        $this->assertTrue($guardOffset < $bootstrapOffset, 'SAPI guard must run before command bootstrap');
        $this->assertTrue(str_contains($contents, 'CLI only'));
    }


    /**
     * @return array{0: PDO, 1: string}
     */
    private function temporaryDatabase(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-cli-');
        if ($path === false) {
            throw new \RuntimeException('Could not create temporary database path');
        }
        unlink($path);

        return [Db::sqlite($path), $path];
    }

    /**
     * @return array{0: PDO, 1: string}
     */
    private function migratedTemporaryDatabase(): array
    {
        [$pdo, $path] = $this->temporaryDatabase();
        (new Cli($pdo, $this->configFor($path)))->run(['edge', 'migrate']);

        return [$pdo, $path];
    }

    private function configFor(string $sqlitePath): Config
    {
        return new Config(
            sqlitePath: $sqlitePath,
            appKey: 'test-app-key-with-at-least-thirty-two-bytes',
            archivePath: sys_get_temp_dir() . '/edge-cli-archive',
            baseUrl: 'http://localhost',
        );
    }
}
