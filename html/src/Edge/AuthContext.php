<?php
declare(strict_types=1);

namespace MaluDbEdge;

final readonly class AuthContext
{
    public function __construct(
        public int $userId,
        public int $apiKeyId,
        public int $tenantId,
        public string $role,
        public array $scopes,
    ) {}

    public function hasScope(string $scope): bool
    {
        return $this->role === 'admin' || in_array($scope, $this->scopes, true);
    }
}
