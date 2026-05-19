<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MaluDbEdge\App;
use MaluDbEdge\Config;
use MaluDbEdge\Db;
use MaluDbEdge\Request;
use MaluDbEdge\Response;

try {
    $config = Config::fromEnv();
    $pdo = Db::sqlite($config->sqlitePath);
    $response = (new App($config, $pdo))->handle(Request::fromGlobals());
} catch (Throwable $e) {
    $response = Response::error('internal_error', 'Internal server error', 500);
}

$response->emit();
