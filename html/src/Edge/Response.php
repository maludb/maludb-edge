<?php
declare(strict_types=1);

namespace MaluDbEdge;

final readonly class Response
{
    public function __construct(public int $status, public array $headers, public string $body) {}

    public static function json(mixed $payload, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public static function error(string $code, string $message, int $status, ?string $detail = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($detail !== null) {
            $error['detail'] = $detail;
        }
        return self::json(['error' => $error], $status);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
