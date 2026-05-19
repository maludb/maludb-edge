<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\Config;

final class ConfigTest extends TestCase
{
    public function testDefaultRuntimePathsAreOutsideDocroot(): void
    {
        $this->withClearedEnvironment([
            'MALUDB_EDGE_SQLITE',
            'MALUDB_EDGE_ARCHIVE',
            'MALUDB_EDGE_APP_KEY',
            'MALUDB_EDGE_BASE_URL',
        ], function (): void {
            $config = Config::fromEnv();
            $repositoryRoot = dirname(__DIR__, 2);
            $docroot = dirname(__DIR__);

            $this->assertSame($repositoryRoot . '/var/edge.sqlite', $config->sqlitePath);
            $this->assertSame($repositoryRoot . '/var/archive', $config->archivePath);
            $this->assertFalse(str_starts_with($config->sqlitePath, $docroot . '/'));
            $this->assertFalse(str_starts_with($config->archivePath, $docroot . '/'));
        });
    }

    /**
     * @param list<string> $names
     */
    private function withClearedEnvironment(array $names, callable $callback): void
    {
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = getenv($name);
            putenv($name);
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === false) {
                    putenv($name);
                    continue;
                }

                putenv($name . '=' . $value);
            }
        }
    }
}
