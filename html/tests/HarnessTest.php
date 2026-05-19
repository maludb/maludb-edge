<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

final class HarnessTest extends TestCase
{
    public function testHarnessRunsTests(): void
    {
        $this->assertTrue(true);
    }

    public function testRunnerFailsWhenNoTestsAreDiscovered(): void
    {
        $root = $this->createRunnerSandbox('empty-tests');

        try {
            exec(PHP_BINARY . ' ' . escapeshellarg($root . '/tests/run.php') . ' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testRunnerOnlyRunsConcreteTestCaseSubclasses(): void
    {
        $root = $this->createRunnerSandbox('filtered-tests');

        try {
            file_put_contents($root . '/tests/RunnerFilteringTest.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

abstract class TestCase
{
}

final class RunnerHelperTest
{
    public function testHelperClassWouldFailIfRun(): void
    {
        throw new \RuntimeException('helper class was run');
    }
}

abstract class AbstractRunnerTest extends TestCase
{
    public function testAbstractClassWouldFailIfRun(): void
    {
        throw new \RuntimeException('abstract class was run');
    }
}

final class ConcreteRunnerTest extends TestCase
{
    public function testConcreteSubclassRuns(): void
    {
    }
}
PHP);

            exec(PHP_BINARY . ' ' . escapeshellarg($root . '/tests/run.php') . ' 2>&1', $output, $exitCode);

            $outputText = implode("\n", $output);
            $this->assertSame(0, $exitCode, $outputText);
            $this->assertTrue(
                str_contains($outputText, 'PASS MaluDbEdge\Tests\ConcreteRunnerTest::testConcreteSubclassRuns'),
                $outputText
            );
            $this->assertFalse(str_contains($outputText, 'helper class was run'), $outputText);
            $this->assertFalse(str_contains($outputText, 'abstract class was run'), $outputText);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function createRunnerSandbox(string $name): string
    {
        $root = sys_get_temp_dir() . '/maludb-edge-' . $name . '-' . bin2hex(random_bytes(8));
        mkdir($root . '/tests', 0777, true);
        mkdir($root . '/vendor', 0777, true);
        file_put_contents($root . '/vendor/autoload.php', "<?php\n");
        copy(__DIR__ . '/run.php', $root . '/tests/run.php');

        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
