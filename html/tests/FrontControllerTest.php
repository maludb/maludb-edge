<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

final class FrontControllerTest extends TestCase
{
    public function testBootstrapFailuresEmitJsonError(): void
    {
        $command = 'MALUDB_EDGE_SQLITE=' . escapeshellarg('/proc/maludb-edge.sqlite')
            . ' ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../index.php')
            . ' 2>&1';

        exec($command, $output, $exitCode);

        $body = implode("\n", $output);
        $this->assertSame(0, $exitCode, $body);
        $this->assertSame('{"error":{"code":"internal_error","message":"Internal server error"}}', $body);
        $this->assertFalse(str_contains($body, 'Fatal error'), $body);
        $this->assertFalse(str_contains($body, 'PDOException'), $body);
    }
}
