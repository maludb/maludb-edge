<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\App;
use MaluDbEdge\Config;
use MaluDbEdge\Db;
use MaluDbEdge\Migrator;
use MaluDbEdge\Request;

final class AppTest extends TestCase
{
    /** @var list<string> */
    private array $sqlitePaths = [];

    public function __destruct()
    {
        foreach ($this->sqlitePaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testHealthReturnsOk(): void
    {
        $app = $this->app();
        $response = $app->handle(new Request('GET', '/v1/health', [], null));
        $this->assertSame(200, $response->status);
        $this->assertSame('{"status":"ok"}', $response->body);
    }

    public function testVersionReturnsEdgeVersion(): void
    {
        $app = $this->app();
        $response = $app->handle(new Request('GET', '/v1/version', [], null));
        $this->assertSame(200, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertSame('maludb-edge', $payload['name']);
    }

    public function testOpenApiReturnsJsonAndMatchesVersionMetadata(): void
    {
        $app = $this->app();

        $version = $app->handle(new Request('GET', '/v1/version', [], null));
        $openApi = $app->handle(new Request('GET', '/v1/openapi.json', [], null));

        $this->assertSame(200, $openApi->status);
        $this->assertSame('application/json', $openApi->headers['Content-Type']);

        $versionPayload = json_decode($version->body, true);
        $openApiPayload = json_decode($openApi->body, true);
        $this->assertSame('3.1.0', $openApiPayload['openapi']);
        $this->assertSame('maludb-edge API', $openApiPayload['info']['title']);
        $this->assertSame($versionPayload['version'], $openApiPayload['info']['version']);
        $this->assertArrayHasKey('/v1/me', $openApiPayload['paths']);
        $this->assertArrayHasKey('ApiKeyAuth', $openApiPayload['components']['securitySchemes']);
        $this->assertSame(
            [['ApiKeyAuth' => []]],
            $openApiPayload['paths']['/v1/me']['get']['security']
        );
    }

    public function testDocsReturnsHtml(): void
    {
        $app = $this->app();
        $response = $app->handle(new Request('GET', '/v1/docs', [], null));
        $this->assertSame(200, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testMissingRouteReturns404(): void
    {
        $app = $this->app();
        $response = $app->handle(new Request('GET', '/v1/missing', [], null));
        $this->assertSame(404, $response->status);
        $this->assertSame('{"error":{"code":"not_found","message":"Route not found"}}', $response->body);
    }

    public function testMethodMismatchReturns404(): void
    {
        $app = $this->app();
        $response = $app->handle(new Request('POST', '/v1/health', [], null));
        $this->assertSame(404, $response->status);
        $this->assertSame('{"error":{"code":"not_found","message":"Route not found"}}', $response->body);
    }

    public function testFourHundredRuntimeErrorsKeepMessage(): void
    {
        $app = $this->app();
        $this->router($app)->get('/v1/bad-request', fn(Request $request) => throw new \RuntimeException('Invalid app request', 422));

        $response = $app->handle(new Request('GET', '/v1/bad-request', [], null));

        $this->assertSame(422, $response->status);
        $this->assertSame('{"error":{"code":"runtime_error","message":"Invalid app request"}}', $response->body);
    }

    public function testFiveHundredRuntimeErrorsHideMessage(): void
    {
        $app = $this->app();
        $this->router($app)->get('/v1/fail', fn(Request $request) => throw new \RuntimeException('sensitive sqlite path /secret/edge.sqlite'));

        $response = $app->handle(new Request('GET', '/v1/fail', [], null));

        $this->assertSame(500, $response->status);
        $this->assertSame('{"error":{"code":"internal_error","message":"Internal server error"}}', $response->body);
        $this->assertFalse(str_contains($response->body, 'sensitive sqlite path'));
    }

    private function app(): App
    {
        $path = tempnam(sys_get_temp_dir(), 'edge-app-');
        unlink($path);
        $this->sqlitePaths[] = $path;
        $pdo = Db::sqlite($path);
        (new Migrator($pdo, __DIR__ . '/../database/migrations'))->migrate();
        $config = new Config($path, str_repeat('a', 32), sys_get_temp_dir() . '/edge-archive', 'http://localhost');
        return new App($config, $pdo);
    }

    private function router(App $app): \MaluDbEdge\Router
    {
        $property = new \ReflectionProperty(App::class, 'router');
        return $property->getValue($app);
    }
}
