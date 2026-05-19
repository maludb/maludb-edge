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
