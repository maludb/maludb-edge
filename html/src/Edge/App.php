<?php
declare(strict_types=1);

namespace MaluDbEdge;

use PDO;

final readonly class App
{
    public const NAME = 'maludb-edge';
    public const VERSION = '0.1.0';

    private Router $router;

    public function __construct(private Config $config, private PDO $pdo)
    {
        $router = new Router();
        $router->get('/v1/health', fn(Request $request) => Response::json(['status' => 'ok']));
        $router->get('/v1/version', fn(Request $request) => Response::json(['name' => self::NAME, 'version' => self::VERSION]));
        $router->get('/v1/openapi.json', fn(Request $request) => Response::json($this->openApi()));
        $router->get('/v1/docs', fn(Request $request) => Response::html('<!doctype html><html><head><title>maludb-edge API</title></head><body><h1>maludb-edge API</h1><p>OpenAPI: <a href="/v1/openapi.json">/v1/openapi.json</a></p></body></html>'));
        $router->get('/v1/me', function (Request $request): Response {
            $context = $this->authenticate($request);
            if ($context === null) {
                return Response::error('unauthorized', 'Invalid API key', 401);
            }

            return Response::json([
                'user_id' => $context->userId,
                'api_key_id' => $context->apiKeyId,
                'tenant_id' => $context->tenantId,
                'role' => $context->role,
                'scopes' => $context->scopes,
            ]);
        });
        $this->router = $router;
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (\RuntimeException $e) {
            if ($e->getCode() >= 400 && $e->getCode() <= 499) {
                return Response::error('runtime_error', $e->getMessage(), $e->getCode());
            }

            return Response::error('internal_error', 'Internal server error', 500);
        } catch (\Throwable $e) {
            return Response::error('internal_error', 'Internal server error', 500);
        }
    }

    private function openApi(): array
    {
        $openApi = require dirname(__DIR__, 2) . '/config/openapi.php';
        $openApi['info']['title'] = self::NAME . ' API';
        $openApi['info']['version'] = self::VERSION;
        return $openApi;
    }

    private function authenticate(Request $request): ?AuthContext
    {
        return (new AuthService($this->pdo))->authenticate($request->bearerToken());
    }
}
