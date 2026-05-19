<?php
declare(strict_types=1);

namespace MaluDbEdge\Tests;

use MaluDbEdge\Request;
use MaluDbEdge\Response;
use MaluDbEdge\Router;

final class RouterTest extends TestCase
{
    public function testMatchesStaticRoute(): void
    {
        $router = new Router();
        $router->get('/v1/health', fn(Request $request) => Response::json(['status' => 'ok']));
        $response = $router->dispatch(new Request('GET', '/v1/health', [], null));
        $this->assertSame(200, $response->status);
        $this->assertSame('{"status":"ok"}', $response->body);
    }

    public function testDispatchNormalizesLowercaseRequestMethod(): void
    {
        $router = new Router();
        $router->get('/v1/health', fn(Request $request) => Response::json(['status' => 'ok']));
        $response = $router->dispatch(new Request('get', '/v1/health', [], null));
        $this->assertSame(200, $response->status);
        $this->assertSame('{"status":"ok"}', $response->body);
    }

    public function testMatchesRouteParam(): void
    {
        $router = new Router();
        $router->get('/v1/users/{id}', fn(Request $request) => Response::json(['id' => $request->param('id')]));
        $response = $router->dispatch(new Request('GET', '/v1/users/42', [], null));
        $this->assertSame('{"id":"42"}', $response->body);
    }

    public function testReturns404(): void
    {
        $router = new Router();
        $response = $router->dispatch(new Request('GET', '/missing', [], null));
        $this->assertSame(404, $response->status);
    }

    public function testEscapesStaticRouteCharacters(): void
    {
        $router = new Router();
        $router->get('/v1/files/report.json', fn(Request $request) => Response::json(['matched' => true]));
        $response = $router->dispatch(new Request('GET', '/v1/files/reportxjson', [], null));
        $this->assertSame(404, $response->status);
    }

    public function testBearerTokenUsesMixedCaseAuthorizationHeader(): void
    {
        $request = new Request('GET', '/v1/health', ['Authorization' => 'Bearer secret-key'], null);
        $this->assertSame('secret-key', $request->bearerToken());
    }

    public function testBearerTokenFallsBackToMixedCaseMaluDbKeyHeader(): void
    {
        $request = new Request('GET', '/v1/health', ['X-MaluDB-Key' => 'fallback-key'], null);
        $this->assertSame('fallback-key', $request->bearerToken());
    }

    public function testFromGlobalsMapsServerHeadersWhenGetAllHeadersIsUnavailable(): void
    {
        $server = $_SERVER;
        try {
            $_SERVER = [
                'REQUEST_METHOD' => 'post',
                'REQUEST_URI' => '/v1/health?check=1',
                'HTTP_AUTHORIZATION' => 'Bearer server-key',
                'HTTP_X_MALUDB_KEY' => 'fallback-key',
                'HTTP_X_CUSTOM_HEADER' => 'custom-value',
            ];

            $request = Request::fromGlobals();

            $this->assertSame('POST', $request->method);
            $this->assertSame('/v1/health', $request->path);
            $this->assertSame('server-key', $request->bearerToken());
            $this->assertSame('custom-value', $request->headers['x-custom-header']);
        } finally {
            $_SERVER = $server;
        }
    }

    public function testHeaderResolutionFallsBackToServerWhenGetAllHeadersReturnsEmptyArray(): void
    {
        $headers = $this->resolveHeadersFromEnvironment([
            'HTTP_AUTHORIZATION' => 'Bearer server-key',
            'HTTP_X_MALUDB_KEY' => 'fallback-key',
            'HTTP_X_CUSTOM_HEADER' => 'custom-value',
        ], []);

        $this->assertSame('Bearer server-key', $headers['authorization']);
        $this->assertSame('fallback-key', $headers['x-maludb-key']);
        $this->assertSame('custom-value', $headers['x-custom-header']);
    }

    public function testHeaderResolutionFallsBackToServerWhenGetAllHeadersReturnsNonArray(): void
    {
        $headers = $this->resolveHeadersFromEnvironment([
            'HTTP_AUTHORIZATION' => 'Bearer server-key',
            'HTTP_X_MALUDB_KEY' => 'fallback-key',
            'HTTP_X_CUSTOM_HEADER' => 'custom-value',
        ], false);

        $this->assertSame('Bearer server-key', $headers['authorization']);
        $this->assertSame('fallback-key', $headers['x-maludb-key']);
        $this->assertSame('custom-value', $headers['x-custom-header']);
    }

    public function testHeaderResolutionMergesIncompleteGetAllHeadersWithServerAuthHeaders(): void
    {
        $headers = $this->resolveHeadersFromEnvironment([
            'HTTP_AUTHORIZATION' => 'Bearer server-key',
            'HTTP_X_MALUDB_KEY' => 'fallback-key',
            'HTTP_X_TRACE_ID' => 'server-trace',
        ], [
            'X-Trace-Id' => 'explicit-trace',
            'X-Request-Id' => 'request-id',
        ]);

        $this->assertSame('Bearer server-key', $headers['authorization']);
        $this->assertSame('fallback-key', $headers['x-maludb-key']);
        $this->assertSame('explicit-trace', $headers['x-trace-id']);
        $this->assertSame('request-id', $headers['x-request-id']);
    }

    public function testHeaderResolutionMapsContentTypeAndContentLengthFromServer(): void
    {
        $headers = $this->resolveHeadersFromEnvironment([
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '42',
        ], []);

        $this->assertSame('application/json', $headers['content-type']);
        $this->assertSame('42', $headers['content-length']);
    }

    public function testResponseErrorShape(): void
    {
        $response = Response::error('invalid_request', 'Invalid request', 400, 'Missing id');
        $this->assertSame(400, $response->status);
        $this->assertSame(['Content-Type' => 'application/json'], $response->headers);
        $this->assertSame('{"error":{"code":"invalid_request","message":"Invalid request","detail":"Missing id"}}', $response->body);
    }

    public function testResponseHtmlContentType(): void
    {
        $response = Response::html('<h1>ok</h1>');
        $this->assertSame(200, $response->status);
        $this->assertSame(['Content-Type' => 'text/html; charset=utf-8'], $response->headers);
        $this->assertSame('<h1>ok</h1>', $response->body);
    }

    public function testRejectsDuplicateRouteParamNames(): void
    {
        $router = new Router();
        try {
            $router->get('/v1/users/{id}/posts/{id}', fn(Request $request) => Response::json(['ok' => true]));
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Duplicate route parameter: id', $exception->getMessage());
            return;
        }

        throw new \RuntimeException('Expected InvalidArgumentException');
    }

    private function resolveHeadersFromEnvironment(array $server, mixed $headers): array
    {
        $method = new \ReflectionMethod(Request::class, 'headersFromEnvironment');
        return $method->invoke(null, $server, $headers);
    }
}
