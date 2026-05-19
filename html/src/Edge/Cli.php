<?php
declare(strict_types=1);

namespace MaluDbEdge;

use PDO;

final readonly class Cli
{
    private const ADMIN_CREATE_OPTIONS = ['email', 'tenant', 'dsn', 'username', 'password'];

    public function __construct(
        private PDO $pdo,
        private Config $config,
        private ?string $migrationDir = null,
    ) {}

    public function run(array $argv): string
    {
        $command = (string)($argv[1] ?? 'help');

        return match ($command) {
            'migrate' => $this->migrate(),
            'admin:create' => $this->createAdmin($argv),
            default => self::help(),
        };
    }

    private function migrate(): string
    {
        (new Migrator($this->pdo, $this->migrationDir ?? dirname(__DIR__, 2) . '/database/migrations'))->migrate();

        return "Migrations applied\n";
    }

    private function createAdmin(array $argv): string
    {
        $options = $this->parseOptions($argv);
        foreach (self::ADMIN_CREATE_OPTIONS as $name) {
            if (!array_key_exists($name, $options)) {
                throw new \InvalidArgumentException("Missing required option --{$name}=");
            }
        }
        if (!filter_var($options['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Admin email must be valid');
        }
        if ($options['tenant'] === '') {
            throw new \InvalidArgumentException('Tenant name must not be empty');
        }
        foreach (['dsn', 'username', 'password'] as $name) {
            if ($options[$name] === '') {
                throw new \InvalidArgumentException("--{$name}= must not be empty");
            }
        }

        $crypto = new Crypto($this->config->requireAppKey());
        $encryptedDsn = $crypto->encrypt($options['dsn']);
        $encryptedUsername = $crypto->encrypt($options['username']);
        $encryptedPassword = $crypto->encrypt($options['password']);

        $this->pdo->beginTransaction();
        try {
            $userId = $this->upsertAdminUser($options['email']);
            $tenantId = $this->upsertTenant($options['tenant'], $encryptedDsn, $encryptedUsername, $encryptedPassword);
            $plainKey = $this->insertAdminApiKey($userId, $tenantId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return "Admin API key: {$plainKey}\n";
    }

    /**
     * @return array<string, string>
     */
    private function parseOptions(array $argv): array
    {
        $options = [];
        foreach (array_slice($argv, 2) as $arg) {
            if (!is_string($arg) || !str_starts_with($arg, '--')) {
                throw new \InvalidArgumentException('Malformed option ' . (string)$arg);
            }
            if (!str_contains($arg, '=')) {
                throw new \InvalidArgumentException("Malformed option {$arg}");
            }
            [$name, $value] = explode('=', substr($arg, 2), 2);
            if ($name === '') {
                throw new \InvalidArgumentException("Malformed option {$arg}");
            }
            if (!in_array($name, self::ADMIN_CREATE_OPTIONS, true)) {
                throw new \InvalidArgumentException("Unknown option --{$name}");
            }
            $options[$name] = $value;
        }

        return $options;
    }

    private function upsertAdminUser(string $email): int
    {
        $upsert = $this->pdo->prepare(
            "INSERT INTO users(email, display_name, role, disabled_at, updated_at)
             VALUES(:email, :display_name, 'admin', NULL, CURRENT_TIMESTAMP)
             ON CONFLICT(email) DO UPDATE SET
                 role = 'admin',
                 disabled_at = NULL,
                 updated_at = CURRENT_TIMESTAMP"
        );
        $upsert->execute([
            ':email' => $email,
            ':display_name' => $email,
        ]);

        return $this->selectId('users', 'email', $email);
    }

    private function upsertTenant(string $name, string $dsn, string $username, string $password): int
    {
        $upsert = $this->pdo->prepare(
            'INSERT INTO tenants(name, dsn_encrypted, username_encrypted, password_encrypted)
             VALUES(:name, :dsn, :username, :password)
             ON CONFLICT(name) DO UPDATE SET
                 dsn_encrypted = excluded.dsn_encrypted,
                 username_encrypted = excluded.username_encrypted,
                 password_encrypted = excluded.password_encrypted,
                 disabled_at = NULL,
                 updated_at = CURRENT_TIMESTAMP'
        );
        $upsert->execute([
            ':name' => $name,
            ':dsn' => $dsn,
            ':username' => $username,
            ':password' => $password,
        ]);

        return $this->selectId('tenants', 'name', $name);
    }

    private function selectId(string $table, string $column, string $value): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE {$column} = :value");
        $stmt->execute([':value' => $value]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Could not load {$table} row after upsert");
        }

        return (int)$id;
    }

    private function insertAdminApiKey(int $userId, int $tenantId): string
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO api_keys(user_id, tenant_id, name, key_hash, key_fingerprint, role, scopes_json)
             VALUES(:user_id, :tenant_id, :name, :key_hash, :key_fingerprint, :role, :scopes_json)'
        );
        $scopesJson = json_encode(['*', 'admin:*'], JSON_THROW_ON_ERROR);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $plainKey = ApiKey::generate();
            try {
                $insert->execute([
                    ':user_id' => $userId,
                    ':tenant_id' => $tenantId,
                    ':name' => 'admin bootstrap',
                    ':key_hash' => ApiKey::hash($plainKey),
                    ':key_fingerprint' => ApiKey::fingerprint($plainKey),
                    ':role' => 'admin',
                    ':scopes_json' => $scopesJson,
                ]);

                return $plainKey;
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not generate a unique API key fingerprint');
    }

    private static function help(): string
    {
        return <<<HELP
Usage:
  edge migrate
  edge admin:create --email= --tenant= --dsn= --username= --password=

HELP;
    }
}
