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
    if (!str_starts_with($class, 'MaluDbEdge\\Tests\\')) {
        continue;
    }

    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract() || !$reflection->isSubclassOf('MaluDbEdge\\Tests\\TestCase')) {
        continue;
    }

    $object = $reflection->newInstance();
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

if ($passed + $failed === 0) {
    fwrite(STDERR, "No tests discovered\n");
    fwrite(STDOUT, "Passed: {$passed}; Failed: {$failed}\n");
    exit(1);
}

fwrite(STDOUT, "Passed: {$passed}; Failed: {$failed}\n");
exit($failed === 0 ? 0 : 1);
