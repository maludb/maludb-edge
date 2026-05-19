<?php
declare(strict_types=1);

namespace MaluDbEdge;

final class Request
{
    private array $params = [];

    public readonly string $method;
    public readonly string $path;
    public readonly array $headers;
    public readonly mixed $body;

    public function __construct(string $method, string $path, array $headers, mixed $body)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->headers = self::normalizeHeaders($headers);
        $this->body = $body;
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = self::headersFromEnvironment(
            $_SERVER,
            function_exists('getallheaders') ? getallheaders() : null,
        );
        $raw = file_get_contents('php://input') ?: '';
        $body = $raw !== '' ? json_decode($raw, true) : null;
        return new self($method, $path, $headers, $body);
    }

    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower(str_replace('_', '-', (string) $name))] = $value;
        }
        return $normalized;
    }

    private static function headersFromEnvironment(array $server, mixed $headers): array
    {
        if (is_array($headers) && $headers !== []) {
            return array_merge(self::headersFromServer($server), self::normalizeHeaders($headers));
        }
        return self::headersFromServer($server);
    }

    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
                continue;
            }

            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
        return $headers;
    }

    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }

    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }
        return $this->headers['x-maludb-key'] ?? null;
    }
}
