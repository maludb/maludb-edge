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
        if ($path === false) {
            throw new \RuntimeException('Could not create temporary database path');
        }
        unlink($path);

        $pdo = null;
        try {
            $pdo = Db::sqlite($path);
            $migrator = new Migrator($pdo, __DIR__ . '/../database/migrations');
            $migrator->migrate();

            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertTrue(in_array('users', $tables, true));
            $this->assertTrue(in_array('tenants', $tables, true));
            $this->assertTrue(in_array('api_keys', $tables, true));
            $this->assertTrue(in_array('audit_events', $tables, true));
            $this->assertTrue(in_array('schema_migrations', $tables, true));

            $count = (int)$pdo->query('SELECT count(*) FROM schema_migrations')->fetchColumn();
            $this->assertSame(1, $count);

            $migrator->migrate();
            $count = (int)$pdo->query('SELECT count(*) FROM schema_migrations')->fetchColumn();
            $this->assertSame(1, $count);
        } finally {
            $pdo = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testMigrateFailsWhenMigrationDirectoryIsMissing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-db-');
        if ($path === false) {
            throw new \RuntimeException('Could not create temporary database path');
        }
        unlink($path);

        $pdo = null;
        try {
            $pdo = Db::sqlite($path);
            $missingDir = sys_get_temp_dir() . '/edge-missing-migrations-' . bin2hex(random_bytes(8));

            try {
                (new Migrator($pdo, $missingDir))->migrate();
                $this->assertTrue(false, 'Expected missing migration directory to throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'Migration directory does not exist or is not readable'));
            }
        } finally {
            $pdo = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testMigrateFailsWhenMigrationDirectoryHasNoSqlFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-db-');
        if ($path === false) {
            throw new \RuntimeException('Could not create temporary database path');
        }
        unlink($path);

        $emptyDir = sys_get_temp_dir() . '/edge-empty-migrations-' . bin2hex(random_bytes(8));
        if (!mkdir($emptyDir, 0775)) {
            throw new \RuntimeException('Could not create empty migration directory');
        }

        $pdo = null;
        try {
            $pdo = Db::sqlite($path);

            try {
                (new Migrator($pdo, $emptyDir))->migrate();
                $this->assertTrue(false, 'Expected empty migration directory to throw');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'No SQL migration files found'));
            }
        } finally {
            $pdo = null;
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($emptyDir)) {
                rmdir($emptyDir);
            }
        }
    }
}
