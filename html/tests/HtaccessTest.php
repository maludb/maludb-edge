<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

final class HtaccessTest extends TestCase
{
    public function testPrivatePathsAreDeniedBeforeStaticFileBypass(): void
    {
        $contents = file_get_contents(__DIR__ . '/../.htaccess');

        $denyOffset = strpos($contents, '[F,L]');
        $staticBypassOffset = strpos($contents, 'REQUEST_FILENAME} !-f');
        $this->assertTrue($denyOffset !== false, 'Expected private path deny rules');
        $this->assertTrue($staticBypassOffset !== false, 'Expected static file bypass');
        $this->assertTrue($denyOffset < $staticBypassOffset, 'Deny rules must run before static file bypass');

        foreach (['var', 'runtime', 'bin', 'config', 'database', 'src', 'source', 'test', 'tests', 'vendor'] as $privateDirectory) {
            $this->assertTrue(str_contains($contents, $privateDirectory), "Missing deny rule for {$privateDirectory}");
        }

        foreach (['composer\\.', '\\.env', 'test-connection\\.php', 'sqlite', 'sqlite3', 'db'] as $privateFilePattern) {
            $this->assertTrue(str_contains($contents, $privateFilePattern), "Missing deny rule for {$privateFilePattern}");
        }
    }
}
