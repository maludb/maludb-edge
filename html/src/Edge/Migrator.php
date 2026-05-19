<?php
declare(strict_types=1);

namespace MaluDbEdge;

use PDO;

final readonly class Migrator
{
    public function __construct(private PDO $pdo, private string $migrationDir) {}

    public function migrate(): void
    {
        if (!is_dir($this->migrationDir) || !is_readable($this->migrationDir)) {
            throw new \RuntimeException("Migration directory does not exist or is not readable: {$this->migrationDir}");
        }

        $files = glob($this->migrationDir . '/*.sql') ?: [];
        sort($files);
        if ($files === []) {
            throw new \RuntimeException("No SQL migration files found in migration directory: {$this->migrationDir}");
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

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
