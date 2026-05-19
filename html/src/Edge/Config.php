<?php
declare(strict_types=1);

namespace MaluDbEdge;

final readonly class Config
{
    public function __construct(
        public string $sqlitePath,
        public string $appKey,
        public string $archivePath,
        public string $baseUrl,
    ) {}

    public static function fromEnv(): self
    {
        $root = dirname(__DIR__, 3);
        return new self(
            sqlitePath: getenv('MALUDB_EDGE_SQLITE') ?: $root . '/var/edge.sqlite',
            appKey: getenv('MALUDB_EDGE_APP_KEY') ?: '',
            archivePath: getenv('MALUDB_EDGE_ARCHIVE') ?: $root . '/var/archive',
            baseUrl: rtrim(getenv('MALUDB_EDGE_BASE_URL') ?: 'http://localhost', '/'),
        );
    }

    public function requireAppKey(): string
    {
        if (strlen($this->appKey) < 32) {
            throw new \RuntimeException('MALUDB_EDGE_APP_KEY must be at least 32 bytes');
        }
        return $this->appKey;
    }
}
